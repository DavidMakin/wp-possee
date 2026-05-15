# AGENTS.md — wp-possee

Operational knowledge for AI agents working on this repo. Read before touching anything.

## Stack

- **WordPress** (DHI hardened image, PHP-FPM, no shell)
- **Nginx** (FastCGI cache + gzip, reverse proxy to PHP-FPM)
- **Cloudflared** (Cloudflare Tunnel — no open ports)
- **MariaDB** (external container on `db` network, shared with other services)
- **Theme**: Blocksy + Blocksy Companion
- **mu-plugins**: `microformats.php`, `theme-styles.php`, `loopback-fix.php`

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
3. Clear nginx cache: `ssh homeip docker compose -f /Storage/docker/wp-possee/docker-compose.yml up -d --force-recreate nginx`
4. **Purge WP-Optimize disk cache** (24h TTL, survives nginx restarts): `ssh homeip rm -rf $(docker volume inspect wp-possee_wp_data --format '{{.Mountpoint}}')/wp-content/cache/wpo-cache/`
5. Wait ~65 seconds for OPcache to revalidate before testing

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
- Blocksy nginx cache must be cleared for PHP changes (OPcache + nginx both cache)
- `blocksy_trim_excerpt` strips HTML — can't inject HTML via `get_the_excerpt`

### filter priorities used

| Filter | Priority | Reason |
|---|---|---|
| `the_content` (e-content wrapper) | 20 | Must run after Simple Location map at 11/12 |
| `the_content` (syndication strip) | 999 | Must run last on non-singular |
| `get_the_excerpt` (checkin) | 5 | Before default excerpt processing |
| `blocksy:archive:render-card-layers` (checkin map) | 10 | Default |

## mu-plugins: what each file does

### `microformats.php`
- `rel="me"` links in `<head>` for Mastodon + Bluesky
- OpenGraph / Twitter card meta tags
- Bridgy Bluesky syndication provider (custom class extending `SynProvider_Webmention_Bridgy`)
- Syndication Links as a Post Meta item in Blocksy card elements
- Checkin post handling: excerpt from content, static map in card excerpt area
- `e-content` wrapper on singular posts (wraps Simple Location output)
- Syndication links stripped from non-singular `the_content`
- `syn_link_mapping` for hachyderm.io → mastodon icon
- Uncategorised category hidden from display
- Micropub post sanitisation (tags array, checkin content generation, status format)
- `h-entry` on `post_class`, `p-name` on singular titles

### `theme-styles.php`
- Google Fonts import (Lato + PT Serif)
- Base typography, colours, card styles
- Syndication link icon sizing inside post meta
- Featured image lightbox (vanilla JS, click `.ct-featured-image` on single posts)
- Checkin card map image styles

### `loopback-fix.php`
- Fixes WordPress loopback HTTP requests inside Docker (resolves hostname to container IP)

## Plugin notes

| Plugin | Note |
|---|---|
| `activitypub` | Installed but inactive — activate after configuring actor URL |
| `syndication-links` | `get_syndication_links($post_id, $args)` returns HTML or empty string |
| `simple-location` | Hooks `the_content` at priority 11 (map) and 12 (location text) |
| `micropub` | Filter `pre_insert_micropub_post` for post manipulation before insert |
| `google-site-kit` | Installed but inactive — requires manual account connection |

## Backups

```bash
ssh homeip docker exec mariadb mysqldump -u wordpress -pGlimmer-Ripeness3-Diffused-Geography wordpress | gzip > /Storage/docker/wp-possee/backups/wordpress-$(date +%Y%m%d-%H%M%S).sql.gz
```
