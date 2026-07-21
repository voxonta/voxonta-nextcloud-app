<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCA\DoneTranscription\AppInfo\Application;
use OCA\DoneTranscription\Settings\AdminSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * The settings an administrator keeps here, and what the service reports back.
 *
 * The transcription service runs outside Nextcloud and used to be configured by
 * editing a .env file on the host — which meant every change needed a person
 * with SSH, and the administrator who owned the instance could not see, let
 * alone alter, how their own calls were being handled. Now Nextcloud holds the
 * settings and the service reads them from here.
 *
 * Authentication is a shared secret both sides hold: the administrator types it
 * into the settings form and into the service's own configuration. It is a
 * deliberate step below Nextcloud's user accounts — the service is not a person
 * and should not act as one — and it grants exactly two things: reading these
 * settings, and reporting back. Blank means no access at all, which is the
 * state an instance starts in.
 */
class ServiceConfig {
	/** Written by the service, not by the form: when it last reported in. */
	public const KEY_LAST_SEEN = 'service_last_seen';
	public const KEY_LAST_VERSION = 'service_version';
	public const KEY_LAST_NOTE = 'service_note';

	/**
	 * How long a report keeps the service counting as connected. Three missed
	 * heartbeats at the service's usual minute, so a single slow poll or a
	 * restart does not show up as an outage.
	 */
	public const STALE_AFTER = 300;

	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Whether this secret is the configured one.
	 *
	 * hash_equals rather than ===: the comparison time of === depends on how
	 * many characters matched, which is enough to recover a secret one
	 * character at a time.
	 */
	public function authenticates(string $presented): bool {
		$expected = $this->appConfig->getValueString(
			Application::APP_ID, AdminSettings::KEY_SERVICE_TOKEN, '');
		if ($expected === '' || $presented === '') {
			return false;
		}
		return hash_equals($expected, $presented);
	}

	/**
	 * What the service needs to know, as it is set right now.
	 *
	 * The token itself is never in here: the service already has it, and a
	 * response that echoes a secret ends up in logs.
	 *
	 * @return array<string, mixed>
	 */
	public function forService(): array {
		return [
			'enabled' => $this->appConfig->getValueBool(
				Application::APP_ID, AdminSettings::KEY_ENABLED, true),
			'publish_to_chat' => $this->appConfig->getValueBool(
				Application::APP_ID, AdminSettings::KEY_PUBLISH_TO_CHAT, true),
			// A list, not the raw string: every caller would otherwise split it
			// themselves, and one of them would split it differently.
			'rooms' => $this->rooms(),
			'retention_days' => max(0, (int)$this->appConfig->getValueString(
				Application::APP_ID, AdminSettings::KEY_RETENTION_DAYS, '0')),
			'folders' => [
				'analysis' => $this->folder(AdminSettings::KEY_ANALYSIS_FOLDER,
					AdminSettings::DEFAULT_ANALYSIS_FOLDER),
				'transcripts' => $this->folder(AdminSettings::KEY_TRANSCRIPTS_FOLDER,
					AdminSettings::DEFAULT_TRANSCRIPTS_FOLDER),
				'minutes' => $this->folder(AdminSettings::KEY_MINUTES_FOLDER,
					AdminSettings::DEFAULT_MINUTES_FOLDER),
			],
		];
	}

	/**
	 * Record that the service is alive, and what it says about itself.
	 */
	public function reportIn(string $version, string $note): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_LAST_SEEN,
			$this->time->getTime());
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_LAST_VERSION,
			mb_substr($version, 0, 64));
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_LAST_NOTE,
			mb_substr($note, 0, 200));
	}

	/**
	 * What to show an administrator about the connection.
	 *
	 * @return array{configured: bool, connected: bool, last_seen: int,
	 *               version: string, note: string}
	 */
	public function status(): array {
		$lastSeen = $this->appConfig->getValueInt(
			Application::APP_ID, self::KEY_LAST_SEEN, 0);

		return [
			'configured' => $this->appConfig->getValueString(
				Application::APP_ID, AdminSettings::KEY_SERVICE_TOKEN, '') !== '',
			'connected' => $lastSeen > 0
				&& ($this->time->getTime() - $lastSeen) < self::STALE_AFTER,
			'last_seen' => $lastSeen,
			'version' => $this->appConfig->getValueString(
				Application::APP_ID, self::KEY_LAST_VERSION, ''),
			'note' => $this->appConfig->getValueString(
				Application::APP_ID, self::KEY_LAST_NOTE, ''),
		];
	}

	/** @return string[] conversation tokens, empty meaning "every call" */
	private function rooms(): array {
		$raw = $this->appConfig->getValueString(
			Application::APP_ID, AdminSettings::KEY_ROOM_ALLOWLIST, '');
		$tokens = array_map('trim', explode(',', $raw));
		return array_values(array_filter($tokens, static fn ($t) => $t !== ''));
	}

	private function folder(string $key, string $default): string {
		$name = trim($this->appConfig->getValueString(Application::APP_ID, $key, $default));
		return $name === '' ? $default : $name;
	}
}
