<?php

declare(strict_types=1);

namespace OCA\Batch\AppInfo;

use OCA\Batch\Listener\LoadFilesScriptsListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'batch';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Connection settings are read from config.php (see BatchService); there
		// is no admin UI.
		// "Process" action in Files: run a job template on the selected file(s).
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptsListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
