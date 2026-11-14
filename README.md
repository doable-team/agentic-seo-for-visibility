# Visibility WordPress plugin

PHP plugin that pairs a WordPress site with a Visibility project using a short pairing code. Once paired, Visibility agents publish + update posts via REST endpoints the plugin exposes under `/wp-json/visibility/v1/*`.

## Layout

```
wordpress-plugin/
├── visibility/                       ← shipped in the .zip distributed to users
│   ├── visibility.php
│   ├── readme.txt
│   ├── assets/
│   │   └── icon.svg                  ← shown on the Settings → Visibility page
│   └── includes/
│       ├── class-visibility-client.php
│       ├── class-visibility-settings.php
│       └── class-visibility-rest.php
└── assets/                           ← NOT shipped; lives on the wordpress.org listing
    ├── icon.svg                      ← preferred (vector, used everywhere)
    ├── icon-128x128.png              ← required (listing thumbnail)
    ├── icon-256x256.png              ← required (retina listing thumbnail)
    ├── banner-772x250.png            ← required (plugin page header)
    ├── banner-1544x500.png           ← required (retina plugin page header)
    ├── banner.svg                    ← source for the banners (not uploaded)
    └── screenshot-1.png              ← shown in the Screenshots tab
```

## Packaging for users / wp.org

```bash
cd wordpress-plugin
zip -r visibility.zip visibility -x '*.DS_Store'
```

Only the `visibility/` directory goes into the zip. The wordpress.org-specific assets in the repo root are uploaded separately (see below).

## Submitting to wordpress.org

1. **Reserve the slug.** Apply at https://wordpress.org/plugins/developers/add/ with the plugin slug `visibility` and `readme.txt` + the latest zip attached. Approval is manual (a few business days).
2. **Once approved**, you'll get SVN access at `https://plugins.svn.wordpress.org/visibility/`. The repo has three top-level directories:
   ```
   trunk/        ← plugin code (the contents of visibility/ in this repo)
   tags/         ← one folder per release, e.g. tags/0.4.0/
   assets/       ← icon + banner + screenshots (from our root-level assets/)
   ```
3. **First commit** to SVN:
   ```bash
   svn co https://plugins.svn.wordpress.org/visibility visibility-svn
   cp -r wordpress-plugin/visibility/* visibility-svn/trunk/
   cp wordpress-plugin/assets/icon*.* wordpress-plugin/assets/banner-*.png wordpress-plugin/assets/screenshot-*.png visibility-svn/assets/
   cd visibility-svn
   svn add --force trunk assets
   svn ci -m "v0.4.0 — initial release"
   ```
4. **Each subsequent release**:
   ```bash
   # Sync new code to trunk
   cp -r wordpress-plugin/visibility/* visibility-svn/trunk/
   # Tag it
   svn cp trunk tags/0.5.0
   # Bump the Stable Tag in trunk/readme.txt to 0.5.0, then:
   svn ci -m "v0.5.0"
   ```

The Stable Tag line in `readme.txt` is what users actually receive — it must match a folder in `tags/`.

## Pairing protocol

```
┌─ User (browser) ─┐                  ┌─ Visibility API ─┐
│  generates code  │ ───────────────▶ │  /pairing-code   │
└──────┬───────────┘                  └──────────────────┘
       │  shows code                            │ stores code (10 min TTL)
       ▼                                        ▼
┌─ User (WP admin) ┐  POST /api/wordpress/plugin/pair  ┌─ Visibility API ┐
│  pastes code     │ ──────────────────────────────▶  │  validates +    │
│  + plugin posts  │ ◀─────────── siteToken ────────  │  issues token   │
└──────────────────┘                                  └─────────────────┘
```

After pairing, both sides authenticate with `Authorization: Bearer <siteToken>`:
- **Plugin → Visibility**: heartbeat, last-seen check-in
- **Visibility → Plugin**: list/create/update/delete posts, taxonomy, media via `/wp-json/visibility/v1/*`

## Plugin endpoints (auth: `Authorization: Bearer <siteToken>`)

