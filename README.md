# PHP Compatibility Checker

[![CI](https://github.com/josephfusco/phpcompat/actions/workflows/ci.yml/badge.svg)](https://github.com/josephfusco/phpcompat/actions/workflows/ci.yml)

A WordPress plugin that checks whether the plugins and themes on a site work with newer versions of PHP. It's on WordPress.org as [PHP Compatibility Checker](https://wordpress.org/plugins/php-compatibility-checker/) and has around 200,000 active installs.

It runs the PHP community's standard compatibility checker, [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) on [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer), with the WordPress ruleset [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP), against PHP 7.4 through 8.5. It checks every plugin and theme: WordPress.org, premium, and custom.

This fork is actively maintained. It's where fixes are developed before being proposed upstream to [wpengine/phpcompat](https://github.com/wpengine/phpcompat).

## How it works

The plugin adds **Tools → PHP Compatibility** to wp-admin. Choose **Only scan active plugins and theme**, **Scan all plugins and themes**, or **Choose which plugins and themes to scan**, then click **Scan site**:

1. The browser loads PHP from [WordPress Playground](https://wordpress.org/playground/) (PHP compiled to WebAssembly, served from playground.wordpress.net) in a hidden iframe, and unpacks the scanner that ships with the plugin (`build/phpcs-bundle.zip`, about 1 MB, checked against its SHA-256).
2. For each plugin and theme:
   - **On WordPress.org:** the browser downloads that exact version from downloads.wordpress.org. If WordPress.org doesn't have that version, it's treated like custom code.
   - **Premium, custom, or modified:** the browser reads its PHP files from this site through the plugin's REST API, in batches of up to 512 KB.
3. `runner.php` runs PHPCompatibilityWP once per PHP version (`testVersion` 7.4, 8.0, … 8.5), so each result means "this code breaks on PHP 8.x". To keep that fast, a first pass checks every file against the whole range and only the files it flags get the per-version passes.
4. Each item gets a status (Passes, Problems found, Not checked) and a chip per PHP version. Versions below the item's own "Requires PHP" are shown as not counted. Errors fail a version; warnings (deprecations) are listed but still run. Selecting a failing version shows the file, line, and message for each problem.
5. The result is stored on the site, keyed by the item's version and the scanner's rule set. Later scans reuse results for WordPress.org code (it can't change) and rescan local code. WP-CLI and the Abilities API read the same results.

The site's server never runs the scan: it only lists plugins and themes, serves local files in small batches, and stores results. That keeps scans off shared hosts with limited CPU and disk I/O.

### Limitations

- **Static analysis.** Code isn't executed. Code paths that exist for backwards compatibility can show up as failures, and runtime problems won't show up at all.
- **PHPCompatibility 10 is in alpha.** It's the release line with PHP 8.1–8.5 checks; coverage of some PHP 8.3–8.5 changes is still being added upstream. The exact versions are in every report and in `build/phpcs-bundle.json`.
- **Needs playground.wordpress.net and downloads.wordpress.org** from the admin's browser. A firewall, browser extension, or security policy that blocks them stops the scan with a message saying so. The `wpephpcompat_playground_remote_url` filter points scans at a self-hosted Playground instead.

## WP-CLI

```bash
wp php-compat scan                      # Every plugin and theme, scanned now
wp php-compat scan --active             # Active items only
wp php-compat scan akismet --format=json
wp php-compat scan --cached             # Stored results only, no scanning
wp php-compat report wp-optimize --php=8.4
wp php-compat php-status
```

WP-CLI has no browser and no time limit, so `scan` runs the same `runner.php` in-process on the installed files (no `exec()` needed) and stores the results. `scan` exits with status 1 when any item has problems, so it can gate deploys or run across many sites.

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

## Code map

| Path | What it is |
| --- | --- |
| `plugin/wpengine-phpcompat.php` | Bootstrap: constants, autoloader, activation, upgrade, and migration hooks |
| `plugin/lib/class-php-compatibility-checker.php` | Admin page, script loading, and the list of plugins and themes to scan, with filters |
| `plugin/lib/class-scanner.php` | Server-side scanner: turns reports into statuses, scans installed files for WP-CLI (used by WP-CLI and abilities) |
| `plugin/lib/class-scanner-bundle.php` | The bundled scanner: rule set, PHP versions, browser settings, and in-process runs for WP-CLI |
| `plugin/lib/class-results.php` | Stored results, one non-autoloaded option per plugin or theme |
| `plugin/lib/class-rest-controller.php` | `php-compat/v1` REST routes: local files in batches, and saving results |
| `plugin/lib/class-cli-command.php` | `wp php-compat` commands |
| `plugin/lib/class-abilities.php` | Abilities API registration |
| `plugin/lib/class-php-releases.php` | Server PHP release status from php.net and critical advisories (latest patch release, branch end of life, PHP2Shell) |
| `plugin/lib/class-plugin-updater.php` | Serves updates from WP Engine's update server (since 1.6.4) |
| `src/js/scan.js` | Page wiring: buttons and toggles |
| `src/js/include/queue-manager.js` | Scan queue: one item at a time, reusing stored results |
| `src/js/include/scanner.js` | In-browser scanner on WordPress Playground (PHP WebAssembly) |
| `tools/phpcs-bundle/` | Pinned phpcs + PHPCompatibilityWP (`composer.lock`), `runner.php`, and `build.php`, which writes `plugin/build/phpcs-bundle.zip` |
| `src/js/include/render.js` | Results rendering (Mustache template in the PHP class) |
| `plugin/build/` | Compiled assets (git-ignored; created by `npm run build`) |
| `tests/*Test.php` | PHP unit tests (PHPUnit + Brain Monkey) |
| `tests/e2e/` | Playwright end-to-end tests and the wp-env test fixture plugin |

### Filters

- `phpcompat_excluded_plugins` and `phpcompat_excluded_themes`: names to leave out of scans.
- `phpcompat_plugins_to_scan` and `phpcompat_themes_to_scan`: the final lists sent to the browser.
- `wpephpcompat_pre_scan_report`: supply a report for an item instead of scanning it with WP-CLI.
- `wpephpcompat_playground_remote_url`: where the browser loads PHP WebAssembly from (default `https://playground.wordpress.net/remote.html`).
- `wpephpcompat_php_advisories`: the critical PHP advisories checked against the server.

## Local development

Requirements: [Docker](https://www.docker.com/), Node.js 24 (`nvm use`), PHP 8.3+, and [Composer](https://getcomposer.org/).

```bash
npm ci
composer install
npm run build
npx wp-env start
```

WordPress runs at http://localhost:8888/wp-admin (user `admin`, password `password`), with the plugin mounted from `plugin/`. Go to **Tools → PHP Compatibility** and click **Scan**.

To have more to scan, install a few plugins:

```bash
npx wp-env run cli wp plugin install akismet contact-form-7 wordpress-seo --activate
```

`npm start` rebuilds assets on change. `npx wp-env stop` stops the containers, and `npx wp-env destroy` resets everything.

## Tests and linting

```bash
composer test          # PHP unit tests
composer lint          # WordPress Coding Standards + PHPCompatibility 7.4+
composer lint:fix      # Auto-fix PHP lint issues
npm run lint:js        # ESLint (WordPress config)
npm run lint:css       # Stylelint (WordPress config, design token rules)
npm run test:e2e       # Playwright end-to-end tests
npm run test:e2e:debug # Same, with the Playwright inspector
```

The end-to-end tests run against a separate wp-env site defined in `.wp-env.tests.json` (http://localhost:8889, started automatically), so your development site isn't touched. A small must-use plugin (`tests/e2e/fixtures/phpcompat-e2e.php`) is mapped into that site only. It supplies a fixed list of plugins and themes, fixture scan reports for WP-CLI, and switches for the WP Engine sidebar and a test advisory. In the browser the tests replace the Playground runtime with the same fixture reports, so they're fast and don't need the network. `tests/RunnerTest.php` runs real scans with the bundled scanner on a fixture plugin (run `composer install -d tools/phpcs-bundle` first).

CI runs all of these on every pull request. See [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

## Tooling

The build and tests use WordPress's own maintained tooling, so there's little for this project to keep up to date:

- [`@wordpress/scripts`](https://www.npmjs.com/package/@wordpress/scripts) for the build (webpack, Babel, Sass), ESLint, Stylelint, and Playwright config.
- [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) for local and test WordPress sites.
- [`@wordpress/e2e-test-utils-playwright`](https://www.npmjs.com/package/@wordpress/e2e-test-utils-playwright) for end-to-end tests.
- WordPress design tokens (`--wpds-*`), with fallbacks for older WordPress added at build time by `@wordpress/theme` (`postcss.config.js`).
- Core SVG icons via `wp_get_icon()` (WordPress 7.1+), with Dashicons on older versions.
- `jquery`, `wp-i18n`, and `wp-api-fetch` are loaded from WordPress, not bundled.
- [`@wp-playground/client`](https://www.npmjs.com/package/@wp-playground/client) (pinned) to run PHP in the browser; loaded only when a scan starts.
- The scanner itself is a Composer project in `tools/phpcs-bundle` with a lock file, built into the plugin by `npm run build`.

## Automation

| Workflow | What it does |
| --- | --- |
| [CI](.github/workflows/ci.yml) | Lint, unit tests on PHP 8.3 and 8.4, and a build check |
| [Dependabot](.github/dependabot.yml) and [auto-merge](.github/workflows/dependabot-auto-merge.yml) | Weekly dependency updates. Dev-only minor and patch updates merge automatically once CI passes. Scanner updates (`tools/phpcs-bundle`) always get a human review, since they change results. |
| [Tested up to](.github/workflows/tested-up-to.yml) | Weekly. Opens a PR when a new WordPress version ships. |

The scheduled workflows and auto-merge only run on `wpengine/phpcompat`.

## Building a release zip

```bash
npm run build
cd plugin && zip -r ../php-compatibility-checker.zip . -x '*.map'
```

## Security

Please report security issues privately to wordpress@wpengine.com rather than opening a public issue.

## License

GPLv2 or later.
