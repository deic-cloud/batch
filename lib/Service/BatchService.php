<?php

declare(strict_types=1);

namespace OCA\Batch\Service;

use OCA\Batch\Exception\BatchServiceException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Client for the GridFactory batch service. Talks REST over mutual-TLS: the
 * user is authenticated to the batch service server-side with their personal
 * X.509 cert/key, managed by files_sharding's CertificateService (via
 * CertBridge).
 *
 * Configuration is read from config.php system values (so it can be deployed
 * and automated per instance rather than stored in the database):
 *   batch_api_url            base URL of the batch service (required).
 *   files_sharding_cert_org  O= component of the user certificate DN.
 * The server certificate is verified against the system CA store; the batch
 * service must therefore present a certificate from a trusted CA.
 */
class BatchService {
	private string $apiUrl;
	private string $org;

	/** @var array<string,array{cert:string,key:string}> temp cred files, per uid */
	private array $creds = [];

	public function __construct(
		IConfig $config,
		private CertBridge $cert,
		private LoggerInterface $logger,
	) {
		$raw = trim($config->getSystemValueString('batch_api_url', ''));
		$this->apiUrl = $raw === '' ? '' : rtrim($raw, '/') . '/';
		$this->org    = $config->getSystemValueString('files_sharding_cert_org', 'sciencedata.dk');
	}

	/** Whether a batch service URL has been configured (in config.php). */
	public function isConfigured(): bool {
		return $this->apiUrl !== '';
	}

	public function __destruct() {
		foreach ($this->creds as $c) {
			@unlink($c['cert']);
			@unlink($c['key']);
		}
	}

	/** The user's certificate subject as the batch service matches it. */
	public function dn(string $uid): string {
		return "/CN={$uid}/O={$this->org}";
	}

	/** RFC 4122 v4 UUID — used as the job id (the GridFactory spool dir name). */
	private function uuid(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		$h = bin2hex($b);
		return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
			. '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
	}

	/**
	 * The batch service reports a job's identifier as a full URL
	 * (https://batch/gridfactory/jobs/<id>). The db/gridfactory endpoints expect
	 * just the trailing <id>, so reduce to the last path segment.
	 */
	private function shortId(string $identifier): string {
		$id = rtrim($identifier, '/');
		$slash = strrpos($id, '/');
		return $slash === false ? $id : substr($id, $slash + 1);
	}

	// ---------------------------------------------------------------- API

	/**
	 * List the user's jobs. Returns a list of assoc rows keyed by the
	 * tab-separated header line the batch service emits.
	 * @return list<array<string,string>>
	 */
	public function getJobs(string $uid): array {
		// Returns the full job listing the batch service exposes to the caller
		// (it authorises the request by the presented client certificate).
		$text = $this->request($uid, 'GET', $this->apiUrl . 'db/jobs/?format=text');
		$lines = explode("\n", trim($text));
		if (count($lines) < 1 || $lines[0] === '') {
			return [];
		}
		$keys = explode("\t", array_shift($lines));
		$jobs = [];
		foreach ($lines as $line) {
			if ($line === '') {
				continue;
			}
			$vals = explode("\t", $line);
			$row = [];
			foreach ($keys as $i => $key) {
				$row[$key] = $vals[$i] ?? '';
			}
			$jobs[] = $row;
		}
		return $jobs;
	}

	/** @return array<string,string> */
	public function getJobInfo(string $uid, string $identifier): array {
		$id = $this->shortId($identifier);
		$text = $this->request($uid, 'GET', $this->apiUrl . 'db/jobs/' . rawurlencode($id) . '/');
		$job = [];
		foreach (explode("\n", $text) as $line) {
			if ($line === '') {
				continue;
			}
			$kv = explode(': ', $line, 2);
			if (count($kv) === 2) {
				$job[$kv[0]] = $kv[1];
			}
		}
		// Make sure the job script itself is reachable as an input file.
		$jobUrl = $this->apiUrl . 'gridfactory/jobs/' . $id . '/job';
		$inputs = isset($job['inputFileURLs']) ? explode(' ', $job['inputFileURLs']) : [];
		if (!in_array($jobUrl, $inputs, true)) {
			$job['inputFileURLs'] = trim(($job['inputFileURLs'] ?? '') . ' ' . $jobUrl);
		}
		return $job;
	}

