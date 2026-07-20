/**
 * Entry point for the archive UI.
 *
 * Nothing special is needed to locate assets any more: Nextcloud serves this
 * bundle from the app itself, so webpack's default public path is correct. The
 * previous version had to override it, because the bundle travelled through
 * AppAPI's proxy and chunks otherwise resolved against the Nextcloud root and
 * 404'd — in a real installation only, never in development.
 */
import Vue from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import App from './App.vue'

Vue.mixin({ methods: { t, n } })

export default new Vue({
	el: '#done_transcription',
	render: h => h(App),
})
