/**
 * Entry point for the admin settings block.
 *
 * Separate from the archive bundle: the settings page has no business loading
 * the whole application, and the archive has no business loading this.
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import ServiceStatus from './components/ServiceStatus.vue'

const app = createApp(ServiceStatus)

app.config.globalProperties.t = t
app.config.globalProperties.n = n

app.mount('#done_transcription_status')
