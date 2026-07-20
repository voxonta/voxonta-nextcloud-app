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
	<div class="meeting-list-wrap">
		<div class="meeting-list__filters">
			<NcTextField
				class="meeting-list__search"
				:value.sync="query"
				:label="t('done_transcription', 'Search')"
				trailing-button-icon="close"
				:show-trailing-button="query !== ''"
				@update:value="onFilterChange"
				@trailing-button-click="clearQuery">
				<MagnifyIcon :size="18" />
			</NcTextField>

			<NcSelect
				v-model="period"
				class="meeting-list__period"
				:options="periods"
				:clearable="false"
				label="label"
				:aria-label-combobox="t('done_transcription', 'Period')"
				@input="onFilterChange" />
		</div>

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
			<!--
				Grouped by when the call happened, with the group heading pinned
				to the top as you scroll past it — so it is always clear whether
				the row under the cursor is from today or from last month, without
				reading each date.
			-->
			<template v-for="group in groups">
				<li :key="group.key" class="meeting-list__heading">
					{{ group.label }}
				</li>
				<NcListItem
					v-for="meeting in group.meetings"
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
			</template>
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
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcListItem from '@nextcloud/vue/dist/Components/NcListItem.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import AlertIcon from 'vue-material-design-icons/AlertCircle.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import MicrophoneIcon from 'vue-material-design-icons/Microphone.vue'
import { fetchMeetings } from '../api.js'

const PAGE = 50

// How long to wait after the last keystroke before searching, so a request is
// not fired on every character.
const DEBOUNCE_MS = 300

export default {
	name: 'MeetingList',

	components: {
		NcButton,
		NcEmptyContent,
		NcListItem,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		AlertIcon,
		MagnifyIcon,
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
			// Filters.
			query: '',
			period: null,
			debounce: null,
		}
	},

	created() {
		// Built here rather than as a constant so the labels follow the user's
		// language.
		this.periods = [
			{ id: 'all', label: t('done_transcription', 'Any time') },
			{ id: 'today', label: t('done_transcription', 'Today') },
			{ id: 'week', label: t('done_transcription', 'Last 7 days') },
			{ id: 'month', label: t('done_transcription', 'Last 30 days') },
			{ id: 'year', label: t('done_transcription', 'Last year') },
		]
		this.period = this.periods[0]
	},

	computed: {
		// The loaded meetings split into date buckets, in the order they appear
		// — the list is already newest-first, so the buckets come out in order
		// too. Anything older than last week is grouped by month, which keeps
		// the number of headings bounded however far back you scroll.
		groups() {
			const now = new Date()
			const startOfDay = d => new Date(d.getFullYear(), d.getMonth(), d.getDate())
			const today = startOfDay(now)
			const dayMs = 86400000
			const yesterday = new Date(today - dayMs)
			// Week starts Monday, the working-week convention here.
			const weekStart = new Date(today - ((now.getDay() + 6) % 7) * dayMs)
			const lastWeekStart = new Date(weekStart - 7 * dayMs)

			const bucket = (ts) => {
				const d = new Date(ts * 1000)
				if (d >= today) {
					return { key: 'today', label: this.t('done_transcription', 'Today') }
				}
				if (d >= yesterday) {
					return { key: 'yesterday', label: this.t('done_transcription', 'Yesterday') }
				}
				if (d >= weekStart) {
					return { key: 'this-week', label: this.t('done_transcription', 'This week') }
				}
				if (d >= lastWeekStart) {
					return { key: 'last-week', label: this.t('done_transcription', 'Last week') }
				}
				// The browser localises the month name for us.
				const label = d.toLocaleDateString([], { month: 'long', year: 'numeric' })
				return { key: `${d.getFullYear()}-${d.getMonth()}`, label }
			}

			const groups = []
			let current = null
			for (const meeting of this.meetings) {
				// A call with no start time cannot be placed on the timeline;
				// keep it visible under a heading of its own rather than drop it.
				const b = meeting.call_start_ts
					? bucket(meeting.call_start_ts)
					: { key: 'undated', label: this.t('done_transcription', 'Earlier') }
				if (!current || current.key !== b.key) {
					current = { key: b.key, label: b.label, meetings: [] }
					groups.push(current)
				}
				current.meetings.push(meeting)
			}
			return groups
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		// The active filters as request parameters. A period is turned into a
		// "from" second; "to" is left open so today is always included.
		filterParams() {
			const params = { query: this.query.trim() }
			const days = { today: 1, week: 7, month: 30, year: 365 }[this.period?.id]
			if (days) {
				const start = new Date()
				start.setHours(0, 0, 0, 0)
				start.setDate(start.getDate() - (days - 1))
				params.from = Math.floor(start.getTime() / 1000)
			}
			return params
		},

		// Debounced so typing does not fire a request per keystroke. The list
		// reloads from the top whenever a filter changes.
		onFilterChange() {
			clearTimeout(this.debounce)
			this.debounce = setTimeout(this.load, DEBOUNCE_MS)
		},

		clearQuery() {
			this.query = ''
			this.load()
		},

		async load() {
			this.loading = true
			this.error = ''
			try {
				const page = await fetchMeetings({ limit: PAGE, ...this.filterParams() })
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
					...this.filterParams(),
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
.meeting-list-wrap {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.meeting-list__filters {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}

/* The search takes the room; the period is only as wide as its longest label. */
.meeting-list__search {
	flex: 1 1 auto;
	min-width: 0;
}

.meeting-list__period {
	flex: 0 0 auto;
	width: 120px;
	min-width: 120px;
}

/* NcTextField ships with a top margin meant for stacked forms; here it just
   adds height to the filter bar. */
.meeting-list__search :deep(.input-field) {
	margin-top: 0;
}

.meeting-list {
	/* Its own scroll under the fixed filters: without a bounded height the pane
	   grows with the list and the rows past the first screen have nowhere to
	   scroll to — which is why only the first dozen were reachable. */
	flex: 1;
	overflow-y: auto;
}

.meeting-list__loading {
	margin-top: 32px;
}

.meeting-list__heading {
	position: sticky;
	top: 0;
	z-index: 1;
	padding: 8px 16px 4px;
	background-color: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	font-weight: bold;
	text-transform: uppercase;
}

.meeting-list__more {
	display: flex;
	justify-content: center;
	margin: 16px auto;
	width: calc(100% - 32px);
}
</style>
