=== Visibility ===
Contributors: visibilityteam
Tags: seo, ai, content, agents, publishing
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
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

== Frequently Asked Questions ==

= Do I need to create an Application Password? =

No. The plugin uses a shared token issued during pairing.

= Can I use this with multiple Visibility projects? =

Each WordPress site pairs with one Visibility project at a time. Disconnect and re-pair if you need to move it.

= Where is the token stored? =

In WordPress's `wp_options` table under `visibility_site_token`. Disconnecting deletes it.

== Changelog ==

= 0.1.0 =
* Initial release: pairing flow, REST endpoints for post create/update.
