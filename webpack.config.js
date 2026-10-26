const path = require('path')

// Only the Files "Process" action is bundled (registerFileAction is not exposed
// to plain JS); the rest of the app is plain JS without a build. Build deps live
// in the sibling app's node_modules (same versions as files_compress etc.).
const DEPS = path.join(__dirname, '..', 'user_group_admin', 'node_modules')

module.exports = (env, argv) => {
	const isDev = argv.mode === 'development'
	return {
		mode: isDev ? 'development' : 'production',
		devtool: false,
		entry: { 'files-action': path.join(__dirname, 'src', 'files-action.js') },
		output: {
			path: path.join(__dirname, 'js'),
			filename: '[name].js',
			clean: false,
		},
		resolve: { extensions: ['.js', '.mjs'], modules: [DEPS, 'node_modules'], fallback: { stream: false } },
		resolveLoader: { modules: [DEPS, 'node_modules'] },
		optimization: { splitChunks: false },
		module: {
			rules: [
				{
					test: /\.m?js$/,
					exclude: /node_modules/,
					use: { loader: 'babel-loader', options: { presets: [require.resolve('@babel/preset-env', { paths: [DEPS] })] } },
				},
			],
		},
	}
}
