=== Agentic SEO for Visibility ===
Contributors: rankth
Tags: agentic seo, ai content, ai writer, content automation, seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.7.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pair WordPress with your Visibility project so AI agents can audit and publish content directly. No Application Passwords required.

== Description ==

**Agentic SEO for Visibility** is the official WordPress plugin for [Visibility](https://app.visibility.so), a SaaS platform that runs AI agents to audit websites, generate SEO-optimised content, and publish it directly to WordPress. Pair your site with your Visibility project in under a minute using a short code — no Application Passwords, no per-user API keys, and no copy-pasting drafts out of an AI tool.

= Why use this plugin =

* **No Application Password required.** Pairing exchanges a one-time short code for a shared site token, so the plugin and Visibility can talk without a WordPress user password ever leaving your site.
* **Built for agentic SEO workflows.** Visibility's AI agents can list, draft, update, schedule, and publish posts on your behalf — including categories, tags, featured images, and excerpts.
* **Drafts by default; publishes only when you allow it.** Every post is saved as a WordPress draft unless you've enabled "Allow publishing" on the agent in your Visibility project, so a human can always review AI-written content before it goes live.
* **Compatible with Yoast SEO, Rank Math, and AIOSEO.** Posts go through the standard WordPress post-save flow, so SEO-plugin meta boxes, schema generation, and sitemap entries populate the same way they do for posts you write by hand.
* **Works on any WordPress 6.0+ install.** No special PHP extensions, no server tweaks, no host control-panel toggles to flip.
* **Open source (GPLv2).** Plugin source lives at [github.com/doable-team/agentic-seo-for-visibility](https://github.com/doable-team/agentic-seo-for-visibility).

= How it works =

1. In your Visibility project, click **Connect via plugin** and copy the short pairing code (for example `VIS-A7K9-XB`).
2. Install and activate this plugin, then open **Settings → Agentic SEO** and paste the code.
3. Your project and this site are paired. AI agents can now read, draft, and (when enabled) publish WordPress content here.

You can disconnect at any time from Settings → Agentic SEO, or from the Visibility dashboard. A Visibility account at [app.visibility.so](https://app.visibility.so) is required. See the **External services** section below for the full list of network calls this plugin makes.

== Installation ==

1. Upload the `agentic-seo-visibility` folder to the `/wp-content/plugins/` directory, or install through the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Settings → Agentic SEO** and follow the pairing instructions.

== Screenshots ==

1. Settings → Agentic SEO, paired state, showing the connected company + project, last-seen heartbeat, and plugin version.

== Frequently Asked Questions ==

= Do I need to create an Application Password? =

No. Pairing produces a shared site token that the plugin uses — no WordPress user password is involved at any point, and you don't need to enable Application Passwords in your WP user profile.

= Can AI agents publish to WordPress automatically through this plugin? =

Yes, when you allow it. Each Visibility agent has an "Allow publishing" toggle in the Visibility dashboard. With it on, the agent publishes posts directly. With it off (the default), every save is forced to draft so a human reviews and publishes manually.

= Can I keep AI content in draft-only mode? =

Yes — that's the default behaviour. Without the "Allow publishing" toggle, every post the AI agent creates or updates is saved as a WordPress draft. You publish manually from wp-admin when you're satisfied with the content.

= What can a Visibility AI agent do on my WordPress site? =

Through the plugin's REST surface (`/wp-json/visibility/v1/*`), AI agents can list, create, update, and delete posts; manage categories and tags; upload media and set featured images; and read site health info (post counts, WP version, timezone). Nothing outside that — no theme files, no user accounts, no general WordPress settings.

= Will this AI content plugin slow down my WordPress site? =

No. The plugin adds a small set of authenticated REST endpoints that only respond when called by Visibility, plus one cron heartbeat that runs once a day. There's no extra JavaScript or CSS on the public side of your site and no background polling.

= Is this compatible with Yoast SEO, Rank Math, AIOSEO, or other SEO plugins? =

Yes. Posts created by AI agents go through WordPress's standard post-save flow, so SEO-plugin meta boxes, structured-data / schema generation, and XML sitemap entries populate the same way they do for posts you write by hand. You can configure agents on the Visibility side to populate Yoast / Rank Math / AIOSEO fields directly via post meta.

= Does this work with WordPress multisite? =

Yes. Each subsite pairs independently with its own Visibility project. Network-activate the plugin or activate it per subsite; the pairing flow runs at the subsite level.

= Can I use the same WordPress site with multiple Visibility projects? =

Each WordPress site pairs with one Visibility project at a time. Disconnect from Settings → Agentic SEO and re-pair when you need to move the site to a different project.

= What happens if the pairing code expires before I paste it? =

Pairing codes are valid for 10 minutes and are single-use. If yours times out, click **Generate a new code** in your Visibility project to mint another one — there's no penalty and the old code is discarded automatically.

= What data does this plugin send to Visibility? =

On pairing and once a day for heartbeats, the plugin sends your site URL, site name, WordPress version, and the installed plugin version. Post content only travels when one of your AI agents creates or updates a post in WordPress — and that always originates from a Visibility project you control. See the **External services** section below for the precise endpoint list.

= How do I uninstall this AI publishing plugin? =

Deactivate it from the Plugins screen, then delete. On deactivation the daily heartbeat cron is unscheduled; on delete the pairing token (`visibility_site_token`) and cached company / project names are removed from `wp_options`. Your data in your Visibility project is untouched — disconnect from this plugin **before** deleting if you also want Visibility to forget about this site.

= What WordPress and PHP versions does it require? =

WordPress 6.0 or newer and PHP 7.4 or newer. Tested up to WordPress 7.0.

== External services ==

This plugin connects your WordPress site to **Visibility**, an external SaaS service at https://app.visibility.so. A Visibility account is required. The plugin makes the following outbound HTTPS requests from your site:

1. **POST https://app.visibility.so/api/wordpress/plugin/pair** — sent once when you submit a pairing code on the Settings → Agentic SEO screen. Body includes: the pairing code, your site URL, site name, WordPress version, and the installed plugin version. Response contains a shared site token plus the connected company + project IDs and names so the plugin can display them.

2. **POST https://app.visibility.so/api/wordpress/plugin/heartbeat** — sent at most once per day from WP-Cron while the plugin is paired. Body includes the installed plugin version; the shared site token is sent in the `Authorization: Bearer` header. Used to refresh the "Last seen" timestamp and pick up renames of the connected company or project on Visibility's side.

3. **POST https://app.visibility.so/api/wordpress/plugin/disconnect** — sent when you click Disconnect on the Settings → Agentic SEO screen. Body includes the installed plugin version; the shared site token is sent in the `Authorization: Bearer` header. Tells Visibility to remove your site from the project. Local cleanup proceeds even if Visibility is unreachable.

Inbound requests from Visibility, authenticated with the shared site token, reach the plugin at `/wp-json/visibility/v1/*` and are used by your Visibility project's agents to read, draft, publish, update, or delete posts; manage categories, tags, and media; and check site health. No content leaves your site automatically — content only travels on agent-initiated requests originating from the Visibility project this site is paired to.

Service URL: https://app.visibility.so
Terms of service & privacy policy: linked from the Visibility dashboard footer.

== Changelog ==

= 0.7.3 =
* All plugin screens now live under a single top-level **Agentic SEO**
  menu in the sidebar (its own icon), with three clearly-labelled
  sub-items: **Connection**, **Approvals** (with a pending-count
  badge), and **Activity**. Previously these were three lookalike
  entries buried under Settings.
* Coding-standards pass for the WordPress.org review: added a
  translators comment to a placeholder string and made the AJAX nonce
  verification visible to static analysis (the nonce was always
  checked — this just satisfies the scanner).

= 0.7.2 =
* Fixed: Text domain now matches the plugin slug (`agentic-seo-visibility`)
  so translations load correctly and the WordPress.org plugin scanner
  passes. The plugin's display name is unchanged.
* Bumped "Tested up to" to WordPress 7.0.

= 0.7.1 =
* Added: Real-time event-receiver endpoints under
  `/wp-json/visibility/v1/events/*`. The Visibility server pushes
  approved actions straight here (with their full payload), so
  changes show up in WordPress within seconds of approval instead
  of waiting on the 5-minute WP-Cron tick.
  * `events/action-approved` -- executes a single approved action.
  * `events/drain-inbox` -- bulk catch-up.
  * `events/site-config-changed` -- mirrors the allow_publish /
    disabled flags so admin banners stay accurate without polling.

= 0.7.0 =
* Added: Approval inbox for every AI write action. Two new submenu
  pages appear under Settings -- Approvals (pending) and Activity
  (history). Every post, page, media upload, taxonomy edit, SEO
  metadata change, or redirect that the Visibility AI requests now
  shows up as a card you review and approve (or reject with a note
  the AI sees in its next run).
* Added: Auto-approval rules so trusted action types (e.g. drafts,
  alt-text edits) can flow through automatically with optional time
  and usage limits. High-risk actions never auto-approve unless you
  explicitly opt in with a separate checkbox.
* Added: Background cron (every 5 minutes) that executes
  auto-approved actions even when no admin is logged in.
* Tightened: Action payloads are validated server-side against a
  canonical action-type registry and a hard deny list (plugin/theme
  ops, user changes, permalink structure, custom CSS, arbitrary
  options/queries) so no AI request can bypass the approval gate.
* Removed: Application Password connection mode. Plugin pairing is
  now the only supported transport. Existing app-password sites
  must be re-paired with the plugin.

= 0.6.3 =
* Split the `Plugin URI` and `Author URI` headers — they were both
  pointing at `https://app.visibility.so` which the WordPress.org
  Plugin Checker flags (the two URIs must describe different things:
  the plugin product vs. the publisher). Plugin URI stays at the
  product page; Author URI now points at the publisher site at
  `https://visibility.so`.

= 0.6.2 =
* Trim the short description from 196 to 131 characters so the WordPress.org
  Plugin Checker stops flagging it for truncation in plugin-directory cards.
* Rewrite the long description for clarity, with a "Why use this plugin"
  bullet list and a tighter "How it works" sequence.
* Expand the FAQ from 3 to 12 entries answering the most common questions
  about AI publishing workflows, draft-only mode, SEO-plugin compatibility,
  performance, multisite, privacy, and uninstall behaviour.
* Refresh the `Tags` line with more specific search terms
  (`agentic seo`, `ai content`, `ai writer`, `content automation`, `seo`).

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
