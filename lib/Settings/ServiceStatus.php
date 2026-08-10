<?php

declare(strict_types=1);

namespace OCA\Voxonta\Settings;

use OCA\Voxonta\AppInfo\Application;
use OCA\Voxonta\Service\BotAccount;
use OCA\Voxonta\Service\ServiceConfig;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\Settings\ISettings;

/**
 * Whether the transcription service has actually collected these settings.
 *
 * Sits above the form deliberately. The settings only matter if something reads
 * them, and until the secret is in both places nothing does — an administrator
 * who fills the form and sees calls unchanged has no way to tell whether the
 * service disagreed or never asked. This says which.
 *
 * Read-only, so it is a plain template rather than a second form: nothing here
 * is saved, and there is no button to press that would help.
 */
class ServiceStatus implements ISettings {
	public function __construct(
		private ServiceConfig $config,
		private BotAccount $botAccount,
		private IL10N $l10n,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'settings-status', [
			'status' => $this->config->status(),
			'bot' => $this->botAccount->status(),
			'l10n' => $this->l10n,
		]);
	}

	public function getSection(): string {
		return 'ai';
	}

	/**
	 * Above the settings form (which sits at 50): the state of the connection
	 * is what an administrator needs first — the fields below it are moot while
	 * nothing is reading them.
	 */
	public function getPriority(): int {
		return 40;
	}
}
