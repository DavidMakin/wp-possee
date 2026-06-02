# AGENTS.md — wp-possee

Operational knowledge for AI agents. Read before touching anything.

## Lessons learned (read before touching Blocksy or WP-CLI)

### CPT card consistency rule

CPT cards on the homepage (`is_home()`) **must render identically** to their standalone archive pages (`/books/`, `/notes/`, `/checkins/`, etc.). Never branch on `is_home()` to render a simpler or different card layout for a CPT. If a CPT needs a custom card, implement it once and let it run everywhere — homepage, archive, and search.

### Blocksy CPT card layout

- CPT card layout key: `{cpt}_archive_archive_order` (double `archive` — e.g. `note_archive_archive_order`). Not `{cpt}_archive_order`.
- Key absent from DB → Blocksy silently falls back to hardcoded default with `read_more` disabled. Customizer settings ignored. Fix: copy `blog_archive_order` into CPT key via `set_theme_mod`.
- Titleless post types (e.g. `note`) → Blocksy skips `read_more` slot entirely. Inject manually via `blocksy:archive:render-card-layers`, check `empty($outputs['read_more'])` first.
- Before assuming `set_theme_mod()` write took effect, verify exact key name Blocksy reads at runtime — no warnings on wrong key.

### Homepage vs archive card rendering

- Don't branch on `is_home()`. Same code path everywhere, suppress unwanted slots. Branching = two code paths + easy-to-miss filter interactions (e.g. excerpt filters).

### Blocksy header search `search_through`

- CPTs default to `false` in header search's "Search Through Criteria" (`$cpt_options[$cpt] = false` in `options.php`). Stored in `header_placements` theme mod under `sections[0].items[<idx>].values.search_through`. Fix: patch with `set_theme_mod`, set CPTs to `1`.
- Without this, the header search form only sends `ct_post_type=post:page`, missing books and other CPTs.

### Live search overlay: inject book covers via `placeholder_image` REST field

- Hook `rest_api_init` at priority 20, `unregister_rest_field('search-result', 'placeholder_image')`, re-register with callback returning `mf2_book-cover-url` for `subtype === 'book'`. No JS changes needed — Blocksy's `search-implementation.js` uses `(ct_featured_media || placeholder_image)`.
- `unregister_rest_field()` returns `WP_Error` if field wasn't registered — check with `is_wp_error()` and proceed.

### WP-CLI quoting over SSH

- **Never** embed multi-line PHP in `ssh homeip "bash -c '...'"`. Quoting breaks silently. Always: write PHP to local file → `scp` to server → `wp eval-file /tmp/file.php`. No exceptions beyond single short expressions.

### Bridgy backfeed: "No webmention targets" for likes/replies

