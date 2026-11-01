# PHP Compatibility Checker

[![CI](https://github.com/josephfusco/phpcompat/actions/workflows/ci.yml/badge.svg)](https://github.com/josephfusco/phpcompat/actions/workflows/ci.yml)

A WordPress plugin that checks whether the plugins and themes on a site work with newer versions of PHP. It's on WordPress.org as [PHP Compatibility Checker](https://wordpress.org/plugins/php-compatibility-checker/) and has around 200,000 active installs.

This fork is actively maintained. It's where fixes are developed before being proposed upstream to [wpengine/phpcompat](https://github.com/wpengine/phpcompat).

## How it works

The plugin adds **Tools → PHP Compatibility** to wp-admin. It lists every plugin and theme, all selected. Untick anything you want to skip (or use **Active only**), then click **Scan**:

1. The plugin sends the selected plugins' and themes' WordPress.org slugs and installed versions to the browser.
2. The browser asks the [Tide](https://wptide.org) API for each item's audit, one at a time:
   `https://wptide.org/api/v1/audit/wporg/{plugin|theme}/{slug}/{version}?reports=phpcs_phpcompatibilitywp`
3. Tide has already run [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP) against that release from WordPress.org. If it hasn't, it queues the audit and the plugin retries every 5 seconds.
4. Each item gets a status (Passes, Problems found, Not checked) and a chip per PHP version. Versions below the item's own "Requires PHP" are shown as not counted. Selecting a failing version shows the file, line, and message for each problem.

Nothing is scanned on the site's own server, so scans don't time out or use server resources.

### Limitations

- **PHP 5.6 through 8.0 only.** That's the range Tide reports today. Support for newer versions depends on Tide upgrading PHPCompatibility. The [Tide watch workflow](.github/workflows/tide-watch.yml) flags when that changes.
- **WordPress.org items only.** Premium, custom, and modified plugins or themes can't be checked, and are shown as "could not be scanned".
- **Static analysis.** Code isn't executed. Code paths that exist for backwards compatibility can show up as failures, and runtime problems won't show up at all.

## WP-CLI

```bash
wp php-compat scan                      # Every plugin and theme
wp php-compat scan --active             # Active items only
wp php-compat scan akismet --format=json
wp php-compat report wp-optimize --php=7.3
wp php-compat php-status
```

`scan` exits with status 1 when any item has problems, so it can gate deploys or run across many sites. Items Tide is still auditing are retried for up to `--wait` seconds (default 60). Results are cached, so repeat scans are fast.

## Abilities API (AI agents)

On WordPress 6.9+ the plugin registers two [abilities](https://developer.wordpress.org/apis/abilities/) in the `php-compatibility` category. Agents and other clients can discover and run them over REST (`/wp-abilities/v1/`) or through an MCP adapter:

| Ability | What it returns |
| --- | --- |
| `php-compatibility-checker/scan` | Status per plugin and theme (`passes`, `problems`, `not_checked`, `pending`, `failed`), results per PHP version, and optional problem details. Input: `slugs`, `type`, `active_only`, `include_details`. |
| `php-compatibility-checker/php-status` | The server's PHP version, whether WordPress considers it secure and at its recommended version, and `release`: whether it's the latest release of a supported branch (from php.net). `release.state` is `up_to_date`, `update_available`, `unsupported_branch`, `vendor_build`, or `unknown`. |

Both are read-only and require an administrator (`manage_options`). The scan output includes `max_php_checked`, the newest PHP version with results, so an agent doesn't treat a pass as readiness for versions that weren't checked.

## Server PHP release status

Static compatibility results don't tell you whether the server's PHP itself is patched. WordPress's own PHP check works at the branch level (it treats any 8.3 release as secure). The plugin also compares the exact version with php.net's release data ([`releases/states.php`](https://www.php.net/releases/states.php) and [`releases/active.php`](https://www.php.net/releases/active.php), cached for 12 hours):

- **update_available:** a newer patch release exists (flagged when it's a security release, as with the October 2026 PHP2Shell fixes).
- **unsupported_branch:** the branch no longer gets security fixes (e.g. 7.4, 8.1).
- **vendor_build:** a host or Linux distribution build (e.g. `8.3.6-0ubuntu…`) that may include backported fixes, so it isn't reported as outdated.
- **up_to_date** or **unknown** (php.net unreachable).

The scan page shows a notice for the first three. The same data is in `wp php-compat php-status` and the `php-status` ability, so hosts can check a whole fleet.

## Code map

| Path | What it is |
| --- | --- |
| `plugin/wpengine-phpcompat.php` | Bootstrap: constants, autoloader, activation, upgrade, and migration hooks |
| `plugin/lib/class-php-compatibility-checker.php` | Admin page, script loading, and the list of plugins and themes to scan, with filters |
| `plugin/lib/class-scanner.php` | Server-side scanner: Tide requests, caching, and the result rules (used by WP-CLI and abilities) |
| `plugin/lib/class-cli-command.php` | `wp php-compat` commands |
| `plugin/lib/class-abilities.php` | Abilities API registration |
| `plugin/lib/class-php-releases.php` | Server PHP release status from php.net (latest patch release, branch end of life) |
| `plugin/lib/class-plugin-updater.php` | Serves updates from WP Engine's update server (since 1.6.4) |
| `src/js/scan.js` | Page wiring: buttons and toggles |
| `src/js/include/queue-manager.js` | Scan queue and Tide requests |
| `src/js/include/render.js` | Results rendering (Mustache template in the PHP class) |
| `plugin/build/` | Compiled assets (git-ignored; created by `npm run build`) |
| `tests/*Test.php` | PHP unit tests (PHPUnit + Brain Monkey) |
| `tests/e2e/` | Playwright end-to-end tests and the wp-env test fixture plugin |

### Filters

- `phpcompat_excluded_plugins` and `phpcompat_excluded_themes`: names to leave out of scans.
- `phpcompat_plugins_to_scan` and `phpcompat_themes_to_scan`: the final lists sent to the browser.

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
composer lint          # WordPress Coding Standards + PHPCompatibility 5.6+
composer lint:fix      # Auto-fix PHP lint issues
npm run lint:js        # ESLint (WordPress config)
npm run lint:css       # Stylelint (WordPress config, design token rules)
npm run test:e2e       # Playwright end-to-end tests
npm run test:e2e:debug # Same, with the Playwright inspector
```

The end-to-end tests run against a separate wp-env site defined in `.wp-env.tests.json` (http://localhost:8889, started automatically), so your development site isn't touched. A small must-use plugin (`tests/e2e/fixtures/phpcompat-e2e.php`) is mapped into that site only. It supplies a fixed list of plugins and themes and a switch for the WP Engine sidebar, and the tests fake Tide's responses, so they're fast and don't depend on Tide being up. The [Tide watch workflow](.github/workflows/tide-watch.yml) covers the live service.

CI runs all of these on every pull request. See [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

## Tooling

The build and tests use WordPress's own maintained tooling, so there's little for this project to keep up to date:

- [`@wordpress/scripts`](https://www.npmjs.com/package/@wordpress/scripts) for the build (webpack, Babel, Sass), ESLint, Stylelint, and Playwright config.
- [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) for local and test WordPress sites.
- [`@wordpress/e2e-test-utils-playwright`](https://www.npmjs.com/package/@wordpress/e2e-test-utils-playwright) for end-to-end tests.
- WordPress design tokens (`--wpds-*`), with fallbacks for older WordPress added at build time by `@wordpress/theme` (`postcss.config.js`).
- Core SVG icons via `wp_get_icon()` (WordPress 7.1+), with Dashicons on older versions.
- `jquery` and `wp-i18n` are loaded from WordPress, not bundled.

## Automation

| Workflow | What it does |
| --- | --- |
| [CI](.github/workflows/ci.yml) | Lint, unit tests on PHP 8.3 and 8.4, and a build check |
| [Dependabot](.github/dependabot.yml) and [auto-merge](.github/workflows/dependabot-auto-merge.yml) | Weekly dependency updates. Dev-only minor and patch updates merge automatically once CI passes. |
| [Tested up to](.github/workflows/tested-up-to.yml) | Weekly. Opens a PR when a new WordPress version ships. |
| [Tide watch](.github/workflows/tide-watch.yml) | Daily. Opens an issue if Tide is down or starts reporting newer PHP versions. |

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
