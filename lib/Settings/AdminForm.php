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
					'description' => 'Base URL of the batch service. Use the kube-Caddy FQDN (https://batch.sciencedata.dk/) — it sets the SSL-CLIENT-DN header that authorises each user for submit AND delete. The direct ClusterIP path lacks that header, so jobs can be submitted but not deleted.',
					'type' => 'url',
					'placeholder' => 'https://batch.sciencedata.dk/',
					'default' => 'https://batch.sciencedata.dk/',
				],
				[
					'id' => 'batch_service_ip',
					'title' => 'Batch service IP (optional)',
					'description' => 'Leave empty for the normal kube-Caddy path above. Set a Kubernetes Service ClusterIP (e.g. 10.0.0.104) only to CURLOPT_RESOLVE the URL host directly to a private pod that bypasses kube-Caddy.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '(empty)',
					'default' => '',
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
