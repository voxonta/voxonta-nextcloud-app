// Nextcloud's shared Vue config, with the entry pointed at our app.
const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

webpackConfig.entry = {
	main: path.join(__dirname, 'ex_app', 'src', 'main.js'),
}
// Bundles land where the Dockerfile copies them from.
webpackConfig.output.path = path.join(__dirname, 'ex_app', 'js')
webpackConfig.output.publicPath = '/apps/app_api/proxy/done_transcription/js/'

module.exports = webpackConfig
