=== Agentic SEO for Visibility ===
Contributors: rankth
Tags: seo, ai, content, agents, publishing
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to the Visibility service (https://app.visibility.so) so SEO agents in your Visibility project can publish content directly. Pair with a code — no Application Passwords required.

== Description ==

Visibility (https://app.visibility.so) is a SaaS service that runs AI agents to audit websites and ship SEO-optimised content. This plugin is published by the team behind Visibility and connects your WordPress install to your Visibility project so the agents can publish drafts and updates straight to wp-admin — no Application Passwords, no copy-pasting.

How it works:

1. In your Visibility project, click **Connect via plugin** and copy the short code (e.g. VIS-A7K9-XB).
2. Install + activate this plugin, open **Settings → Agentic SEO**, and paste the code.
3. That's it. Your project and this site are paired; the agents can now publish here.

You can disconnect anytime from Settings → Agentic SEO (or from the Visibility dashboard).

A Visibility account is required to use this plugin. See the **External services** section below for details on the network calls the plugin makes.

== Installation ==

1. Upload the `agentic-seo-for-visibility` folder to the `/wp-content/plugins/` directory, or install through the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Settings → Agentic SEO** and follow the pairing instructions.

== Screenshots ==

1. Settings → Agentic SEO, paired state, showing the connected company + project, last-seen heartbeat, and plugin version.

== Frequently Asked Questions ==

= Do I need to create an Application Password? =

No. The plugin uses a shared token issued during pairing.

= Can I use this with multiple Visibility projects? =

Each WordPress site pairs with one Visibility project at a time. Disconnect and re-pair if you need to move it.

= Where is the token stored? =

In WordPress's `wp_options` table under `visibility_site_token`. Disconnecting deletes it.

== External services ==

This plugin connects your WordPress site to **Visibility**, an external SaaS service at https://app.visibility.so. A Visibility account is required. The plugin makes the following outbound HTTPS requests from your site:

1. **POST https://app.visibility.so/api/wordpress/plugin/pair** — sent once when you submit a pairing code on the Settings → Agentic SEO screen. Body includes: the pairing code, your site URL, site name, WordPress version, and the installed plugin version. Response contains a shared site token plus the connected company + project IDs and names so the plugin can display them.

2. **POST https://app.visibility.so/api/wordpress/plugin/heartbeat** — sent at most once per day from WP-Cron while the plugin is paired. Body includes the installed plugin version; the shared site token is sent in the `Authorization: Bearer` header. Used to refresh the "Last seen" timestamp and pick up renames of the connected company or project on Visibility's side.

3. **POST https://app.visibility.so/api/wordpress/plugin/disconnect** — sent when you click Disconnect on the Settings → Agentic SEO screen. Body includes the installed plugin version; the shared site token is sent in the `Authorization: Bearer` header. Tells Visibility to remove your site from the project. Local cleanup proceeds even if Visibility is unreachable.

Inbound requests from Visibility, authenticated with the shared site token, reach the plugin at `/wp-json/visibility/v1/*` and are used by your Visibility project's agents to read, draft, publish, update, or delete posts; manage categories, tags, and media; and check site health. No content leaves your site automatically — content only travels on agent-initiated requests originating from the Visibility project this site is paired to.

Service URL: https://app.visibility.so
Terms of service & privacy policy: linked from the Visibility dashboard footer.

== Changelog ==

= 0.6.1 =
* Security & WP-coding-standards pass on the settings page and REST handler
  ahead of the WordPress.org re-review:
  * Status-redirect query args (`?visibility_status=...&visibility_msg=...`)
    are now nonce-protected, so a crafted URL can no longer inject a spoofed
    admin notice into the Settings page.
  * `wp_die(__(...))` upgraded to `wp_die(esc_html__(...))` on the
    permission-denied paths.
  * URL output in the settings template now escapes at echo time with
    `esc_url()` (variables were already escape-on-assign — this is the
    belt-and-braces "escape late" pattern WP coding standards prefer).
  * Media-upload route: `parse_url()` → `wp_parse_url()` and
    `@unlink($tmp)` → `wp_delete_file($tmp)` (use the WordPress wrappers
    so filter hooks run and the filesystem op doesn't silent-fail).
* `readme.txt` `Tested up to: 6.9`.

= 0.6.0 =
* Rebrand to **Agentic SEO for Visibility** — display name, slug
  (`agentic-seo-for-visibility`), text domain, and Settings submenu
  ("Agentic SEO") updated to follow WordPress.org plugin-directory naming
  guidance for plugins that integrate with a named external service.
  Internal REST namespace, option keys, and cron action names are
  unchanged so pre-release test installs continue to work after update.
* `readme.txt` now includes an **External services** section listing
  every outbound network call the plugin makes (pair, heartbeat,
  disconnect), what is sent, and explaining that a Visibility account is
  required.

= 0.4.3 =
* Icon polish — the bundled brand icon (Settings → Visibility, and the
  wp.org listing assets) now uses a shifted viewBox so the sonar C-shape
  sits visually centred in the square canvas. Previous renders looked
  like the icon was cropped on the right because the dot was at the
  geometric centre but the C's visual mass extends only leftward.

= 0.4.2 =
* Inline paste button in the pairing-code input — one click pulls the code
  from your clipboard so you don't have to right-click → paste. Falls back
  to focusing the input when the browser blocks clipboard reads.

= 0.4.1 =
* Disconnect now actually disconnects on both sides. The Disconnect button
  on Settings → Visibility used to wipe the local options only, leaving the
  Visibility project still marked "Connected via plugin" until the next
  failed publish reverified it. The plugin now calls a new server endpoint
  (POST /api/wordpress/plugin/disconnect) before wiping local state, so
  Visibility removes the project's site row immediately. Local teardown
  still completes even if Visibility is unreachable.

= 0.4.0 =
* Expanded REST surface — agents can now manage everything end-to-end through
  the plugin instead of hitting wp-json directly:
  - GET /posts (list with search, status filter, pagination)
  - DELETE /posts/{id} (trash or force-delete)
  - GET /categories + POST /categories
  - GET /tags + POST /tags
  - GET /media + POST /media (upload from a remote source_url)
  - Post create/update now accept categories[], tags[], featured_media, date
* Post responses now include categories[], tags[], featured media, full content
  + excerpt — no need to call out to wp-json/wp/v2 for the same data.
* /health response now includes adminUrl, timezone, and post counts (publish,
  draft, pending, future, private, trash).
* Security fix: explicit `Cache-Control: private, no-store, no-cache` headers
  on every /visibility/v1/* response so page caches (LiteSpeed, WP Rocket,
  Cloudflare, etc.) can't serve a stale unauthenticated body to a different
  caller. Earlier versions relied on WP's default headers, which LiteSpeed
  was overriding.

= 0.3.1 =
* Fix `modified` field returning a zero date ("-001-11-30T00:00:00+00:00")
  for freshly created posts where post_modified_gmt hadn't been normalised
  yet. Falls back to current GMT time when the stored value is empty/zero.

= 0.3.0 =
* Settings page now shows the connected company and project names (not just
  the UUID). Names are returned from the pair endpoint and refreshed on each
  daily heartbeat, so renames in Visibility surface here automatically.
* New "Last seen" row showing when the daily heartbeat last reached Visibility.

= 0.2.0 =
* Settings page links to root of Visibility instead of /integrations.
* Plugin REST: added GET /wp-json/visibility/v1/posts/{id} for verification.
* Post create/update responses now include `editUrl` (wp-admin link) so the
  agent + user have something they can open even when the post is a draft
  (drafts return 404 to anonymous visitors, which is normal WP behaviour).

= 0.1.0 =
* Initial release: pairing flow, REST endpoints for post create/update.
