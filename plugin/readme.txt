=== PHP Compatibility Checker ===
Contributors:      wpengine, octalmage, stevenkword, Taylor4484, pross, jcross, rfmeier, cadic, dkotter, ankit-k-gupta, jeffpaul
Tags:              php 8, php, compatibility, phpcompatibility, upgrade
Requires at least: 6.4
Requires PHP:      7.4
Tested up to:      7.2
Stable tag:        2.0.0
License:           GPLv2 or later
License URI:       http://www.gnu.org/licenses/gpl-2.0.html

Make sure your plugins and themes are compatible with newer PHP versions.

== Description ==

The WP Engine PHP Compatibility Checker can be used by any WordPress website on any web host to check PHP version compatibility.

It checks your plugins and themes with [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility), the PHP community's standard compatibility checker for [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer), using its WordPress ruleset, [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP). Each problem lists the file and line of the code and why it breaks on that version of PHP. Problems are categorized into errors (the code breaks) and warnings (deprecated, but still runs). The plugin also suggests updates to themes and plugins, as a new version may offer compatible code.

**Built for shared hosting.** The scan runs in your browser. Your site sends the plugin and theme files a few at a time, and your browser checks them with PHP compiled to WebAssembly, so the scan barely touches your host's CPU. No code is sent to any other service. Results are kept, and a plugin or theme is only checked again when its files change.

**This plugin does not execute your theme and plugin code, as such this plugin cannot detect runtime compatibility issues.**

**Please note that linting code is not perfect. This plugin cannot detect unused code-paths that might be used for backwards compatibility, and thus might show false positives.**

= What gets checked =

* Every plugin and theme you choose: from WordPress.org, premium, custom, or modified.
* PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, and 8.5, each on its own. PHP versions below a plugin's or theme's own "Requires PHP" are not counted.
* Whether a plugin or theme needs a newer PHP than its "Requires PHP" says, from the scan and from Composer's own platform check.
* Your server's PHP: whether it's the latest release of a branch that still gets security fixes, and whether it's affected by a critical vulnerability such as PHP2Shell (CVE-2026-109618, CVE-2026-109619).

Reports download as PDF (tagged for screen readers), text, CSV, or JSON. WP-CLI commands and the Abilities API give the same results to scripts, hosts, and AI agents.

= Disclaimer =
*While this plugin is written to detect as many problems as accurately as possible, 100% reliable detection is very difficult to ensure. It is best practice to run comprehensive tests before you migrate to a new PHP version.*

