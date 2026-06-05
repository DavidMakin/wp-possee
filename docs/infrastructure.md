# Infrastructure

Stack overview, networking, PHP configuration, and fonts.

## Stack

| Component | Role |
|---|---|
| WordPress (DHI hardened, PHP-FPM, no shell) | CMS |
| Nginx | Reverse proxy to PHP-FPM, fastcgi cache, gzip |
| Cloudflared | Cloudflare Tunnel — no open ports |
| MariaDB | External container on `db` network (shared with other services) |
| Theme | Blocksy + Blocksy Companion (font/color/typography via Customizer GUI — do not override in mu-plugin) |
| mu-plugins | `microformats.php`, `comments.php`, `theme-styles.php`, `loopback-fix.php`, `books.php`, `post-types.php` |

## Infrastructure table

| Thing | Value |
|---|---|
| MariaDB container | `mariadb` (external, on `db` network) |
| DB credentials | user=`wordpress` pass=`$MYSQL_PASSWORD` db=`wordpress` |
| WordPress container | `wp-possee-wordpress-1` |
| Uploads owner UID | `65532` |
| Ingress | Cloudflare Tunnel → nginx → PHP-FPM |
| mu-plugins host path | `/storage/Docker/wp-possee/mu-plugins/` |
| Named volume | `wp-possee_wp_data` (WP core, themes, plugins — not mu-plugins) |
| Compose project | `/storage/Docker/wp-possee` (lowercase `storage`, capital `D` in `Docker`) |

## PHP configuration (`php/uploads.ini`)

- `opcache.revalidate_freq = 0` — file changes detected immediately
- `opcache.validate_timestamps = 1`
- `opcache.jit = tracing`, `opcache.jit_buffer_size = 64M`
- `memory_limit = 256M`
- `upload_max_filesize / post_max_size = 10M`

## Fonts

- **Body font**: Lato (Blocksy local font system, no Google Fonts API calls)
- **Heading font**: Lato (Blocksy Customizer → Typography → Headings; weight 700)
- **Page title font**: Lato (Blocksy Customizer per post-type)
- **No Google Fonts API calls** — fonts downloaded and hosted locally by Blocksy

Don't set `font-family` in mu-plugin CSS — Blocksy Customizer owns it. Exception: `monospace` for code elements.

## Colophon

Site has `/built-with/` page (post ID 385). Update when infrastructure, theme, or plugin list changes.
