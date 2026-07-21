<!--
	Whether the transcription service has picked up these settings.

	The settings below this block only take effect when something reads them, and
	the only thing that does is a service running outside Nextcloud. Until the
	same secret is in both places, an administrator filling in the form sees no
	change in behaviour and no explanation. This says which of the three states
	they are in: no key yet, key set but nothing has called, or connected.
-->
<template>
	<div class="service-status">
		<h2>{{ t('done_transcription', 'Transcription service') }}</h2>

		<p class="service-status__line">
			<span class="service-status__dot" :class="dotClass" />
			<span>{{ headline }}</span>
		</p>

		<p v-if="detail" class="service-status__detail">{{ detail }}</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'ServiceStatus',

	data() {
		return {
			status: loadState('done_transcription', 'serviceStatus', {
				configured: false,
				connected: false,
				last_seen: 0,
				version: '',
				note: '',
			}),
		}
	},

	computed: {
		dotClass() {
			if (!this.status.configured) {
				return 'service-status__dot--idle'
			}
			return this.status.connected
				? 'service-status__dot--ok'
				: 'service-status__dot--warn'
		},

		headline() {
			if (!this.status.configured) {
				return t('done_transcription', 'No access key set — the service cannot read these settings.')
			}
			if (this.status.connected) {
				return t('done_transcription', 'Connected.')
			}
			return this.status.last_seen
				? t('done_transcription', 'The service has stopped reporting in.')
				: t('done_transcription', 'The service has never reported in. Check that it holds the same key.')
		},

		detail() {
			if (!this.status.last_seen) {
				return ''
			}
			const when = new Date(this.status.last_seen * 1000).toLocaleString()
			const parts = [t('done_transcription', 'Last report: {when}', { when })]
			if (this.status.version) {
				parts.push(t('done_transcription', 'version {version}', { version: this.status.version }))
			}
			if (this.status.note) {
				parts.push(this.status.note)
			}
			return parts.join(' · ')
		},
	},

	methods: { t },
}
</script>

<style scoped>
.service-status__line {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* A dot rather than an icon: three states, and the colour carries them without
   asking anyone to learn a symbol. The words say the same thing beside it, so
   colour is never the only cue. */
.service-status__dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex: 0 0 auto;
}

.service-status__dot--ok {
	background: var(--color-success);
}

.service-status__dot--warn {
	background: var(--color-warning);
}

.service-status__dot--idle {
	background: var(--color-text-maxcontrast);
}

.service-status__detail {
	color: var(--color-text-maxcontrast);
	margin-block-start: 4px;
}
</style>
