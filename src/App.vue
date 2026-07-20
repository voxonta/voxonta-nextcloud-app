<!--
	The archive, in Nextcloud's own application frame.

	NcContent / NcAppNavigation / NcAppContent are what every shipped app uses,
	and using them is not only about looking familiar: the responsive behaviour,
	the navigation toggle on narrow screens, focus handling and theming all come
	with them. Hand-rolled panels drift from the platform on every Nextcloud
	release; these follow it.

	"All meetings" appears only for accounts allowed to see it. Offering it to
	everyone would mean a menu entry whose only answer is a refusal.
-->
<template>
	<NcContent app-name="done_transcription">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					:name="t('done_transcription', 'My meetings')"
					:active="scope === 'mine'"
					@click="select('mine')">
					<template #icon>
						<AccountIcon :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					v-if="canSeeEverything"
					:name="t('done_transcription', 'All meetings')"
					:active="scope === 'all'"
					@click="select('all')">
					<template #icon>
						<ArchiveIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent :show-details="!!selected" @update:showDetails="selected = null">
			<template #list>
				<MeetingList
					:key="scope"
					:scope="scope"
					:selected-id="selected ? selected.session_id : ''"
					@select="open"
					@access="canSeeEverything = $event" />
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
import NcAppNavigation from '@nextcloud/vue/dist/Components/NcAppNavigation.js'
import NcAppNavigationItem from '@nextcloud/vue/dist/Components/NcAppNavigationItem.js'
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import ArchiveIcon from 'vue-material-design-icons/Archive.vue'
import MicrophoneIcon from 'vue-material-design-icons/Microphone.vue'
import MeetingDetail from './components/MeetingDetail.vue'
import MeetingList from './components/MeetingList.vue'

export default {
	name: 'App',

	components: {
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcContent,
		NcEmptyContent,
		AccountIcon,
		ArchiveIcon,
		MicrophoneIcon,
		MeetingDetail,
		MeetingList,
	},

	data() {
		return {
			selected: null,
			scope: 'mine',
			// Told by the server on the first listing. Assumed false until then,
			// so the wider view is never offered to someone who cannot use it.
			canSeeEverything: false,
		}
	},

	methods: {
		t,

		select(scope) {
			if (this.scope === scope) {
				return
			}
			this.scope = scope
			// The open meeting may not be present in the view we switch to.
			this.selected = null
		},

		open(meeting) {
			this.selected = meeting
		},
	},
}
</script>
