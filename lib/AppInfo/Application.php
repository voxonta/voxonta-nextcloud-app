<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\AppInfo;

use OCA\DoneTranscription\Listener\BotListener;
use OCA\DoneTranscription\Listener\CallListener;
use OCA\DoneTranscription\Settings\AdminSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'done_transcription';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Without this the form exists as a class and nowhere else: Nextcloud
		// only renders declarative settings it was told about, and an
		// unregistered one fails silently — no error, no section, nothing.
		$context->registerDeclarativeSettings(AdminSettings::class);

		// Talk dispatches this instead of calling a webhook when a bot's URL
		// uses the nextcloudapp:// prefix. That is the whole reason the opt-out
		// command works in one-to-one calls: there is no REST polling involved,
		// Talk hands us the message directly.
		//
		// Registered conditionally because the app must still install on an
		// instance without Talk — the archive is useful on its own, and a
		// missing class here would break the whole app, not just the bot.
		if (class_exists('\OCA\Talk\Events\BotInvokeEvent')) {
			$context->registerEventListener(
				'\OCA\Talk\Events\BotInvokeEvent',
				BotListener::class,
			);
		}

		// What replaces reading Nextcloud's database over an SSH tunnel: Talk
		// says when a call starts and ends, and the capture service asks us.
		// Registered by name for the same reason as above — the app installs on
		// an instance without Talk, where these classes do not exist.
		foreach ([
			'\OCA\Talk\Events\CallStartedEvent',
			'\OCA\Talk\Events\CallEndedEvent',
			'\OCA\Talk\Events\CallEndedForEveryoneEvent',
		] as $event) {
			if (class_exists($event)) {
				$context->registerEventListener($event, CallListener::class);
			}
		}
	}

	public function boot(IBootContext $context): void {
	}
}
