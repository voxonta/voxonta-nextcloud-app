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
	<div ref="scroll" class="meeting-list" @scroll.passive="onScroll">
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

		<!--
			More loads as the list is scrolled, not behind a button — the button
			sat below fifty rows where it was never seen. The spinner is the only
			cue the reader needs that older calls are on the way; a button that
			stays visible is kept as the fallback for when the list is too short
			to scroll at all.
		-->
		<div v-if="loadingMore" class="meeting-list__more">
			<NcLoadingIcon :size="28" />
		</div>

		<NcButton
			v-else-if="hasMore && !loading && !scrollable"
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
			loadingMore: false,
			error: '',
			hasMore: false,
			nextOffset: 0,
			// Whether the list is tall enough to scroll. When it is not — a
			// short list, or a wide window — there is no scroll to trigger the
			// next page, so the button is shown instead.
			scrollable: false,
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
				this.$nextTick(this.updateScrollable)
			}
		},

		async loadMore() {
			// One page at a time: the scroll handler fires on every scroll
			// event, and without this guard a fast scroll would ask for the
			// same next page several times over.
			if (this.loadingMore || !this.hasMore) {
				return
			}
			this.loadingMore = true
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
			} finally {
				this.loadingMore = false
				this.$nextTick(this.updateScrollable)
			}
		},

		onScroll() {
			const el = this.$refs.scroll
			if (!el) {
				return
			}
			// Fetch before the very bottom, so the next rows are usually there
			// by the time the reader reaches them.
			const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 400
			if (nearBottom) {
				this.loadMore()
			}
		},

		updateScrollable() {
			const el = this.$refs.scroll
			this.scrollable = !!el && el.scrollHeight > el.clientHeight
			// A list that does not fill the pane cannot be scrolled to load
			// more, so pull the next page until it does or the archive ends.
			if (this.hasMore && !this.scrollable && !this.loadingMore) {
				this.loadMore()
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
.meeting-list {
	/* Its own scroll: without a bounded height the pane grows with the list and
	   the rows past the first screen have nowhere to scroll to — which is why
	   only the first dozen were reachable. */
	height: 100%;
	overflow-y: auto;
}

.meeting-list__loading {
	margin-top: 32px;
}

.meeting-list__more {
	display: flex;
	justify-content: center;
	margin: 16px auto;
	width: calc(100% - 32px);
}
</style>
