<?php

declare(strict_types=1);

namespace OCA\Batch\Service;

use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Per-user setup for the batch app: X.509 certificate status/generation (via
 * CertBridge), the work folder (where job templates and outputs live), and
 * reading/writing job scripts in the user's files.
 */
class SetupService {
	private const WORK_FOLDER_KEY = 'batch_folder';
	private const DEFAULT_WORK_FOLDER = '/Batch';
	private const SCRIPT_EXTS = ['sh', 'py'];
	private const SHARDING_SERVICE = '\\OCA\\FilesSharding\\Service\\ShardingService';

	public function __construct(
		private IConfig $config,
		private CertBridge $cert,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private IAppManager $appManager,
	) {
	}

	/** @return array<string,mixed> */
	public function status(string $uid): array {
		$info = $this->cert->info($uid);
		return [
			'filesSharding' => $this->cert->available(),
			'hasCert'       => $info !== null,
			'certDn'        => $info['dn'] ?? '',
			'certExpires'   => $info['expires'] ?? '',
			'workFolder'    => $this->workFolder($uid),
			'configured'    => $info !== null && $this->config->getUserValue($uid, 'batch', self::WORK_FOLDER_KEY, '') !== '',
		];
	}

	public function workFolder(string $uid): string {
		return $this->config->getUserValue($uid, 'batch', self::WORK_FOLDER_KEY, self::DEFAULT_WORK_FOLDER);
	}

	/**
	 * Base URL of the user's home server, used to build the input/output file
	 * URLs in job scripts. The app runs on the user's home silo, so the home
	 * server is this instance — use its own URL (overwrite.cli.url). In the test
	 * setup the external URL doubles as the internal one; a dedicated internal
	 * URL for the staging network can be added later if it differs.
	 */
	public function homeServerUrl(string $uid): string {
		return rtrim($this->config->getSystemValueString('overwrite.cli.url', ''), '/');
	}

	/**
	 * The user's home server as seen from the pod network (e.g. https://10.2.0.20),
	 * for HOME_SERVER_PRIVATE_URL: a job on the user's own worker pod fetches the
	 * user's key or certificate there, and the home server maps a request to the
	 * pod's owner only when it arrives from the pod network (files_sharding
	 * IpAuthBackend, 'uservlannet'). Over the public URL the request leaves through
	 * NAT and is refused. Derived as the OC7 app did: the home server's internal
	 * URL (files_sharding registry) with its network prefix replaced by the pod
	 * network's, e.g. 10.0.0.20 -> 10.2.0.20. Falls back to the public URL.
	 */
	public function homeServerPrivateUrl(string $uid): string {
		$public = $this->homeServerUrl($uid);
		$vlan = explode(' ', trim($this->config->getSystemValueString('uservlannet', '')))[0];
		$prefix = rtrim($vlan, '.');
		if ($prefix === '' || !$this->appManager->isEnabledForAnyone('files_sharding')
				|| !class_exists(self::SHARDING_SERVICE)) {
			return $public;
		}
		try {
			$server = Server::get(self::SHARDING_SERVICE)->getUserServer($uid);
			$internal = $server !== null ? trim((string)$server->getInternalUrl()) : '';
		} catch (\Throwable $e) {
			return $public;
		}
		$p = $internal !== '' ? parse_url($internal) : false;
		$host = is_array($p) ? ($p['host'] ?? '') : '';
		if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return $public;
		}
		$keep = array_slice(explode('.', $host), substr_count($prefix, '.') + 1);
		return ($p['scheme'] ?? 'https') . '://' . $prefix . '.' . implode('.', $keep)
			. (isset($p['port']) ? ':' . $p['port'] : '');
	}

	/** @return array{dn:string,expires:string}|false */
	public function generateCert(string $uid): array|false {
		return $this->cert->generate($uid);
	}

	public function saveSettings(string $uid, string $workFolder): void {
		$workFolder = '/' . trim($workFolder, '/');
		$this->config->setUserValue($uid, 'batch', self::WORK_FOLDER_KEY, $workFolder);
		// Make sure the folder and its output_files subfolder exist.
		$this->ensureFolder($this->rootFolder->getUserFolder($uid), ltrim($workFolder, '/') . '/output_files');
	}

	/**
	 * Copy the bundled job templates into <workFolder>/job_templates in the
	 * user's files (idempotent — skips files that already exist).
	 */
	public function getTemplates(string $uid): void {
		$src = dirname(__DIR__, 2) . '/job_templates';
		if (!is_dir($src)) {
			return;
		}
		$dst = $this->ensureFolder(
			$this->rootFolder->getUserFolder($uid),
			ltrim($this->workFolder($uid), '/') . '/job_templates',
		);
		$this->copyTree($src, $dst);
	}

	/** @return list<string> relative script paths (.sh/.py) under the work folder */
	public function listScripts(string $uid): array {
		try {
			$base = $this->rootFolder->getUserFolder($uid)->get(ltrim($this->workFolder($uid), '/'));
		} catch (NotFoundException $e) {
			return [];
		}
		if (!$base instanceof Folder) {
			return [];
		}
		$scripts = [];
		// Prefix with the work-folder path so entries are root-relative and feed
		// straight back into loadScript().
		$this->collectScripts($base, ltrim($this->workFolder($uid), '/'), $scripts);
		sort($scripts);
		return $scripts;
	}

	/** $relPath is relative to the user's files root (e.g. Batch/job_templates/util/xz.sh). */
	public function loadScript(string $uid, string $relPath): string {
		$node = $this->rootFolder->getUserFolder($uid)->get(ltrim($relPath, '/'));
		return $node instanceof \OCP\Files\File ? $node->getContent() : '';
	}

	public function saveScript(string $uid, string $relPath, string $text): void {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$relPath = ltrim($relPath, '/');
		if ($userFolder->nodeExists($relPath)) {
			$userFolder->get($relPath)->putContent($text);
			return;
		}
		$dir = dirname($relPath);
		$folder = $dir === '.' ? $userFolder : $this->ensureFolder($userFolder, $dir);
		$folder->newFile(basename($relPath), $text);
	}

	// ----------------------------------------------------------------- helpers

	private function ensureFolder(Folder $base, string $relPath): Folder {
		$node = $base;
		foreach (explode('/', trim($relPath, '/')) as $segment) {
			if ($segment === '') {
				continue;
			}
			if ($node->nodeExists($segment)) {
				$child = $node->get($segment);
				$node = $child instanceof Folder ? $child : $node->newFolder($segment);
			} else {
				$node = $node->newFolder($segment);
			}
		}
		return $node;
	}

	private function copyTree(string $srcDir, Folder $dstFolder): void {
		foreach (scandir($srcDir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$srcPath = $srcDir . '/' . $entry;
			if (is_dir($srcPath)) {
				$this->copyTree($srcPath, $this->ensureFolder($dstFolder, $entry));
			} else {
				// Overwrite existing templates (as the UI states) so re-copying
				// refreshes them to the current bundled versions.
				$content = (string)file_get_contents($srcPath);
				if ($dstFolder->nodeExists($entry)) {
					$dstFolder->get($entry)->putContent($content);
				} else {
					$dstFolder->newFile($entry, $content);
				}
			}
		}
	}

	/** @param list<string> $out */
	private function collectScripts(Folder $folder, string $prefix, array &$out): void {
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			$rel = $prefix === '' ? $name : $prefix . '/' . $name;
			if ($node instanceof Folder) {
				$this->collectScripts($node, $rel, $out);
			} else {
				$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
				if (in_array($ext, self::SCRIPT_EXTS, true)) {
					$out[] = $rel;
				}
			}
		}
	}
}