	/**
	 * Submit a job. $scriptText is the (already-loaded) job script; $inputFile
	 * is an optional path in the user's home folder to wire into the
	 * #GRIDFACTORY placeholders. Returns the created job id (URL) or throws.
	 */
	public function submitJob(string $uid, string $scriptText, ?string $inputFile, string $workFolder, string $homeServerUrl): string {
		if (trim($scriptText) === '') {
			throw new BatchServiceException('Empty job script.');
		}
		// The GridFactory spoolmanager requires the job id (its spool dir name) to
		// be a UUID; a non-UUID name makes the server assign its own UUID, which
		// then no longer matches the dir ("could not prepare job").
		$jobId = $this->apiUrl . 'gridfactory/jobs/' . $this->uuid();

		// Stamp the job's own id and substitute the template placeholders.
		$pos = strpos($scriptText, '#GRIDFACTORY');
		if ($pos !== false) {
			$scriptText = substr_replace($scriptText, "#GRIDFACTORY -u {$jobId}\n#GRIDFACTORY", $pos, strlen('#GRIDFACTORY'));
		}

		// The worker stages files over WebDAV against the user's home server;
		// Nextcloud serves each user's files at /remote.php/dav/files/<uid>.
		// Keep the trailing slash on the base and strip leading slashes off the
		// folder/file parts so the join is correct whether or not the caller
		// passed leading slashes (otherwise ".../files/alice" + "Batch/…" would
		// yield ".../files/aliceBatch/…").
		$davBase = $homeServerUrl !== '' ? $homeServerUrl . '/remote.php/dav/files/' . $uid . '/' : '';
		$workFolderUrl = $davBase !== '' ? $davBase . ltrim($workFolder, '/') : '';
		$subs = [
			'WORK_FOLDER_URL'        => $workFolderUrl,
			'HOME_SERVER_PRIVATE_URL' => $homeServerUrl,
			'MY_SSL_DN'              => $this->dn($uid),
			'SD_USER'                => $uid,
		];
		if ($inputFile !== null && $inputFile !== '') {
			$inputFileUrl   = $davBase . ltrim($inputFile, '/');
			$inputFolderUrl = preg_replace('|/[^/]+$|', '/', $inputFileUrl) ?? $inputFileUrl;
			$inputFilename  = basename($inputFile);
			$inputBasename  = preg_replace('|\.[^.]+$|', '', $inputFilename) ?? $inputFilename;
			$subs += [
				'IN_FILENAME_RAW' => rawurldecode($inputFilename),
				'IN_BASENAME_RAW' => rawurldecode($inputBasename),
				'IN_FILE_URL'     => $inputFileUrl,
				'IN_FOLDER_URL'   => $inputFolderUrl,
				'IN_FILENAME'     => $inputFilename,
				'IN_BASENAME'     => $inputBasename,
			];
		}
		$scriptText = strtr($scriptText, $subs);

		$this->request($uid, 'MKCOL', $jobId);
		// Uploading the job script triggers execution.
		$this->request($uid, 'PUT', $jobId . '/job', $scriptText);
		return $jobId;
	}

	public function requestJobOutput(string $uid, string $identifier): void {
		$this->request($uid, 'PUT', $this->apiUrl . 'db/jobs/' . rawurlencode($this->shortId($identifier)), 'csStatus: running:requestOutput');
	}

	public function deleteJob(string $uid, string $identifier): void {
		$id = rawurlencode($this->shortId($identifier));
		// Delete the job's spool dir; the GridFactory daemon then reconciles the
		// db row away within a few seconds (so the listing clears on the next
		// refresh). This is the authorised path — a direct db/jobs DELETE of a
		// queued job is refused (401) and/or re-asserted from the spool, whereas
		// deleting your own spool dir is allowed. Tolerate 404 (already gone).
		try {
			$this->request($uid, 'DELETE', $this->apiUrl . 'gridfactory/jobs/' . $id);
		} catch (BatchServiceException $e) {
			if ($e->getCode() !== 404) {
				throw $e;
			}
		}
	}

