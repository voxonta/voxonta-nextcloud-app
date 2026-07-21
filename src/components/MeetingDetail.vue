<!--
	One meeting: the summary, with the transcript a click away.

	The order reflects how these are used. People open a meeting to find out
	what was decided, and the summary answers that; the verbatim transcript is
	where they go when the summary left a question — checking a number, finding
	who committed to what, seeing the exact wording. Showing both at once buries
	the answer under the evidence, and loads the larger file on every visit for
	the minority of cases that want it.

	So the transcript is fetched only when asked for, and stays open once it is:
	somebody reading it will scroll back and forth.
-->
<template>
	<div class="meeting-detail">
		<header class="meeting-detail__header">
			<h2>{{ meeting.room_name || t('done_transcription', 'Untitled call') }}</h2>
			<p class="meeting-detail__meta">
				{{ formattedDate }}
				<template v-if="formattedDuration"> · {{ formattedDuration }}</template>
				<template v-if="participants"> · {{ participants }}</template>
			</p>
		</header>

		<NcLoadingIcon v-if="loadingSummary" :size="24" class="meeting-detail__loading" />

		<template v-else>
			<section v-if="summary" class="meeting-detail__section">
				<NcRichText :text="summary" :use-extended-markdown="true" />
			</section>

			<!--
				Calls from before the service shared the transcript are their
				summary and nothing else: no transcript to offer, and no empty
				"nobody spoke" to explain away.
			-->
			<section
				v-if="meeting.has_transcript !== false"
				class="meeting-detail__section">
				<!--
					When there are minutes, the transcript is the evidence behind
					them and waits behind a click. When there are none — the
					analyser has not run — there is nothing to summarise the call
					with, so the transcript is the content and shows at once.
				-->
				<NcButton
					v-if="summary && !transcriptShown"
					@click="showTranscript">
					<template #icon>
						<TextIcon :size="20" />
					</template>
					{{ t('done_transcription', 'Show transcript') }}
				</NcButton>

				<template v-else>
					<h3 v-if="summary">{{ t('done_transcription', 'Transcript') }}</h3>

					<NcLoadingIcon v-if="loadingTranscript" :size="24" />

					<NcEmptyContent
						v-else-if="transcriptError"
						:name="t('done_transcription', 'Could not load the transcript.')">
						<template #icon>
							<AlertIcon />
						</template>
					</NcEmptyContent>

					<NcEmptyContent
						v-else-if="!transcript"
						:name="t('done_transcription', 'Nobody spoke during this call, or the audio could not be captured.')">
						<template #icon>
							<MicrophoneOffIcon />
						</template>
					</NcEmptyContent>

					<NcRichText v-else :text="transcript" :use-extended-markdown="true" />
				</template>
			</section>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcRichText from '@nextcloud/vue/components/NcRichText'
import AlertIcon from 'vue-material-design-icons/AlertCircle.vue'
import MicrophoneOffIcon from 'vue-material-design-icons/MicrophoneOff.vue'
import TextIcon from 'vue-material-design-icons/TextBoxOutline.vue'
import { fetchSummary, fetchTranscript } from '../api.js'

export default {
	name: 'MeetingDetail',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcRichText,
		AlertIcon,
		MicrophoneOffIcon,
		TextIcon,
	},

	props: {
		meeting: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			summary: '',
			transcript: '',
			loadingSummary: true,
			loadingTranscript: false,
			transcriptShown: false,
			transcriptError: false,
		}
	},

	computed: {
		formattedDate() {
			if (!this.meeting.call_start_ts) {
				return ''
			}
			return new Date(this.meeting.call_start_ts * 1000).toLocaleString()
		},

		formattedDuration() {
			const { call_start_ts: start, call_end_ts: end } = this.meeting
			if (!start || !end || end <= start) {
				return ''
			}
			const minutes = Math.round((end - start) / 60)
			return minutes < 60
				? t('done_transcription', '{count} min', { count: minutes })
				: t('done_transcription', '{hours} h {minutes} min', {
					hours: Math.floor(minutes / 60),
					minutes: minutes % 60,
				})
		},

		participants() {
			return (this.meeting.participants || []).join(', ')
		},
	},

	watch: {
		// Switching meetings must not leave the previous one on screen, and must
		// not carry over the previous transcript — including the fact that it
		// was open.
		'meeting.session_id': {
			immediate: true,
			handler() {
				this.summary = ''
				this.transcript = ''
				this.transcriptShown = false
				this.transcriptError = false
				this.loadSummary()
			},
		},
	},

	methods: {
		t,

		async loadSummary() {
			this.loadingSummary = true
			const sessionId = this.meeting.session_id

			try {
				const text = await fetchSummary(sessionId)
				// A late answer for a meeting the user already navigated away
				// from would overwrite what they are reading now.
				if (this.meeting.session_id !== sessionId) {
					return
				}
				this.summary = text
			} catch (e) {
				console.error('failed to load summary', e)
			} finally {
				if (this.meeting.session_id === sessionId) {
					this.loadingSummary = false
				}
			}

			// No minutes: the transcript is the content, so load it now rather
			// than behind a click nobody would know to press.
			if (this.meeting.session_id === sessionId && !this.summary) {
				this.showTranscript()
			}
		},

		async showTranscript() {
			this.transcriptShown = true
			this.loadingTranscript = true
			this.transcriptError = false
			const sessionId = this.meeting.session_id

			try {
				const text = await fetchTranscript(sessionId)
				if (this.meeting.session_id === sessionId) {
					this.transcript = text
				}
			} catch (e) {
				if (this.meeting.session_id === sessionId) {
					this.transcriptError = true
				}
				console.error('failed to load transcript', e)
			} finally {
				this.loadingTranscript = false
			}
		},
	},
}
</script>

<style scoped>
.meeting-detail {
	padding: 24px 32px;
	max-width: 800px;
}

.meeting-detail__header {
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
	margin-bottom: 20px;
}

.meeting-detail__header h2 {
	margin: 0 0 4px;
}

.meeting-detail__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.meeting-detail__section {
	margin-bottom: 24px;
}

.meeting-detail__section h3 {
	margin: 0 0 12px;
}
</style>