| Method | Route | Purpose |
|---|---|---|
| `GET`    | `/wp-json/visibility/v1/health` | Site info + post counts + plugin version |
| `GET`    | `/wp-json/visibility/v1/posts` | List with `?search=`, `?status=`, `?page=`, `?per_page=` |
| `POST`   | `/wp-json/visibility/v1/posts` | Create (title, content, excerpt, status, slug, categories[], tags[], featured_media, date) |
| `GET`    | `/wp-json/visibility/v1/posts/{id}` | One post (incl. content + taxonomy + featuredMedia) |
| `PATCH`  | `/wp-json/visibility/v1/posts/{id}` | Update (same body shape as create) |
| `DELETE` | `/wp-json/visibility/v1/posts/{id}` | Trash (`?force=true` to permanently delete) |
| `GET`    | `/wp-json/visibility/v1/categories` | List with `?search=` |
| `POST`   | `/wp-json/visibility/v1/categories` | Create `{ name, slug?, description? }` |
| `GET`    | `/wp-json/visibility/v1/tags` | List with `?search=` |
| `POST`   | `/wp-json/visibility/v1/tags` | Create `{ name, slug?, description? }` |
| `GET`    | `/wp-json/visibility/v1/media` | List with `?search=` |
| `POST`   | `/wp-json/visibility/v1/media` | Upload from `{ source_url, title?, alt_text? }` |
| `GET`    | `/wp-json/visibility/v1/capabilities` | What the site has and what agents may do there |
| `GET`    | `/wp-json/visibility/v1/fields/schema` | Custom fields of a post type (`?type=`) or post (`?postId=`) |
| `GET`    | `/wp-json/visibility/v1/changes` | Change log (`?postId=`) |
| `GET`    | `/wp-json/visibility/v1/changes/{id}` | One change with before/after |
| `POST`   | `/wp-json/visibility/v1/changes/{id}/rollback` | Undo a change (409 when the post changed since) |

All endpoints send `Cache-Control: private, no-store` so page-cache plugins (LiteSpeed, WP Rocket, Cloudflare) can't serve stale unauthenticated bodies.

## Changelog (developer notes)

### 0.11.0 (not released yet)

Phase 2 of the WordPress roadmap (doable-team/Visibility-V2#265). Every 0.10.0
endpoint and response shape is unchanged; everything below is additive, and
`GET /capabilities` `features` lists each new ability so the app can gate on it.

| Issue | Note |
|---|---|
| #285 | `post.edit_parts` / `page.edit_parts`: change one block (Gutenberg path) or element (classic `p.3`, `h2.0`, `img.0`) with `replace_text` (+`find`, `selector`), `set_attr` (alt, href, level), `replace_block`, `insert_before/after`, `remove`; everything else byte-identical; `diff` in the result. Block-theme template reported (`blockTemplate`). Published page slug change needs a redirect plugin. |
| #291 | Snapshots in `{prefix}visibility_changes`; every write returns `changeId`; `POST /changes/{id}/rollback` (409 `visibility_conflict` when the post changed since), `change.rollback` action, `GET /changes`, `GET /changes/{id}`; WP admin › Agentic SEO › Activity with Roll back. |
| #293 | `Visibility_Cache`: purge only the changed page; `cachePurged: [names]` on every write; `/capabilities` `cachePurge`. |
| #275 | Default author for content from Visibility (settings dropdown; connecting admin, else first admin); `/capabilities` `defaultAuthor`. |
| #295 | Owner controls pushed by Visibility (`controls: {actions, postTypes, lockedPostIds, fieldAllowlist}`, `null` resets) enforced on every action and REST write (`not_allowed`, `locked`); WordPress Pause button (own flag, immediate heartbeat with `paused`/`pausedBy`); `specialPages`, `createdByVisibility`. |
| #288 | Custom fields: `GET /fields/schema`, `fields` on GET /posts with values, `fields.update` for allowlisted keys only, typed validation, denied keys. |
| #286 | Page-builder adapter layer (`visibility_builder_adapters`); `elements` on read, `target.elementId` edits. |
| #314 | SEOPress adapter (post + term fields, schema in its graph). |
| #315 | The SEO Framework adapter (through its Data API). |
| #316 | Slim SEO adapter (`slim_seo` array). |
| #317–#319 | Spectra, Kadence Blocks, GenerateBlocks checked with the block editor (`selector`, GenerateBlocks `htmlAttributes`). |
| #320 | Elementor adapter: widget edits saved through `Document::save` (CSS rebuilt, still opens in Elementor). |
| #321 | ACF (free/Pro): `acf:<field key>` incl. group and repeater. |
| #322 | Meta Box: `metabox:<id>` via `rwmb_set_meta`. |
| #323 | Pods: `pods:<name>` via `pods()->save()`. |
| #324–#327 | Astra, GeneratePress, Kadence, OceanWP page options as `theme:<theme>:<key>`, writable only when allowlisted. |
| #328–#332 | WP Super Cache, W3 Total Cache, LiteSpeed Cache, WP Fastest Cache, SiteGround Speed Optimizer purgers. |
| #333 | Cloudflare plugin/APO purge through its own client, when connected. |
| #334 | Polylang: `language`/`translations`, `?lang=`, `language_required`/`language_mismatch`, `language` + `translationOf` on create. |

Checks for each are in `tests/` (see `tests/README.md`).

## Local dev

To point the plugin at a non-prod backend:

```php
add_filter('visibility_api_base_url', function () {
  return 'http://localhost:3100';
});
```

Put that in a mu-plugin or your wp-config.
