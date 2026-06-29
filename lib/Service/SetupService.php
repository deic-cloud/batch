<?php

declare(strict_types=1);

namespace OCA\Batch\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IConfig;
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

	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private CertBridge $cert,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
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

	/** Base URL of the user's home server, used to build input/output URLs in job scripts. */
	public function homeServerUrl(string $uid): string {
		return rtrim($this->appConfig->getValueString('batch', 'batch_home_server_url', ''), '/');
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
			} elseif (!$dstFolder->nodeExists($entry)) {
				$dstFolder->newFile($entry, (string)file_get_contents($srcPath));
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
