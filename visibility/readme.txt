=== Visibility ===
Contributors: visibilityteam
Tags: seo, ai, content, agents, publishing
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Visibility so your SEO agents can publish content directly. Pair with a code — no app passwords required.

== Description ==

Visibility runs AI agents that audit your site and ship SEO-optimised content. This plugin connects your WordPress install to your Visibility project so the agents can publish drafts and updates straight to wp-admin — no Application Passwords, no copy-pasting.

How it works:

1. In your Visibility project, click **Connect via plugin** and copy the short code (e.g. VIS-A7K9-XB).
2. Install + activate this plugin, open **Settings → Visibility**, and paste the code.
3. That's it. Your project and this site are paired; the agents can now publish here.

You can disconnect anytime from Settings → Visibility (or from the Visibility dashboard).

== Installation ==

1. Upload the `visibility` folder to the `/wp-content/plugins/` directory, or install through the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Settings → Visibility** and follow the pairing instructions.

== Screenshots ==

1. Settings → Visibility, paired state, showing the connected company + project, last-seen heartbeat, and plugin version.

== Frequently Asked Questions ==

= Do I need to create an Application Password? =

No. The plugin uses a shared token issued during pairing.

= Can I use this with multiple Visibility projects? =

Each WordPress site pairs with one Visibility project at a time. Disconnect and re-pair if you need to move it.

= Where is the token stored? =

In WordPress's `wp_options` table under `visibility_site_token`. Disconnecting deletes it.

== Changelog ==

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