The plugin was created by WP Engine to help the WordPress community increase adoption of modern PHP versions. We [welcome contributors](https://github.com/wpengine/phpcompat) to this plugin, and are excited to see how developers and other WordPress hosts use this plugin.

To disclose security issues for this plugin please email WordPress@wpengine.com.

== Installation ==

*Note: Go to 'Plugins' > 'Add New' in the WordPress admin and search for "PHP Compatibility Checker" and install it from there.*

To manually install:
1. Upload the `php-compatibility-checker` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

Go to `Tools => PHP Compatibility`, choose what to scan, and click `Scan site`. Most plugins take a few seconds each; later scans only check what changed. The scan page drives the scan, so keep it open until it finishes.

== Frequently Asked Questions ==

= 1. Will this work outside of the WP Engine hosting account? =

    Yes, this plugin can be used any ANY WordPress website on ANY host.

= 2. Are there WP-CLI commands available? =

    Yes. Run `wp php-compat scan` to check every plugin and theme (add `--active` for active ones only, or `--format=json`). WP-CLI scans the installed files directly on your server, with the same scanner the scan page uses. It exits with status 1 when problems are found, so it works in scripts and CI. `wp php-compat report <slug>` shows the details, and `wp php-compat php-status` shows the server's PHP status. Run `wp help php-compat` for all options.

= 3. A plugin I created is listed as not compatible, what should I do? =

    Results come from [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility), a static code checker. It can flag code that never runs on newer PHP, such as backwards compatibility fallbacks. Every report lists the exact PHPCompatibility version used. If you think a result is wrong, check it against PHPCompatibility's [issue tracker](https://github.com/PHPCompatibility/PHPCompatibility/issues), or open a [GitHub issue](https://github.com/wpengine/phpcompat/issues) here with the plugin, version, and the reported line.

= 4. Can I use this to test non-WordPress PHP Projects? =

    Yes! While you cannot use this WordPress plugin to test your non-WordPress projects, you can use the [open source PHPCompatibility library](https://github.com/PHPCompatibility/PHPCompatibility) that this plugin is built on.

= 5. Can it check premium and custom plugins and themes? =

    Yes. Every plugin and theme is checked as installed on your site, whether it's from WordPress.org, premium, custom, or modified. Your code isn't uploaded anywhere.

= 6. Will scanning slow down my site? =

    Barely. The scan page sends one small request at a time and sizes each to take a few seconds, waits and retries if your site is busy, and skips (and lists) any file that runs into a memory or time limit. A first scan of a large site takes a few minutes; later scans only check what changed. You can also run `wp php-compat scan` from WP-CLI.

= 7. Can a host turn scanning off? =

    Yes. Add `define( 'WPEPHPCOMPAT_DISABLE_SCANNING', true );` to wp-config.php, or return false from the `wpephpcompat_scanning_enabled` filter (in a must-use plugin to cover a whole platform). The scan page then shows a notice instead of scanning, and the scan endpoint refuses requests. Stored results, reports, Site Health, and `wp php-compat scan` keep working.

= 8. I found a bug, or have a suggestion, can I contribute back? =

    Yes! Please open an issue or pull request on the [plugin's GitHub repo](https://github.com/wpengine/phpcompat). Contributions are welcome.

    The plugin was created by WP Engine to help the WordPress community increase adoption of modern PHP versions. We welcome contributors to this plugin, and are excited to see how developers and other WordPress hosts use this plugin.

    To disclose security issues for this plugin please email WordPress@wpengine.com.

= 9. Does it check whether my server's PHP is secure? =

    It checks whether the server runs the latest release of a PHP branch that still gets security fixes, using php.net's release data, and whether the version is affected by a critical vulnerability such as PHP2Shell. The scan page shows a notice if not. It can't confirm builds from your host or Linux distribution, which often include security fixes under an older-looking version number, so for those it points you to your host's security notices. It can't patch PHP for you; ask your host to update.

= 10. What external services does this plugin use? =

    * [php.net](https://www.php.net): the list of PHP branches and their latest releases (releases/states.php and releases/active.php), requested by your server at most twice a day. Nothing about your site is sent beyond the request itself. See the [php.net privacy policy](https://www.php.net/privacy.php).

== Screenshots ==

1. The scan page: results for each plugin and theme by PHP version, with the problems for a failing version open.
2. The PDF report: a summary with next steps, then every plugin and theme at a glance.
3. Site Health: active plugins and themes with problems on newer PHP, including one that needs a newer PHP than it says.

== Changelog ==

= 2.0.0 =
- Updates come from WordPress.org again.
- New scanner: PHPCompatibility 10 with PHPCompatibilityWP 3, built into the plugin, for PHP 7.4 through 8.5. No outside service. Premium, custom, and modified plugins and themes are now checked too. Scans run in your browser (PHP compiled to WebAssembly), on files your site sends in small batches, with retries, and skip (and list) any file that crashes the scan. Results are stored and reused until a plugin's or theme's files change.
- Site Health: a test for your active plugins and theme (from stored results; it never scans), and a PHP Compatibility section on the Info tab, so results go into "Copy site info".
- Flag plugins and themes whose code, or whose Composer dependencies, need a newer PHP than their "Requires PHP" header says.
- Requires PHP 7.4 and WordPress 6.4.
- Hosts can turn scanning off with the `WPEPHPCOMPAT_DISABLE_SCANNING` constant or the `wpephpcompat_scanning_enabled` filter. Stored results, Site Health, and WP-CLI keep working.
- Only one scan runs per site at a time; a second admin or tab sees who is scanning instead of starting another.
- Skip `node_modules` and version control folders, whose PHP WordPress never loads. WP-CLI and the scan page check the same files.
- Flag critical PHP vulnerabilities on the server, starting with PHP2Shell (CVE-2026-109618, CVE-2026-109619): the scan page says the server is vulnerable and which release fixes it. CVE-2026-115560's workaround is shown when the server's session settings are exposed.
- Keep the familiar scan page from 1.6.4, with fixes: a third scan option to choose which plugins and themes to scan (#67), a Cancel link while scanning (#56), progress for each plugin and theme while it's scanned (#42), and clear errors when the scanner can't load (offline, blocked, or timed out) instead of "Status: 0" (#231), and a Scan again button once a scan finishes.
- Each result shows its error count, and notes when every problem is in a bundled library (vendor, third-party), which is a common source of false positives.
- Show "Update available" on results when WordPress knows of a newer version, as the scan page has always promised to suggest updates.
- Reports: Download Report now defaults to PDF, with Text, CSV, and JSON as options. The PDF downloads in one click and is tagged for screen readers (headings, tables with header cells, lists, document language and title, bookmarks). It opens with a summary and recommended next steps, states the scan scope, gives a recommended action for each plugin or theme with problems, groups repeated problems by message, and summarizes problems in bundled libraries per library. The text report now starts with the site, date, server PHP, WordPress version, and a summary.
- Replace the LocalWP suggestion with a link that opens the plugins and themes with problems in WordPress Playground on the latest PHP.
- Use core's wp.template() and wp.a11y.speak() instead of bundled Mustache and compare-versions.
- Tested with WordPress 7.2 (beta 3).
- Check whether the server's PHP is the latest release of a supported branch, using php.net's release data. The scan page shows a notice when a newer (security) release is available, when the branch no longer gets security fixes, or when the server runs a host or distribution build to verify. Also in `wp php-compat php-status` and the `php-status` ability.
- WP-CLI commands: `wp php-compat scan` (scans the installed files on the server; exits 1 when problems are found; `--cached` for stored results), `wp php-compat report <slug>`, and `wp php-compat php-status`.
- Abilities API (WordPress 6.9+): `php-compatibility-checker/scan` and `php-compatibility-checker/php-status`, so AI agents and other clients can check PHP compatibility over REST or MCP. Both require an administrator.
- Fix the `phpcompat_plugins_to_scan` filter not running when no plugins are left to scan (for example, when this is the only plugin installed).
- Accessibility: failing PHP versions are buttons with expanded/collapsed state, results don't rely on color alone, and closing a report returns focus to its version.
- Respect each plugin's and theme's "Requires PHP": PHP versions below it are marked with a dash as not counted instead of as failures. Items whose minimum is newer than any PHP version with results are marked "not checked" instead of passing.
- Fix PHP 8.2 "Creation of dynamic property" deprecation notice.
- Fix "Undefined array key" warnings when updating plugins from sources other than WordPress.org.
- Fix translations not loading: use the WordPress.org text domain (php-compatibility-checker) and stop calling load_plugin_textdomain() too early (WordPress 6.7 notice).
- Load JavaScript translations and declare the wp-i18n and jquery script dependencies.
- Clarify on the scan page where results come from and which PHP versions they cover.
- WP Engine sidebar (shown only on WP Engine-hosted sites): replace the outdated PHP 7 upgrade pitch with how to test a new PHP version safely using PHP Test Driver or a staging environment, and show the environment's current PHP version.
- Only show the "Need help fixing these issues?" panel when a scan finds problems.
- Remove the "Submit to Codeable" form; the Codeable endpoint no longer accepts reports.
- Fix PHP version ordering in scan results.
- Remove outdated install, FAQ, and whitelist information from the readme.

= 1.6.4 =
- Updates are now served from WP Engine servers.
- Update dependencies.

= 1.6.3 =
- Fix issue where Buffer was not available to the scans.

= 1.6.2 =
- Update packages.

= 1.6.1 =
- Fix issue on update where old files were included.

= 1.6.0 =
- Changed from running PHP Compatibility scans on your WordPress server to using scan data from an external service.
- Removed `phpcompat` WP-CLI command.
- Update dependencies.

= 1.5.2 =
- Removed PHP 5.2 checks
- Fixed PHP 8 issue where plugin cannot cannot be uninstalled.

= 1.5.1 =
- Added Smart Plugin Manager to whitelisted plugins.

= 1.5.0 =
- Added support for PHP 7.3 compatibility checks.

= 1.4.8 =
- Update dependencies.

= 1.4.7 =
- Better translation support.

= 1.4.6 =
- Switched to new PHPCompatibilityWP library to help prevent false positives.

= 1.4.5 =
- Use plugin version number to enqueue scripts and styles.

= 1.4.4 =
- PHP 5.2 Support & PHP 7.1 and 7.2 Lints.
- Updated call to action sidebar depending on platform.

= 1.4.3 =
- Fixed Composer issue.

= 1.4.1 =
- Updated PHP_CodeSniffer to fix a security advisory.
- Whitelisted a number of plugins.

= 1.4.0 =
- Updated UX for viewing PHP errors to be more intuitive and require less scrolling.
- Added links for non-technical users who need assistance from developers to fix PHP errors or to test their site in PHP 7 enabled hosting environments.

= 1.3.2 =
- Added a "Clean up" button and uninstall.php.
- Added phpcompat_phpversions filter.

= 1.3.1 =
- Whitelisted a number of plugins.

= 1.3.0 =
- Updated the PHPCompatibility library to latest version. Should fix many false positives.
- Changed language and added help text to Admin UI.

= 1.2.4 =
- Fixed Composer issue.

= 1.2.3 =
- Updated the PHPCompatibility library to latest version.
- Whitelisted TablePress.

= 1.2.2 =
- Whitelisted UpdraftPlus and Max Mega Menu.

= 1.2.1 =
- Updated the PHPCompatibility library to latest version.

= 1.2.0 =
- Updated the PHPCompatibility library to latest version.
- Added support for PHP 5.6

= 1.1.2 =
- Fixed issue with WordPress notices breaking the plugin header.
- Changed the way we send and parse JSON.
- You can now restart an in progress scan.
- Updated download.js to v4.2 for better Safari compatibility.

= 1.1.1 =
- Fixed bug with active job display.
- Updated progress bar calculation.

= 1.1.0 =
- Test results now persist page reloads.
- Failed tests will show an overview of the results.
- The scan timeout is now configurable using a filter. See the FAQ for more details.

= 1.0.3 =
- Fixed a bug in the WP-CLI command.
- Added a handful of PHP 7 compatible plugins to the whitelist.

= 1.0.2 =
- Added additional role protections.
- Changed the UI colors to better understand output at a glance.
- Exclude checking node_modules and tmp directories.
- Added support for child theme's parent theme.

= 1.0.1 =
- Updated compatibility library with a few bugfixes.
- Added skip logic to prevent checker from hanging.

= 1.0.0 =
- Major update to add PHP 7 checking support.
- Improved the UX of the progress bar.
- Fixed bug with the way the plugin menu was registered.

= 0.1.0 =
- Initial version.
- PHP 5.5, 5.4, and 5.3 Support.
- Basic WP-CLI Commands.

== Upgrade Notice ==

= 2.0.0 =
- Built-in scanner, no external service: PHP 7.4 to 8.5, including premium and custom code.
- Scans run in your browser; your site only reads the files.
- Requires PHP 7.4 and WordPress 6.4. Scan again after updating.

= 1.6.0 =
- WordPress minimum increased from 4.8 to 5.6.
- PHP Compatibility scans now use an external service and no longer run on your host server!
- The WP-CLI `phpcompat` command has been removed as this plugin no longer runs on your host server.

= 1.4.8 =
- Update dependencies.
