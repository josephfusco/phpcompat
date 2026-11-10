# Reference

WP-CLI commands, the Abilities API, server PHP release status, and filters.

## WP-CLI

```bash
wp php-compat scan                      # Every plugin and theme, scanned now
wp php-compat scan --active             # Active items only
wp php-compat scan akismet --format=json
wp php-compat scan --cached             # Stored results only, no scanning
wp php-compat report wp-optimize --php=8.4
wp php-compat php-status
wp php-compat readiness --format=json   # Which newer PHP the active code is ready for (see readiness.md)
```

WP-CLI has no browser and no time limit, so `scan` runs the same `runner.php` natively and in-process on the installed files (no `exec()` needed) and stores the results. `scan` exits with status 1 when any item has problems, so it can gate deploys or run across many sites.

## Abilities API (AI agents)

On WordPress 6.9+ the plugin registers two [abilities](https://developer.wordpress.org/apis/abilities/) in the `php-compatibility` category. Agents and other clients can discover and run them over REST (`/wp-abilities/v1/`) or through an MCP adapter:

| Ability | What it returns |
| --- | --- |
| `php-compatibility-checker/scan` | Stored results per plugin and theme (`passes`, `problems`, `not_checked`, `not_scanned`, `failed`), results per PHP version, `scanned_at`, and optional problem details. Input: `slugs`, `type`, `active_only`, `include_details`. |
| `php-compatibility-checker/php-status` | The server's PHP version, whether WordPress considers it secure and at its recommended version, and `release`: whether it's the latest release of a supported branch (from php.net) and any critical advisories that affect it. `release.state` is `vulnerable`, `up_to_date`, `update_available`, `unsupported_branch`, `vendor_build`, or `unknown`. |

Both are read-only and require an administrator (`manage_options`). Scanning takes longer than a REST request allows, so the scan ability returns stored results: `not_scanned` means nobody has scanned that version yet. The output includes `max_php_checked`, the newest PHP version checked, so an agent doesn't treat a pass as readiness for versions that weren't checked.

## Server PHP release status

Static compatibility results don't tell you whether the server's PHP itself is patched. WordPress's own PHP check works at the branch level (it treats any 8.3 release as secure). The plugin also compares the exact version with php.net's release data ([`releases/states.php`](https://www.php.net/releases/states.php) and [`releases/active.php`](https://www.php.net/releases/active.php), cached for 12 hours), and with a short list of critical advisories that php.net's data can't express:

- **vulnerable:** affected by a critical advisory, such as [PHP2Shell](https://www.cisa.gov/news-events/alerts/2026/10/21/critical-vulnerabilities-php-require-immediate-patching) (CVE-2026-109618 and CVE-2026-109619, unauthenticated remote code execution, actively exploited, fixed in 8.2.35, 8.3.36, 8.4.27, and 8.5.12). Works even when php.net is unreachable.
- **update_available:** a newer patch release exists (flagged when it's a security release).
- **unsupported_branch:** the branch no longer gets security fixes (e.g. 7.4, 8.1). Critical advisories without a fix for that branch are named.
- **vendor_build:** a host or Linux distribution build (e.g. `8.3.6-0ubuntu…`) that may include backported fixes, so it isn't reported as outdated; the advisory's CVEs are listed to confirm with the host.
- **up_to_date** or **unknown** (php.net unreachable).

Advisories that depend on configuration are only shown when the server's settings match. For example, CVE-2026-115560 (no fix yet) needs `session.upload_progress.enabled` on and `session.serialize_handler` set to `php_binary` or `php_serialize`; when they are, the page shows the workaround.

The scan page shows a notice for each of these. The same data is in `wp php-compat php-status` and the `php-status` ability, so hosts can check a whole fleet. Advisories live in `Php_Releases::advisories()` and can be changed with the `wpephpcompat_php_advisories` filter.

## Host panel

A panel beside the results shows the site's PHP version, which newer PHP the active plugins and theme are ready for, and how to change PHP on this host. It appears when the plugin knows something about the host:

- **WP Engine** is detected with `is_wpe()` and links to the environment's PHP settings in the User Portal.
- **Hosts that already set a PHP guide** for WordPress core, through `WP_UPDATE_PHP_URL` or the `wp_update_php_url` filter, get a panel that links to it. No extra code is needed.
- **Any other host** can describe itself with `wpephpcompat_host`:

```php
add_filter( 'wpephpcompat_host', function ( $host ) {
	return array(
		'name'       => 'Example Hosting',
		'change_php' => 'In the control panel, open <strong>Sites → PHP</strong>. Try a staging copy first.',
		'links'      => array(
			array( 'label' => 'Open the control panel', 'url' => 'https://panel.example.com/php' ), // First link is a button.
			array( 'label' => 'PHP upgrade guide', 'url' => 'https://example.com/docs/php' ),
		),
		// Optional. Shown only after a scan finds problems.
		'help'       => array(
			'text'  => 'Our support team can look at the results with you.',
			'links' => array( array( 'label' => 'Contact support', 'url' => 'https://example.com/support' ) ),
		),
	);
} );
```

Return `false` to hide the panel. `change_php` allows basic HTML, and a blank line starts a new paragraph.

## Filters

- `phpcompat_excluded_plugins` and `phpcompat_excluded_themes`: names to leave out of scans.
- `phpcompat_plugins_to_scan` and `phpcompat_themes_to_scan`: the final lists sent to the browser.
- `wpephpcompat_pre_scan_report`: supply a report for an item instead of scanning it (scan page and WP-CLI), e.g. results your host already has.
- `wpephpcompat_host`: the host details in the panel beside the results; see [Host panel](#host-panel).
- `wpephpcompat_php_advisories`: the critical PHP advisories checked against the server.
- `wpephpcompat_scanning_enabled`: return `false` to turn off scanning from the page (same as `WPEPHPCOMPAT_DISABLE_SCANNING`).
- `wpephpcompat_wasm_base_url`: where the browser loads the PHP WebAssembly runtime from (defaults to the plugin's `build/php-wasm/`).
