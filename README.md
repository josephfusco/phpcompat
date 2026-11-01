# PHP Compatibility Checker

[![CI](https://github.com/josephfusco/phpcompat/actions/workflows/ci.yml/badge.svg)](https://github.com/josephfusco/phpcompat/actions/workflows/ci.yml)

A WordPress plugin that checks whether the plugins and themes on a site work with newer versions of PHP. It's on WordPress.org as [PHP Compatibility Checker](https://wordpress.org/plugins/php-compatibility-checker/) and has around 200,000 active installs.

This fork is actively maintained. It's where fixes are developed before being proposed upstream to [wpengine/phpcompat](https://github.com/wpengine/phpcompat).

## How it works

The plugin adds **Tools → PHP Compatibility** to wp-admin. When you click **Scan site**:

1. The plugin lists the site's plugins and themes, with their WordPress.org slugs and installed versions.
2. The browser asks the [Tide](https://wptide.org) API for each item's audit, one at a time:
   `https://wptide.org/api/v1/audit/wporg/{plugin|theme}/{slug}/{version}?reports=phpcs_phpcompatibilitywp`
3. Tide has already run [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP) against that release from WordPress.org. If it hasn't, it queues the audit and the plugin retries every 5 seconds.
4. The results page shows pass or fail per PHP version. Clicking a failing version shows the file, line, and message for each problem.

Nothing is scanned on the site's own server, so scans don't time out or use server resources.

### Limitations

- **PHP 5.6 through 8.0 only.** That's the range Tide reports today. Support for newer versions depends on Tide upgrading PHPCompatibility. The [Tide watch workflow](.github/workflows/tide-watch.yml) flags when that changes.
- **WordPress.org items only.** Premium, custom, and modified plugins or themes can't be checked, and are shown as "could not be scanned".
- **Static analysis.** Code isn't executed. Code paths that exist for backwards compatibility can show up as failures, and runtime problems won't show up at all.

## Code map

| Path | What it is |
| --- | --- |
| `plugin/wpengine-phpcompat.php` | Bootstrap: constants, autoloader, activation, upgrade, and migration hooks |
| `plugin/lib/class-php-compatibility-checker.php` | Admin page, script loading, and the list of plugins and themes to scan, with filters |
| `plugin/lib/class-plugin-updater.php` | Serves updates from WP Engine's update server (since 1.6.4) |
| `src/js/scan.js` | Page wiring: buttons and toggles |
| `src/js/include/queue-manager.js` | Scan queue and Tide requests |
| `src/js/include/render.js` | Results rendering (Mustache template in the PHP class) |
| `plugin/build/` | Compiled assets (git-ignored; created by `npm run build`) |

### Filters

- `phpcompat_excluded_plugins` and `phpcompat_excluded_themes`: names to leave out of scans.
- `phpcompat_plugins_to_scan` and `phpcompat_themes_to_scan`: the final lists sent to the browser.

## Local development

Requirements: [Docker](https://www.docker.com/), Node.js 20 (`nvm use`), PHP 8.3+, and [Composer](https://getcomposer.org/).

```bash
npm ci
composer install
npm run build
npx wp-env start
```

WordPress runs at http://localhost:8888/wp-admin (user `admin`, password `password`), with the plugin mounted from `plugin/`. Go to **Tools → PHP Compatibility** and click **Scan site**.

To have more to scan, install a few plugins:

```bash
npx wp-env run cli wp plugin install akismet contact-form-7 wordpress-seo --activate
```

`npm start` rebuilds assets on change. `npx wp-env stop` stops the containers, and `npx wp-env destroy` resets everything.

## Tests and linting

```bash
vendor/bin/phpunit                       # Unit tests (Brain Monkey)
vendor/bin/phpcs --standard=./phpcs.xml  # WordPress Coding Standards + PHPCompatibility 5.6+
vendor/bin/phpcbf --standard=./phpcs.xml # Auto-fix lint issues
npm run build                            # Must compile without warnings
```

CI runs all of these on every pull request. See [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

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
