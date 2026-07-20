<!--
	The archive, in Nextcloud's own application frame.

	NcContent / NcAppNavigation / NcAppContent are what every shipped app uses,
	and using them is not only about looking familiar: the responsive behaviour,
	the navigation toggle on narrow screens, focus handling and theming all come
	with them. Hand-rolled panels drift from the platform on every Nextcloud
	release; these follow it.

	There is no "my meetings" versus "everything" switch, because there is nothing
	to switch between: the list is the meetings shared with this person. Someone
	who should see more is given more files, in Nextcloud, by the people who own
	them.
-->
<template>
	<NcContent app-name="done_transcription">
		<NcAppContent :show-details="!!selected" @update:showDetails="selected = null">
			<template #list>
				<MeetingList
					:selected-id="selected ? selected.session_id : ''"
					@select="open" />
			</template>

			<MeetingDetail v-if="selected" :meeting="selected" />

			<NcEmptyContent
				v-else
				:name="t('done_transcription', 'No meeting selected')"
				:description="t('done_transcription', 'Select a meeting to read its transcript.')">
				<template #icon>
					<MicrophoneIcon />
				</template>
			</NcEmptyContent>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import MicrophoneIcon from 'vue-material-design-icons/Microphone.vue'
import MeetingDetail from './components/MeetingDetail.vue'
import MeetingList from './components/MeetingList.vue'

export default {
	name: 'App',

	components: {
		NcAppContent,
		NcContent,
		NcEmptyContent,
		MicrophoneIcon,
		MeetingDetail,
		MeetingList,
	},

	data() {
		return { selected: null }
	},

	methods: {
		t,

		open(meeting) {
			this.selected = meeting
		},
	},
}
</script>

<!--
	Not scoped: the mount point and Nextcloud's content wrapper are outside this
	component. Vue mounts into a div that sits inside the standard content area,
	and NcContent then builds its own full-height frame inside that — so the
	mount point has to be told to fill its parent, or a strip of desktop shows
	above the app.
-->
<style>
#done_transcription {
	width: 100%;
	height: 100%;
}
</style>
