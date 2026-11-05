const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const path = require("path");

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
  resolve: {
    ...defaultConfig.resolve,
    // @php-wasm/universal has a Node-only path (worker_threads) the browser never takes.
    fallback: { ...(defaultConfig.resolve?.fallback || {}), worker_threads: false },
  },
  performance: {
    ...defaultConfig.performance,
    // The PDF chunk is large but only loads when someone downloads a PDF.
    assetFilter: (file) => !/^pdf\.js/.test(file) && !/\.map$/.test(file),
  },
};
