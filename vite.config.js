// Nextcloud's own Vite preset — it sets the output directory, the asset
// handling and the externals (the @nextcloud/* globals the server provides) so
// the app does not have to. The bundle is named {app id}-{entry}, which is what
// PageController hands to Util::addScript.
import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig(
	{
		main: 'src/main.js',
		settings: 'src/settings.js',
	},
	{
		// Styles ride inside the JS bundle, so the page needs only addScript —
		// no separate addStyle, and no CSS file to ship and keep in step.
		inlineCSS: true,
	},
)
