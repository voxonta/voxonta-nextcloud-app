<!--
	Two panes: your meetings on the left, the selected one on the right.

	On a phone there is only room for one, so the list gives way to the meeting
	and a back link returns to it — the same shape Nextcloud's own apps use, so
	it needs no explanation.
-->
<template>
	<div class="archive" :class="{ 'archive--detail-open': selected }">
		<aside class="archive__list">
			<h1 class="archive__title">{{ t('Meetings') }}</h1>
			<MeetingList :selected-id="selected ? selected.session_id : ''" @select="open" />
		</aside>

		<main class="archive__detail">
			<button v-if="selected" class="archive__back" @click="selected = null">
				← {{ t('Meetings') }}
			</button>

			<MeetingDetail v-if="selected" :meeting="selected" />

			<p v-else class="archive__placeholder">
				{{ t('Select a meeting to read its transcript.') }}
			</p>
		</main>
	</div>
</template>

<script>
import { translate } from './l10n.js'
import MeetingDetail from './components/MeetingDetail.vue'
import MeetingList from './components/MeetingList.vue'

export default {
	name: 'App',
	components: { MeetingDetail, MeetingList },

	data() {
		return { selected: null }
	},

	methods: {
		t: translate,

		open(meeting) {
			this.selected = meeting
		},
	},
}
</script>

<style scoped>
.archive {
	display: flex;
	height: 100%;
	width: 100%;
}

.archive__list {
	width: 340px;
	flex-shrink: 0;
	border-inline-end: 1px solid var(--color-border);
	overflow-y: auto;
}

.archive__title {
	padding: 16px;
	margin: 0;
	font-size: 1.2em;
}

.archive__detail {
	flex: 1;
	overflow-y: auto;
}

.archive__placeholder {
	padding: 32px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.archive__back {
	display: none;
	margin: 12px 0 0 12px;
}

/* One pane at a time on narrow screens. */
@media (max-width: 768px) {
	.archive__list {
		width: 100%;
	}

	.archive__detail {
		display: none;
	}

	.archive--detail-open .archive__list {
		display: none;
	}

	.archive--detail-open .archive__detail {
		display: block;
	}

	.archive__back {
		display: inline-block;
	}
}
</style>
