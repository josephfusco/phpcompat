# PHP Compatibility Checker: what's changed since end of support

**Status:** proposal for discussion · **Date:** November 2026 · **Branch:** `feature/wasm-scanner`

## Summary

In July 2026 WP Engine ended support for PHP Compatibility Checker and archived the repository. Given how the plugin worked then, that was the right call: every result came from WP Tide, an outside service that is no longer maintained, whose results stop at PHP 8.0, and that we couldn't fix or replace from inside the plugin.

That blocker is now gone. The plugin no longer uses Tide. It runs the standard PHP compatibility checker itself, in the admin's browser, for PHP 7.4 through 8.5, for every plugin and theme, including premium and custom code. WP Engine doesn't need to run any service. At the same time, PHP has had its most serious security event in years, so people need this tool more than they did in July.

This document records the original decision, what has changed since, and what maintaining the plugin would actually cost, so the decision can be revisited on its merits.

## The July 2026 decision

On July 20, 2026, the end-of-support change ([wpengine/phpcompat#431](https://github.com/wpengine/phpcompat/pull/431)) added this notice to the README and the WordPress.org readme, and the repository was archived:

> PHP Compatibility Checker is no longer actively maintained. This software is still free to use under the license provided, but users should be aware that it is not currently maintained. No additional releases, including security releases, will be made available.

The notice doesn't state reasons. These were the conditions on record at the time:

- **Every result depended on WP Tide** ([wptide.org](https://wptide.org)). The plugin sent each plugin's and theme's slug and version to Tide and displayed what came back. It had no scanner of its own.
- **Tide was frozen.** Its scanning tools date from 2019 to 2022 (PHP_CodeSniffer 3.7.1, PHPCompatibility 9.3.5), and it checks a fixed list of PHP versions that ends at 8.0. In November 2026, colleagues who had offered to contribute to Tide reported that neither its maintainers nor its funders were interested. Nothing inside the plugin could get results past PHP 8.0.
- **Tide couldn't check premium or custom code.** It only audits code published on WordPress.org.
- **Upkeep was almost all dependency churn.** In the months before the decision, nearly every commit was a Dependabot bump. There was no feature work and no fixes for user-reported issues, and 137 issues were open.

*Inference, not on record:* with an unfixable outside dependency and no owner with time for it, ending support was the responsible choice. Shipping a plugin that can only answer "up to PHP 8.0" while presenting itself as current would have been worse.

## What's different now

### 1. Tide is gone

The plugin now ships its own scanner and runs it in the admin's browser:

- **The checker:** [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) on [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer), with the WordPress ruleset [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP). These are the tools the PHP and WordPress communities already use; WordPress VIP's PHP-upgrade guidance, for example, is built on them. The versions are pinned in `tools/phpcs-bundle/composer.lock`, shipped as a 1.2 MB zip, and printed in every report.
- **Where it runs:** PHP runs in the browser on [WordPress Playground](https://wordpress.org/playground/), loaded from playground.wordpress.net (WordPress.org's own infrastructure). The plugin ships no WebAssembly. The site's server never runs a scan, so shared hosts with limited CPU and disk I/O are unaffected. Avoiding exactly that load is why the plugin moved to Tide in the first place.
- **Where the code comes from:** code from WordPress.org is downloaded by the browser from downloads.wordpress.org, which allows this directly. Premium, custom, and modified code is read from the site in small batches, by administrators who are already allowed to see that code.
- **WP-CLI:** runs the same scanner directly on the server, with no browser and no `exec()`.

| | With Tide (1.6.x) | Now |
|---|---|---|
| PHP versions | 5.6 to 8.0 | 7.4 to 8.5 (WordPress 7.0 requires 7.4) |
| Premium and custom code | Not checked | Checked |
| Depends on | An unmaintained outside service | Pinned open-source tools in the plugin; Playground and downloads.wordpress.org from WordPress.org |
| Service for WP Engine to run | None (Tide was free) | None |
| Wrong results from Tide's own bugs | Possible: unknown versions were scanned as the latest release; custom plugins sharing a WordPress.org name got that plugin's results; large plugins could silently lose a PHP version | Not reproduced: exact versions only, and every version accounted for |

On a test site with nine plugins and themes, a first scan takes about a minute and later scans take about 4 seconds, because results for unchanged WordPress.org code are reused. Three items that Tide reported as "not checked" are now checked. One plugin that Tide passed has a real PHP 7.4 failure (Yoast SEO 28.8 calls `get_debug_type()` without the polyfill it needs).

### 2. PHP's security picture changed

- **PHP2Shell** (CVE-2026-109618 and CVE-2026-109619, October 2026) lets attackers who aren't logged in run code on the server. It affects every PHP version from 5.4 to 8.5.11, is in CISA's catalog of actively exploited vulnerabilities, and [CISA advises](https://www.cisa.gov/news-events/alerts/2026/10/21/critical-vulnerabilities-php-require-immediate-patching) taking PHP 8.1-and-older systems offline until they're patched or upgraded. A related flaw, CVE-2026-115560, is still unpatched.
- **Support windows:** PHP 8.1 reached end of life in December 2025, PHP 8.2's security support ends December 31, 2026, and PHP 8.6 is due November 19, 2026.

Many WordPress sites still run PHP 8.1 or older, and the question they all face is "can my plugins run on a supported PHP version?". This plugin answers that question. It now also checks the server's own PHP: it flags PHP2Shell with the exact release that fixes it, and shows the workaround for CVE-2026-115560 when the server's settings are exposed.

### 3. Nothing else fills the gap

A survey of current tools (November 2026) found no free, maintained equivalent:

- **Plugin Check**, which WordPress.org runs on plugin updates, doesn't include PHPCompatibility, and its results aren't published.
- The other plugins found either need `exec()`, which WP Engine, Kinsta, and SiteGround disable, are paid services, or use hand-written checks with a small install base.

At around 200,000 installs, this is WP Engine's third-largest plugin on WordPress.org after ACF and Better Search Replace.

## What maintaining it would take

The parts WP Engine would own are small: the plugin itself, one runner file (`tools/phpcs-bundle/runner.php`), and a pinned list of dependencies. There's no service to run.

| Task | How often | Effort | Automation |
|---|---|---|---|
| Review scanner updates (PHPCompatibility, PHP_CodeSniffer) | When upstream releases | Read the changelog, merge | Dependabot opens the PR; CI runs real scans (`tests/RunnerTest.php`). Stored results are rescanned automatically when the rule set changes. These PRs are never auto-merged. |
| Add a new PHP version | Once a year (8.6 in November 2026) | One constant, test fixtures, readme | None needed |
| Add a critical PHP advisory | Rare (PHP2Shell is the first) | One entry in `Php_Releases::advisories()` plus a test | Sites can also add advisories with a filter |
| Update the Playground client | When it changes | Merge, run one real scan | Dependabot; the e2e tests stub the runtime, so do one manual check |
| "Tested up to" for new WordPress releases | Each WordPress release | Merge | Workflow opens the PR |
| Development dependencies | Weekly | None | Auto-merged when CI passes |
| Issues and support forum | Ongoing | Triage | None |

*Estimate:* a few hours a month, plus a day or two around each PHP release. Most dependency churn is already automated, and the work that remains is the kind that keeps results correct.

CI covers PHP lint (WordPress Coding Standards, PHPCompatibility 7.4+), 48 PHP unit tests (including real scans), 22 JavaScript unit tests, and 19 end-to-end tests across the scan page, reports, WP-CLI, and the Abilities API.

## Risks and open questions

1. **Shipping updates (the main decision needed).** Since 1.6.4, installed sites get updates from `wpe-plugin-updates.wpengine.com` (`Update URI: false`), not from WordPress.org, and WordPress.org shows 1.6.3 with "no longer maintained". A new release needs one of two things:
   - WP Engine publishes it through its own update server, as before. This needs an owner on WP Engine's side.
   - The plugin goes back to updating from WordPress.org. This needs Plugins team coordination and someone with commit access.
2. **PHPCompatibility 10 is in alpha.** It's the only release line with PHP 8.1–8.5 checks, and some 8.3–8.5 changes are still being added upstream. The plugin says which version produced each result, and moving to the stable release when it ships is a routine Dependabot update.
3. **Depending on WordPress.org's Playground and downloads.** These are actively maintained, but if a firewall or browser extension blocks them, the scan page can't scan. There's a subtler case too: Playground's CDN sometimes answers with a bot challenge (seen during testing, after many automated scans from one network), and a hidden iframe can't complete it. The scan page then stops after 45 seconds and says what to do. WP-CLI is unaffected, and the `wpephpcompat_playground_remote_url` filter allows a self-hosted Playground. Before release:
   - The Playground and WordPress.org systems teams should confirm that embedding `remote.html` from a plugin with around 200,000 installs is supported, and how their bot protection treats embedded loads.
   - The WordPress.org Plugins team should confirm that loading playground.wordpress.net in an iframe is within the plugin guidelines.
4. **Ownership.** Someone needs to review and merge, and to be listed in `CODEOWNERS`. Day-to-day upkeep is mostly automated (see above).
5. **PHPCompatibility is static analysis.** It can't see code that never runs, or problems that only appear at runtime. That was true with Tide as well. The plugin says so, and marks problems found only in bundled libraries.

## Options

1. **Lift end of support under WP Engine.** Unarchive, ship this branch as 1.7.0 through the existing update channel, and name an owner. This keeps the plugin, its install base, and its reviews under WP Engine's name. *Recommended.*
2. **Transfer it to the WordPress community.** Move the plugin to a WordPress.org or community home with WP Engine credited. This is a good longer-term path, and it's easier now that the plugin doesn't depend on any service.
3. **Keep it archived.** The July decision stands. This work stays available as a public fork, and the WordPress.org listing keeps saying "no longer maintained".

## Respecting the original decision

This proposal doesn't argue the July decision was wrong. It was made under a constraint, a plugin that depended entirely on a frozen outside service, and that constraint no longer exists. The end-of-support work wasn't wasted either: the notice told users the truth at the time, and it should stay in place until there's a supported release to replace it.

If the answer is still no, nothing here needs to change upstream.

## Evidence

- Branch: [`feature/wasm-scanner`](https://github.com/josephfusco/phpcompat/tree/feature/wasm-scanner) on the maintained fork.
- How it works, limitations, and a code map: [README](../README.md).
- What the plugin relied on from Tide, and how it's replaced: the Tide request in `plugin/lib/class-scanner.php` and `src/js/include/queue-manager.js` (1.6.x) is replaced by `src/js/include/scanner.js`, `tools/phpcs-bundle/runner.php`, `plugin/lib/class-results.php`, and `plugin/lib/class-rest-controller.php`.
- Server PHP status and advisories: `plugin/lib/class-php-releases.php`.
