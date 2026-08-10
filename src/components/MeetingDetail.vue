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
			<h2>{{ meeting.room_name || t('voxonta', 'Untitled call') }}</h2>
			<p class="meeting-detail__meta">
				{{ formattedDate }}
				<template v-if="formattedDuration"> · {{ formattedDuration }}</template>
				<template v-if="participants"> · {{ participants }}</template>
			</p>

			<!--
				Taking the meeting away with you: the summary is what people
				forward, the transcript what they keep. Permissions are not here
				— these are ordinary Nextcloud files, managed in Files.
			-->
			<div class="meeting-detail__actions">
				<NcActions :inline="2">
					<NcActionButton :disabled="!summary" @click="download('summary')">
						<template #icon>
							<DownloadIcon :size="20" />
						</template>
						{{ t('voxonta', 'Download summary') }}
					</NcActionButton>
					<NcActionButton
						v-if="meeting.has_transcript !== false"
						@click="download('transcript')">
						<template #icon>
							<DownloadIcon :size="20" />
						</template>
						{{ t('voxonta', 'Download transcript') }}
					</NcActionButton>
				</NcActions>
			</div>
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
					{{ t('voxonta', 'Show transcript') }}
				</NcButton>

				<template v-else>
					<h3 v-if="summary">{{ t('voxonta', 'Transcript') }}</h3>

					<NcLoadingIcon v-if="loadingTranscript" :size="24" />

					<NcEmptyContent
						v-else-if="transcriptError"
						:name="t('voxonta', 'Could not load the transcript.')">
						<template #icon>
							<AlertIcon />
						</template>
					</NcEmptyContent>

					<NcEmptyContent
						v-else-if="!transcript"
						:name="t('voxonta', 'Nobody spoke during this call, or the audio could not be captured.')">
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
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcRichText from '@nextcloud/vue/components/NcRichText'
import AlertIcon from 'vue-material-design-icons/AlertCircle.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import MicrophoneOffIcon from 'vue-material-design-icons/MicrophoneOff.vue'
import TextIcon from 'vue-material-design-icons/TextBoxOutline.vue'
import { fetchSummary, fetchTranscript } from '../api.js'

export default {
	name: 'MeetingDetail',

	components: {
		NcActionButton,
		NcActions,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcRichText,
		AlertIcon,
		DownloadIcon,
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
				? t('voxonta', '{count} min', { count: minutes })
				: t('voxonta', '{hours} h {minutes} min', {
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

		// Nextcloud's own toast, reached through the global rather than
		// @nextcloud/dialogs: the app does not otherwise need that dependency,
		// and a failure to warn must not itself throw.
		warn(message) {
			if (window.OCP?.Toast?.error) {
				window.OCP.Toast.error(message)
			} else {
				console.warn(message)
			}
		},

		/**
		 * Save what is already on screen as a .md file.
		 *
		 * From memory rather than a second request: the text is here, and this
		 * way the download works on exactly what was read.
		 *
		 * @param {string} what 'summary' or 'transcript'
		 */
		async download(what) {
			let text = what === 'summary' ? this.summary : this.transcript
			// The transcript is only fetched when opened, so a download from a
			// meeting still showing its summary has to fetch it first.
			if (what === 'transcript' && !text) {
				try {
					text = await fetchTranscript(this.meeting.session_id)
				} catch (e) {
					console.error('failed to load transcript', e)
					this.warn(t('voxonta', 'Could not load the transcript.'))
					return
				}
			}
			if (!text) {
				return
			}

			const title = this.meeting.room_name || t('voxonta', 'Untitled call')
			const label = what === 'summary'
				? t('voxonta', 'summary')
				: t('voxonta', 'transcript')
			// Slashes and colons are not filename characters on every system.
			const name = `${title} — ${label}.md`.replace(/[\\/:*?"<>|]/g, '-')

			const url = URL.createObjectURL(new Blob([text], { type: 'text/markdown' }))
			const link = document.createElement('a')
			link.href = url
			link.download = name
			link.click()
			URL.revokeObjectURL(url)
		},

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
	position: relative;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
	margin-bottom: 20px;
	/* Room for the actions pinned to the top right, so a long meeting name
	   wraps before it reaches them. */
	padding-inline-end: 100px;
}

.meeting-detail__header h2 {
	margin: 0 0 4px;
}

.meeting-detail__actions {
	position: absolute;
	inset-inline-end: 0;
	top: 0;
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
