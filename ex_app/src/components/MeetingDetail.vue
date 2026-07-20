<!--
	One meeting: the summary, and the transcript underneath.

	The summary comes first because that is what people actually came for — the
	transcript is what you open when the summary raised a question. Both are
	shown, neither is hidden behind a click.

	Transcript rendering groups consecutive lines from the same speaker. Raw
	segments arrive every few seconds, and printing a name above each one turns a
	two-minute answer into a wall of repeated labels.
-->
<template>
	<div class="meeting-detail">
		<header class="meeting-detail__header">
			<h2>{{ meeting.room_name || 'Untitled call' }}</h2>
			<p class="meeting-detail__meta">
				{{ formattedDate }} · {{ formattedDuration }} ·
				{{ (meeting.participants || []).join(', ') }}
			</p>
		</header>

		<section v-if="analysisState === 'ready'" class="meeting-detail__section">
			<h3>Summary</h3>
			<div v-for="artifact in artifacts" :key="artifact.name" class="meeting-detail__artifact">
				<h4>{{ prettyName(artifact.name) }}</h4>
				<div class="meeting-detail__markdown" v-text="artifact.content" />
			</div>
		</section>

		<section v-else-if="analysisState === 'running'" class="meeting-detail__section">
			<h3>Summary</h3>
			<p class="meeting-detail__note">
				<span class="icon-loading-small" />
				The summary is being prepared. The transcript below is complete.
			</p>
		</section>

		<section class="meeting-detail__section">
			<h3>Transcript</h3>

			<p v-if="loadingTranscript" class="meeting-detail__note">
				<span class="icon-loading-small" /> Loading…
			</p>

			<p v-else-if="transcriptError" class="meeting-detail__note meeting-detail__note--error">
				{{ transcriptError }}
			</p>

			<p v-else-if="!blocks.length" class="meeting-detail__note">
				Nobody spoke during this call, or the audio could not be captured.
			</p>

			<div v-else class="meeting-detail__transcript">
				<div v-for="(block, index) in blocks" :key="index" class="meeting-detail__block">
					<div class="meeting-detail__speaker">
						{{ block.speaker }}
						<span class="meeting-detail__time">{{ stamp(block.time) }}</span>
					</div>
					<p class="meeting-detail__text">{{ block.text }}</p>
				</div>
			</div>
		</section>
	</div>
</template>

<script>
import { fetchAnalysis, fetchArtifact, fetchTranscript } from '../api.js'

export default {
	name: 'MeetingDetail',

	props: {
		meeting: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			blocks: [],
			artifacts: [],
			loadingTranscript: true,
			transcriptError: '',
		}
	},

	computed: {
		analysisState() {
			return this.meeting.analysis_status || 'none'
		},

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
				? `${minutes} min`
				: `${Math.floor(minutes / 60)} h ${minutes % 60} min`
		},
	},

	watch: {
		// Switching meetings must not leave the previous transcript on screen.
		'meeting.session_id': {
			immediate: true,
			handler() {
				this.blocks = []
				this.artifacts = []
				this.load()
			},
		},
	},

	methods: {
		async load() {
			this.loadingTranscript = true
			this.transcriptError = ''
			const sessionId = this.meeting.session_id

			try {
				const data = await fetchTranscript(sessionId)
				// A late response for a meeting the user already navigated away
				// from would overwrite what they are reading now.
				if (this.meeting.session_id !== sessionId) {
					return
				}
				this.blocks = this.group(data.segments || [])
			} catch (e) {
				if (this.meeting.session_id === sessionId) {
					this.transcriptError = 'Could not load the transcript.'
				}
				console.error('failed to load transcript', e)
			} finally {
				this.loadingTranscript = false
			}

			if (this.analysisState === 'ready') {
				await this.loadAnalysis(sessionId)
			}
		},

		async loadAnalysis(sessionId) {
			try {
				const list = await fetchAnalysis(sessionId)
				const loaded = await Promise.all(
					list.map(async ({ name }) => ({
						name,
						content: (await fetchArtifact(sessionId, name)).content || '',
					})),
				)
				if (this.meeting.session_id === sessionId) {
					this.artifacts = loaded
				}
			} catch (e) {
				console.error('failed to load analysis', e)
			}
		},

		group(segments) {
			// Consecutive lines from one person become one block: the engine
			// emits a segment every few seconds, and a name above each of them
			// makes a long answer unreadable.
			const blocks = []
			for (const segment of segments) {
				const speaker = segment.speaker_name || segment.speaker_id || '—'
				const last = blocks[blocks.length - 1]
				if (last && last.speaker === speaker) {
					last.text += ' ' + segment.text
				} else {
					blocks.push({
						speaker,
						text: segment.text,
						time: segment.time || 0,
					})
				}
			}
			return blocks
		},

		stamp(seconds) {
			// Offset from the start of the call, so it can be matched against a
			// recording or quoted in a message.
			const total = Math.max(0, Math.round(seconds))
			const mm = String(Math.floor(total / 60)).padStart(2, '0')
			const ss = String(total % 60).padStart(2, '0')
			return `${mm}:${ss}`
		},

		prettyName(name) {
			// Artefacts arrive as "01_Executive_Summary.md".
			return name
				.replace(/\.md$/, '')
				.replace(/^\d+[_-]/, '')
				.replace(/[_-]/g, ' ')
		},
	},
}
</script>

<style scoped>
.meeting-detail {
	padding: 16px 24px;
	overflow-y: auto;
}

.meeting-detail__header h2 {
	margin: 0;
}

.meeting-detail__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.meeting-detail__section {
	margin-top: 24px;
}

.meeting-detail__note {
	color: var(--color-text-maxcontrast);
}

.meeting-detail__note--error {
	color: var(--color-error);
}

.meeting-detail__artifact {
	margin-bottom: 16px;
}

.meeting-detail__markdown {
	white-space: pre-wrap;
	line-height: 1.5;
}

.meeting-detail__block {
	margin-bottom: 14px;
}

.meeting-detail__speaker {
	font-weight: 600;
	font-size: 0.9em;
}

.meeting-detail__time {
	margin-inline-start: 8px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.meeting-detail__text {
	margin: 2px 0 0;
	line-height: 1.5;
	white-space: pre-wrap;
}
</style>
