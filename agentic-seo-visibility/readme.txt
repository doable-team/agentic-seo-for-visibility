=== Agentic SEO for Visibility ===
Contributors: rankth
Tags: agentic seo, ai content, ai writer, content automation, seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pair WordPress with your Visibility project so AI agents can read, draft and improve your content, with a person approving every change.

== Description ==

**Agentic SEO for Visibility** is the official WordPress plugin for [Visibility](https://visibility.so), a service that runs AI agents to audit websites, improve SEO and write content. Pair your site with your Visibility project in under a minute using a short code. No Application Passwords, no per-user API keys, and no copy-pasting drafts out of an AI tool.

= Why use this plugin =

* **No Application Password required.** Pairing exchanges a one-time short code for a shared site token, so the plugin and Visibility can talk without a WordPress user password ever leaving your site.
* **Built for agentic SEO workflows.** Visibility's agents can read your posts and pages (content, SEO fields, categories, tags, images), create drafts, and propose changes: SEO titles and descriptions, structured data, internal links, image alt text.
* **A person approves every live change.** Agents save new posts as drafts. Publishing, and every change to a page that is already live, raises an approval in your Visibility project (Notifications, Approvals tab) showing what will change. Nothing goes live until someone approves it.
* **The plugin enforces your switches too.** If publishing is turned off or Visibility is paused for this site, the plugin itself refuses the change, even if a request reaches it.
* **Works with your SEO plugin.** On sites with Yoast SEO, Rank Math or All in One SEO, SEO fields are written into that plugin's own settings and structured data is added to its own schema output, so nothing is printed twice. Without an SEO plugin, the plugin prints the meta description, Open Graph tags, canonical, robots and structured data itself.
* **Existing page content is kept intact.** When an agent edits one part of a page, embeds, forms and scripts elsewhere on the page are left exactly as they were.
* **Works on any WordPress 6.0+ install.** No special PHP extensions or server changes.
* **Open source (GPLv2).** Plugin source lives at [github.com/doable-team/agentic-seo-for-visibility](https://github.com/doable-team/agentic-seo-for-visibility).

= How it works =

1. In your Visibility project, open the WordPress connection and copy the short pairing code (for example `VIS-A7K9-XB`).
2. Install and activate this plugin, then open **Agentic SEO → Connection** and paste the code.
3. Your project and this site are paired. Agents can now read your content and propose drafts and changes; you approve them in Visibility.

You can disconnect at any time from Agentic SEO → Connection, or from Visibility. A Visibility account at [app.visibility.so](https://app.visibility.so) is required. See the **External services** section below for the full list of network calls this plugin makes.

== Installation ==

1. Upload the `agentic-seo-visibility` folder to the `/wp-content/plugins/` directory, or install through the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Agentic SEO → Connection** and follow the pairing instructions.

== Screenshots ==

1. Agentic SEO → Connection, paired state, showing the connected company + project, last-seen heartbeat, and plugin version.

== Frequently Asked Questions ==

= Do I need to create an Application Password? =

No. Pairing produces a shared site token that the plugin uses. No WordPress user password is involved at any point, and you don't need to enable Application Passwords in your WP user profile.

= Can AI agents publish to WordPress automatically through this plugin? =

Not without a person. An agent can ask to publish a draft or change a live page, but that request appears in your Visibility project under Notifications, Approvals, and nothing happens until someone approves it.

= Can I stop agents from publishing, or pause them? =

Yes. Publishing can be turned off and Visibility can be paused for a site from Visibility. The plugin stores both switches and checks them before every change, so it refuses a publish (or, when paused, any change) on its own.

= What can a Visibility AI agent do on my WordPress site? =

Through the plugin's REST surface (`/wp-json/visibility/v1/*`), agents can read your site's setup (WordPress version, SEO plugin, post types), list and read posts and pages with their SEO fields, create drafts, update content and SEO fields, add internal links, manage categories and tags, and upload images to the media library with alt text and titles. Nothing outside that: no theme or plugin files, no user accounts, no general WordPress settings.

= Will this plugin slow down my WordPress site? =

No. The plugin adds a small set of authenticated REST endpoints that only respond when called by Visibility, a daily heartbeat, and a check for approved changes every 5 minutes through WP-Cron. It adds no JavaScript or CSS to your public pages.

= Is this compatible with Yoast SEO, Rank Math, AIOSEO, or other SEO plugins? =

Yes. With Yoast SEO, Rank Math or All in One SEO, SEO titles, descriptions, canonical, robots and social fields are written into that plugin's own settings, and structured data is merged into its schema output. With SEOPress, The SEO Framework or Slim SEO the plugin stays out of your page head so tags are never duplicated. Without any SEO plugin, the plugin outputs the tags itself.

= Does this work with WordPress multisite? =

Yes. Each subsite pairs independently with its own Visibility project. Network-activate the plugin or activate it per subsite; the pairing flow runs at the subsite level.

= Can I use the same WordPress site with multiple Visibility projects? =

Each WordPress site pairs with one Visibility project at a time. Disconnect from Agentic SEO → Connection and re-pair when you need to move the site to a different project.

= What happens if the pairing code expires before I paste it? =

Pairing codes are valid for 10 minutes and are single-use. If yours times out, generate a new code in your Visibility project. The old code is discarded automatically.

= What data does this plugin send to Visibility? =

On pairing and once a day for heartbeats, the plugin sends your site URL, site name, WordPress version, and the installed plugin version. After it carries out an approved change it reports the result. Post content only travels when your Visibility project's agents read or change it. See the **External services** section below for the precise endpoint list.

= How do I uninstall the plugin? =

Deactivate it from the Plugins screen, then delete it. On deactivation the scheduled tasks are removed; on delete the plugin removes its connection key (`visibility_site_token`), every other `visibility_*` setting, its temporary data and its scheduled tasks. SEO fields it saved on your posts are kept, since your pages may rely on them (define `VISIBILITY_UNINSTALL_DELETE_POST_META` as true in wp-config.php to remove those too). Your data in Visibility is untouched; disconnect **before** deleting if you also want Visibility to forget this site.

= What WordPress and PHP versions does it require? =

WordPress 6.0 or newer and PHP 7.4 or newer. Tested up to WordPress 7.0.

== External services ==

This plugin connects your WordPress site to **Visibility**, an external service at https://app.visibility.so. A Visibility account is required. The plugin makes the following outbound HTTPS requests from your site:

1. **POST https://app.visibility.so/api/wordpress/plugin/pair**: sent once when you submit a pairing code on the Agentic SEO → Connection screen. Body includes the pairing code, your site URL, site name, WordPress version, and the installed plugin version. The response contains a shared site token plus the connected company and project IDs and names so the plugin can display them.

2. **POST https://app.visibility.so/api/wordpress/plugin/heartbeat**: sent at most once per day from WP-Cron while the plugin is paired. Body includes the installed plugin version; the shared site token is sent in the `Authorization: Bearer` header. Used to refresh the "Last seen" timestamp and pick up renames and the publishing/pause switches.

3. **GET https://app.visibility.so/api/wordpress/plugin/sites/{site}/inbox**: sent every 5 minutes from WP-Cron while paired, with the site token, to pick up changes that were approved in Visibility.

4. **POST https://app.visibility.so/api/wordpress/plugin/sites/{site}/requests/{request}/execution-result**: sent after the plugin carries out an approved change, with the site token. Body is whether it worked, its result (for example the post ID and link) or the error message.

5. **POST https://app.visibility.so/api/wordpress/plugin/disconnect**: sent when you click Disconnect on the Agentic SEO → Connection screen, with the site token. Tells Visibility to remove your site from the project. Local cleanup proceeds even if Visibility is unreachable.

Inbound requests from Visibility, authenticated with the shared site token, reach the plugin at `/wp-json/visibility/v1/*` and are used by your Visibility project's agents to read your content and site setup and to carry out approved changes. No content leaves your site on its own; it only travels when the Visibility project this site is paired to asks for it.

Service URL: https://app.visibility.so
Terms of service & privacy policy: linked from the Visibility dashboard footer.

== Changelog ==

= 0.9.1 =
* Fixed the Connection screen layout. WordPress moves admin notices to sit
  directly after the first heading unless the page marks where the header
  ends, and ours sits in a flex row -- so the notice landed beside the logo
  and squeezed the title into a narrow column. 0.9.0 made this permanent by
  always showing a notice; it is now placed correctly.

= 0.9.0 =
* **SEO tags now work without an SEO plugin.** WordPress itself has no meta
  description and no Open Graph tags, so on a site without Yoast, Rank Math or
  All in One SEO the SEO fields written for a post previously had nowhere to go
  -- the plugin refused the action outright. It now stores them and outputs
  them itself: meta description, Open Graph, canonical, robots and structured
  data.
* Install an SEO plugin at any time and Visibility hands the tags straight over
  -- it stops emitting its own so nothing is ever duplicated, and nothing you
  have already written is lost.
* The Connection screen now tells you which of the two is happening, instead of
  leaving you to guess whether your SEO fields do anything.
* Fixed: structured data (JSON-LD) written for a post was stored but never
  rendered on the page. It is now output.

= 0.8.0 =
* Removed the **Approvals** and **Activity** screens from WP admin.
  Requests are reviewed and decided in your Visibility project under
  Inbox -- Approvals, which is now the single place a publish is ever
  approved. Nothing else changes: the plugin still executes approved
  actions, and the Connection screen is unchanged.
* Fixed: the plugin's list endpoint now honours `status=any` instead of
  silently narrowing the results to published posts, so drafts are
  visible to Visibility again.

= 0.7.5 =
* Polished the top-level **Agentic SEO** menu icon (now a globe) and
  updated the in-readme setup steps to point at the new
  Agentic SEO → Connection location.

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
  pointing at the same Visibility app address, which the WordPress.org
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
