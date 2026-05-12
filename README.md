# Visibility WordPress plugin

PHP plugin that pairs a WordPress site with a Visibility project using a short pairing code. Once paired, Visibility agents publish + update posts via REST endpoints the plugin exposes under `/wp-json/visibility/v1/*`.

## Layout

```
visibility/
  visibility.php            # plugin header + bootstrap
  readme.txt                # wordpress.org plugin directory readme
  includes/
    class-visibility-client.php    # HTTP client + option storage
    class-visibility-settings.php  # Settings → Visibility admin page
    class-visibility-rest.php      # REST endpoints called BY Visibility
```

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
- **Plugin → Visibility**: heartbeat, optional draft pushes
- **Visibility → Plugin**: publish/update posts via `/wp-json/visibility/v1/*`

## Local dev

To point the plugin at a non-prod backend:

```php
add_filter('visibility_api_base_url', function () {
  return 'http://localhost:3100';
});
```

Put that in a mu-plugin or your wp-config.

## Packaging

Zip the `visibility/` directory for upload to a WP install:

```bash
cd wordpress-plugin
zip -r visibility.zip visibility -x '*.DS_Store'
```
