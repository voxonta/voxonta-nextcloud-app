<?php

declare(strict_types=1);

namespace OCA\Voxonta\AppInfo;

use OCA\Voxonta\Listener\BotListener;
use OCA\Voxonta\Listener\CallListener;
use OCA\Voxonta\Settings\AdminSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'voxonta';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Without this the form exists as a class and nowhere else: Nextcloud
		// only renders declarative settings it was told about, and an
		// unregistered one fails silently — no error, no section, nothing.
		$context->registerDeclarativeSettings(AdminSettings::class);

		// Talk's events, registered by name and unconditionally.
		//
		// By name because that is all registerEventListener needs; on an
		// instance without Talk they simply never fire, and the archive works on
		// its own. Not behind class_exists: apps register before every other
		// app's classes are loadable, so that check can be false at this moment
		// and true forever after — which is what happened, leaving the listeners
		// unregistered with nothing in the log to say so.
		//
		// BotInvokeEvent is how Talk hands a bot its messages when the bot's URL
		// uses the nextcloudapp:// prefix — that is why the opt-out command
		// works in one-to-one calls, where there is no webhook.
		$context->registerEventListener(
			'OCA\Talk\Events\BotInvokeEvent',
			BotListener::class,
		);

		// And these replace reading Nextcloud's database over an SSH tunnel:
		// Talk says when a call starts and ends, the capture service asks us
		// which are live.
		foreach ([
			'OCA\Talk\Events\CallStartedEvent',
			'OCA\Talk\Events\CallEndedEvent',
			'OCA\Talk\Events\CallEndedForEveryoneEvent',
		] as $event) {
			$context->registerEventListener($event, CallListener::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}