	/** Fetch an arbitrary job file (stdout/stderr/script/output) with the user's cert. */
	public function getContent(string $uid, string $url): string {
		return $this->request($uid, 'GET', $url);
	}

	/**
	 * Fetch a file belonging to a job (stdout, stderr, the job script, …). The
	 * URL is built server-side from the job id + filename so a client can't aim
	 * the user's certificate at an arbitrary host. For a still-running job,
	 * nudge the worker to deliver fresh output first.
	 */
	public function getJobFile(string $uid, string $identifier, string $filename, string $status = ''): string {
		if (($filename === 'stdout' || $filename === 'stderr') && str_starts_with($status, 'running')) {
			try {
				$this->requestJobOutput($uid, $identifier);
			} catch (BatchServiceException $e) {
				// best-effort; fall through to whatever output already exists
			}
		}
		$url = $this->apiUrl . 'gridfactory/jobs/' . rawurlencode($this->shortId($identifier)) . '/' . rawurlencode($filename);
		return $this->getContent($uid, $url);
	}

	// ------------------------------------------------------------- transport

	private function request(string $uid, string $method, string $url, ?string $body = null): string {
		[$certFile, $keyFile] = $this->credentials($uid);

		$ch = curl_init();
		$opts = [
			CURLOPT_URL            => $url,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_SSLCERT        => $certFile,
			CURLOPT_SSLKEY         => $keyFile,
			CURLOPT_USERAGENT      => 'Nextcloud-batch',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 60,
			// Verify the server certificate and hostname against the system CA
			// store; the batch service must present a certificate from a CA the
			// host trusts.
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_SSL_VERIFYPEER => true,
		];
		if ($body !== null) {
			$opts[CURLOPT_POSTFIELDS] = $body;
		}
		curl_setopt_array($ch, $opts);

		$data = curl_exec($ch);
		$errno = curl_errno($ch);
		$err   = curl_error($ch);
		$code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($errno !== 0) {
			$this->logger->error("batch: {$method} {$url} failed: {$err}", ['app' => 'batch']);
			throw new BatchServiceException('Batch service unreachable: ' . $err);
		}
		if ($code >= 400) {
			$this->logger->error("batch: {$method} {$url} -> HTTP {$code}", ['app' => 'batch']);
			throw new BatchServiceException("Batch service returned HTTP {$code}" . ($code === 401 || $code === 403 ? ' (not authorised — you may not own this job, or your certificate is not in a required VO)' : ''), $code);
		}
		return (string)$data;
	}

	/**
	 * Write the user's cert + decrypted key to short-lived 0600 temp files for
	 * curl (cleaned up in __destruct). Cached per uid for the request lifetime.
	 * @return array{0:string,1:string} [certFile, keyFile]
	 */
	private function credentials(string $uid): array {
		if (isset($this->creds[$uid])) {
			return [$this->creds[$uid]['cert'], $this->creds[$uid]['key']];
		}
		if (!$this->cert->available()) {
			throw new BatchServiceException('X.509 certificates are managed by files_sharding, which is not enabled.');
		}
		$certPem = $this->cert->certPem($uid);
		$keyPem  = $this->cert->keyPem($uid);
		if ($certPem === '' || $keyPem === '') {
			throw new BatchServiceException('No certificate found — generate one in Batch setup first.');
		}
		$certFile = (string)tempnam(sys_get_temp_dir(), 'batchcert_');
		$keyFile  = (string)tempnam(sys_get_temp_dir(), 'batchkey_');
		chmod($certFile, 0600);
		chmod($keyFile, 0600);
		file_put_contents($certFile, $certPem);
		file_put_contents($keyFile, $keyPem);
		$this->creds[$uid] = ['cert' => $certFile, 'key' => $keyFile];
		return [$certFile, $keyFile];
	}
}
