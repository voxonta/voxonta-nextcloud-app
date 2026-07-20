/**
 * Entry point for the archive UI.
 *
 * Nextcloud serves this bundle through the AppAPI proxy, so webpack has to be
 * told where the assets really live — otherwise it resolves chunks against the
 * Nextcloud root and they 404 at runtime, only in a real installation and never
 * in local development.
 */
import Vue from 'vue'
import App from './App.vue'

// eslint-disable-next-line camelcase
__webpack_public_path__ = '/apps/app_api/proxy/done_transcription/js/'

Vue.mixin({ methods: { t, n } })

export default new Vue({
	el: '#done_transcription',
	render: h => h(App),
})
