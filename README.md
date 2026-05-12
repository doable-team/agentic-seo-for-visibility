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

All endpoints send `Cache-Control: private, no-store` so page-cache plugins (LiteSpeed, WP Rocket, Cloudflare) can't serve stale unauthenticated bodies.

## Local dev

To point the plugin at a non-prod backend:

```php
add_filter('visibility_api_base_url', function () {
  return 'http://localhost:3100';
});
```

Put that in a mu-plugin or your wp-config.
