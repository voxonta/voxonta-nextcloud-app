<?php

declare(strict_types=1);

/**
 * Whether the transcription service has actually collected these settings.
 *
 * Plain PHP, no bundle: this is three lines of static text, and giving it its
 * own JS entry point made Vite split the shared CSS into a chunk the page never
 * loaded — which left the whole app unstyled.
 *
 * @var array{configured: bool, connected: bool, last_seen: int,
 *            version: string, note: string} $_['status']
 */

$status = $_['status'];
$bot = $_['bot'];
$l = $_['l10n'];

\OCP\Util::addScript(\OCA\DoneTranscription\AppInfo\Application::APP_ID,
	\OCA\DoneTranscription\AppInfo\Application::APP_ID . '-settings');

$state = !$status['configured'] ? 'idle' : ($status['connected'] ? 'ok' : 'warn');

if (!$status['configured']) {
	$headline = $l->t('No access key set — the service cannot read these settings.');
} elseif ($status['connected']) {
	$headline = $l->t('Connected.');
} elseif ($status['last_seen'] > 0) {
	$headline = $l->t('The service has stopped reporting in.');
} else {
	$headline = $l->t('The service has never reported in. Check that it holds the same key.');
}

$detail = '';
if ($status['last_seen'] > 0) {
	$parts = [$l->t('Last report: {when}',
		['when' => date('d.m.Y H:i', $status['last_seen'])])];
	if ($status['version'] !== '') {
		$parts[] = $l->t('version {version}', ['version' => $status['version']]);
	}
	if ($status['note'] !== '') {
		$parts[] = $status['note'];
	}
	$detail = implode(' · ', $parts);
}
?>
<div class="section">
	<h2><?php p($l->t('Transcription service')); ?></h2>

	<p class="done-transcription-status">
		<span class="done-transcription-status__dot done-transcription-status__dot--<?php p($state); ?>"></span>
		<?php p($headline); ?>
	</p>

	<?php if ($detail !== '') { ?>
		<p class="done-transcription-status__detail"><?php p($detail); ?></p>
	<?php } ?>

	<div id="done_transcription_bot" class="done-transcription-bot">
		<h3><?php p($l->t('Capture account')); ?></h3>
		<p class="done-transcription-status__detail">
			<?php p($l->t('The account the transcription service signs in to Nextcloud as, to join calls and write transcripts back. The service fetches its password itself — you do not enter it there.')); ?>
		</p>

		<?php if ($bot['user'] !== '') { ?>
			<p>
				<?php p($l->t('In use: {user}', ['user' => $bot['user']])); ?>
				<?php if (!$bot['managed']) { p('· ' . $l->t('created by you')); } ?>
				<?php if ($bot['managed'] && !$bot['exists']) { p('· ' . $l->t('the account is gone — recreate it')); } ?>
			</p>
		<?php } ?>

		<p class="done-transcription-bot__actions">
			<button type="button" data-action="provision" class="primary">
				<?php p($bot['user'] === '' || $bot['managed']
					? $l->t('Create the account')
					: $l->t('Switch to an app-managed account')); ?>
			</button>
			<?php if ($bot['managed'] && $bot['exists']) { ?>
				<button type="button" data-action="regenerate">
					<?php p($l->t('New password')); ?>
				</button>
			<?php } ?>
		</p>

		<details class="done-transcription-bot__manual">
			<summary><?php p($l->t('Use an account I made myself')); ?></summary>
			<p class="done-transcription-status__detail">
				<?php p($l->t('Create a user in Nextcloud, generate an app password for it in its security settings, and enter both here.')); ?>
			</p>
			<p>
				<input type="text" data-field="user"
					placeholder="<?php p($l->t('Account name')); ?>">
				<input type="password" data-field="password"
					placeholder="<?php p($l->t('App password')); ?>">
				<button type="button" data-action="existing"><?php p($l->t('Save')); ?></button>
			</p>
		</details>

		<div data-role="result" class="done-transcription-bot__result"></div>
	</div>
</div>

<style>
	/* A dot rather than an icon: three states, and the colour carries them
	   without asking anyone to learn a symbol. The words say the same thing
	   beside it, so colour is never the only cue. */
	.done-transcription-status {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.done-transcription-status__dot {
		width: 10px;
		height: 10px;
		border-radius: 50%;
		flex: 0 0 auto;
	}

	.done-transcription-status__dot--ok { background: var(--color-success); }
	.done-transcription-status__dot--warn { background: var(--color-warning); }
	.done-transcription-status__dot--idle { background: var(--color-text-maxcontrast); }

	.done-transcription-status__detail {
		color: var(--color-text-maxcontrast);
		margin-block-start: 4px;
	}

	.done-transcription-bot {
		margin-block-start: 20px;
	}

	.done-transcription-bot__actions {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
	}

	.done-transcription-bot__manual {
		margin-block-start: 12px;
	}

	.done-transcription-bot__manual input {
		margin-inline-end: 8px;
	}

	.done-transcription-bot__result {
		margin-block-start: 12px;
	}

	.done-transcription-bot__password {
		display: inline-block;
		margin-block-start: 4px;
		padding: 4px 8px;
		background: var(--color-background-dark);
		border-radius: var(--border-radius);
		user-select: all;
		word-break: break-all;
	}
</style>
