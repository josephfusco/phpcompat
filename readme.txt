=== PHP Compatibility Checker ===
Contributors:      wpengine, octalmage, stevenkword, Taylor4484, pross, jcross, rfmeier, cadic, dkotter, ankit-k-gupta, jeffpaul
Tags:              php 7, php 8, php, version, compat, compatibility, checker, wp engine, wpe, wpengine
Requires at least: 5.6
Tested up to:      6.4
Stable tag:        1.6.4
License:           GPLv2 or later
License URI:       http://www.gnu.org/licenses/gpl-2.0.html

Make sure your plugins and themes are compatible with newer PHP versions.

== Description ==

The WP Engine PHP Compatibility Checker can be used by any WordPress website on any web host to check PHP version compatibility.

This plugin will lint theme and plugin code installed on your WordPress site and give you back a report of compatibility issues as reported by [Tide](https://wptide.org) for you to fix. Compatibility issues are categorized into errors and warnings and will list the file and line number of the offending code, as well as the info about why that line of code is incompatible with the chosen version of PHP. The plugin will also suggest updates to themes and plugins, as a new version may offer compatible code.

**This plugin does not execute your theme and plugin code, as such this plugin cannot detect runtime compatibility issues.**

**Please note that linting code is not perfect. This plugin cannot detect unused code-paths that might be used for backwards compatibility, and thus might show false positives.**

**This plugin relies on [Tide](https://wptide.org) that constantly scans updated versions of plugins and themes in the background. Your scan results should be near real-time, but if not that just means Tide has not yet scanned your specific plugin or theme version. Please be patient as this may take up to 10 minutes for results to be returned from Tide. Please see the [FAQ](https://wordpress.org/plugins/php-compatibility-checker/faq/) for more information.**

= What gets checked =

* Results cover PHP versions up to PHP 8.0, which is the newest version Tide currently reports on.
* Only plugins and themes available on WordPress.org can be checked. Premium, custom, or modified plugins and themes are skipped and marked as not scannable.

= Disclaimer =
*While this plugin is written to detect as many problems as accurately as possible, 100% reliable detection is very difficult to ensure. It is best practice to run comprehensive tests before you migrate to a new PHP version.*

The plugin was created by WP Engine to help the WordPress community increase adoption of modern PHP versions. We [welcome contributors](https://github.com/wpengine/phpcompat) to this plugin, and are excited to see how developers and other WordPress hosts use this plugin.

To disclose security issues for this plugin please email WordPress@wpengine.com.

== Installation ==

*Note: Go to 'Plugins' > 'Add New' in the WordPress admin and search for "PHP Compatibility Checker" and install it from there.*

To manually install:
1. Upload the `php-compatibility-checker` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

Go to `Tools => PHP Compatibility`, leave everything selected (or untick what you want to skip), and click `Scan`. Most results come back within seconds. The scan runs in your browser, so keep the page open until it finishes.

== Frequently Asked Questions ==

= 1. Will this work outside of the WP Engine hosting account? =

    Yes, this plugin can be used any ANY WordPress website on ANY host.

= 2. Are there WP-CLI commands available? =

    Yes. Run `wp php-compat scan` to check every plugin and theme (add `--active` for active ones only, or `--format=json`). It exits with status 1 when problems are found, so it works in scripts and CI. `wp php-compat report <slug>` shows the details, and `wp php-compat php-status` shows the server's PHP status. Run `wp help php-compat` for all options.

= 3. A plugin I created is listed as not compatible, what should I do? =

    Results come from [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility), a static code checker, as run by [Tide](https://wptide.org). It can flag code that never runs on newer PHP, such as backwards compatibility fallbacks. If you think a result is wrong, please open a [GitHub issue](https://github.com/wpengine/phpcompat/issues) with the plugin, version, and the reported line.

= 4. Can I use this to test non-WordPress PHP Projects? =

    Yes! While you cannot use this WordPress plugin to test your non-WordPress projects, you can use the [open source PHPCompatibility library](https://github.com/PHPCompatibility/PHPCompatibility) that this plugin is built on.

= 5. Why was my plugin/theme skipped? =

    If your plugin or theme is not available on WordPress.org, then [Tide](https://wptide.org) will not be able to scan or return results of that plugin or theme.
    
    If your plugin or theme is available on WordPress.org, but Tide is not immediately returning results than it likely means Tide has not yet audited that plugin or theme and within a few minutes results should be available once Tide completes its audit.

= 6. The scan is stuck, what can I do? =

    As of version 1.6.0 of this plugin, there should no longer be issues of the scan getting stuck as it no longer runs on your WordPress host server.  If you are seeing significantly slow or unresponsive results from a plugin or theme that is available on WordPress.org, then please [open an issue](https://github.com/wptide/wptide.org/issues/new/choose) with those details for the Tide team to investigate why that specific plugin or theme version is not appearing in the Tide results.

= 7. I found a bug, or have a suggestion, can I contribute back? =

    Yes! Please open an issue or pull request on the [plugin's GitHub repo](https://github.com/wpengine/phpcompat). Contributions are welcome.

    The plugin was created by WP Engine to help the WordPress community increase adoption of modern PHP versions. We welcome contributors to this plugin, and are excited to see how developers and other WordPress hosts use this plugin.

    To disclose security issues for this plugin please email WordPress@wpengine.com.

= 8. Does it check whether my server's PHP is secure? =

    It checks whether the server runs the latest release of a PHP branch that still gets security fixes, using php.net's release data, and shows a notice on the scan page if not. It can't confirm builds from your host or Linux distribution, which often include security fixes under an older-looking version number, so for those it points you to your host's security notices. It can't patch PHP for you; ask your host to update.

= 9. What external services does this plugin use? =

    * [Tide](https://wptide.org) (wptide.org): scan results. The slug and version of each plugin and theme you scan are sent to Tide's API, from your browser on the scan page, or from your server for WP-CLI and Abilities API scans. See the [Tide project](https://github.com/wptide) for its terms and privacy information.
    * [php.net](https://www.php.net): the list of PHP branches and their latest releases (releases/states.php and releases/active.php), requested by your server at most twice a day. Nothing about your site is sent beyond the request itself. See the [php.net privacy policy](https://www.php.net/privacy.php).

== Screenshots ==

1. Main screen: compatibility checker options
2. Compatibility results screen

== Changelog ==

= Unreleased =
- Check whether the server's PHP is the latest release of a supported branch, using php.net's release data. The scan page shows a notice when a newer (security) release is available, when the branch no longer gets security fixes, or when the server runs a host or distribution build to verify. Also in `wp php-compat php-status` and the `php-status` ability.
- WP-CLI commands: `wp php-compat scan` (exits 1 when problems are found), `wp php-compat report <slug>`, and `wp php-compat php-status`.
- Abilities API (WordPress 6.9+): `php-compatibility-checker/scan` and `php-compatibility-checker/php-status`, so AI agents and other clients can check PHP compatibility over REST or MCP. Both require an administrator.
- Tide results are cached for server-side scans (a week for completed audits).
- Simpler scan page: every plugin and theme is listed with a checkbox and selected by default, with Select all, Select none, and Active only shortcuts. This replaces the three scan options. Scans now include inactive plugins and themes unless you untick them. After a scan, "Start over" begins a new one.
- Fix the `phpcompat_plugins_to_scan` filter not running when no plugins are left to scan (for example, when this is the only plugin installed).
- Refresh the scan page for WordPress 7.2: core SVG icons (with Dashicons fallback on older versions), WordPress design tokens, a status label on each result, PHP versions as chips, a results summary, core buttons and notices, and a layout that stacks on narrow screens.
- Accessibility: failing PHP versions are buttons with expanded/collapsed state, results don't rely on color alone, and closing a report returns focus to its version.
- Respect each plugin's and theme's "Requires PHP": PHP versions below it are shown in gray as not counted instead of as failures. Items whose minimum is newer than any PHP version with results are marked "not checked" instead of passing.
- Fix PHP 8.2 "Creation of dynamic property" deprecation notice.
- Fix "Undefined array key" warnings when updating plugins from sources other than WordPress.org.
- Fix translations not loading: use the WordPress.org text domain (php-compatibility-checker) and stop calling load_plugin_textdomain() too early (WordPress 6.7 notice).
- Load JavaScript translations and declare the wp-i18n and jquery script dependencies.
- Show a clear "could not be scanned" message, with its own style, when a plugin or theme is not on WordPress.org.
- Clarify on the scan page where results come from and which PHP versions they cover.
- WP Engine sidebar (shown only on WP Engine-hosted sites): replace the outdated PHP 7 upgrade pitch with how to test a new PHP version safely using PHP Test Driver or a staging environment, and show the environment's current PHP version.
- Only show the "Need help fixing these issues?" panel when a scan finds problems.
- Remove the "Submit to Codeable" form; the Codeable endpoint no longer accepts reports.
- Fix PHP version ordering in scan results.
- Correct the readme: results cover PHP up to 8.0. Remove outdated install, FAQ, and whitelist information.

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
- Changed from running PHP Compatibility scans on your WordPress server to using scan data from [Tide](https://wptide.org).
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

= 1.6.0 =
- WordPress minimum increased from 4.8 to 5.6.
- PHP Compatibility scans now run via [Tide](https://wptide.org) and no longer run on your host server!
- The WP-CLI `phpcompat` command has been removed as this plugin no longer runs on your host server and relies upon Tide.

= 1.4.8 =
- Update dependencies.
