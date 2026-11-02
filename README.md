# PHP Compatibility Checker

[![CI](https://github.com/josephfusco/phpcompat/actions/workflows/ci.yml/badge.svg)](https://github.com/josephfusco/phpcompat/actions/workflows/ci.yml)

A WordPress plugin that checks whether the plugins and themes on a site work with newer versions of PHP. It's on WordPress.org as [PHP Compatibility Checker](https://wordpress.org/plugins/php-compatibility-checker/) and has around 200,000 active installs.

It runs the PHP community's standard compatibility checker, [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) on [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer), with the WordPress ruleset [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP), against PHP 7.4 through 8.5. It checks every plugin and theme: WordPress.org, premium, and custom.

This fork is actively maintained. It's where fixes are developed before being proposed upstream to [wpengine/phpcompat](https://github.com/wpengine/phpcompat).

## How it works

The plugin adds **Tools → PHP Compatibility** to wp-admin. Choose **Only scan active plugins and theme**, **Scan all plugins and themes**, or **Choose which plugins and themes to scan**, then click **Scan site**:

1. The scan page sends one small request at a time to the plugin's REST API (`POST /wp-json/php-compat/v1/scan-batch`). The first request for each item only plans: it returns the stored result if the item's files haven't changed, otherwise the number of PHP files.
2. Each following request scans the next batch of files on the site, in-process (no `exec()`), with the scanner that ships in the plugin (`build/scanner/`, plain PHP files). The browser sizes batches to take about 3 seconds, so no request comes near a host's time or memory limit.
3. `runner.php` runs PHPCompatibilityWP once per PHP version (`testVersion` 7.4, 8.0, … 8.5), so each result means "this code breaks on PHP 8.x". To keep that fast, a first pass checks every file against the whole range and only the files it flags get the per-version passes.
4. The browser merges the batches. Each item gets a status (Passes, Problems found, Not checked) and a chip per PHP version. Versions below the item's own "Requires PHP" are shown as not counted. Errors fail a version; warnings (deprecations) are listed but still run. Selecting a failing version shows the file, line, and message for each problem.
5. The result is stored on the site with the item's version, the scanner's rule set, and a fingerprint of its PHP files (paths, sizes, modification times). Later scans reuse it until any of those change. WP-CLI and the Abilities API read the same results.

Nothing is sent to another service: the code never leaves the site.

The results also feed:

- **Site Health** (Tools → Site Health): a test for the active plugins and theme, and a PHP Compatibility section on the Info tab. It reads stored results and never scans.
- **"Requires PHP" checks**: an item whose code only passes from a newer PHP than its header says, or whose `vendor/composer/platform_check.php` requires one, gets a note. Both sources keep themselves current.

### Built for shared hosting

Scanning is a resumable batch job, using the usual patterns for that:

- **One request in flight**, so a scan holds at most one PHP worker.
- **Adaptive batch size**: aims for about 2 seconds per request, grows (at most 1.5×) while requests are fast, halves after a slow or failed one. The server also caps every batch (100 files, 1 MB). Request time includes waiting on disk, so hosts with limited I/O get smaller batches.
- **Pacing**: after a slow request, it waits a quarter of that time (at most 3 seconds) before the next, so a busy host has room for visitors.
- **Progress you can see**: each item shows files checked, a progress bar, an estimate of the time left, and "still working…" if one request takes longer than 10 seconds.
- **Retries with exponential backoff and jitter** on 429, 503, timeouts, and dropped connections, honoring `Retry-After`.
- **Poison-file isolation**: a batch that crashes PHP (memory, time) is split until the file causing it is found; that file is skipped and listed. If more than a few files crash, the item fails instead of passing.
- **Optimistic concurrency**: if an item's files change mid-scan, the server answers 409 and the item starts again.
- **Integrity check**: the bundled scanner is checked against its build's SHA-256 (once a day per build); a missing or incomplete copy says to reinstall instead of failing on every file.
- **Clean JSON**: messages are collected without phpcs's JSON report, so files that aren't UTF-8 can't break a response, and stray PHP notices before the JSON are skipped.

### Limitations

- **Static analysis.** Code isn't executed. Code paths that exist for backwards compatibility can show up as failures, and runtime problems won't show up at all.
- **PHPCompatibility 10 is in alpha.** It's the release line with PHP 8.1–8.5 checks; coverage of some PHP 8.3–8.5 changes is still being added upstream. The exact versions are in every report and in `build/scanner.json`.
- **The site does the work.** A first scan of a large site takes a few minutes of one PHP worker; later scans only check what changed. WP-CLI (`wp php-compat scan`) runs the same scanner without request limits.

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
| `plugin/lib/class-site-health.php` | Site Health test and Info section |
| `plugin/lib/class-rest-controller.php` | `php-compat/v1` REST routes: local files in batches, and saving results |
| `plugin/lib/class-cli-command.php` | `wp php-compat` commands |
| `plugin/lib/class-abilities.php` | Abilities API registration |
| `plugin/lib/class-php-releases.php` | Server PHP release status from php.net and critical advisories (latest patch release, branch end of life, PHP2Shell) |
| `src/js/scan.js` | Page wiring: buttons and toggles |
| `src/js/include/queue-manager.js` | Scan queue: one item at a time |
| `src/js/include/scanner.js` | Batched scanning over REST: sizing, retries, skipping, merging |
| `src/js/include/report-model.js` | What goes in a report (labels, grouping, wording), shared by the PDF and the printable page |
| `plugin/lib/class-rest-controller.php` | `POST /scan-batch` and `POST /results` |
| `tools/phpcs-bundle/` | Pinned phpcs + PHPCompatibilityWP (`composer.lock`), `runner.php`, `collect-report.php`, and `build.php`, which writes `plugin/build/scanner/` and `scanner.json` (plain files: WordPress.org doesn't accept archives in plugins) |
| `src/js/include/render.js` | Results rendering (Mustache template in the PHP class) |
| `plugin/build/` | Compiled assets (git-ignored; created by `npm run build`) |
| `tests/*Test.php` | PHP unit tests (PHPUnit + Brain Monkey) |
| `tests/e2e/` | Playwright end-to-end tests and the wp-env test fixture plugin |

### Filters

- `phpcompat_excluded_plugins` and `phpcompat_excluded_themes`: names to leave out of scans.
- `phpcompat_plugins_to_scan` and `phpcompat_themes_to_scan`: the final lists sent to the browser.
- `wpephpcompat_pre_scan_report`: supply a report for an item instead of scanning it (scan page and WP-CLI), e.g. results your host already has.
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

The end-to-end tests run against a separate wp-env site defined in `.wp-env.tests.json` (http://localhost:8889, started automatically), so your development site isn't touched. A small must-use plugin (`tests/e2e/fixtures/phpcompat-e2e.php`) is mapped into that site only. It supplies a fixed list of plugins and themes, fixture scan reports for WP-CLI, and switches for the WP Engine sidebar and a test advisory. Most browser tests replace the scanner with the same fixture reports, so they can hold or fail a scan on cue; one test runs the real batched scanner through `/scan-batch`, where the fixture plugin answers with those reports, and checks that nothing is loaded from another site. `tests/RunnerTest.php` runs real scans with the bundled scanner on a fixture plugin (run `composer install -d tools/phpcs-bundle` first).

CI runs all of these on every pull request. See [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

## Tooling

The build and tests use WordPress's own maintained tooling, so there's little for this project to keep up to date:

- [`@wordpress/scripts`](https://www.npmjs.com/package/@wordpress/scripts) for the build (webpack, Babel, Sass), ESLint, Stylelint, and Playwright config.
- [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) for local and test WordPress sites.
- [`@wordpress/e2e-test-utils-playwright`](https://www.npmjs.com/package/@wordpress/e2e-test-utils-playwright) for end-to-end tests.
- WordPress design tokens (`--wpds-*`), with fallbacks for older WordPress added at build time by `@wordpress/theme` (`postcss.config.js`).
- Core SVG icons via `wp_get_icon()` (WordPress 7.1+), with Dashicons on older versions.
- `jquery`, `wp-i18n`, and `wp-api-fetch` are loaded from WordPress, not bundled.
- The scanner itself is a Composer project in `tools/phpcs-bundle` with a lock file, built into the plugin by `npm run build`.

## Automation

| Workflow | What it does |
| --- | --- |
| [CI](.github/workflows/ci.yml) | Lint, unit tests on PHP 8.3 and 8.4, a build check (including that no archives ship, which WordPress.org rejects), WordPress.org's Plugin Check on the build, and end-to-end tests |
| [Dependabot](.github/dependabot.yml) and [auto-merge](.github/workflows/dependabot-auto-merge.yml) | Weekly dependency updates. Dev-only minor and patch updates merge automatically once CI passes. Scanner updates (`tools/phpcs-bundle`) always get a human review, since they change results. |
| [Tested up to](.github/workflows/tested-up-to.yml) | Weekly. Opens a PR when a new WordPress version ships. |

The scheduled workflows and auto-merge only run on `wpengine/phpcompat`.

## WordPress.org assets

`.wordpress-org/` holds the listing's banner, icons, and screenshots (`screenshot-1.png` to `screenshot-3.png`, matching the captions in `readme.txt`). They go in the SVN repository's `/assets` folder, not in the plugin zip.

## Building a release zip

```bash
npm run build
cd plugin && zip -r ../php-compatibility-checker.zip . -x '*.map'
```

## Security

Please report security issues privately to wordpress@wpengine.com rather than opening a public issue.

## License

GPLv2 or later.
