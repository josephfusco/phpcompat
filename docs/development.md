# Development

Setup is in the [README](../README.md#local-development).

## Code map

| Path | What it is |
| --- | --- |
| `plugin/wpengine-phpcompat.php` | Bootstrap: constants, autoloader, activation, upgrade, and migration hooks |
| `plugin/lib/class-php-compatibility-checker.php` | Admin page, script loading, and the list of plugins and themes to scan, with filters |
| `plugin/lib/class-scanner.php` | Server-side scanner: turns reports into statuses, scans installed files for WP-CLI, and works out PHP upgrade readiness (used by WP-CLI and abilities) |
| `plugin/lib/class-scanner-bundle.php` | The bundled scanner: rule set, PHP versions, the off switch, browser settings (worker and runtime URLs), the files for `GET /scanner-bundle`, and in-process runs for WP-CLI |
| `plugin/lib/class-results.php` | Stored results, one non-autoloaded option per plugin or theme |
| `plugin/lib/class-site-health.php` | Site Health test and Info section |
| `plugin/lib/class-rest-controller.php` | `php-compat/v1` REST routes: `/scan-batch` (files in batches), `/scanner-bundle`, `/results`, and `/scan-lock` |
| `plugin/lib/class-cli-command.php` | `wp php-compat` commands |
| `plugin/lib/class-abilities.php` | Abilities API registration |
| `plugin/lib/class-php-releases.php` | Server PHP release status from php.net and critical advisories (latest patch release, branch end of life, PHP2Shell) |
| `src/js/scan.js` | Page wiring: buttons and toggles |
| `src/js/include/queue-manager.js` | Scan queue: one item at a time |
| `src/js/include/scanner.js` | Batched scanning: requests, sizing, retries, skipping, merging |
| `src/js/include/wasm-engine.js` | Starts the worker, caches the scanner's files in Cache Storage, and passes batches to it with timeouts |
| `src/js/wasm-worker.js` | Web Worker: loads PHP in WebAssembly, checks the scanner against its SHA-256, runs `runner.php` on each batch, and restarts PHP after a crash |
| `src/js/include/report-model.js` | What goes in a report (labels, grouping, wording), shared by the PDF and the printable page, and `readiness()` |
| `src/js/include/readiness-note.js` | The WP Engine sidebar's "Ready for PHP …" note after a scan |
| `tools/phpcs-bundle/` | Pinned phpcs + PHPCompatibilityWP (`composer.lock`), `runner.php`, `collect-report.php`, and `build.php`, which writes `plugin/build/scanner/` and `scanner.json` (plain files: WordPress.org doesn't accept archives in plugins) |
| `src/js/include/render.js` | Results rendering (Mustache template in the PHP class) |
| `plugin/build/` | Compiled assets, the scanner, and the PHP WebAssembly runtime in `php-wasm/` (git-ignored; created by `npm run build`) |
| `tests/*Test.php` | PHP unit tests (PHPUnit + Brain Monkey) |
| `tests/e2e/` | Playwright end-to-end tests and the wp-env test fixture plugin |

## Tests

The end-to-end tests run against a separate wp-env site defined in `.wp-env.tests.json` (http://localhost:8889, started automatically), so your development site isn't touched. A small must-use plugin (`tests/e2e/fixtures/phpcompat-e2e.php`) is mapped into that site only. It supplies a fixed list of plugins and themes, fixture scan reports for WP-CLI, and switches for the WP Engine sidebar and a test advisory. Most browser tests replace the scanner with the same fixture reports, so they can hold or fail a scan on cue; one test runs the real batched scanner through `/scan-batch`, where the fixture plugin answers with those reports, and checks that nothing is loaded from another site. `tests/RunnerTest.php` runs real scans with the bundled scanner on a fixture plugin (run `composer install -d tools/phpcs-bundle` first).

CI runs all of these on every pull request. See [`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

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
| [CI](../.github/workflows/ci.yml) | Lint, unit tests on PHP 8.3 and 8.4, a build check (including that no archives ship, which WordPress.org rejects), WordPress.org's Plugin Check on the build, and end-to-end tests |
| [Dependabot](../.github/dependabot.yml) and [auto-merge](../.github/workflows/dependabot-auto-merge.yml) | Weekly dependency updates. Dev-only minor and patch updates merge automatically once CI passes. Scanner updates (`tools/phpcs-bundle`) always get a human review, since they change results. |
| [Tested up to](../.github/workflows/tested-up-to.yml) | Weekly. Opens a PR when a new WordPress version ships. |

The scheduled workflows and auto-merge only run on `wpengine/phpcompat`.

## WordPress.org assets

`.wordpress-org/` holds the listing's banner, icons, and screenshots (`screenshot-1.png` to `screenshot-3.png`, matching the captions in `readme.txt`). They go in the SVN repository's `/assets` folder, not in the plugin zip.

## Building a release zip

```bash
npm run build
cd plugin && zip -r ../php-compatibility-checker.zip . -x '*.map'
```
