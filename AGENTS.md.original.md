# AGENTS.md — wp-possee

Operational knowledge for AI agents working on this repo. Read before touching anything.

## Lessons learned (hard-won — read before touching Blocksy or WP-CLI)

### Blocksy CPT card layout

- CPT-specific card layout key is `{cpt}_archive_archive_order` (double `archive` — e.g. `note_archive_archive_order`). Not `{cpt}_archive_order`.
- If the key is absent from the DB, Blocksy silently falls back to a hardcoded default with `read_more` disabled. Customizer settings are ignored. Fix: copy `blog_archive_order` into the CPT key via WP-CLI `set_theme_mod`.
- Titleless post types (e.g. `note`) cause Blocksy to skip the `read_more` slot entirely. Must inject it manually via the `blocksy:archive:render-card-layers` filter, checking `empty($outputs['read_more'])` first.
- Before assuming a `set_theme_mod()` write took effect, verify the exact key name Blocksy reads at runtime — it won't warn you if the key is wrong.

### Homepage vs archive card rendering

- Don't branch rendering logic on `is_home()`. Use the same code path (e.g. `book-archive-row`) everywhere and suppress the slots you don't want. Branching means two code paths to maintain and easy-to-miss filter interactions (e.g. excerpt filters).

### WP-CLI quoting over SSH

- **Never** embed multi-line PHP in `ssh homeip "bash -c '...'"`. Quoting breaks silently. Always: write PHP to a local file → `scp` to server → `wp eval-file /tmp/file.php`. No exceptions for anything beyond a single short expression.

### Blog post drafting

- Read VOICE.md and fetch 2–3 existing published posts via WP-CLI before writing anything. The style is specific; generic "developer voice" is noticeably wrong.
- Gutenberg block markup: headings need `class="wp-block-heading"` or they render oddly in the editor.
- Cross-links between posts: use `/?p=ID` format. Permanent regardless of permalink structure.
- Write excerpts as 1–2 sentences stating the specific thing the reader will learn. Not a tease.

## Writing blog posts

See **[VOICE.md](./VOICE.md)** for the author's tone, patterns to use, and patterns to avoid. Read it before writing or editing any post content.

## Stack

- **WordPress** (DHI hardened image, PHP-FPM, no shell)
- **Nginx** (FastCGI cache + gzip, reverse proxy to PHP-FPM)
- **Cloudflared** (Cloudflare Tunnel — no open ports)
- **MariaDB** (external container on `db` network, shared with other services)
- **Theme**: Blocksy + Blocksy Companion (font/color/typography configured via Customizer GUI — do not override in mu-plugin)
- **mu-plugins**: `microformats.php`, `comments.php`, `theme-styles.php`, `loopback-fix.php`, `books.php`, `post-types.php`

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
  -e WORDPRESS_DB_PASSWORD=${MYSQL_PASSWORD} \
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
| DB credentials | user=`wordpress` pass=`$MYSQL_PASSWORD` db=`wordpress` |
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

### `blocksy:archive:render-card-layers` outputs for titleless CPTs

When a post type has no title (e.g. `note`), Blocksy skips the `read_more` slot entirely — `$outputs['read_more']` is absent. To inject the standard button on a CPT archive, set `$outputs['read_more']` explicitly in the filter:

```php
add_filter( 'blocksy:archive:render-card-layers', 'possee_note_read_more', 11, 3 );
function possee_note_read_more( $outputs, $prefix, $args ) {
    if ( get_post_type() !== 'note' || is_home() || is_feed() ) {
        return $outputs;
    }
    if ( ! empty( $outputs['read_more'] ) ) {
        return $outputs;
    }
    $outputs['read_more'] = sprintf(
        '<a class="entry-button wp-element-button ct-button" href="%s">Read More<span class="screen-reader-text"> %s</span></a>',
        esc_url( get_permalink() ),
        esc_html( get_the_date( 'j M Y' ) )
    );
    return $outputs;
}
```

**This only works if `read_more` is enabled in the archive order for that CPT.** Blocksy stores the card layout per prefix: `blog_archive_order` for the homepage, `{prefix}_archive_order` for CPT archives (e.g. `note_archive_archive_order`). If the CPT-specific key doesn't exist in the DB, Blocksy falls back to a hardcoded default that has `read_more: enabled=false` — the filter output is ignored because the component is never iterated.

Fix: copy `blog_archive_order` into the CPT-specific key via WP-CLI:
```php
set_theme_mod('note_archive_archive_order', get_theme_mod('blog_archive_order'));
```

This applies to all titleless CPTs. The same fix is needed for `checkin_archive_archive_order` and `book_archive_archive_order` — all three have been set in the DB.

