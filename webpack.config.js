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
  },
  performance: {
    ...defaultConfig.performance,
    // The PDF chunk is large but only loads when someone downloads a PDF.
    assetFilter: (file) => !/^pdf\.js/.test(file) && !/\.map$/.test(file),
  },
};