- **Root cause**: Bridgy crawls the blog (periodic, every few hours) and may hit a newly-published post **before** the 90-second delayed cron fires and adds the Bluesky `u-syndication` link. Bridgy stores the post without a Bluesky mapping. When a like/reply arrives, it reports "No webmention targets" and sends nothing.
- **Diagnosis**: Check `https://brid.gy/bluesky/<handle>` → Responses section. A like entry with "No webmention targets" confirms this. The Bridgy like source page (`https://brid.gy/like/bluesky/...`) will have only one `u-like-of` (the Bluesky URL) instead of two (Bluesky URL + blog post URL).
- **Permanent fix**: `possee_bridgy_delayed_handler` in `microformats.php` now calls `possee_ping_bridgy_discover()` after `syn_syndication` fires. This POSTs to `https://brid.gy/discover` with the blog post URL, causing Bridgy to re-crawl and update its mapping. WP-Optimize disk cache for the post is also cleared first (nginx already bypasses its fastcgi cache for Bridgy's user-agent).
- **Source key**: The Bridgy datastore key for the Bluesky account is stored in WP option `possee_bridgy_bluesky_source_key`. If you disconnect/reconnect the Bluesky account on Bridgy, get the new value from `view-source:https://brid.gy/bluesky/sleep-er.bsky.social` (search `discover-source-key`) and update: `wp option set possee_bridgy_bluesky_source_key '<value>'`
- **One-off recovery** (for posts published before the fix): Trigger Bridgy to re-crawl, then manually send webmentions:
  ```bash
  # 1. Re-crawl via Bridgy discover (get source_key from wp option)
  SOURCE_KEY=$(wp option get possee_bridgy_bluesky_source_key)
  curl -X POST https://brid.gy/discover \
    -d "url=https://blog.sleep-er.co.uk/YOUR-POST-URL/&source_key=${SOURCE_KEY}"

  # 2. Wait ~15s, then get liker DIDs from Bluesky API
  curl "https://public.api.bsky.app/xrpc/app.bsky.feed.getLikes?uri=at://did:plc:eemo37qp56jdqiier5krh537/app.bsky.feed.post/POST_RKEY"

  # 3. Send webmention for each liker (double-encode AT URI and liker DID)
  curl -X POST https://blog.sleep-er.co.uk/wp-json/webmention/1.0/endpoint \
    --data-urlencode "source=https://brid.gy/like/bluesky/AUTHOR_DID/DOUBLE_ENCODED_AT_URI/DOUBLE_ENCODED_LIKER_DID" \
    --data-urlencode "target=https://blog.sleep-er.co.uk/YOUR-POST-URL/"

  # 4. Approve the resulting pending comments
  wp comment approve <ID>
  ```
- **nginx bypasses cache for Bridgy**: The nginx config already has `if ($http_user_agent ~* "bridgy|brid\.gy") { set $skip_cache 1; }` — Bridgy always hits PHP-FPM directly. Only WP-Optimize disk cache can serve stale content to Bridgy.
- **Compose project location**: `/storage/Docker/wp-possee` (note lowercase `storage`, capital `D` in `Docker`).

### Syndication Links checkbox state driven by `is_checked()`, not `_syndicate-to` meta

- The "Syndicate To" sidebar checkboxes in the post editor get their checked state from `SynProvider::is_checked()`, which returns `false` by default. The `_syndicate-to` post meta is NOT consulted when rendering checkboxes — it's only read on `save_post`.
- To sync checkboxes with `_syndicate-to` meta, use the `syndication_link_checked` filter:
  ```php
  add_filter( 'syndication_link_checked', 'possee_syndication_link_checked', 10, 3 );
  function possee_syndication_link_checked( $checked, $uid, $post_id ) {
      if ( ! in_array( $uid, MY_UID_LIST, true ) ) return $checked;
      $syndicate_to = get_post_meta( $post_id, '_syndicate-to', true );
      return is_array( $syndicate_to ) && in_array( $uid, $syndicate_to, true );
  }
  ```
- Defined in `microformats.php` for our Bridgy providers.

### Bridgy Fed: own POSSE'd post bouncing back as self-comment

- **Root cause**: Bridgy Fed sends a webmention back to your blog for your own syndicated Bluesky post. Source URL pattern: `https://brid.gy/post/bluesky/did:plc:eemo37qp56jdqiier5krh537/...` — your own DID. Results in a comment with the same text as the post content.
- **Fix**: `possee_spam_bsky_self_comments` in `comments.php` now also matches `brid.gy/post/bluesky/did:plc:eemo37qp56jdqiier5krh537/` in `webmention_source_url` meta and marks it spam. Previously only blocked `comment_author === 'bsky.app'`.
- **One-off recovery**: `wp comment delete <ID> --force`

### Syndication to Bluesky/Mastodon using short URL (/?p=ID) instead of pretty permalink

- **Root cause**: `get_permalink()` returns `/?p=ID` for posts in `future`, `draft`, or `pending` status. Syndication Links calls `get_permalink()` just before sending the webmention to Bridgy. If the post was published as scheduled (future post_date) or had no slug yet at syndication time, the wrong URL is sent. Bridgy appends whatever source URL it receives verbatim.
- **Diagnosis**: Mastodon/Bluesky post reads "Title: https://blog.sleep-er.co.uk/?p=293" instead of the pretty permalink.
- **Fix**: `possee_syndication_force_pretty_permalink` in `microformats.php` hooks `pre_syndication_links_webmention` (priority 5). It clones the post in WP's object cache with `post_status = 'publish'` and a computed slug so `get_permalink()` returns the pretty URL. Original is restored on `shutdown`. No-op for posts that are already publish with a slug.
- **One-off recovery**: Resend via Bridgy with a cache-busting param (same as "Couldn't find link" recovery — `?v=N` on the source URL), then update `mf2_syndication` meta.

### Bridgy reply/comment webmention never arrives (post published before Bridgy had the mapping)

- **Root cause**: If a Bluesky reply was posted before Bridgy learned the AT-URI → blog URL mapping (e.g. the mapping was missing due to the "No webmention targets" issue), Bridgy skips the reply and never re-processes it. `brid.gy/comment/bluesky/...` for that reply returns 404.
- **Diagnosis**: Comment doesn't exist in WordPress at all (not in spam, not pending). `brid.gy/bluesky/sleep-er.bsky.social/post/{RKEY}` returns 404 — post not indexed.
- **Fix**: Trigger discover to establish the mapping, then insert the comment manually:
  ```bash
  # 1. Trigger discover
  SOURCE_KEY=$(wp option get possee_bridgy_bluesky_source_key)
  curl -X POST https://brid.gy/discover \
    -d "url=https://blog.sleep-er.co.uk/YOUR-POST-URL/&source_key=${SOURCE_KEY}"

  # 2. Insert comment manually via wp eval-file (write PHP locally, scp, run)
  #    - comment_type must be 'comment' (not 'webmention') — webmention type is
  #      excluded from the standard comment thread display
  #    - set meta: protocol=webmention, webmention_source_url, webmention_target_url,
  #      semantic_linkbacks_type=comment, semantic_linkbacks_author_photo
  #    - comment_date/comment_date_gmt from Bluesky API createdAt (UTC)
  ```
- **comment_type must be 'comment'**: Inserting with `comment_type = 'webmention'` causes the comment to be excluded from the standard thread query — it won't render even if approved. Use `comment_type = 'comment'` with `protocol = 'webmention'` meta instead.
- **After inserting**: clear nginx fastcgi cache (`docker compose up -d --force-recreate nginx`) AND WP-Optimize disk cache (`rm -rf .../wp-content/cache/wpo-cache/`) — both must be cleared or the page still shows the old version.

### Bridgy Publish failure: "Couldn't find link to brid.gy/publish/bluesky"

- **Root cause**: Syndication Links fires the Bridgy webmention synchronously during the Micropub HTTP request — before nginx/Cloudflare/WP-Optimize caches have a warm copy of the page. Bridgy fetches stale content, fails, and **caches the failure** keyed on source+target URL pair. Subsequent resends of the same webmention return the cached error without re-fetching.
- **Diagnosis**: Check `syndication_log` post meta. A `status: 400` entry for `webmention-bluesky-bridgy` with message `"Couldn't find link to brid.gy/publish/bluesky"` confirms this.
- **One-off recovery**: Send the webmention with a cache-busting query param on the source URL — Bridgy treats it as a new source+target pair and re-fetches:
  ```bash
  curl -X POST https://brid.gy/publish/webmention \
    -d "source=https://blog.sleep-er.co.uk/YOUR-POST-URL/?v=2&target=https://brid.gy/publish/bluesky"
  ```
  Then update `mf2_syndication` post meta to replace `https://brid.gy/publish/bluesky` with the returned Bluesky URL, and add a success entry to `syndication_log`.
- **Permanent fix**: `microformats.php` intercepts `micropub_syndication` at priorities 1 and 99, uses `pre_http_request` to block the immediate Bridgy send, and schedules `possee_bridgy_delayed` cron for 90 seconds later. Do not remove this — it prevents the race condition on every future Micropub post.
- **Docker network**: WordPress container is on the `db` network (not `wp-possee_internal`). WP-CLI throwaway containers need `--network db`.

### Verifying PHP changes: bypass Cloudflare cache

The public domain goes through Cloudflare, which caches responses with `s-maxage=3600` (1 hour). Fetching the public URL after a PHP change will often return a stale cached page — `cf-cache-status: HIT` in response headers confirms this. **Never use the public URL to verify PHP output changes.** Fetch directly from nginx via the internal Docker network instead:

```bash
ssh homeip bash << 'EOF'
docker run --rm \
  --network wp-possee_wp-possee \
  alpine:latest \
  wget -q -O - --header="Host: blog.sleep-er.co.uk" "http://nginx/your/path/" 2>&1
EOF
```

This bypasses Cloudflare, nginx fastcgi cache (which is reset on container recreate), and WP-Optimize disk cache (cleared separately). Use it any time `curl https://blog.sleep-er.co.uk/...` isn't reflecting your changes.

### Micropub `render_content` and `e-content` nesting

Micropub plugin's `Render::render_content` hooks `the_content` at **priority 1**. For any post with `micropub_version` meta set (all Micropub-posted content) and no `mf2_content` meta, it wraps the post content in `<div class="e-content">`. This runs before our `possee_wrap_econtent` at priority 20, causing **nested `e-content` divs**.

- `remove_filter( 'the_content', array( 'Micropub\Render', 'render_content' ), 1 )` in a `wp` action hook does not reliably remove it — the filter stays registered.
- Do not try to regex-strip the inner `e-content` wrapper inside `possee_wrap_econtent` — Micropub's `generate_post_content` may emit other content before/after the `e-content` div (interactions, gallery shortcodes).
- The working approach: accept the nesting and ensure `p-bridgy-bluesky-content` is always emitted so Bridgy uses that instead of parsing `e-content`.

### Bridgy Bluesky content: always emit `p-bridgy-bluesky-content`

Bridgy reads the `e-content` microformat value to determine what to post to Bluesky. The `e-content` div includes all inner text — including the `dt-published` ISO timestamp, `u-url`, "Added via Quill" footers, and Micropub-generated wrappers. This makes Bluesky posts start with the ISO timestamp and URL.

**Fix**: `SynProvider_Webmention_Bridgy_Bluesky::wp_footer()` always emits `<p class="p-bridgy-bluesky-content" style="display:none">` on singular posts. Bridgy treats this as authoritative and ignores `e-content`. The text is `wp_strip_all_tags(get_the_content())` — raw post text with no metadata or footers.

**Hidden microformat div placement**: `possee_wrap_econtent` must return `$hidden . '<div class="e-content">' . $content . '</div>'` (hidden div BEFORE the e-content opening tag, not inside it). If the hidden div is inside `e-content`, its ISO timestamp text is included in what Bridgy reads.

### Blog post drafting

- Read VOICE.md and fetch 2–3 existing published posts via WP-CLI before writing. Style is specific; generic "developer voice" is noticeably wrong.
- Gutenberg headings need `class="wp-block-heading"` or render oddly in editor.
- Cross-links: use `/?p=ID` format. Permanent regardless of permalink structure.
- Excerpts: 1–2 sentences stating the specific thing reader will learn. Not a tease.

## Writing blog posts

See **[VOICE.md](./VOICE.md)** for tone, patterns to use, and patterns to avoid. Read before writing or editing any post content.

## Stack

- **WordPress** (DHI hardened image, PHP-FPM, no shell)
- **Nginx** (FastCGI cache + gzip, reverse proxy to PHP-FPM)
- **Cloudflared** (Cloudflare Tunnel — no open ports)
- **MariaDB** (external container on `db` network, shared with other services)
- **Theme**: Blocksy + Blocksy Companion (font/color/typography via Customizer GUI — do not override in mu-plugin)
- **mu-plugins**: `microformats.php`, `comments.php`, `themegf-styles.php`, `loopback-fix.php`, `books.php`, `post-types.php`

## Critical: WP-CLI invocation

WordPress container has no shell. Run WP-CLI in throwaway container:

```bash
docker run --rm \
  --user 65532 \
  -v wp-possee_wp_data:/var/www/html \
  -v /storage/Docker/wp-possee/mu-plugins:/var/www/html/wp-content/mu-plugins \
  --network db \
  -e WORDPRESS_DB_HOST=mariadb \
  -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=${MYSQL_PASSWORD} \
  -e WORDPRESS_DB_NAME=wordpress \
  wordpress:cli-php8.3 wp --allow-root <command>
```

**`--user 65532` mandatory** — uploads dir owned by that UID. Omitting breaks `media_sideload_image` and any file write.

**mu-plugins bind mount mandatory** — mu-plugins at `/storage/Docker/wp-possee/mu-plugins/` on host, not in named volume. Without `-v` they won't load.

## Critical: inspecting files inside container

```bash
docker run --rm -v wp-possee_wp_data:/data alpine:latest cat /data/wp-content/...
docker run --rm -v wp-possee_wp_data:/data alpine:latest grep -rn "pattern" /data/wp-content/...
```

Alpine `grep` has no `--include`. Use `-r` with path instead.

## Deploy workflow

1. Edit locally under `mu-plugins/`
2. `scp mu-plugins/foo.php homeip:/storage/Docker/wp-possee/mu-plugins/`
3. **PHP changes** — restart wordpress container: `ssh homeip docker compose -f /storage/Docker/wp-possee/docker-compose.yml up -d --force-recreate wordpress`
4. **Clear nginx cache**: `ssh homeip docker compose -f /storage/Docker/wp-possee/docker-compose.yml up -d --force-recreate nginx`
5. **Purge WP-Optimize disk cache** (24h TTL, survives nginx restarts): `ssh homeip rm -rf $(docker volume inspect wp-possee_wp_data --format '{{.Mountpoint}}')/wp-content/cache/wpo-cache/`
6. Wait ~65s for OPcache revalidation (unless `opcache.revalidate_freq = 0`, then ~5s)

Remote shell is **fish** — use `ssh homeip bash << 'EOF' ... EOF` for multi-line commands.

## Infrastructure

| Thing | Value |
|---|---|
| MariaDB container | `mariadb` (external, on `db` network) |
| DB credentials | user=`wordpress` pass=`$MYSQL_PASSWORD` db=`wordpress` |
| WordPress container | `wp-possee-wordpress-1` (DHI hardened, no shell) |
| Uploads owner UID | `65532` |
| Ingress | Cloudflare Tunnel → nginx → PHP-FPM |
| mu-plugins host path | `/storage/Docker/wp-possee/mu-plugins/` |
| Named volume | `wp-possee_wp_data` (WP core, themes, plugins — not mu-plugins) |
| PHP INI | `php/uploads.ini` mounted into container (memory_limit=256M, OPcache, upload sizes) |

## PHP configuration (`php/uploads.ini`)

- `opcache.revalidate_freq = 0` — file changes detected immediately
- `opcache.validate_timestamps = 1`
- `opcache.jit = tracing`, `opcache.jit_buffer_size = 64M`
- `memory_limit = 256M`
- `upload_max_filesize / post_max_size = 10M`

## Blocksy: how customizer options work

Blocksy stores card/meta layout in `blog_archive_order` theme mod. **Persisted to DB when user saves Customizer.** PHP filters only set defaults — don't affect already-saved values.

### Adding a new card element

1. Filter `blocksy:options:posts-listing-archive-order` to add to `value` and `settings` arrays (default for fresh installs)
2. **Also patch stored DB value** via `set_theme_mod` — existing installs won't see new element otherwise

### Adding an item inside Post Meta

1. Filter `blocksy:options:meta:meta_default_elements` — adds to default value (fresh installs only)
2. Filter `blocksy:options:meta:meta_elements` — adds settings panel in Customizer
3. Action `blocksy:post-meta:render-meta` — render `<li>` when `$id === 'your_id'`
4. **Also patch stored `blog_archive_order`** — find every `post_meta` item, append element to its `meta_elements` array

### Relevant filters

| Filter / Action | Purpose |
|---|---|
| `blocksy:options:posts-listing-archive-order` | Card elements order + Customizer panels |
| `blocksy:archive:render-card-layers` | Build `$outputs` array (fires once per card) |
| `blocksy:archive:render-card-layer` | Render single card element |
| `blocksy:options:meta:meta_default_elements` | Default items in post meta layer |
| `blocksy:options:meta:meta_elements` | Customizer settings panels for meta items |
| `blocksy:post-meta:render-meta` | Render single meta `<li>` item |
| `blocksy:post-meta:items` | Filter full `<ul>` HTML after rendering |

### CSS quirks

- Featured image on single posts: `aspect-ratio: 3/1` applied inline by Blocksy — images crop, not letterbox
- Blocksy nginx + OPcache both cache; clear both after mu-plugin changes
- `blocksy_trim_excerpt` strips HTML — can't inject HTML via `get_the_excerpt`
- Blocksy enqueues external CSS **after** `wp_head` inline `<style>` — ID selectors often needed to beat specificity

### `blocksy:archive:render-card-layers` outputs for titleless CPTs

Post type with no title (e.g. `note`) → Blocksy skips `read_more` slot — `$outputs['read_more']` absent. To inject standard button on CPT archive, set `$outputs['read_more']` explicitly:

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

**Only works if `read_more` enabled in archive order for that CPT.** Blocksy stores card layout per prefix: `blog_archive_order` for homepage, `{prefix}_archive_order` for CPT archives (e.g. `note_archive_archive_order`). CPT-specific key absent → hardcoded default with `read_more: enabled=false` — filter output ignored.

Fix: copy `blog_archive_order` into CPT-specific key:
```php
set_theme_mod('note_archive_archive_order', get_theme_mod('blog_archive_order'));
```

Same fix needed for `checkin_archive_archive_order` and `book_archive_archive_order` — all three set in DB.

**Do not** append to `$outputs['excerpt']` — puts link inside excerpt div, not Blocksy button slot, breaks when likes facepile injected after excerpt.

### Filter priorities used

| Filter | Priority | Reason |
|---|---|---|
| `the_content` (e-content wrapper) | 20 | After Simple Location map at 11/12 |
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

- **Body font**: Lato (Blocksy local font system from `wp-content/uploads/fonts/`)
- **Heading font**: Lato (Blocksy Customizer → Typography → Headings; weight 700)
- **Page title font**: Lato (Blocksy Customizer per post-type)
- **No Google Fonts API calls** — fonts downloaded and hosted locally by Blocksy

Don't set `font-family` in mu-plugin CSS — Blocksy Customizer owns it. Exception: `monospace` for code elements.

## mu-plugins: what each file does

### `microformats.php`

**IndieWeb microformats & meta tags**
- `rel="me"` links in `<head>` for Mastodon (`@_sleeper@hachyderm.io`) + Bluesky (`sleep-er.bsky.social`)
- OpenGraph + Twitter Card meta tags on singular posts and front page
- `h-entry` class on `post_class`, `p-name` span on singular titles

**Bridgy Bluesky syndication provider**
- Extends `SynProvider_Webmention_Bridgy` as `SynProvider_Webmention_Bridgy_Bluesky`
- Registers `webmention-bluesky-bridgy` provider for Syndication Links
- Outputs hidden `.p-bridgy-bluesky-content` on all singular posts with raw `wp_strip_all_tags(get_the_content())` — Bridgy uses this instead of parsing `e-content`, preventing timestamp/footer leakage

**Delayed Bridgy cron + discover ping**
- `possee_bridgy_delayed_handler`: fires 90s after Micropub post creation via `possee_bridgy_delayed` cron; calls `syn_syndication`, then `possee_clear_post_page_cache` (WP-Optimize), then `possee_ping_bridgy_discover`
- `possee_clear_post_page_cache($post_id)`: clears WP-Optimize disk cache for the post URL so Bridgy doesn't get a stale page during discover crawl
- `possee_ping_bridgy_discover($post_id)`: POSTs to `https://brid.gy/discover` with the post URL and `possee_bridgy_bluesky_source_key` option; causes Bridgy to re-crawl and learn the `at://...` → blog URL mapping before any likes arrive

**Syndication Links as Blocksy Card Element**
- `reading_time` meta element: word-count → "N min read"
- `syndication_links` meta element: renders syndication link icons in post meta (`meta-syndication-links`)

**Checkin posts**
- `get_the_excerpt` (priority 5): checkin posts on archive → full content (stripped HTML) as excerpt instead of `blocksy_trim_excerpt` truncation
- `blocksy:archive:render-card-layers` (priority 10): injects static map image at end of excerpt card for posts with `checkin` tag + geo coordinates

**Syndication content handling**
- `the_content` (priority 999): strips syndication-links div HTML from non-singular views
- `syn_link_mapping`: maps `hachyderm.io` → `mastodon` icon
- `the_content` (priority 20): wraps singular content in `<div class="e-content">` with hidden `dt-published`/`u-url` placed **before** (not inside) the wrapper; static `$done` flag prevents double-wrapping when Syndication Links calls `apply_filters('the_content', ...)`

**Category cleanup**
- `get_the_terms`: hides Uncategorised/Uncategorized from display

**Micropub post sanitisation**
- `pre_insert_micropub_post`: sanitises tags array, generates checkin post content from venue name/locality, sets `post-format-status`

**Named functions**: All hooks use named functions (`possee_*` prefix) for grepability and `remove_filter` support. Only remaining closure is in `plugins_loaded` (wraps class definition).

### `theme-styles.php`

CSS not configurable via Blocksy Customizer — plugin integrations and custom features only. Body/heading font/color handled by Blocksy GUI.

| Feature | Description |
|---|---|
| `code` styling | Inline code color `#ce887b`, border `#607D8B`, radius, padding |
| `pre` styling | Light bg `#faf9f9`, rounded corners, auto-overflow, `pre-wrap` |
| `pre code` | Reset inside pre blocks (no border, inherit color) |
| `blockquote` | Left border `#263959`, italic, muted color `#ada8a8` |
| `.entry-card` | Border-radius `10px`, box-shadow, white bg, hover lift/shadow transition |
| `.entry-meta` | Muted color `#a0a0a0`, `12px` font |
| `table` | Bordered, grey bg, striped even rows, gradient header |
| `.meta-categories:empty` | Hide empty category display |
| `.entry-card .syndication-links` | Hide syndication links inside cards |
| `.meta-syndication-links` | Icon sizing: `1rem`, inline-flex |
| Featured image lightbox | Click `.ct-featured-image` → overlay (`#blx-overlay`), vanilla JS, close on click/Escape |
| `.likes` facepile | `28px` round avatars, `-8px` overlap, heart SVG label, `+N` overflow button |
| `.reposts` facepile | Same style as `.likes` |
| `#comments` | Tighter spacing on titles, inner padding, comment-respond |
| `.sloc-map-thumb` | Checkin map: 4/3 aspect, `border: 1px solid #ddd`, subtle shadow |

**JS (via wp_footer)**: Adds heart SVG to likes facepile, implements image lightbox.

### `comments.php`

Comment & webmention handling.

- `get_comments_number` (priority 10): subtracts webmention-type comments from count shown to users
- `pre_get_comments` (priority 9): saves original `type__not_in` before Webmention plugin overwrites at priority 10
- `pre_get_comments` (priority 11): restores `type__not_in` for `mention` queries, or strips webmention types for `like`/`repost`
- `semantic_linkbacks_enhance_comment_types`: adds `'like'` so Bridgy Bluesky likes get proper `semantic_linkbacks_type` meta
- `get_comment_text` (priority 13): suppresses "Bridgy Response" text for like-type webmentions
- `get_comment_text` (priority 13): appends ` (via Mastodon)` or ` (via Bluesky)` based on `webmention_source_url` meta
- `webmention_comment_data` (priority 22): marks Bridgy Fed `bsky.app` self-comments as spam

### `loopback-fix.php`

WordPress loopback HTTPS requests fail inside Docker — `wp_safe_remote_get()` resolves public domain to nginx container's private IP (rejected as unsafe).

- `http_request_args` (priority 1): disables `reject_unsafe_urls` for requests to own domain
- `pre_http_request` (priority 10): rewrites `https://domain` → `http://nginx`, sets `Host` header, retries with `wp_remote_request`
- Static `$in_progress` guard prevents infinite recursion

### `books.php`

Book display and Open Library cover fetching.

- `possee_book_get_data($post_id)` — reads `mf2_read-of` (serialized h-cite) and `mf2_read-status` meta; extracts title, author, isbn (from `uid: isbn:XXXXX`), status, rating (parsed from excerpt `(N/5)` pattern)
- `possee_book_cover_url($isbn, $size)` — `https://covers.openlibrary.org/b/isbn/{isbn}-{size}.jpg?default=false`; `?default=false` → 404 instead of blank placeholder
- `possee_book_cover_img_html()` — `<img>` with SVG placeholder as `src`, real cover as `data-cover-src`; JS swaps on load
- `possee_book_cover_loader_script()` — via `wp_footer`; swaps on `onload` (no `naturalWidth` check needed)
- `possee_book_card_html($post_id, $data, $context)` — `context='single'`: 140px cover (L) + Hardcover + Open Library links; `context='archive'`: 80px cover (M)
- `possee_book_stars_html($rating)` — renders `★`/`☆` with `aria-label`
- `possee_is_book_post($post_id)` — true for `book` CPT or posts tagged `book`
- `the_content` (priority 15): prepends book card on singular book views
- `has_excerpt` filter: suppresses Blocksy hero excerpt on book posts
- `blocksy:archive:render-card-layers` (priority 10): renders `book-archive-row` layout on all archives (homepage + `/books/`), replacing `title` and `excerpt` slots
- `blocksy:archive:render-card-layer` (priority 10): suppresses `post_meta` layer on book CPT archive
- `book-status--{slug}` CSS class drives status badge theming — if badge unstyled, deployed `books.php` is stale

**Open Library attribution**: single book pages link to `https://openlibrary.org/isbn/{isbn}`. Rate limit: 100 req/IP/5 min.

### `post-types.php`

CPT registration and URL rewriting for `book`, `note`, `checkin`.

- Notes have no title — Blocksy skips `read_more` slot on note archives. `possee_note_read_more` (priority 11) injects `entry-button` into `$outputs['read_more']` for non-home, non-feed note archives. Check `empty($outputs['read_more'])` first.

## Header Post Counts widget

"Post Counts" element in header (Blocksy header builder, middle-row, end column). Configured **entirely in Customizer UI** — no PHP filter for item list.

### How config is stored

All header builder state in `header_placements` theme mod. `post-counts` item in `items` array with optional `values` key. When absent, widget uses Blocksy default (standard posts only, labelled "Articles").

### Adding CPTs to count

1. **Customizer UI** — Appearance → Customize → Header → middle row → Post Counts. Preferred; writes `values` to DB automatically.

2. **Patch DB via WP-CLI** — read `header_placements`, find `post-counts` item, set `values.header_post_counts_items`:
   ```json
   [
     { "id": "articles", "post_type": "post",    "label": "Articles", "url": "/articles/", "enabled": true },
     { "id": "books",    "post_type": "book",     "label": "Books",    "url": "/books/",    "enabled": true },
     { "id": "notes",    "post_type": "note",     "label": "Notes",    "url": "/notes/",    "enabled": true },
     { "id": "checkins", "post_type": "checkin",  "label": "Checkins", "url": "/checkins/", "enabled": true }
   ]
   ```
   Then `set_theme_mod('header_placements', $updated)`.

### What NOT to do

No PHP filter for post counts item list — feature compiled into JS bundles. Don't grep theme/blocksy-companion PHP for it.

### Implementation: custom header item

Not built-in Blocksy. Custom header item registered by mu-plugin:

```
mu-plugins/header-items/post-counts/
  config.php   — registers item with Blocksy's header builder
  options.php  — Customizer panel options
  view.php     — renders HTML
```

Blocksy discovers via `blocksy:header:items-paths` filter. Path confirmed at runtime: `/var/www/html/wp-content/mu-plugins/header-items/post-counts`.

### Counts and sparklines

`view.php` runs single SQL query against `wp_posts` grouped by `post_type` and `DATE_FORMAT(post_date, '%x%v')` (ISO year+week). Builds:

- **Total counts** via `wp_count_posts()` per CPT
- **52-week sparklines** — one `int` per ISO week, oldest first — inline SVG `<polyline>`, pure PHP, no JS or charting library

Cached in transient `possee_header_post_counts_v2` for 12 hours. Force refresh: `wp transient delete possee_header_post_counts_v2`.

Sparkline SVG: 52×16px, `preserveAspectRatio="none"`, styled via `.post-counts-sparkline` in `theme-styles.php` (35% opacity, brightens on hover).

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

Book covers from `covers.openlibrary.org`. No API key.

**URL**: `https://covers.openlibrary.org/b/isbn/{isbn}-{S|M|L}.jpg`
- `?default=false` → 404 instead of blank placeholder; JS swap only fires on real image load
- Rate limit: **100 req/IP/5 min**; over limit returns 403
- Sizes: `S` (small), `M` (medium, 80px), `L` (large, 140px)

**Attribution**: link each single book page to `https://openlibrary.org/isbn/{isbn}` (`.book-ol-link`). Built-with page also credits API.

## Backups

```bash
ssh homeip docker exec mariadb mysqldump -u wordpress -p"${MYSQL_PASSWORD}" wordpress | gzip > /storage/Docker/wp-possee/backups/wordpress-$(date +%Y%m%d-%H%M%S).sql.gz
```

## Git commit conventions

- Load `caveman-commit` skill before writing commit message
- Follow **Conventional Commits**: `<type>(<scope>): <summary>`
- Types: `feat`, `fix`, `refactor`, `perf`, `docs`, `test`, `chore`, `style`
- Subject ≤50 chars, imperative mood, no trailing period
- **One commit = one thing** — never bundle unrelated changes
- Body only when *why* isn't obvious; wrap at 72 chars
- No AI attribution, no "this commit does X", no emoji

### Hardcover book backfill: Bearer prefix double-prepend

Hardcover API keys from https://hardcover.app/account/api are **full Authorization header values** (starting with `Bearer `). When using them in PHP `wp_remote_post`, strip the prefix before passing to the script, or check if it's already present:
```php
$api_key = preg_replace( '/^Bearer\s+/i', '', $raw_key );
```
The backfill script (`scripts/backfill-books.php`) handles this via file-fallback: if no CLI arg or env var, reads `/tmp/hc_key.txt` and strips the prefix.

### Blocksy header search: CPTs excluded from `search_through`

Blocksy's header search element has a "Search Through Criteria" setting (Customizer → Header → Search → Search Results → Search Through Criteria) that defaults to `post`, `page`, `product` only. CPTs are set to `false` by default in `options.php` (`$cpt_options[$single_cpt] = false`). The saved value lives in `header_placements` theme mod at `sections[0].items[<idx>].values.search_through`.

**Fix via WP-CLI** — read `header_placements`, find the search item, set each CPT to `1`:
```php
$placements = get_theme_mod('header_placements');
// Walk sections[0].items, find ['id'=>'search'], enable values.search_through[$cpt] = 1
set_theme_mod('header_placements', $placements);
```

On the search results page, the search form reads `ct_post_type` from the URL and perpetuates it. If the initial form is correct (includes all CPTs), subsequent searches stay correct.

### Live search overlay: injecting book covers via `placeholder_image`

Blocksy's live search overlay renders each result using the `placeholder_image` REST field on `search-result` objects. The JS (`search-implementation.js`) uses `ct_featured_media` first, falling back to `placeholder_image`. For books without featured images, overriding `placeholder_image` is the cleanest injection point.

**Approach**: Hook `rest_api_init` at priority 20 (after Blocksy's registration at 10), unregister the existing `placeholder_image` field, re-register with a callback that returns `mf2_book-cover-url` for `subtype === 'book'`:
```php
add_action( 'rest_api_init', 'possee_search_cover_field', 20 );
function possee_search_cover_field() {
    if ( ! isset( $_GET['ct_live_search'] ) || 'true' !== $_GET['ct_live_search'] ) return;
    unregister_rest_field( 'search-result', 'placeholder_image' );
    register_rest_field( 'search-result', 'placeholder_image', array(
        'get_callback' => function ( $post ) {
            if ( 'book' === ( $post['subtype'] ?? '' ) ) {
                $cover = get_post_meta( $post['id'], 'mf2_book-cover-url', true );
                if ( $cover ) return $cover;
            }
            // fallback to product placeholder or SVG icon
        },
    ) );
}
```

`unregister_rest_field()` returns `WP_Error` if the field wasn't registered — check with `is_wp_error()` and proceed regardless.

### Book series HTML: `esc_html` on HTML-containing strings

`possee_book_series_html()` builds the position label as `#3 <span class="book-series-of">of</span> 12`. Using `esc_html()` on the full label double-escapes the inner span. Fix: escape only the raw position number, concatenate the HTML parts directly:
```php
$p = $pos == (int) $pos ? (int) $pos : esc_html( $pos );
$parts[] = '<span class="book-series-position">#' . $p . ' <span class="book-series-of">of</span> ' . (int) $data['series_count'] . '</span>';
```

### Flex children stretching with `align-self: flex-start`

In a flex column (`display: flex; flex-direction: column`), all children stretch to full width by default (`align-items: stretch`). Elements that should only wrap their content width (series accent bar, link buttons) need `align-self: flex-start`. This applies to `.book-series`, `.book-hardcover-link`, `.book-ol-link` in `theme-styles.php`.

### Dot separators in metadata lines

Metadata lines like "Novella · 160 pages · 2018" use `::after` pseudo-elements on `<span>` children (except the last) with `content: "\00b7"`. The parent must be `display: flex; align-items: center;` and children use `:not(:last-child)::after` to avoid a trailing dot.

## Colophon

Site has `/built-with/` page (post ID 385). Update when infrastructure, theme, or plugin list changes.

## NEVER

- **Never set `font-family` in mu-plugin CSS** — Blocksy Customizer owns typography. Exception: `monospace` for code elements.
- **Never run multi-line commands over `ssh homeip` without `bash << 'EOF' ... EOF`** — remote shell is fish.
- **Never edit mu-plugin files directly on server** — edit locally, `scp`, restart. Direct edits overwritten on next deploy.
- **Never skip clearing all three caches after PHP change** — OPcache, nginx fastcgi cache, WP-Optimize disk cache are independent.
- **Never omit `--user 65532` from WP-CLI containers** — uploads dir owned by that UID; omitting silently breaks any file write.
- **Never use `$post->post_excerpt` to check for native excerpt** — use `has_excerpt($post_id)`. Our `get_the_excerpt` filter can return non-empty strings even without native excerpt, causing Blocksy hero to render generated content.