**Do not** append to `$outputs['excerpt']` instead — that puts the link inside the excerpt div, not in the Blocksy button slot, and it breaks when a likes facepile is injected after the excerpt.

### filter priorities used

| Filter | Priority | Reason |
|---|---|---|
| `the_content` (e-content wrapper) | 20 | Must run after Simple Location map at 11/12 |
| `the_content` (syndication strip) | 999 | Must run last on non-singular |
| `get_the_excerpt` (checkin) | 5 | Before default excerpt processing |
| `blocksy:archive:render-card-layers` (checkin map) | 10 | Default |
| `blocksy:archive:render-card-layers` (note read_more) | 11 | After checkin map at 10 |
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
| `.sloc-map-thumb` | Checkin map thumbnail: 4/3 aspect, `border: 1px solid #ddd`, subtle shadow |

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

### `books.php`

Book display and Open Library cover fetching.

- `possee_book_get_data($post_id)` — reads `mf2_read-of` (serialized h-cite) and `mf2_read-status` meta; extracts title, author, isbn (from `uid: isbn:XXXXX`), status, rating (parsed from excerpt `(N/5)` pattern)
- `possee_book_cover_url($isbn, $size)` — returns `https://covers.openlibrary.org/b/isbn/{isbn}-{size}.jpg?default=false`; `?default=false` makes Open Library return 404 instead of a blank placeholder when no cover exists
- `possee_book_cover_img_html()` — renders `<img>` with SVG placeholder as `src` and real cover URL as `data-cover-src`; JS swaps on load success
- `possee_book_cover_loader_script()` — injected via `wp_footer`; swaps placeholder → real cover on `onload` (no `naturalWidth` check needed since `?default=false` means 404 = no swap)
- `possee_book_card_html($post_id, $data, $context)` — renders the book card; `context='single'` shows 140px cover (size L) + Hardcover link + Open Library link; `context='archive'` shows 80px cover (size M)
- `possee_book_stars_html($rating)` — renders `★`/`☆` glyphs with `aria-label`
- `possee_is_book_post($post_id)` — true for `book` CPT or posts tagged `book`
- `the_content` (priority 15): prepends book card on singular book views
- `has_excerpt` filter: suppresses Blocksy hero excerpt on book posts (prevents rating string showing as hero description)
- `get_the_excerpt` (priority 6): on homepage/search, synthesises author · status + stars as excerpt
- `blocksy:archive:render-card-layers` (priority 10): on homepage renders compact cover in `featured_image` slot; on `/books/` archive renders full horizontal layout replacing `title` and `excerpt` slots
- `blocksy:archive:render-card-layer` (priority 10): suppresses `post_meta` layer on book CPT archive (it has its own meta in the card)
- `book-status--{slug}` CSS class on status badge drives all theming — if status parses correctly in PHP but badge is unstyled/missing the modifier class, the deployed `books.php` on the server is stale

**Open Library attribution**: each single book page links to `https://openlibrary.org/isbn/{isbn}` (only when ISBN exists). Rate limit: 100 req/IP/5 min for ISBN-based lookups.

### `post-types.php`

CPT registration and URL rewriting for `book`, `note`, `checkin`.

- Notes have no title — Blocksy skips `read_more` slot entirely on note archives. `possee_note_read_more` (priority 11) injects the standard `entry-button` into `$outputs['read_more']` for non-home, non-feed note archives. Check `empty($outputs['read_more'])` first to avoid double-rendering.

## Header Post Counts widget

The "Post Counts" element in the header (Blocksy header builder, middle-row, end column) shows counts like "8 Articles". It is configured **entirely in the Customizer UI** — there is no PHP filter or hook for the item list.

### How the config is stored

All header builder state lives in a single theme mod: `header_placements`. The `post-counts` item appears in the `items` array with an optional `values` key. When `values` is absent or empty the widget uses Blocksy's built-in default, which shows only standard `post` type posts labelled "Articles" linking to `/articles/`.

### Adding CPTs to the count

To add books, notes, checkins (or change labels/URLs) you must either:

1. **Use the Customizer UI** — Appearance → Customize → Header → middle row → Post Counts → add items there. This is the preferred approach; it writes the `values` into `header_placements` in the DB automatically.

2. **Patch the DB directly via WP-CLI** — read the current `header_placements` mod, find the `post-counts` item in the `items` array, set its `values.header_post_counts_items` to an array of objects like:
   ```json
   [
     { "id": "articles", "post_type": "post",    "label": "Articles", "url": "/articles/", "enabled": true },
     { "id": "books",    "post_type": "book",     "label": "Books",    "url": "/books/",    "enabled": true },
     { "id": "notes",    "post_type": "note",     "label": "Notes",    "url": "/notes/",    "enabled": true },
     { "id": "checkins", "post_type": "checkin",  "label": "Checkins", "url": "/checkins/", "enabled": true }
   ]
   ```
   Then call `set_theme_mod('header_placements', $updated)`.

