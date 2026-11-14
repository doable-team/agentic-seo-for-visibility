# Plugin checks

End-to-end shell checks run against local Docker WordPress sites (not shipped
in the plugin zip). Each site exposes the plugin with a fixed test token:

| Site | URL | Token | Setup |
|---|---|---|---|
| 1 | http://localhost:8181 | `vis-test-token-1` | Twenty Twenty-Five, Gutenberg, no SEO plugin |
| 2 | http://localhost:8182 | `vis-test-token-2` | Twenty Twenty-One + Classic Editor + Yoast SEO |
| 3 | http://localhost:8183 | `vis-test-token-3` | Twenty Twenty-Five + Rank Math |
| 4 | http://localhost:8184 | `vis-test-token-4` | Twenty Twenty-Five + All in One SEO |
| 5 | http://localhost:8185 | `vis-test-token-5` | PHP 7.4 + WordPress 6.0, no SEO plugin |
| 6 | http://localhost:8186 | `vis-test-token-6` | Astra + Elementor + Rank Math (T3) |
| 7 | http://localhost:8187 | `vis-test-token-7` | ACF + The SEO Framework + Meta Box + Pods (T6), Astra/GeneratePress/Kadence/OceanWP installed |
| 8 | http://localhost:8188 | `vis-test-token-8` | Twenty Twenty-Five + SEOPress |
| 9 | http://localhost:8189 | `vis-test-token-9` | Twenty Twenty-Five + Slim SEO |
| 10 | http://localhost:8190 | `vis-test-token-10` | Secure Custom Fields (repeater) |
| 11 | http://localhost:8191 | `vis-test-token-11` | Astra, GeneratePress, Kadence, OceanWP installed (theme options) |

Sites are created with `/home/tulasinath/viswp/site.sh <n> <php> [wp-version]`
(MariaDB container `viswp-db`, WP admin `admin`/`admin`); `sync.sh` copies the
working plugin into every running site.

Most single-site checks take `SITE=N` (default 1); `t3-schema.sh` and
`t-seo-adapters.sh` take `SITES="1 2"`.

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
- `t-seo-adapters.sh` + `head-check.py` — seo_meta.update on no-plugin, Yoast, Rank Math, AIOSEO sites: each tag once in the rendered head, before/after, unsupportedFields, clearing, category SEO (#287, #311–#313).
- `t-seopress-standdown.sh` — on a SEOPress site our head prints nothing, fields land in SEOPress's keys, one title/description on the page, rollback (#282, #314). `t-seo-adapters.sh` and `t3-schema.sh` take `SITES=8` too.
- `fixtures/og-1200x630.png` — test image (Rank Math only prints images of at least 200x200 from the media library).
- `t-media.sh` — POST /media: alt, title, caption, readable file name, attached to the post, one upload per source URL, GET /media?id= (#310 plugin part).
- `t2-classic.sh` — Classic Editor edit keeps shortcodes, wpautop paragraphs and embeds; `post.edit_parts` on classic paths (T2, #285).
- `t14-rollback.sh` — every write returns `changeId`; rollback restores content and SEO (front end too), a created draft is trashed, rollback after a manual edit is a 409 conflict (T14, #291). `SITES="1 2 4"`.
- `t-cache-purge.sh` — only the changed page's cache is purged and `cachePurged` lists it (WP Rocket stub with a purge log); `[]` without a cache plugin (#293).
- `t-author.sh` — new drafts get the default author (admin, then the user picked in the settings dropdown), edits keep the author, a deleted user falls back to the first administrator with a notice (#275).
- `t13-owner-controls.sh` — controls pushed from Visibility (actions, content types, locked pages) enforced on actions and REST; Visibility's own drafts count as create_drafts; the WordPress Pause button refuses every write, sends a heartbeat at once and is not cleared by `disabled:false`; `controls:null` resets (T13, #295).
- `t-fields-meta.sh` — `/fields/schema`, registered meta read and written only when allowlisted and edit_fields is on, denied/unknown keys and bad values refused, rollback (#288).
- `t-builder-shared.sh` — a builder page reports `builder:<slug>`/writable; without a writing adapter edit_parts is refused as unsupported and post_content untouched (#286).
- `t-block-plugins.sh` — edits inside Spectra / Kadence Blocks / GenerateBlocks blocks: only the target text/alt changes, block attributes kept, the rendered page identical apart from the edit, rollback (#317–#319).
- `t3-elementor.sh` — Elementor widget edits by elementId (heading text/level, image alt, button link): front end, CSS regenerated, page opens in the Elementor editor, other widgets untouched, rollback, Rank Math schema once (T3, #320). Site 6.
- `t6-fields.sh` — ACF fields listed with types, read, updated by field key only when allowlisted, refusals (outside list, token key, bad choice/URL, all-or-nothing), rollback restores them; `SOURCES="acf metabox pods"` adds Meta Box / Pods (T6, #321–#323). Site 7 + `mu/vis-fields-fixture.php`.
- `t6-repeater.sh` — repeater rows (typed sub fields, row image) and an image field updated by key, bad rows refused, rollback (T6, #321). Site 10: Secure Custom Fields (ACF's free fork with repeater, same API as ACF Pro).
- `t-theme-options.sh` — theme page options listed per theme, refused until allowlisted, value checked against the theme's choices, front end changes, rollback (#324–#327). Site 11, `THEMES="…"`.
- `t9-cache.sh` — with a real cache plugin caching pages (browser Accept header), a change shows on the live page at once, another cached page stays cached, `cachePurged` names the cache (T9, #328–#333). `SITE=N CACHE="WP Super Cache"`. Sites are created per cache plugin and removed after the row.
- `t9-litespeed.sh` — LiteSpeed Cache: the X-LiteSpeed-Purge header tags only the changed post, never `*` (#330).
- `t9-cloudflare.sh` + `cf-mock.py` — Cloudflare plugin connected (token, zone, APO) against a mocked Cloudflare API: only the changed page's URLs are purged in the site's zone, never everything; a Cloudflare error is not reported as purged; not connected = not listed (#333).
- `t1-block-edit.sh` — `post.edit_parts` changes only the target block (text, heading level, alt, href, insert/remove), block template reported, slug-change rules (T1, T11, #285).
