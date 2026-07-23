/**
 * Entry point for the archive UI.
 *
 * Vue 3: createApp mounts into the template's div rather than replacing it, so
 * the mount point stays in the DOM — unlike Vue 2's `el`, which replaced it.
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import App from './App.vue'
import { wireBotAccount } from './settings-ui.js'

// One bundle for both pages. The archive mounts where its element is; the
// settings page has no such element, only the bot-account block, and wiring
// that here keeps everything in a single entry — two entries split the app's
// stylesheet into a chunk the page never loaded, and the archive rendered
// unstyled.
const mount = document.getElementById('done_transcription')
if (mount) {
	const app = createApp(App)
	// t/n as global properties, so every component can call them in its template
	// the way Nextcloud components expect.
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(mount)
}

wireBotAccount()
