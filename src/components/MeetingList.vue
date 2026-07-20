<!--
	The list of your meetings.

	Two things this screen has to get right. It must be obvious which call is
	which — people recognise a meeting by when it happened and who was in it, not
	by a room name that is often "Standup" for the hundredth time. And it must say
	plainly when there is nothing to show and why, because "empty" here has three
	very different causes: the app was just installed, you were not in any calls,
	or something is broken.
-->
<template>
	<div class="meeting-list">
		<div v-if="loading" class="meeting-list__state">
			<span class="icon-loading-small" /> {{ t('done_transcription', 'Loading meetings…') }}
		</div>

		<div v-else-if="error" class="meeting-list__state meeting-list__state--error">
			<p>{{ error }}</p>
			<button @click="load">{{ t('done_transcription', 'Try again') }}</button>
		</div>

		<div v-else-if="!meetings.length" class="meeting-list__state">
			<p>{{ t('done_transcription', 'No transcribed meetings yet.') }}</p>
			<p class="meeting-list__hint">
				{{ t('done_transcription', 'Calls you take part in appear here once they end and the transcript is ready.') }}
			</p>
		</div>

		<ul v-else class="meeting-list__items">
			<li
				v-for="meeting in meetings"
				:key="meeting.session_id"
				class="meeting-list__item"
				:class="{ 'meeting-list__item--selected': meeting.session_id === selectedId }"
				tabindex="0"
				@click="$emit('select', meeting)"
				@keyup.enter="$emit('select', meeting)">
				<div class="meeting-list__row">
					<span class="meeting-list__name">{{ meeting.room_name || t('done_transcription', 'Untitled call') }}</span>
					<span class="meeting-list__duration">{{ duration(meeting) }}</span>
				</div>
				<div class="meeting-list__row meeting-list__row--secondary">
					<span>{{ when(meeting) }}</span>
					<span>{{ people(meeting) }}</span>
				</div>
				<span
					v-if="meeting.analysis_status === 'ready'"
					class="meeting-list__badge">{{ t('done_transcription', 'Summary') }}</span>
				<span
					v-else-if="meeting.analysis_status === 'running'"
					class="meeting-list__badge meeting-list__badge--muted">{{ t('done_transcription', 'Analysing') }}…</span>
			</li>
		</ul>

		<button
			v-if="hasMore && !loading"
			class="meeting-list__more"
			@click="loadMore">
			{{ t('done_transcription', 'Show older meetings') }}
		</button>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { fetchMeetings } from '../api.js'

const PAGE = 50

export default {
	name: 'MeetingList',

	props: {
		selectedId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			meetings: [],
			loading: true,
			error: '',
			hasMore: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		async load() {
			this.loading = true
			this.error = ''
			try {
				const meetings = await fetchMeetings({ limit: PAGE })
				this.meetings = meetings
				this.hasMore = meetings.length === PAGE
			} catch (e) {
				// Say what happened rather than showing an empty list — an
				// empty list reads as "you have no meetings", which is a
				// different and misleading statement.
				this.error = t('done_transcription', 'Could not load your meetings. The transcription service may be unavailable.')
				console.error('failed to load meetings', e)
			} finally {
				this.loading = false
			}
		},

		async loadMore() {
			try {
				const older = await fetchMeetings({
					limit: PAGE,
					offset: this.meetings.length,
				})
				this.meetings = this.meetings.concat(older)
				this.hasMore = older.length === PAGE
			} catch (e) {
				console.error('failed to load older meetings', e)
			}
		},

		when(meeting) {
			if (!meeting.call_start_ts) {
				return ''
			}
			const started = new Date(meeting.call_start_ts * 1000)
			const today = new Date()
			const sameDay = started.toDateString() === today.toDateString()
			const time = started.toLocaleTimeString([], {
				hour: '2-digit',
				minute: '2-digit',
			})
			// "Today, 14:05" is easier to place than a full date for the calls
			// people look for most often — the recent ones.
			return sameDay
				? `${t('done_transcription', 'Today')}, ${time}`
				: `${started.toLocaleDateString()}, ${time}`
		},

		duration(meeting) {
			const { call_start_ts: start, call_end_ts: end } = meeting
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

		people(meeting) {
			const names = meeting.participants || []
			if (!names.length) {
				return ''
			}
			// Recognising a call is mostly "who was in it", so show names, and
			// only fall back to a count when the list is long.
			return names.length <= 3
				? names.join(', ')
				: t('done_transcription', '{names} and {count} others', {
					names: names.slice(0, 2).join(', '),
					count: names.length - 2,
				})
		},
	},
}
</script>

<style scoped>
.meeting-list__state {
	padding: 24px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.meeting-list__state--error {
	color: var(--color-error);
}

.meeting-list__hint {
	font-size: 0.9em;
	margin-top: 8px;
}

.meeting-list__items {
	list-style: none;
	margin: 0;
	padding: 0;
}

.meeting-list__item {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.meeting-list__item:hover,
.meeting-list__item:focus {
	background-color: var(--color-background-hover);
}

.meeting-list__item--selected {
	background-color: var(--color-primary-light);
}

.meeting-list__row {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}

.meeting-list__row--secondary {
	margin-top: 4px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.meeting-list__name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.meeting-list__badge {
	display: inline-block;
	margin-top: 6px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 0.75em;
}

.meeting-list__badge--muted {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.meeting-list__more {
	display: block;
	width: calc(100% - 32px);
	margin: 16px;
}
</style>
