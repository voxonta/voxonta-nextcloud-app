<!--
	The list of meetings.

	Two things this screen has to get right. It must be obvious which call is
	which — people recognise a meeting by when it happened and who was in it,
	not by a room name that is "Standup" for the hundredth time. And it must say
	plainly when there is nothing to show and why, because "empty" here has
	three very different causes: the app was just installed, you were in no
	calls, or something is broken.

	NcListItem carries the selection state, keyboard handling and the layout
	Nextcloud users already know from Mail and Talk.
-->
<template>
	<div class="meeting-list">
		<NcLoadingIcon v-if="loading" class="meeting-list__loading" :size="32" />

		<NcEmptyContent
			v-else-if="error"
			:name="t('done_transcription', 'Could not load your meetings')"
			:description="error">
			<template #icon>
				<AlertIcon />
			</template>
			<template #action>
				<NcButton @click="load">
					{{ t('done_transcription', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="!meetings.length"
			:name="t('done_transcription', 'No transcribed meetings yet.')"
			:description="t('done_transcription', 'Calls you take part in appear here once they end and the transcript is ready.')">
			<template #icon>
				<MicrophoneIcon />
			</template>
		</NcEmptyContent>

		<ul v-else>
			<NcListItem
				v-for="meeting in meetings"
				:key="meeting.session_id"
				:name="meeting.room_name || t('done_transcription', 'Untitled call')"
				:active="meeting.session_id === selectedId"
				:details="duration(meeting)"
				@click="$emit('select', meeting)">
				<template #icon>
					<MicrophoneIcon :size="32" />
				</template>
				<template #subname>
					{{ when(meeting) }} · {{ people(meeting) }}
				</template>
			</NcListItem>
		</ul>

		<NcButton
			v-if="hasMore && !loading"
			class="meeting-list__more"
			wide
			@click="loadMore">
			{{ t('done_transcription', 'Show older meetings') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcListItem from '@nextcloud/vue/dist/Components/NcListItem.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import AlertIcon from 'vue-material-design-icons/AlertCircle.vue'
import MicrophoneIcon from 'vue-material-design-icons/Microphone.vue'
import { fetchMeetings } from '../api.js'

const PAGE = 50

export default {
	name: 'MeetingList',

	components: {
		NcButton,
		NcEmptyContent,
		NcListItem,
		NcLoadingIcon,
		AlertIcon,
		MicrophoneIcon,
	},

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
			nextOffset: 0,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		async load() {
			this.loading = true
			this.error = ''
			try {
				const page = await fetchMeetings({ limit: PAGE })
				this.meetings = page.meetings || []
				this.nextOffset = page.nextOffset || 0
				this.hasMore = !!page.hasMore
			} catch (e) {
				// Say what happened rather than showing an empty list — an
				// empty list reads as "you have no meetings", which is a
				// different and misleading statement.
				this.error = t('done_transcription', 'Please try again.')
				console.error('failed to load meetings', e)
			} finally {
				this.loading = false
			}
		},

		async loadMore() {
			try {
				const page = await fetchMeetings({
					limit: PAGE,
					offset: this.nextOffset,
				})
				this.meetings = this.meetings.concat(page.meetings || [])
				this.nextOffset = page.nextOffset || 0
				this.hasMore = !!page.hasMore
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
			// fall back to a count only when the list is long.
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
.meeting-list__loading {
	margin-top: 32px;
}

.meeting-list__more {
	margin: 16px auto;
	width: calc(100% - 32px);
}
</style>
