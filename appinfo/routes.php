<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Setup / per-user configuration.
		['name' => 'api#setup',        'url' => '/api/setup',         'verb' => 'GET'],
		['name' => 'api#generateCert', 'url' => '/api/cert',          'verb' => 'POST'],
		['name' => 'api#saveSettings', 'url' => '/api/settings',      'verb' => 'POST'],
		['name' => 'api#getTemplates', 'url' => '/api/templates/get', 'verb' => 'POST'],

		// Job scripts in the user's work folder.
		['name' => 'api#listScripts', 'url' => '/api/scripts',      'verb' => 'GET'],
		['name' => 'api#loadScript',  'url' => '/api/script',       'verb' => 'GET'],
		['name' => 'api#saveScript',  'url' => '/api/script',       'verb' => 'POST'],

		// Jobs — thin JSON API over the batch service (mutual-TLS, client cert).
		['name' => 'api#jobs',     'url' => '/api/jobs',        'verb' => 'GET'],
		['name' => 'api#jobInfo',  'url' => '/api/job',         'verb' => 'GET'],
		['name' => 'api#submit',   'url' => '/api/job',         'verb' => 'POST'],
		['name' => 'api#delete',   'url' => '/api/jobs/delete', 'verb' => 'POST'],
		['name' => 'api#file',     'url' => '/api/job/file',    'verb' => 'GET'],
	],
];
