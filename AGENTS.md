# AGENTS.md — wp-possee

Operational knowledge for AI agents working on this repo. Read before touching anything.

## Writing blog posts

See **[VOICE.md](./VOICE.md)** for the author's tone, patterns to use, and patterns to avoid. Read it before writing or editing any post content.

## Stack

- **WordPress** (DHI hardened image, PHP-FPM, no shell)
- **Nginx** (FastCGI cache + gzip, reverse proxy to PHP-FPM)
- **Cloudflared** (Cloudflare Tunnel — no open ports)
- **MariaDB** (external container on `db` network, shared with other services)
- **Theme**: Blocksy + Blocksy Companion (font/color/typography configured via Customizer GUI — do not override in mu-plugin)
- **mu-plugins**: `microformats.php`, `comments.php`, `theme-styles.php`, `loopback-fix.php`

## Critical: WP-CLI invocation

The WordPress container has no shell. Run WP-CLI in a throwaway container:

```bash
docker run --rm \
  --user 65532 \
  -v wp-possee_wp_data:/var/www/html \
  -v /Storage/docker/wp-possee/mu-plugins:/var/www/html/wp-content/mu-plugins \
  --network db \
  -e WORDPRESS_DB_HOST=mariadb \
  -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=Glimmer-Ripeness3-Diffused-Geography \
  -e WORDPRESS_DB_NAME=wordpress \
  wordpress:cli-php8.3 wp --allow-root <command>
```

**`--user 65532` is mandatory** — uploads dir is owned by that UID. Omitting it breaks `media_sideload_image` and any file write.

**The mu-plugins bind mount is mandatory** — mu-plugins live at `/Storage/docker/wp-possee/mu-plugins/` on the host, not inside the named volume. Without the `-v` flag they won't load.

## Critical: inspecting files inside the container

WordPress container has no shell. Read theme/plugin files via:

```bash
docker run --rm -v wp-possee_wp_data:/data alpine:latest cat /data/wp-content/...
docker run --rm -v wp-possee_wp_data:/data alpine:latest grep -rn "pattern" /data/wp-content/...
```

Note: alpine `grep` has no `--include` flag. Use `-r` with a path instead.

## Deploy workflow

1. Edit files locally under `mu-plugins/`
2. `scp` to server: `scp mu-plugins/foo.php homeip:/Storage/docker/wp-possee/mu-plugins/`
3. **PHP changes** — restart the wordpress container to clear OPcache: `ssh homeip docker compose -f /Storage/docker/wp-possee/docker-compose.yml up -d --force-recreate wordpress`
4. **Clear nginx cache**: `ssh homeip docker compose -f /Storage/docker/wp-possee/docker-compose.yml up -d --force-recreate nginx`
5. **Purge WP-Optimize disk cache** (24h TTL, survives nginx restarts): `ssh homeip rm -rf $(docker volume inspect wp-possee_wp_data --format '{{.Mountpoint}}')/wp-content/cache/wpo-cache/`
6. Wait ~65 seconds for OPcache to revalidate before testing (unless `opcache.revalidate_freq = 0`, in which case wait ~5s)

Remote shell is **fish** — always use `ssh homeip bash << 'EOF' ... EOF` for multi-line commands.

## Infrastructure

| Thing | Value |
|---|---|
| MariaDB container | `mariadb` (external, on `db` network) |
| DB credentials | user=`wordpress` pass=`Glimmer-Ripeness3-Diffused-Geography` db=`wordpress` |
| WordPress container | `wp-possee-wordpress-1` (DHI hardened, no shell) |
| Uploads owner UID | `65532` |
| Ingress | Cloudflare Tunnel → nginx → PHP-FPM |
| mu-plugins host path | `/Storage/docker/wp-possee/mu-plugins/` |
| Named volume | `wp-possee_wp_data` (contains WP core, themes, plugins — not mu-plugins) |
| PHP INI | `php/uploads.ini` mounted into container (memory_limit=256M, OPcache, upload sizes) |

## PHP configuration (`php/uploads.ini`)

- `opcache.revalidate_freq = 0` — file changes detected immediately (no 60s delay)
- `opcache.validate_timestamps = 1`
- `opcache.jit = tracing`, `opcache.jit_buffer_size = 64M`
- `memory_limit = 256M`
- `upload_max_filesize / post_max_size = 10M`

## Blocksy: how customizer options work

Blocksy stores card/meta layout in `blog_archive_order` theme mod. **This is persisted to the DB when the user saves the Customizer.** PHP filters only set defaults — they don't affect already-saved values.

### Adding a new card element

1. Filter `blocksy:options:posts-listing-archive-order` to add to the option's `value` and `settings` arrays (sets the default for fresh installs)
2. **Also patch the stored DB value directly** via WP-CLI `set_theme_mod` — otherwise existing installs won't see the new element in the Customizer

