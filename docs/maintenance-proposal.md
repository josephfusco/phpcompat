# PHP Compatibility Checker: what's changed since end of support

**Status:** proposal for discussion · **Date:** November 2026 · **Branch:** `fix/scanner-fallback`

## Summary

In July 2026 WP Engine ended support for PHP Compatibility Checker and archived the repository. Given how the plugin worked then, that was the right call: every result came from WP Tide, an outside service that is no longer maintained, whose results stop at PHP 8.0, and that we couldn't fix or replace from inside the plugin.

That blocker is now gone. The plugin no longer uses Tide. It runs the standard PHP compatibility checker itself, on the site, for PHP 7.4 through 8.5, for every plugin and theme, including premium and custom code. WP Engine doesn't need to run any service. At the same time, PHP has had its most serious security event in years, so people need this tool more than they did in July.

This document records the original decision, what has changed since, and what maintaining the plugin would actually cost, so the decision can be revisited on its merits.

## The July 2026 decision

On July 20, 2026, the end-of-support change ([wpengine/phpcompat#431](https://github.com/wpengine/phpcompat/pull/431)) added this notice to the README and the WordPress.org readme, and the repository was archived:

> PHP Compatibility Checker is no longer actively maintained. This software is still free to use under the license provided, but users should be aware that it is not currently maintained. No additional releases, including security releases, will be made available.

The notice doesn't state reasons. These were the conditions on record at the time:

- **Every result depended on WP Tide** ([wptide.org](https://wptide.org)). The plugin sent each plugin's and theme's slug and version to Tide and displayed what came back. It had no scanner of its own.
- **Tide was frozen.** Its scanning tools date from 2019 to 2022 (PHP_CodeSniffer 3.7.1, PHPCompatibility 9.3.5), and it checks a fixed list of PHP versions that ends at 8.0. Nothing inside the plugin could get results past PHP 8.0.
- **Tide couldn't check premium or custom code.** It only audits code published on WordPress.org.
- **Upkeep was almost all dependency churn.** In the months before the decision, nearly every commit was a Dependabot bump. There was no feature work and no fixes for user-reported issues, and 137 issues were open.

*Inference, not on record:* with an unfixable outside dependency and no owner with time for it, ending support was the responsible choice. Shipping a plugin that can only answer "up to PHP 8.0" while presenting itself as current would have been worse.

## What's different now

### 1. Tide is gone

The plugin now ships its own scanner and runs it on the site, in short batches:

- **The checker:** [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) on [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer), with the WordPress ruleset [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP). These are the tools the PHP and WordPress communities already use; WordPress VIP's PHP-upgrade guidance, for example, is built on them. The versions are pinned in `tools/phpcs-bundle/composer.lock`, shipped as plain PHP files (about 4.7 MB; WordPress.org doesn't accept archives in plugins), checked against their build checksum, and printed in every report.
- **Where it runs:** on the site's own PHP, in-process (no `exec()`), driven by the scan page as a resumable batch job: one short request at a time, each sized to take about 3 seconds, with retries and backoff when the host is busy, and any file that hits a memory or time limit skipped and listed. Nothing is sent to another service.
- **Why not the browser:** an earlier version ran PHP in the browser on WordPress Playground. Playground's CDN answered the hidden iframe with a bot challenge that the iframe can't complete, so scans failed on real admin browsers. Depending on a third-party site we can't control is the same kind of risk that ended Tide, so scanning moved onto the site. It's designed for shared hosting (see the README's "Built for shared hosting").
- **Reuse:** results are stored with a fingerprint of each item's PHP files, so a later scan only checks what changed (a site with nine plugins and themes rescans in about 2 seconds).
- **WP-CLI:** runs the same scanner directly on the server, with no browser and no `exec()`.

| | With Tide (1.6.x) | Now |
|---|---|---|
| PHP versions | 5.6 to 8.0 | 7.4 to 8.5 (WordPress 7.0 requires 7.4) |
| Premium and custom code | Not checked | Checked |
| Depends on | An unmaintained outside service | Nothing outside the site: pinned open-source tools in the plugin |
| Service for WP Engine to run | None (Tide was free) | None |
| Wrong results from Tide's own bugs | Possible: unknown versions were scanned as the latest release; custom plugins sharing a WordPress.org name got that plugin's results; large plugins could silently lose a PHP version | Not reproduced: exact versions only, and every version accounted for |

On a test site with nine plugins and themes, a first scan takes under a minute (Broken Link Checker's 569 files take about 11 seconds, in requests of at most 4 seconds), and later scans about 2 seconds, because unchanged items are reused. Three items that Tide reported as "not checked" are now checked. One plugin that Tide passed has a real PHP 7.4 failure (Yoast SEO 28.8 calls `get_debug_type()` without the polyfill it needs).

### 2. PHP's security picture changed

- **PHP2Shell** (CVE-2026-109618 and CVE-2026-109619, October 2026) lets attackers who aren't logged in run code on the server. It affects every PHP version from 5.4 to 8.5.11, is in CISA's catalog of actively exploited vulnerabilities, and [CISA advises](https://www.cisa.gov/news-events/alerts/2026/10/21/critical-vulnerabilities-php-require-immediate-patching) taking PHP 8.1-and-older systems offline until they're patched or upgraded. A related flaw, CVE-2026-115560, is still unpatched.
- **Support windows:** PHP 8.1 reached end of life in December 2025, PHP 8.2's security support ends December 31, 2026, and PHP 8.6 is due November 19, 2026.

Many WordPress sites still run PHP 8.1 or older, and the question they all face is "can my plugins run on a supported PHP version?". This plugin answers that question. It now also checks the server's own PHP: it flags PHP2Shell with the exact release that fixes it, and shows the workaround for CVE-2026-115560 when the server's settings are exposed.

### 3. Nothing else fills the gap

A survey of current tools (November 2026) found no free, maintained equivalent:

- **Plugin Check**, which WordPress.org runs on plugin updates, doesn't include PHPCompatibility, and its results aren't published.
- The other plugins found either need `exec()`, which WP Engine, Kinsta, and SiteGround disable, are paid services, or use hand-written checks with a small install base.

At around 200,000 installs, this is WP Engine's third-largest plugin on WordPress.org after ACF and Better Search Replace.

### 4. It fits WP Engine's own PHP upgrade flow

WP Engine customers can already preview a newer PHP version with Preview PHP in the User Portal. That platform feature and this plugin do different jobs, and neither can replace the other:

| | PHP Compatibility Checker | Preview PHP |
|---|---|---|
| Who can use it | Any WordPress site, any host | WP Engine customers |
| How it checks | Reads the code (static analysis) against every PHP version at once | Runs the real site on one newer PHP version, for your browser only |
| Catches | Problems in code paths you may never click, with file and line | Runtime problems in the pages you actually visit |
| Best for | Deciding, and finding what to fix | Confirming just before switching |

A plugin can't take over Preview PHP: the server picks the PHP version before WordPress loads, so switching it per browser has to happen on the platform. A scanner is the opposite: it belongs with the site's code, not the platform. Together they make one flow: **check, then preview, then switch.**

- **On the site:** on WP Engine, the plugin's sidebar already links straight to the environment's page with the Preview PHP button, and it only shows on WP Engine.
- **In the User Portal (proposed):** the portal can show the plugin's results next to Preview PHP ("3 plugins have problems on PHP 8.4") by reading `wp php-compat scan --cached --format=json` or the Abilities API. Both exist today, return the same results as the scan page, and need no `exec()`. WP Engine gets the "check" step without building or running a scanner.
- **In Support:** PHP upgrade campaigns or tickets that need a compatibility answer can use the same WP-CLI command instead of separate scripts or third-party scans.

That makes the plugin the first step of a PHP upgrade, for WP Engine customers and for every site that isn't on WP Engine.

## What maintaining it would take

The parts WP Engine would own are small: the plugin itself, one runner file (`tools/phpcs-bundle/runner.php`), and a pinned list of dependencies. There's no service to run.

| Task | How often | Effort | Automation |
|---|---|---|---|
| Review scanner updates (PHPCompatibility, PHP_CodeSniffer) | When upstream releases | Read the changelog, merge | Dependabot opens the PR; CI runs real scans (`tests/RunnerTest.php`). Stored results are rescanned automatically when the rule set changes. These PRs are never auto-merged. |
| Add a new PHP version | Once a year (8.6 in November 2026) | One constant, test fixtures, readme | None needed |
| Add a critical PHP advisory | Rare (PHP2Shell is the first) | One entry in `Php_Releases::advisories()` plus a test | Sites can also add advisories with a filter |
| "Tested up to" for new WordPress releases | Each WordPress release | Merge | Workflow opens the PR |
| Development dependencies | Weekly | None | Auto-merged when CI passes |
| Issues and support forum | Ongoing | Triage | None |

*Estimate:* a few hours a month, plus a day or two around each PHP release. Most dependency churn is already automated, and the work that remains is the kind that keeps results correct.

CI covers PHP lint (WordPress Coding Standards, PHPCompatibility 7.4+), WordPress.org's Plugin Check on the build (no errors or warnings), 80 PHP unit tests (including real scans, non-UTF-8 files, historical false positives, and Site Health), 55 JavaScript unit tests (including retries, backoff, and failure handling of the batch scanner), and 22 end-to-end tests across the scan page, reports, Site Health, permissions, WP-CLI, and the Abilities API.

## Risks and open questions

1. **Shipping updates.** Releases go to WordPress.org directly, like any hosted plugin; the custom updater and `Update URI: false` (added in 1.6.4) are removed. One catch: sites already on 1.6.4 don't check WordPress.org for this plugin, and their updater only asks `wpe-plugin-updates.wpengine.com`. That server needs one entry for the new release (pointing at the WordPress.org zip) so those sites move over; after that, everything updates from WordPress.org.
2. **PHPCompatibility 10 is in alpha.** It's the only release line with PHP 8.1–8.5 checks, and some 8.3–8.5 changes are still being added upstream. The plugin says which version produced each result, and moving to the stable release when it ships is a routine Dependabot update.
3. **Scanning uses the site's PHP.** A first scan of a large site keeps one PHP worker busy for a few minutes. Requests are short and one at a time, so hosts' limits aren't hit, but it is real work on the server. WP-CLI runs the same scanner without request limits, and the `wpephpcompat_pre_scan_report` filter lets a host supply results it already has.
4. **Ownership.** Joe Fusco and the headless-oss team, who also maintain Faust.js and WPGraphQL Content Blocks. Day-to-day upkeep is mostly automated (see above).
5. **PHPCompatibility is static analysis.** It can't see code that never runs, or problems that only appear at runtime. That was true with Tide as well. The plugin says so, and marks problems found only in bundled libraries.

## Options

1. **Lift end of support under WP Engine.** Unarchive, ship this branch as 2.0.0 on WordPress.org (with one bridge entry on WP Engine's update server for sites on 1.6.4), maintained by Joe Fusco and the headless-oss team, and add the plugin as the "check" step of WP Engine's PHP upgrade flow. This keeps the plugin, its install base, and its reviews under WP Engine's name. *Recommended.*
2. **Transfer it to the WordPress community.** Move the plugin to a WordPress.org or community home with WP Engine credited. This is a good longer-term path, and it's easier now that the plugin doesn't depend on any service.
3. **Keep it archived.** The July decision stands. This work stays available as a public fork, and the WordPress.org listing keeps saying "no longer maintained".

## Respecting the original decision

This proposal doesn't argue the July decision was wrong. It was made under a constraint, a plugin that depended entirely on a frozen outside service, and that constraint no longer exists. The end-of-support work wasn't wasted either: the notice told users the truth at the time, and it should stay in place until there's a supported release to replace it.

If the answer is still no, nothing here needs to change upstream.

## Evidence

- Branch: [`fix/scanner-fallback`](https://github.com/josephfusco/phpcompat/tree/fix/scanner-fallback) on the maintained fork.
- How it works, limitations, and a code map: [README](../README.md).
- What the plugin relied on from Tide, and how it's replaced: the Tide request in `plugin/lib/class-scanner.php` and `src/js/include/queue-manager.js` (1.6.x) is replaced by `src/js/include/scanner.js`, `tools/phpcs-bundle/runner.php`, `plugin/lib/class-results.php`, and `plugin/lib/class-rest-controller.php`.
- Site Health test and the "needs a newer PHP than it says" check: `plugin/lib/class-site-health.php` and `Scanner::needed_php()`.
- Server PHP status and advisories: `plugin/lib/class-php-releases.php`.
