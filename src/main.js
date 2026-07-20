/**
 * Entry point for the archive UI.
 *
 * Vue 3: createApp mounts into the template's div rather than replacing it, so
 * the mount point stays in the DOM — unlike Vue 2's `el`, which replaced it.
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import App from './App.vue'

const app = createApp(App)

// t/n as global properties, so every component can call them in its template
// the way Nextcloud components expect.
app.config.globalProperties.t = t
app.config.globalProperties.n = n

app.mount('#done_transcription')
