// Nextcloud's shared Vue config, with the entry pointed at our app.
const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

// The bundle is named {package name}-{entry} by the Nextcloud config, and that
// filename is what PageController hands to Util::addScript. The package is
// therefore named after the app id: a mismatch means the script tag points at a
// file that does not exist, and the page renders empty with no error worth the
// name. A test guards the pairing.
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
}
// js/ is where Nextcloud looks for an app's scripts. No publicPath override:
// the bundle is served from the app itself now, so webpack's default is right.
webpackConfig.output.path = path.join(__dirname, 'js')

module.exports = webpackConfig