### What NOT to do

- There is **no PHP filter** for the post counts item list — searching the theme/plugin PHP for `post-counts` or `post_counts_items` returns nothing because the feature is compiled into JS bundles.
- Do not try to grep the theme or blocksy-companion PHP for this feature; it lives entirely in the Customizer JS runtime.

### Implementation: custom header item

The post-counts widget is **not** a built-in Blocksy feature — it is a custom header item registered by a mu-plugin and loaded from:

```
mu-plugins/header-items/post-counts/
  config.php   — registers the item with Blocksy's header builder
  options.php  — Customizer panel options
  view.php     — renders the HTML
```

Blocksy discovers custom header items via the `blocksy:header:items-paths` filter (or equivalent registration). The item path is resolved at runtime; Blocksy confirmed the path as `/var/www/html/wp-content/mu-plugins/header-items/post-counts` when queried via `ReflectionClass`.

### Counts and sparklines

`view.php` runs a single SQL query against `wp_posts` grouped by `post_type` and `DATE_FORMAT(post_date, '%x%v')` (ISO year+week). It builds:

- **Total counts** via `wp_count_posts()` per CPT
- **52-week sparklines** — one `int` per ISO week, oldest first — as inline SVG `<polyline>` elements, generated entirely in PHP with no JS or charting library

Results are cached in a WordPress transient `possee_header_post_counts_v2` for 12 hours. To force refresh: `wp transient delete possee_header_post_counts_v2`.

The sparkline SVG is 52×16px, `preserveAspectRatio="none"`, styled via `.post-counts-sparkline` in `theme-styles.php` (35% opacity, brightens on hover).

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

## Open Library Covers API

Book cover images come from `covers.openlibrary.org`. No API key required.

**URL format**: `https://covers.openlibrary.org/b/isbn/{isbn}-{S|M|L}.jpg`
- Append `?default=false` to get a 404 instead of a blank placeholder when no cover exists — this is what we use, so the JS swap only fires on a real image load (`onload`, no `naturalWidth` check needed)
- Rate limit: **100 req/IP/5 min** for ISBN-based lookups; over limit returns 403
- Sizes: `S` (small), `M` (medium, 80px target), `L` (large, 140px target)

**Attribution**: Open Library requests a courtesy link-back. We link each single book page to `https://openlibrary.org/isbn/{isbn}` (`.book-ol-link`, shown when ISBN exists). The built-with page also credits the API.

## Backups

```bash
ssh homeip docker exec mariadb mysqldump -u wordpress -p"${MYSQL_PASSWORD}" wordpress | gzip > /Storage/docker/wp-possee/backups/wordpress-$(date +%Y%m%d-%H%M%S).sql.gz
```

## Git commit conventions

- Load the `caveman-commit` skill before writing any commit message
- Follow **Conventional Commits**: `<type>(<scope>): <summary>`
- Types: `feat`, `fix`, `refactor`, `perf`, `docs`, `test`, `chore`, `style`
- Subject ≤50 chars, imperative mood, no trailing period
- **One commit = one thing**: one feature, one fix, one chore — never bundle unrelated changes
- Body only when the *why* isn't obvious; wrap at 72 chars
- No AI attribution, no "this commit does X", no emoji

## Colophon

The site has a `/built-with/` page (post ID 385) listing the tech stack. Update it whenever the infrastructure, theme, or plugin list changes.

## NEVER

- **Never set `font-family` in mu-plugin CSS** — Blocksy Customizer owns typography. Exception: `monospace` for code elements.
- **Never run multi-line commands over `ssh homeip` without `bash << 'EOF' ... EOF`** — remote shell is fish, which breaks bash heredocs and quoting.
- **Never edit mu-plugin files directly on the server** — edit locally under `mu-plugins/`, then `scp` and restart. Direct edits are overwritten on next deploy.
- **Never skip clearing all three caches after a PHP change** — OPcache, nginx fastcgi cache, and WP-Optimize disk cache are independent. Missing one means stale output with no obvious cause.
- **Never omit `--user 65532` from WP-CLI containers** — the uploads directory is owned by that UID; omitting it silently breaks any file write including `media_sideload_image`.
- **Never use `$post->post_excerpt` to check for a native excerpt** — use `has_excerpt($post_id)`, which checks the raw DB value. Our `get_the_excerpt` filter can return non-empty strings even when no native excerpt exists, causing Blocksy's hero to render generated content as a description.