### Adding an item inside Post Meta

1. Filter `blocksy:options:meta:meta_default_elements` — adds to default value (fresh installs only)
2. Filter `blocksy:options:meta:meta_elements` — adds the settings panel in Customizer
3. Action `blocksy:post-meta:render-meta` — render `<li>` when `$id === 'your_id'`
4. **Also patch the stored `blog_archive_order`** — find every `post_meta` item in the array and append your element to its `meta_elements` array

### Relevant filters

| Filter / Action | Purpose |
|---|---|
| `blocksy:options:posts-listing-archive-order` | Card elements order + Customizer panels |
| `blocksy:archive:render-card-layers` | Build `$outputs` array (fires once per card) |
| `blocksy:archive:render-card-layer` | Render a single card element |
| `blocksy:options:meta:meta_default_elements` | Default items in a post meta layer |
| `blocksy:options:meta:meta_elements` | Customizer settings panels for meta items |
| `blocksy:post-meta:render-meta` | Render a single meta `<li>` item |
| `blocksy:post-meta:items` | Filter the full `<ul>` HTML after rendering |

### CSS quirks

- Featured image on single posts has `aspect-ratio: 3/1` applied inline by Blocksy — images crop, not letterbox
- Blocksy nginx + OPcache both cache; after mu-plugin changes clear both
- `blocksy_trim_excerpt` strips HTML — can't inject HTML via `get_the_excerpt`
- Blocksy enqueues its external CSS **after** `wp_head` inline `<style>`, so ID selectors are often needed to beat Blocksy's specificity

### filter priorities used

| Filter | Priority | Reason |
|---|---|---|
| `the_content` (e-content wrapper) | 20 | Must run after Simple Location map at 11/12 |
| `the_content` (syndication strip) | 999 | Must run last on non-singular |
| `get_the_excerpt` (checkin) | 5 | Before default excerpt processing |
| `blocksy:archive:render-card-layers` (checkin map) | 10 | Default |
| `get_comment_text` (suppress "Bridgy Response") | 13 | Same priority as via label |
| `get_comment_text` (via label) | 13 | Same priority as suppress |
| `pre_get_comments` (save type__not_in) | 9 | Before Webmention plugin at 10 |
| `pre_get_comments` (restore/strip) | 11 | After Webmention plugin at 10 |
| `webmention_comment_data` (spam bsky.app) | 22 | After default processing |

## Fonts

