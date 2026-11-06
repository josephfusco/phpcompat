# Reading results across many sites

`wp php-compat readiness` answers "can this site move to a newer PHP, and what's in the way?" for the active plugins and theme. It only reads stored results (from the scan page or `wp php-compat scan`) and never scans, so it's quick and safe to run on every site in a fleet. The output is one self-contained document per site with no site URL and no file contents: whoever runs it already knows which site it is.

```bash
wp php-compat readiness                 # Summary table
wp php-compat readiness --format=json   # The full document
```

```json
{
  "schema_version": 1,
  "generated_at": "2026-11-06T13:25:23+00:00",
  "plugin_version": "2.0.0",
  "source": "PHPCompatibilityWP (phpcs-4.0.4+phpcompatibility-10.0.0-alpha2+wp-3.0.0-alpha2)",
  "environment": { "php": "8.3.35", "wordpress": "7.1.4", "multisite": false },
  "scope": "active",
  "summary": { "passes": 6, "problems": 4, "not_checked": 0, "not_scanned": 0, "failed": 0 },
  "max_php_checked": "8.5",
  "complete": true,
  "ready_for": [],
  "next_ready": "",
  "next_blocked": "8.4",
  "blockers": {
    "8.4": [
      { "type": "plugin", "slug": "broken-link-checker", "name": "Broken Link Checker", "version": "2.4.15" },
      { "type": "plugin", "slug": "wp-optimize", "name": "WP-Optimize - Clean, Compress, Cache", "version": "4.7.0" }
    ]
  },
  "unknown": [],
  "items": [
    { "type": "plugin", "slug": "akismet", "name": "Akismet Anti-spam: Spam Protection", "version": "5.7.2", "source": "wporg", "requires_php": "7.2", "status": "passes", "failing_versions": [], "needs_php": null, "scanned_at": "2026-11-06T13:03:47+00:00" }
  ]
}
```

(`blockers` and `items` are shortened here.)

| Field | Meaning |
| --- | --- |
| `complete` | Every active plugin and theme has results. When it's `false`, the ones without are in `unknown` (with their `status`) and the readiness fields only cover the rest. |
| `ready_for` | Checked PHP versions on which no active item fails and none requires a newer PHP, oldest first. |
| `next_ready` | The newest version above the server's PHP that the site can reach without crossing a blocked one. Empty if the very next version is blocked, or the server is already on the newest version checked. |
| `next_blocked` | The first version above the server's PHP that isn't ready. Empty if none is. |
| `blockers` | Version => the items that fail on it, or that require a newer PHP than it. Every checked version is listed, including those below the server's PHP. |
| `items` | Each active item's status, failing versions, `needs_php` (when its code needs a newer PHP than its header says), and when it was scanned. |
| `source`, `max_php_checked` | The scanner and rule set behind the results, and the newest PHP version they cover. Results are only as current as the rule set. |

`schema_version` goes up when a field is removed or changes meaning. New fields can be added without a bump, so consumers should ignore fields they don't know. The logic lives in `Scanner::readiness()` and is mirrored in JavaScript; both are tested against `tests/fixtures/readiness-cases.json`.

On WP Engine, the scan page's sidebar shows the same answer after each scan ("Ready for PHP 8.4", or "Before PHP 8.4: update or replace …"), next to the link for previewing PHP in the User Portal.
