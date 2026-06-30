<?php

declare(strict_types=1);

namespace OCA\Batch\Service;

use OCA\Batch\Exception\BatchServiceException;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Client for the ScienceData GridFactory batch service (NC34 port of the OC7
 * OC_Batch_Util). Talks REST over mutual-TLS: the user is authenticated to the
 * batch service server-side with their personal X.509 cert/key, managed by
 * files_sharding's CertificateService (via CertBridge).
 *
 * Connection (system config, with defaults that match our cluster):
 *   batch_api_url     base URL, default https://batch/
 *   batch_service_ip  stable k8s Service ClusterIP, default 10.0.0.104 — the
 *                     api_url host is CURLOPT_RESOLVE'd to this, so we don't
 *                     depend on an /etc/hosts entry.
 *   batch_ca_cert     path to the batch pod's pinned (self-signed) cert. If set,
 *                     the server cert is verified against it (VERIFYPEER on) with
 *                     hostname checking off (the pod CN is the volatile pod name,
 *                     no SAN). If empty, peer verification is disabled (dev).
 *   files_sharding_cert_org  O= component of the user DN, default sciencedata.dk
 */
class BatchService {
	private string $apiUrl;
	private string $serviceIp;
	private string $caCert;
	private string $org;

	/** @var array<string,array{cert:string,key:string}> temp cred files, per uid */
	private array $creds = [];

	public function __construct(
		IAppConfig $appConfig,
		IConfig $config,
		private CertBridge $cert,
		private LoggerInterface $logger,
	) {
		// Connection config = per-instance appconfig (driven by the admin form).
		// Default to the kube-Caddy FQDN: it terminates the client-cert TLS and
		// sets the SSL-CLIENT-DN header mod_gacl needs to authorise the user
		// (submit AND manage/delete). The direct ClusterIP path carries no such
		// header, so jobs can be submitted but not deleted. Leave batch_service_ip
		// empty so the host resolves normally (through kube-Caddy); set it only
		// for a private pod reachable directly by ClusterIP.
		$this->apiUrl    = rtrim($appConfig->getValueString('batch', 'batch_api_url', 'https://batch.sciencedata.dk/'), '/') . '/';
		$this->serviceIp = $appConfig->getValueString('batch', 'batch_service_ip', '');
		$this->caCert    = $appConfig->getValueString('batch', 'batch_ca_cert', '');
		// O= component of the user DN is a files_sharding system value.
		$this->org       = $config->getSystemValueString('files_sharding_cert_org', 'sciencedata.dk');
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
		// NB: we do NOT filter by userInfo. On the direct silo→pod path the
		// SSL-Client-DN header (set only by kube-Caddy from 10.2.12.1) is absent,
		// so the batch service stores an empty userInfo on submitted jobs and a
		// userInfo filter would hide every job. Per-user scoping needs the
		// qualified kube-Caddy path — a decision flagged for Frederik.
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
	 * is an optional path in the user's ScienceData home to wire into the
	 * #GRIDFACTORY placeholders. Returns the created job id (URL) or throws.
	 */
	public function submitJob(string $uid, string $scriptText, ?string $inputFile, string $workFolder, string $homeServerUrl): string {
		if (trim($scriptText) === '') {
			throw new BatchServiceException('Empty job script.');
		}
		$jobId = $this->apiUrl . 'gridfactory/jobs/' . uniqid();

		// Stamp the job's own id and substitute the template placeholders.
		$pos = strpos($scriptText, '#GRIDFACTORY');
		if ($pos !== false) {
			$scriptText = substr_replace($scriptText, "#GRIDFACTORY -u {$jobId}\n#GRIDFACTORY", $pos, strlen('#GRIDFACTORY'));
		}

		$workFolderUrl = $homeServerUrl !== '' ? $homeServerUrl . '/grid' . $workFolder : '';
		$subs = [
			'WORK_FOLDER_URL'        => $workFolderUrl,
			'HOME_SERVER_PRIVATE_URL' => $homeServerUrl,
			'MY_SSL_DN'              => $this->dn($uid),
			'SD_USER'                => $uid,
		];
		if ($inputFile !== null && $inputFile !== '') {
			$inputFileUrl   = $homeServerUrl . '/grid' . $inputFile;
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
			CURLOPT_USERAGENT      => 'ScienceData/Nextcloud-batch',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 60,
			// Verify the server certificate and hostname. The default kube-Caddy
			// FQDN (batch.sciencedata.dk) presents a Let's Encrypt cert validated
			// against the system CA store. For a private pod reached directly, set
			// batch_ca_cert to pin its self-signed cert — that cert now carries a
			// 'batch' SAN, so the hostname check still passes.
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_SSL_VERIFYPEER => true,
		];
		if ($this->caCert !== '') {
			$opts[CURLOPT_CAINFO] = $this->caCert;
		}
		// Resolve the api_url host to the stable Service IP without an
		// /etc/hosts entry (avoids depending on the batch_host.sh cronjob).
		$host = parse_url($this->apiUrl, PHP_URL_HOST);
		if ($host && $this->serviceIp !== '') {
			$opts[CURLOPT_RESOLVE] = ["{$host}:443:{$this->serviceIp}"];
		}
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
			throw new BatchServiceException("Batch service returned HTTP {$code}" . ($code === 401 || $code === 403 ? ' (not authorised — is your certificate in a VO?)' : ''), $code);
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
