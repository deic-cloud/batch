<?php

declare(strict_types=1);

namespace OCA\Batch\AppInfo;

use OCA\Batch\Settings\AdminForm;
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
		// Connection settings live under Administration → Additional settings.
		$context->registerDeclarativeSettings(AdminForm::class);
	}

	public function boot(IBootContext $context): void {
	}
}
