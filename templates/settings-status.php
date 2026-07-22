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
$l = $_['l10n'];

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
</style>
