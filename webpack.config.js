const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const path = require("path");
const webpack = require("webpack");
const CopyWebpackPlugin = require("copy-webpack-plugin");
const wasmVersion = require("@php-wasm/web-8-3/package.json").version;

// Extend the @wordpress/scripts config without webpack-merge: merging appends
// arrays, which ran every default plugin twice and produced an asset file with
// an empty dependency list (so wp-i18n/jquery were never enqueued).
module.exports = {
  ...defaultConfig,
  output: {
    ...defaultConfig.output,
    path: path.resolve(process.cwd(), "plugin/build"),
  },
  entry: {
    scan: path.resolve(process.cwd(), "src/js", "scan.js"),
    // Browser engine (experimental): PHP in WebAssembly, in a Web Worker.
    "wasm-worker": path.resolve(process.cwd(), "src/js", "wasm-worker.js"),
  },
  plugins: [
    ...defaultConfig.plugins,
    new CopyWebpackPlugin({
      patterns: [
        {
          from: "{asyncify,jspi}/**/*.{js,wasm}",
          context: path.dirname(require.resolve("@php-wasm/web-8-3/package.json")),
          to: `php-wasm/${wasmVersion}/[path][name][ext]`,
          info: { minimized: true },
        },
        {
          from: "{LICENSE,README.md,package.json}",
          context: path.dirname(require.resolve("@php-wasm/web-8-3/package.json")),
          to: `php-wasm/${wasmVersion}/[name][ext]`,
        },
      ],
    }),
    // @php-wasm/universal has a Node-only path (worker_threads) the browser
    // never takes; leave it out instead of emitting an empty chunk for it.
    new webpack.IgnorePlugin({ resourceRegExp: /^worker_threads$/ }),
  ],
  performance: {
    ...defaultConfig.performance,
    // The PDF chunk is large but only loads when someone downloads a PDF; PHP
    // for WebAssembly only loads when a scan starts, and is cached after.
    assetFilter: (file) =>
      !/^pdf\.js/.test(file) && !/^php-wasm\//.test(file) && !/\.map$/.test(file),
  },
};