- **Body font**: Lato (loaded via Blocksy's local font system from `wp-content/uploads/fonts/`)
- **Heading font**: Lato (set in Blocksy Customizer → Typography → Headings; weight 700)
- **Page title font**: Lato (set in Blocksy Customizer per post-type)
- **No Google Fonts API calls made** — fonts are downloaded and hosted locally by Blocksy

Do not set `font-family` in mu-plugin CSS — Blocksy's Customizer handles it. The only exception is `monospace` for code elements (not available in the GUI).

## mu-plugins: what each file does

### `microformats.php`

**IndieWeb microformats & meta tags**
- `rel="me"` links in `<head>` for Mastodon (`@_sleeper@hachyderm.io`) + Bluesky (`sleep-er.bsky.social`)
- OpenGraph (`og:title`, `og:description`, `og:image`, `og:url`) and Twitter Card meta tags on singular posts and front page
- `h-entry` class on `post_class`, `p-name` span on singular titles

**Bridgy Bluesky syndication provider**
- Extends `SynProvider_Webmention_Bridgy` as `SynProvider_Webmention_Bridgy_Bluesky`
- Registers the `webmention-bluesky-bridgy` provider for Syndication Links to push content to Bridgy Bluesky
- Outputs hidden `.p-bridgy-bluesky-content` with excerpt for Bridgy to pick up

**Syndication Links as Blocksy Card Element**
- `reading_time` meta element: word-count → "N min read"
- `syndication_links` meta element: renders syndication link icons inside post meta (`meta-syndication-links`)

**Checkin posts**
- `get_the_excerpt` (priority 5): for checkin posts on archive pages, uses full content (stripped of HTML) as excerpt instead of `blocksy_trim_excerpt`'s truncated version
- `blocksy:archive:render-card-layers` (priority 10): injects static map image at end of excerpt card for posts with `checkin` tag and geo coordinates

**Syndication content handling**
- `the_content` (priority 999): strips syndication-links div HTML from non-singular views
- `syn_link_mapping`: maps `hachyderm.io` → `mastodon` icon
- `the_content` (priority 20): wraps singular post content in `<div class="e-content">` with hidden `dt-published`/`u-url` microformats; uses a static `$done` flag to prevent leaking hidden content when Syndication Links calls `apply_filters('the_content', ...)` a second time

**Category cleanup**
- `get_the_terms`: hides Uncategorised/Uncategorized from display

**Micropub post sanitisation**
- `pre_insert_micropub_post`: sanitises tags array (filters non-strings), generates checkin post content from venue name/locality, sets `post-format-status`

**Named functions**: All hooks use named functions (`possee_*` prefix) for grepability and `remove_filter` support. The only remaining closure is in `plugins_loaded` (wraps a class definition).

### `theme-styles.php`

All CSS values in this file are **not configurable via Blocksy Customizer** — they cover plugin integrations and custom features. Body/heading font/color was removed in favour of Blocksy GUI.

| Feature | Description |
|---|---|
| `code` styling | Inline code color `#ce887b`, border `#607D8B`, radius, padding |
| `pre` styling | Light bg `#faf9f9`, rounded corners, auto-overflow, `pre-wrap` |
| `pre code` | Reset inside pre blocks (no border, inherit color) |
| `blockquote` | Left border `#263959`, italic, muted color `#ada8a8` |
| `.entry-card` | Border-radius `10px`, subtle box-shadow, white bg, hover lift/shadow transition |
| `.entry-meta` | Muted color `#a0a0a0`, `12px` font |
| `table` | Simple bordered table with grey bg, striped even rows, gradient header |
| `.meta-categories:empty` | Hide empty category display |
| `.entry-card .syndication-links` | Hide syndication links inside cards |
| `.meta-syndication-links` | Icon sizing: `1rem`, inline-flex |
| Featured image lightbox | Click `.ct-featured-image` → overlay (`#blx-overlay`), vanilla JS, close on click/Escape |
| `.likes` facepile | Bluesky-style: `28px` round avatars with `-8px` overlap, heart SVG label, `+N` overflow button |
| `.reposts` facepile | Same style as `.likes` |
| `#comments` | Tighter spacing: reduced margin/padding on titles, inner padding, comment-respond |
| `.sloc-map-thumb` | Checkin map thumbnail: 16/9 aspect, `border: 1px solid #ddd`, subtle shadow |

**JS (injected via wp_footer)**: Adds heart SVG label to likes facepile, implements image lightbox (opens on `.ct-featured-image` click, closes on click or Escape).

### `comments.php`

Comment & webmention handling extracted from microformats.php for clarity.

- `get_comments_number` (priority 10): subtracts webmention-type comments (like, repost, mention) from the count shown to users
- `pre_get_comments` (priority 9): saves original `type__not_in` before Webmention plugin overwrites it at priority 10
- `pre_get_comments` (priority 11): restores `type__not_in` for `mention` queries (Semantic Linkbacks), or strips webmention types for `like`/`repost` queries
- `semantic_linkbacks_enhance_comment_types`: adds `'like'` so Bridgy Bluesky likes get proper `semantic_linkbacks_type` meta
- `get_comment_text` (priority 13): suppresses "Bridgy Response" text for like-type webmentions
- `get_comment_text` (priority 13): appends ` (via Mastodon)` or ` (via Bluesky)` to webmention comments based on `webmention_source_url` meta
- `webmention_comment_data` (priority 22): marks Bridgy Fed `bsky.app` self-comments as spam

### `loopback-fix.php`
- WordPress loopback HTTPS requests fail inside Docker because `wp_safe_remote_get()` resolves the public domain to the nginx container's private IP (rejected as unsafe).
- `http_request_args` (priority 1): disables `reject_unsafe_urls` for requests to own domain
- `pre_http_request` (priority 10): rewrites `https://domain` → `http://nginx`, sets `Host` header, retries with `wp_remote_request`
- Uses a static `$in_progress` guard to prevent infinite recursion

## Plugin notes

| Plugin | Note |
|---|---|
| `activitypub` | Installed but inactive — activate after configuring actor URL |
| `syndication-links` | `get_syndication_links($post_id, $args)` returns HTML or empty string |
| `simple-location` | Hooks `the_content` at priority 11 (map) and 12 (location text); `Loc_Config::map_provider()` renders static maps |
| `micropub` | Filter `pre_insert_micropub_post` for post manipulation before insert |
| `google-site-kit` | Installed but inactive — requires manual account connection |
| `semantic-linkbacks` | `get_linkbacks()` builds meta_query with `type__not_in` for mentions |
| `webmention` | `class-comment-walker.php` overwrites `type__not_in` at priority 10; sender hooks `publish_post` |

## Backups

```bash
ssh homeip docker exec mariadb mysqldump -u wordpress -pGlimmer-Ripeness3-Diffused-Geography wordpress | gzip > /Storage/docker/wp-possee/backups/wordpress-$(date +%Y%m%d-%H%M%S).sql.gz
```
