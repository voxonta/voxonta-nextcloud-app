<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Settings;

use OCP\IL10N;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

/**
 * Admin settings.
 *
 * Declarative: Nextcloud renders the form and stores the values in appconfig
 * itself, so there is no controller, no template and no save handler to get
 * wrong. The titles and descriptions go through the app's own translations,
 * which means each administrator reads them in their own language — something
 * the previous external-app version could not do, because it registered the
 * form once for the whole instance.
 *
 * Two values that used to be deploy-time environment variables live here now.
 * They were only environment variables because a container reads those once at
 * startup; there was never a reason an administrator should reinstall the app
 * to change an address.
 */
class AdminSettings implements IDeclarativeSettingsForm {
	public const KEY_BACKEND_URL = 'backend_url';
	public const KEY_BACKEND_TOKEN = 'backend_token';
	public const KEY_ENABLED = 'transcription_enabled';
	public const KEY_ROOM_ALLOWLIST = 'room_allowlist';
	public const KEY_PUBLISH_TO_CHAT = 'publish_to_chat';

	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function getSchema(): array {
		return [
			'id' => 'done_transcription_admin',
			'priority' => 50,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'ai',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => $this->l('Done Transcription'),
			'description' => $this->l('Calls are transcribed per speaker and posted back to the conversation.'),

			'fields' => [
				[
					'id' => self::KEY_BACKEND_URL,
					'title' => $this->l('Transcription service address'),
					'description' => $this->l('Where the service that records and transcribes your calls is reachable, e.g. https://transcribe.example.com'),
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'https://transcribe.example.com',
					'default' => '',
				],
				[
					'id' => self::KEY_BACKEND_TOKEN,
					'title' => $this->l('Service token'),
					'description' => $this->l('Grants access to every meeting in this instance, so it stays on the server and is never sent to the browser.'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'default' => '',
				],
				[
					'id' => self::KEY_ENABLED,
					'title' => $this->l('Transcribe calls'),
					'description' => $this->l('Turn transcription off without uninstalling the app. Calls already running are not interrupted.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => self::KEY_ROOM_ALLOWLIST,
					'title' => $this->l('Limit to these conversations'),
					'description' => $this->l('Comma-separated conversation tokens. Leave empty to transcribe every call. Useful for a pilot: switch it on for one team before rolling it out.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'abc123xy, def456uv',
					'default' => '',
				],
				[
					'id' => self::KEY_PUBLISH_TO_CHAT,
					'title' => $this->l('Post the transcript to the conversation'),
					'description' => $this->l('When a call ends, share the transcript as a file card in the room. Turn off to keep transcripts in the archive only.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
			],
		];
	}

	/**
	 * Who may open the app is deliberately absent from this form: Nextcloud
	 * already restricts an app to selected groups, and a second, weaker control
	 * next to it would be the one people configure by mistake. The external-app
	 * version had to ask, because it could not hide its own menu entry.
	 */
	private function l(string $text): string {
		return $this->l10n->t($text);
	}
}
