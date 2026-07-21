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
	public const KEY_ENABLED = 'transcription_enabled';
	public const KEY_ROOM_ALLOWLIST = 'room_allowlist';
	public const KEY_PUBLISH_TO_CHAT = 'publish_to_chat';
	public const KEY_SERVICE_TOKEN = 'service_token';
	public const KEY_ANALYSIS_FOLDER = 'analysis_folder';
	public const KEY_TRANSCRIPTS_FOLDER = 'transcripts_folder';
	public const KEY_MINUTES_FOLDER = 'minutes_folder';

	/** What the folders are called unless an administrator says otherwise. */
	public const DEFAULT_ANALYSIS_FOLDER = 'Аналитика встреч';
	public const DEFAULT_TRANSCRIPTS_FOLDER = 'Транскрипции';
	public const DEFAULT_MINUTES_FOLDER = 'Протоколы';

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
				[
					'id' => self::KEY_SERVICE_TOKEN,
					'title' => $this->l('Access key'),
					'description' => $this->l('Shared secret the transcription service presents to read these settings. Put the same value in the service configuration. Leave empty to refuse it access.'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'default' => '',
				],
				[
					'id' => self::KEY_ANALYSIS_FOLDER,
					'title' => $this->l('Analysis folder'),
					'description' => $this->l('Folder under Talk/ where the service writes one folder per analysed call. The archive is read from here.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => self::DEFAULT_ANALYSIS_FOLDER,
					'default' => self::DEFAULT_ANALYSIS_FOLDER,
				],
				[
					'id' => self::KEY_TRANSCRIPTS_FOLDER,
					'title' => $this->l('Transcripts folder'),
					'description' => $this->l('Folder under Talk/ holding the loose transcripts written before the analyser existed.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => self::DEFAULT_TRANSCRIPTS_FOLDER,
					'default' => self::DEFAULT_TRANSCRIPTS_FOLDER,
				],
				[
					'id' => self::KEY_MINUTES_FOLDER,
					'title' => $this->l('Minutes folder'),
					'description' => $this->l('Folder under Talk/ holding the older "Протокол" minutes that pair with those transcripts.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => self::DEFAULT_MINUTES_FOLDER,
					'default' => self::DEFAULT_MINUTES_FOLDER,
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
