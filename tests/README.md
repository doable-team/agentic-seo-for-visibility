# Plugin checks

End-to-end shell checks run against local Docker WordPress sites (not shipped
in the plugin zip). Each site exposes the plugin with a fixed test token:

| Site | URL | Token | Setup |
|---|---|---|---|
| 1 | http://localhost:8091 | `vis-test-token-1` | Twenty Twenty-Five, Gutenberg, no SEO plugin |
| 2 | http://localhost:8092 | `vis-test-token-2` | Twenty Twenty-One + Classic Editor + Yoast SEO |
| 3 | http://localhost:8093 | `vis-test-token-3` | Twenty Twenty-Five + Rank Math |
| 4 | http://localhost:8094 | `vis-test-token-4` | Twenty Twenty-Five + All in One SEO |

`lib.sh` expects a `wp.sh <site> <wp-cli args>` wrapper (path in `WP`) that
runs WP-CLI against the site, and the plugin copied into each site.

- `lint.sh` — `php -l` on every plugin file under PHP 7.4 and 8.3.
- `t11-custom-html.sh` — editing one block leaves a Custom HTML block byte-identical (#276).
- `t4-aioseo.sh` — AIOSEO title/description/canonical/robots/OG show in the rendered head (#268).
- `t12-reliability.sh` + `mock-api.py` — an approved request runs once when the report fails, and when push and cron race (#272).
- `t3-schema.sh` + `jsonld-check.py` — injected JSON-LD appears exactly once on no-plugin, Yoast, Rank Math and AIOSEO sites (#267).
- `baseline.sh <ref> <test>` — run a check against an older plugin version (e.g. `5ffda1f` = 0.9.1).
- `t15-internal-links.sh` — internal_link.suggest links a phrase once, not in links/headings/code; 0 links is an error (#266).
- `unit/run.sh` — standalone PHP unit tests (no WordPress) on PHP 7.4 and 8.3.
- `t13-owner-switches.sh` — publishing off / paused are enforced by the plugin (#273).
- `t16-uninstall.sh` — uninstall leaves no visibility_* options, transients or cron events (#281).
- `t-read.sh` — rich read of a page (type, editor, blocks with paths, SEO values + rendered, images), list by type/parent, stale modifiedGmt -> conflict (#284).
- `t-capabilities.sh` — GET /capabilities on an Elementor + Yoast + WP Rocket (detection stub) + LiteSpeed site and the others (#294).
