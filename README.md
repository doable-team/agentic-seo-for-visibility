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

After pairing, both sides authenticate with `Authorization: Bearer <siteToken>`. From 2.0.0 Visibility also signs every request to the plugin (signing v1, see `includes/class-visibility-auth.php`):
`X-Visibility-Timestamp`, `X-Visibility-Nonce`, `X-Visibility-Signature: v1=hex(HMAC-SHA256(token, "v1\nMETHOD\nROUTE\nSORTED_QUERY\nTIMESTAMP\nNONCE\nsha256(body)"))`, 300 s window, each nonce once. Bearer-only keeps working until signatures are required (`/auth/rotate {requireSignature:true}` or the WP admin switch). `tests/sign.py` is a reference signer.
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

### 2.0.0 (not released yet)

The parked "Later" items of the WordPress roadmap (doable-team/Visibility-V2#265).
Every earlier endpoint and response shape is unchanged; new abilities are in
`GET /capabilities` `features`: `redirects.manage`, `redirects.auto`,
`cron.status`, `auth.signed`, `auth.rotate`, `media.bulk_alt`,
`builder.beaver`, `builder.read`, `seo.theme`, `multilingual.translatepress`,
`fields.carbon`.

| Issue | Note |
|---|---|
| #292 (+ #270, #271) | `Visibility_Redirects`: one layer over the redirect plugins and a built-in store (option `visibility_redirects`, served on 404s only). `GET /redirects` items `{id, source, target, type, enabled, regex, provider, group?}`; `redirect.create {source, target, type?, group?}` → `{redirectId, source, target, type, provider, redirect, changeId, loopWarning?}`; `redirect.delete {redirectId}`; conflict / loop / source==target refused; rollback through `/changes/{id}/rollback` (custom change rows, `postId` 0). A published item whose slug changes gets a 301 (`redirectAdded` / `redirectSkipped` / `redirectRemoved`), undone with the change; the old "refused without a redirect plugin" rule is gone. `capabilities.redirectStore`; `capabilities.redirects` keeps its 0.10 shape. |
| C-31 #387 | Redirection: group by id/name, default "Visibility" group found or created; `groups` listed. |
| C-32 #375 | Rank Math: `\RankMath\Redirections\DB`, only while its Redirections module is on. |
| C-33 #376 | Safe Redirect Manager: `srm_create_redirect()`, `redirect_rule` posts. |
| C-34 #377 | Yoast SEO Premium: `WPSEO_Redirect_Manager` / `WPSEO_Redirect` (plain). |
| C-35 #378 | AIOSEO Pro Redirection Manager: its `Models\Redirect`, `aioseo_redirects`, `server->rewrite()`. |
| C-36 #379 | SEOPress Pro: `seopress_404` posts with `_seopress_redirections_*`. |
| #280 | Every authenticated call runs the backup inbox check at the end of the request when it is >10 min late; `capabilities.cron` `lastTickAt`, `lastTickVia`, `systemCron`; Connection screen shows the last check; readme FAQ on a real cron job. |
| #296 | Signing v1 + `/auth/rotate` (pending token, promoted on first use, plugin heartbeats with it); WP admin "Only accept signed requests"; `capabilities.auth`. |
| #297 | `GET /media?missingAlt=1`; `media.bulk_alt {items:[{mediaId, altText, title?, caption?}]}` (≤50) updates the library and the `wp-image-ID` images in pages (page-specific alt kept, locked pages skipped); one change, one rollback. |
| C-37 #380 | Beaver Builder: modules by node id through `FLBuilderModel::update_layout_data()`, asset cache cleared, post_content re-rendered; unpublished builder drafts and global modules refused. |
| C-38–C-40 #381–#383 | Bricks, Oxygen (classic + 6), Breakdance: detected, elements read (`builder.readable`), edits refused "can only read". |
| C-41 #384 | Genesis / Divi / Avada SEO fields when no SEO plugin (`seo.plugin` = theme, `seo.theme`, `seo.themePrints`); Visibility prints only what the theme does not. |
| C-42 #385 | TranslatePress read-only: `language` = default, `translations` `[{language, locale, url}]`, other languages `language_unsupported`. |
| C-43 #386 | Carbon Fields `carbon:<name>` through `carbon_get_post_meta` / `carbon_set_post_meta`. |

#### What each 2.0.0 adapter was built from

| Adapter | Built from | Tested against |
|---|---|---|
| Redirection | wordpress.org source (`Red_Item`, `Red_Group`) | real Redirection 5.10.1 — `t10-redirects.sh EXPECT=redirection` |
| Rank Math redirections | wordpress.org source (`RankMath\Redirections\DB`, `Helper::is_module_active`) | real Rank Math — `EXPECT=rankmath` |
| Safe Redirect Manager | wordpress.org source (`srm_create_redirect`, `redirect_rule`) | real 2.3.0 — `EXPECT=srm` |
| Yoast SEO Premium redirects | Premium's redirect classes and `wp yoast redirect` commands (public copies), developer.yoast.com redirect option docs | stand-in `tests/standins/yoast-premium-redirects-standin.php` — `EXPECT=yoast` |
| AIOSEO Pro redirects | the Redirection Manager add-on's public code (model, table, REST create/delete) | stand-in `aioseo-redirects-standin.php` — `EXPECT=aioseo` |
| SEOPress Pro redirects | seopress.org redirections guide + free SEOPress's `_seopress_redirections_*` keys | stand-in `seopress-pro-redirects-standin.php` — `EXPECT=seopress` |
| Beaver Builder | wordpress.org Beaver Builder Lite source (`FLBuilderModel`) | real Lite 2.11 — `t-beaver.sh` |
| Bricks / Oxygen / Breakdance | their documented storage (`_bricks_page_content_2`, `ct_builder_json`, `_breakdance_data.tree_json_string`) | stand-in `readonly-builders-standin.php` (constants only) — `t-readonly-builders.sh` |
| Divi SEO | Divi 4.21 `epanel/custom_functions.php` | real Divi 4.21 (local copy, not shipped) — `t-theme-seo.sh THEME=divi` |
| Genesis / Avada SEO | Genesis `_genesis_*` keys; Avada `class-avada-head.php`, `Fusion_Data_PostMeta` | stand-in themes `standins/themes/` — `THEME=genesis|avada` |
| TranslatePress | wordpress.org source (`trp_settings`, `url_converter`) | real 3.3.9 — `t-translatepress.sh` |
| Carbon Fields | github.com/htmlburger/carbon-fields 3.6.11 | real library — `t-carbon.sh` |

Plugin Check (`wp plugin check agentic-seo-visibility`): no errors or warnings.

### 0.12.0 (not released yet)

Phase 3 of the WordPress roadmap (doable-team/Visibility-V2#265): paid plugins
and WooCommerce. Everything in 0.11.0 is unchanged; new abilities are listed in
`GET /capabilities` `features` (`woocommerce.products`, `terms.update`,
`multilingual.wpml`, `builder.divi`, `builder.wpbakery`, `fields.jetengine`,
`cache.host`).

| Issue | Note |
|---|---|
| #289 | WooCommerce: `product` on GET /posts/{id} (name, descriptions, slug, images with alt, categories; price/SKU/stock read-only); `product.update {productId, fields:{name, description, shortDescription, slug}}` saved with `wc_get_product()->set_*()->save()`; `term.update {termId, taxonomy: product_cat|product_tag|category|post_tag, fields:{name, slug, description}}`; `GET /terms?taxonomy=` and `GET /terms/{id}` (with SEO); product + product_cat SEO through the SEO adapters. Price, sale price, SKU, stock, tax, shipping, dimensions, attributes and variations are refused (`not_allowed`) on every write path, nothing written. |
| #290 / #340 | Multilingual providers: Polylang (0.11.0) and WPML. Same `language`/`translations`, `?lang=`, `language_required`/`language_mismatch`, `language` + `translationOf` on create. WPML without the hooks below → `multilingual.writable: false` and creating in a language is refused. |
| #335 | Divi 4 (shortcodes): `elements` with ids like `et_pb_text-0`; `replace_text` (inner text or `field` title/button_text/…) and `set_attr` (alt, title, href); attribute values stored in Divi's `%22/%91/%93` form; the page is re-parsed and must keep every module and every other byte, else nothing is saved. Divi 5 block layouts: not writable. |
| #336 | WPBakery: same, `vc_*` modules; image alt in the media library (`vc_single_image` keeps an id); links in `url:…|title:…` form; quotes/brackets refused in attributes. |
| #337 | JetEngine: `jetengine:<field name>` from its meta boxes; values as JetEngine stores them (plain meta, switcher "true"/"false", media id). |
| #338 | WP Rocket: `rocket_clean_post()`; counted as purged only when it ran for the post (its `after_rocket_clean_post`). |
| #339 | Kinsta: `$kinsta_cache->kinsta_cache_purge->initiate_purge()`, purged when the cache server answered 2xx; WP Engine: `WpeCommon::purge_varnish_cache($post_id)`, purged when it returned true. |

#### What each adapter was built from

| Adapter | Built from | Tested against |
|---|---|---|
| WooCommerce | WooCommerce CRUD (`WC_Product` getters/setters, `save()`), wordpress.org source | real WooCommerce 11.3 (+ Yoast) — `t7-woocommerce.sh` |
| WPML | WPML Hooks Reference on wpml.org: `wpml_active_languages`, `wpml_default_language`, `wpml_element_language_details`, `wpml_get_element_translations`, `wpml_object_id`, `wpml_is_translated_post_type`, `wpml_set_element_language_details`, `wpml_switch_language` (scope closed with `null`) | real WPML Multilingual CMS 4.8.0 + String Translation 3.4.0 + WooCommerce Multilingual 5.6.3 (local copies, not shipped) — `t8-wpml.sh` (`WCML=1`) |
| Polylang Pro / Polylang for WooCommerce | same Polylang API as 0.11.0 | real Polylang Pro 3.3 + Polylang for WooCommerce 1.7 — `t8-polylang.sh` (`PLLWC=1`) |
| Divi | Elegant Themes' module shortcodes as Divi 4 saves them (`et_pb_*`, `_et_pb_use_builder`, `ET_BUILDER_VERSION`, `ET_Core_PageResource::remove_static_resources`) | real Divi theme 4.21.0 (local copy, not shipped) + AIOSEO — `t4-divi.sh` |
| WPBakery | WPBakery developer docs (`vc_map` parameters, `WPB_VC_VERSION`, `_wpb_vc_js_status`, `vc_link` format) | stand-in `tests/standins/wpbakery-standin.php` (not the real plugin) — `t5-wpbakery.sh` |
| JetEngine | JetEngine meta box config (`jet_engine_meta_boxes` option, `jet_engine()->meta_boxes->get_meta_fields_for_object()`), field storage as public JetEngine source shows it | stand-in `tests/standins/jetengine-standin.php` — `t6-jetengine.sh` |
| WP Rocket | public source github.com/wp-media/wp-rocket (`inc/common/purge.php`: `rocket_clean_post`, its hooks) | stand-in `tests/standins/wp-rocket-standin.php` — `t9-paid-caches.sh`, `t-cache-purge.sh`, `t7-woocommerce.sh` |
| Kinsta | Kinsta must-use plugin (`cache/class-cache-purge.php`: `initiate_purge`, `KINSTAMU_VERSION`) as used by WP Rocket's Kinsta integration | stand-in `tests/standins/kinsta-standin.php` — `t9-paid-caches.sh` |
| WP Engine | WP Engine must-use plugin (`WpeCommon::purge_varnish_cache($post_id)`) as used by WP Rocket's WP Engine integration | stand-in `tests/standins/wpengine-standin.php` — `t9-paid-caches.sh` |

Stand-ins live in `tests/standins/` and are never part of the plugin zip.

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
