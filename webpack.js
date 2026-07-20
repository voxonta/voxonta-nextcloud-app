// Nextcloud's shared Vue config, with the entry pointed at our app.
const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

// The bundle is named {package name}-{entry} by the Nextcloud config, and that
// filename is what enabled_handler registers. The package is therefore named
// after the app id: a mismatch here means the script tag points at a file that
// does not exist, and the UI silently never loads.
webpackConfig.entry = {
	main: path.join(__dirname, 'ex_app', 'src', 'main.js'),
}
// Bundles land where the Dockerfile copies them from.
webpackConfig.output.path = path.join(__dirname, 'ex_app', 'js')
webpackConfig.output.publicPath = '/apps/app_api/proxy/done_transcription/js/'

module.exports = webpackConfig
