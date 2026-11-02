# PHP Compatibility Checker: what's changed since end of support

**Status:** for discussion · **Date:** November 2026

WP Engine archived PHP Compatibility Checker in July 2026 ([wpengine/phpcompat#431](https://github.com/wpengine/phpcompat/pull/431)). That was the right call: every result came from WP Tide, which stops at PHP 8.0 and is no longer maintained, and the plugin couldn't fix that from the inside. This note records what has changed since, in case the decision is ever revisited. The July decision stands.

## What changed

- **No Tide.** The plugin ships the standard checker ([PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) on [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer)) and runs it on the site, so it depends on no outside service.
- **Built for shared hosting.** Scans run as short, paced requests, one at a time, and back off when the host is busy. Unchanged plugins aren't rescanned.
- **PHP 7.4 through 8.5**, instead of stopping at 8.0, including premium and custom code, which Tide never checked.
- **Lower upkeep.** Development dependency updates merge automatically once CI passes, and CI runs WordPress.org's Plugin Check. Only scanner updates need a person.

Try it in one click: [2.0.0 beta in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/josephfusco/phpcompat/main/blueprints/beta.json).

## What maintaining it would take

My estimate, not yet measured: a few hours a month to review scanner updates and triage issues, plus a day or two each year to add a new PHP version. There's no service to run. The headless-oss team would maintain it.

"Support" here means releases (security fixes, new PHP versions, WordPress compatibility) and answering the WordPress.org forum and GitHub issues. It doesn't add work for WP Engine Support.

## Open questions

- **Upstream.** PHPCompatibility 10 is in alpha and maintained by volunteers. If it stalls, the plugin keeps working with older rules rather than stopping, but new PHP versions wait on it.
- **Shared-hosting proof.** Tested locally and in Playground so far. A run on a small shared plan would confirm the load.
- **Sites on 1.6.4.** They update from WP Engine's update server, which would need one entry to move them to WordPress.org.
- **Legal and security.** The bundled licenses (LGPL-3.0, BSD-3) need a check, and the new REST endpoints haven't had a formal security review.

## Options

1. Keep it archived. Nothing changes; this work stays available as a public fork.
2. Lift end of support and release 2.0.0 on WordPress.org.
3. Transfer the plugin to a community home, with WP Engine credited.

Details: [README](../README.md) (how it works, limitations, code map).
