<?php

declare(strict_types=1);

namespace OCA\Batch\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** Loads the Files "Process" action. @template-implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadFilesScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}
		// Loaded after the Files app so its action registry exists.
		Util::addScript('batch', 'files-action', 'files');
	}
}
