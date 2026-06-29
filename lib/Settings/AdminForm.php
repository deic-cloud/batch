<?php

declare(strict_types=1);

namespace OCA\Batch\Settings;

use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

/**
 * Admin settings for the Batch app, rendered by core under
 * Administration → Additional settings.
 *
 * storage_type = internal means core reads/writes each field straight to this
 * app's config (batch/<field id>) — the exact appconfig keys BatchService and
 * SetupService consume — so no controller or JS is needed. Field ids therefore
 * MUST match those keys: batch_api_url, batch_service_ip, batch_ca_cert,
 * batch_home_server_url.
 */
class AdminForm implements IDeclarativeSettingsForm {
	public function getSchema(): array {
		return [
			'id' => 'batch_admin',
			'priority' => 50,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'additional',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Batch',
			'description' => 'Connection to the ScienceData GridFactory batch service. The app authenticates each user server-side with their X.509 certificate (managed by files_sharding).',
			'fields' => [
				[
					'id' => 'batch_api_url',
					'title' => 'Batch service URL',
					'description' => 'Base URL of the batch service, e.g. https://batch/. Its host is resolved to the Service IP below, so no /etc/hosts entry is needed.',
					'type' => 'url',
					'placeholder' => 'https://batch/',
					'default' => 'https://batch/',
				],
				[
					'id' => 'batch_service_ip',
					'title' => 'Batch service IP',
					'description' => 'Stable Kubernetes Service ClusterIP fronting the batch pod, e.g. 10.0.0.104. The batch service URL host is CURLOPT_RESOLVE\'d to this address.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '10.0.0.104',
					'default' => '10.0.0.104',
				],
				[
					'id' => 'batch_ca_cert',
					'title' => 'Batch service CA/pinned certificate (path)',
					'description' => 'Path to the batch pod\'s self-signed certificate to pin (PEM). If set, the server certificate is verified against it (hostname check off, as the pod CN is volatile). Leave empty to skip server-cert verification.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '/etc/ssl/batch.pem',
					'default' => '',
				],
				[
					'id' => 'batch_home_server_url',
					'title' => 'Home server base URL',
					'description' => 'Base URL used to build input/output file URLs in job scripts (the user\'s ScienceData home server), e.g. https://silo2.sciencedata.dk. Needed for jobs that stage files from/to the user\'s home.',
					'type' => 'url',
					'placeholder' => 'https://silo2.sciencedata.dk',
					'default' => '',
				],
			],
		];
	}
}
