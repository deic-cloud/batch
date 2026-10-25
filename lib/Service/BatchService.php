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

	/**
	 * The user's certificate subject in GridFactory's slash form
	 * ("/CN=alice/O=sciencedata.dk" - what its own client writes with
	 * #GRIDFACTORY -s, Util.getDN), read from the actual certificate.
	 */
	public function dn(string $uid): string {
		try {
			$name = (string)(openssl_x509_parse($this->cert->certPem($uid))['name'] ?? '');
			if ($name !== '') {
				return $name;
			}
		} catch (\Throwable) {
		}
		return "/CN={$uid}/O={$this->org}";
	}

	private function certPemOrEmpty(string $uid): string {
		try {
			return trim($this->cert->certPem($uid));
		} catch (\Throwable) {
			return '';
		}
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
		// The batch service lists every job it holds; the caller's are those whose
		// userInfo (the DN stamped at submission) is theirs. Filtered here rather than
		// with ?userInfo=, which mod_gridfactory pastes into SQL unescaped. Access to a
		// job itself is still decided by the server (per-job GACL on the verified DN).
		$text = $this->request($uid, 'GET', $this->apiUrl . 'db/jobs/?format=text');
		$mine = $this->dn($uid);
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
			if (($row['userInfo'] ?? '') !== $mine) {
				continue;
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
	public function submitJob(string $uid, string $scriptText, ?string $inputFile, string $workFolder, string $homeServerUrl, string $homeServerPrivateUrl = ''): string {
		if (trim($scriptText) === '') {
			throw new BatchServiceException('Empty job script.');
		}
		// The GridFactory spoolmanager requires the job id (its spool dir name) to
		// be a UUID; a non-UUID name makes the server assign its own UUID, which
		// then no longer matches the dir ("could not prepare job").
		$jobId = $this->apiUrl . 'gridfactory/jobs/' . $this->uuid();

		// Stamp the job's own id and the submitter's DN (#GRIDFACTORY -s, as
		// GridFactory's own client does) - the spoolmanager records it as the job's
		// userInfo. Any -s line in the script is dropped: a job is labelled with its
		// real submitter only.
		$scriptText = preg_replace('/^#GRIDFACTORY[ \t]+-s\b.*\R?/m', '', $scriptText);
		$dn = $this->dn($uid);
		$pos = strpos($scriptText, '#GRIDFACTORY');
		if ($pos !== false) {
			$scriptText = substr_replace($scriptText, "#GRIDFACTORY -u {$jobId}\n#GRIDFACTORY -s {$dn}\n#GRIDFACTORY", $pos, strlen('#GRIDFACTORY'));
		} else {
			// No directives of its own: stamp them after the shebang (or at the top).
			$stamp = "#GRIDFACTORY -u {$jobId}\n#GRIDFACTORY -s {$dn}\n";
			$nl = strpos($scriptText, "\n");
			$scriptText = str_starts_with($scriptText, '#!') && $nl !== false
				? substr($scriptText, 0, $nl + 1) . $stamp . substr($scriptText, $nl + 1)
				: $stamp . $scriptText;
		}

		// The batch server stages files over WebDAV against the user's home
		// server through /grid/ (rewritten to /remote.php/webdav/), the location
		// that asks for a client certificate: the server presents its own
		// certificate and names the job's owner in the SSL-CLIENT-DN header
		// (files_sharding X509Backend, trusted daemon). /remote.php/dav/files/<uid>
		// asks for no certificate, so the server cannot authenticate there.
		$davBase = $homeServerUrl !== '' ? $homeServerUrl . '/grid/' : '';
		// Paths come in raw (as shown in Files: "Templates credits.md"). URLs get
		// each segment percent-encoded - a raw space would split the #GRIDFACTORY
		// -i/-o directive. IN_FILENAME/IN_BASENAME are the encoded forms (the names
		// that are safe in directives); the _RAW forms are the real names, which
		// is what the staged input file is called on the worker.
		$enc = static fn (string $path): string => implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
		$workFolderUrl = $davBase !== '' ? $davBase . $enc($workFolder) : '';
		$subs = [
			'WORK_FOLDER_URL'        => $workFolderUrl,
			'HOME_SERVER_PRIVATE_URL' => $homeServerPrivateUrl !== '' ? $homeServerPrivateUrl : $homeServerUrl,
			'MY_SSL_DN'              => $this->dn($uid),
			// The user's own certificate (public), for jobs that encrypt to the
			// user without fetching anything from the worker.
			'MY_SSL_CERT'            => $this->certPemOrEmpty($uid),
			'SD_USER'                => $uid,
		];
		if ($inputFile !== null && $inputFile !== '') {
			$inputFileUrl   = $davBase . $enc($inputFile);
			$inputFolderUrl = preg_replace('|/[^/]+$|', '/', $inputFileUrl) ?? $inputFileUrl;
			$rawFilename    = basename($inputFile);
			$rawBasename    = preg_replace('|\.[^.]+$|', '', $rawFilename) ?? $rawFilename;
			$subs += [
				'IN_FILENAME_RAW' => $rawFilename,
				'IN_BASENAME_RAW' => $rawBasename,
				'IN_FILE_URL'     => $inputFileUrl,
				'IN_FOLDER_URL'   => $inputFolderUrl,
				'IN_FILENAME'     => rawurlencode($rawFilename),
				'IN_BASENAME'     => rawurlencode($rawBasename),
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

	/**
	 * Request a running job be KILLED. Unlike deleteJob (which removes the job
	 * directory), this only sets csStatus to "running:requestKill" — the
	 * queuemanager daemon stops the process but LEAVES the job and its files,
	 * so stdout/stderr stay inspectable (e.g. to see why a job ran too long).
	 * GridFactory only allows csStatus to change (mod_gridfactory), so this is
	 * the same PUT shape as requestJobOutput. A queued/ready job is not
	 * "running" and is removed with deleteJob instead; the UI only offers Kill
	 * on running jobs.
	 */
	public function killJob(string $uid, string $identifier): void {
		$id = $this->shortId($identifier);
		// Decide the target status from the job's current one, mirroring the
		// GridFactory CLI killJob(): a running-ish job is asked to be killed
		// (process stops, files kept); a still-queued job is aborted; a finished
		// job is a no-op. GridFactory only lets csStatus change.
		$status = '';
		try {
			$text = $this->request($uid, 'GET', $this->apiUrl . 'db/jobs/' . rawurlencode($id) . '/');
			foreach (explode("\n", $text) as $line) {
				if (str_starts_with($line, 'csStatus: ')) {
					$status = trim(substr($line, 10));
					break;
				}
			}
		} catch (BatchServiceException) {
			// fall through — treat as running (best effort)
		}
		$target = '';
		if ($status === '' || str_starts_with($status, 'running') || str_starts_with($status, 'prepared') || str_starts_with($status, 'paused')) {
			$target = 'running:requestKill';
		} elseif (str_starts_with($status, 'ready') || str_starts_with($status, 'defined')) {
			$target = 'aborted';
		} else {
			return; // done / failed / aborted — nothing to kill
		}
		$this->request($uid, 'PUT', $this->apiUrl . 'db/jobs/' . rawurlencode($id), 'csStatus: ' . $target);
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
