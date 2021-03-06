const COMPONENT_NAME = 'hexr';
const OUTPUT_NAME = 'global';
const {sass, prefix, uglify, write} = require('./css.js');

async function buildHexrStyles() {
	let pipeline = '';

	pipeline = await sass(COMPONENT_NAME);
	pipeline = await prefix(pipeline);
	pipeline = await uglify(pipeline);

	return write(pipeline, OUTPUT_NAME);
}

module.exports = buildHexrStyles;

if (!module.parent) {
	buildHexrStyles().then(() => {
		if (process.argv.join(' ').indexOf('copy') >= 0) {
			require('fs').copyFileSync('./src/assets/css/global.css', './built/assets/css/global.css');
		}
	});
}
