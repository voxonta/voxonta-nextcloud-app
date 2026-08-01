// Nextcloud's own Vite preset — it sets the output directory, the asset
// handling and the externals (the @nextcloud/* globals the server provides) so
// the app does not have to. The bundle is named {app id}-{entry}, which is what
// PageController hands to Util::addScript.
import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig(
	// One entry, always. A second one makes Vite split the shared CSS into a
	// chunk the page never loads, and the app renders unstyled. The settings
	// page loads this same bundle; main.js wires whichever elements it finds.
	{
		main: 'src/main.js',
	},
	{
		// Styles ride inside the JS bundle, so the page needs only addScript —
		// no separate addStyle, and no CSS file to ship and keep in step.
		inlineCSS: true,
	},
)
