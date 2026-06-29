<?php

declare(strict_types=1);

namespace OCA\Batch\Controller;

use OCA\Batch\Exception\BatchServiceException;
use OCA\Batch\Service\BatchService;
use OCA\Batch\Service\SetupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private BatchService $batch,
		private SetupService $setup,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	private function ok(mixed $data = null): JSONResponse {
		return new JSONResponse(['status' => 'success', 'data' => $data]);
	}

	private function fail(string $message, int $code = Http::STATUS_OK): JSONResponse {
		return new JSONResponse(['status' => 'error', 'data' => ['message' => $message]], $code);
	}

	/**
	 * Run a batch-service call, turning a transport-level failure into a 502
	 * with the same {status:error,data:{message}} shape the JS already handles.
	 */
	private function host(callable $fn): JSONResponse {
		try {
			return $this->ok($fn());
		} catch (BatchServiceException $e) {
			$this->logger->error('batch: host call failed: ' . $e->getMessage(), ['app' => 'batch', 'exception' => $e]);
			return $this->fail($e->getMessage(), Http::STATUS_BAD_GATEWAY);
		}
	}

	// ----------------------------------------------------------------- setup

	#[NoAdminRequired]
	public function setup(): JSONResponse {
		return $this->ok($this->setup->status($this->uid()));
	}

	#[NoAdminRequired]
	public function generateCert(): JSONResponse {
		$res = $this->setup->generateCert($this->uid());
		return $res === false
			? $this->fail('Could not generate a certificate (is files_sharding enabled and the CA configured?).')
			: $this->ok($res);
	}

	#[NoAdminRequired]
	public function saveSettings(string $work_folder = ''): JSONResponse {
		if (trim($work_folder) === '') {
			return $this->fail('Please choose a work folder.');
		}
		$this->setup->saveSettings($this->uid(), $work_folder);
		return $this->ok($this->setup->status($this->uid()));
	}

	#[NoAdminRequired]
	public function getTemplates(): JSONResponse {
		$this->setup->getTemplates($this->uid());
		return $this->ok(['scripts' => $this->setup->listScripts($this->uid())]);
	}

	#[NoAdminRequired]
	public function listScripts(): JSONResponse {
		return $this->ok($this->setup->listScripts($this->uid()));
	}

	#[NoAdminRequired]
	public function loadScript(string $path = ''): JSONResponse {
		if ($path === '') {
			return $this->fail('No script specified.');
		}
		try {
			return $this->ok($this->setup->loadScript($this->uid(), $path));
		} catch (\Throwable $e) {
			return $this->fail('Could not read ' . $path);
		}
	}

	#[NoAdminRequired]
	public function saveScript(string $path = '', string $text = ''): JSONResponse {
		if ($path === '') {
			return $this->fail('No script specified.');
		}
		try {
			$this->setup->saveScript($this->uid(), $path, $text);
			return $this->ok();
		} catch (\Throwable $e) {
			return $this->fail('Could not save ' . $path);
		}
	}

	// ------------------------------------------------------------------ jobs

	#[NoAdminRequired]
	public function jobs(): JSONResponse {
		return $this->host(fn () => $this->batch->getJobs($this->uid()));
	}

	#[NoAdminRequired]
	public function jobInfo(string $identifier = ''): JSONResponse {
		if ($identifier === '') {
			return $this->fail('No job specified.');
		}
		return $this->host(fn () => $this->batch->getJobInfo($this->uid(), $identifier));
	}

	#[NoAdminRequired]
	public function submit(string $script_text = '', string $script_path = '', string $input_files = ''): JSONResponse {
		$uid = $this->uid();
		if (trim($script_text) === '' && $script_path !== '') {
			try {
				$script_text = $this->setup->loadScript($uid, $script_path);
			} catch (\Throwable $e) {
				return $this->fail('Could not read ' . $script_path);
			}
		}
		if (trim($script_text) === '') {
			return $this->fail('No job script.');
		}
		$inputs = [];
		if (trim($input_files) !== '') {
			$decoded = json_decode($input_files, true);
			if (is_array($decoded)) {
				$inputs = $decoded;
			}
		}
		$workFolder = $this->setup->workFolder($uid);
		$homeUrl    = $this->setup->homeServerUrl($uid);
		try {
			$ids = [];
			if ($inputs === []) {
				$ids[] = $this->batch->submitJob($uid, $script_text, null, $workFolder, $homeUrl);
			} else {
				foreach ($inputs as $file) {
					$ids[] = $this->batch->submitJob($uid, $script_text, (string)$file, $workFolder, $homeUrl);
				}
			}
			return $this->ok(['jobs' => $ids]);
		} catch (BatchServiceException $e) {
			return $this->fail($e->getMessage(), Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function delete(string $identifiers = ''): JSONResponse {
		$ids = json_decode($identifiers, true);
		if (!is_array($ids) || $ids === []) {
			return $this->fail('No job specified.');
		}
		try {
			foreach ($ids as $id) {
				$this->batch->deleteJob($this->uid(), (string)$id);
			}
			return $this->ok();
		} catch (BatchServiceException $e) {
			return $this->fail($e->getMessage(), Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function file(string $identifier = '', string $filename = '', string $status = '', string $download = ''): JSONResponse|DataDownloadResponse {
		if ($identifier === '' || $filename === '') {
			return $this->fail('Job and filename required.');
		}
		try {
			$content = $this->batch->getJobFile($this->uid(), $identifier, $filename, $status);
		} catch (BatchServiceException $e) {
			return $this->fail($e->getMessage(), Http::STATUS_BAD_GATEWAY);
		}
		if ($download === 'true' || $download === '1') {
			return new DataDownloadResponse($content, $filename, 'application/octet-stream');
		}
		return $this->ok(['filename' => $filename, 'content' => $content]);
	}
}
