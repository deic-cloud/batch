<?php

declare(strict_types=1);

namespace OCA\Batch\Service;

use OCP\App\IAppManager;
use OCP\Server;

/**
 * Lazy bridge to files_sharding's CertificateService (X.509 user certs).
 *
 * batch must stay independently installable, so we do NOT inject
 * CertificateService directly (its class would be absent when files_sharding
 * isn't enabled, breaking DI of the whole app). Instead we resolve it on demand
 * behind an availability guard.
 */
class CertBridge {
	private const CERT_SERVICE = '\\OCA\\FilesSharding\\Service\\CertificateService';

	public function __construct(
		private IAppManager $appManager,
	) {
	}

	public function available(): bool {
		return $this->appManager->isInstalled('files_sharding')
			&& class_exists(self::CERT_SERVICE);
	}

	private function service(): object {
		return Server::get(self::CERT_SERVICE);
	}

	/** @return array{dn:string,expires:string}|null */
	public function info(string $uid): ?array {
		if (!$this->available()) {
			return null;
		}
		try {
			return $this->service()->getCertInfo($uid);
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** @return array{dn:string,expires:string}|false */
	public function generate(string $uid, int $days = 365): array|false {
		if (!$this->available()) {
			return false;
		}
		return $this->service()->generateCertificate($uid, $days);
	}

	public function certPem(string $uid): string {
		return $this->available() ? (string)$this->service()->getCertPem($uid) : '';
	}

	public function keyPem(string $uid): string {
		return $this->available() ? (string)$this->service()->getKeyPem($uid) : '';
	}

	public function deleteCert(string $uid): bool {
		return $this->available() ? (bool)$this->service()->deleteCertificate($uid) : false;
	}
}
