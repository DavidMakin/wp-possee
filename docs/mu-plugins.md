# mu-plugins Reference

Per-file documentation for all mu-plugins at `/var/www/html/wp-content/mu-plugins/`.

## `microformats.php`

**IndieWeb microformats & meta tags**
- `rel="me"` links in `<head>` for Mastodon (`@_sleeper@hachyderm.io`) + Bluesky (`sleep-er.bsky.social`)
- OpenGraph + Twitter Card meta tags on singular posts and front page
- `h-entry` class on `post_class`, `p-name` span on singular titles

**Bridgy Bluesky syndication provider**
- Extends `SynProvider_Webmention_Bridgy` as `SynProvider_Webmention_Bridgy_Bluesky`
- Registers `webmention-bluesky-bridgy` provider for Syndication Links
- Outputs hidden `.p-bridgy-bluesky-content` on singular posts — Bridgy uses this instead of `e-content`

**Delayed Bridgy cron + discover ping**
- `possee_bridgy_delayed_handler`: fires 90s after Micropub post; calls `syn_syndication`, clears WP-Optimize cache, pings Bridgy discover
- `possee_clear_post_page_cache($post_id)`: clears WP-Optimize disk cache for the post URL
- `possee_ping_bridgy_discover($post_id)`: POSTs to `https://brid.gy/discover`

**Syndication Links as Blocksy Card Element**
- `reading_time` meta element: word-count → "N min read"
- `syndication_links` meta element: renders syndication link icons

**Checkin posts**
- `get_the_excerpt` (priority 5): full content as excerpt instead of truncation
- `blocksy:archive:render-card-layers` (priority 10): injects static map image

**Syndication content handling**
- `the_content` (priority 999): strips syndication-links div from non-singular views
- `syn_link_mapping`: maps `hachyderm.io` → `mastodon` icon
- `the_content` (priority 20): wraps singular content in `<div class="e-content">`

**Category cleanup**
- `get_the_terms`: hides Uncategorised/Uncategorized from display

**Micropub post sanitisation**
- `pre_insert_micropub_post`: sanitises tags, generates checkin content, sets `post-format-status`

**Named functions**: All hooks use named functions (`possee_*` prefix) for grepability. Only closure: `plugins_loaded` (wraps class definition).

**Book meta dedup map**: `$book_meta_map` in `possee_micropub_book_deduplicate` maps Micropub property names (e.g. `hardcover-slug`, `book-pages`, `book-cover-url`) to `mf2_*` meta keys. To add a new book field: (1) add to n8n GraphQL query, (2) add Micropub property in JS payload, (3) add to `$book_meta_map`. No PHP template changes needed if the display code already handles it.

## `theme-styles.php`

CSS for elements not configurable via Blocksy Customizer. See source for full details.

| Feature | Description |
|---|---|
| `code` styling | Inline code color `#ce887b`, border `#607D8B` |
| `pre` styling | Light bg `#faf9f9`, rounded corners, `pre-wrap` |
| `blockquote` | Left border `#263959`, italic, muted color |
| `.entry-card` | Border-radius `10px`, box-shadow, hover lift transition |
| `.entry-meta` | Muted color `#a0a0a0`, `12px` font |
| `table` | Bordered, striped even rows, gradient header |
| Featured image lightbox | Click → overlay, vanilla JS |
| `.likes` / `.reposts` facepile | `28px` round avatars, `-8px` overlap, +N overflow |
| `.sloc-map-thumb` | Checkin map: 4/3 aspect, border, shadow |

**JS (via wp_footer)**: Heart SVG for likes facepile, image lightbox.

## `comments.php`

Comment & webmention handling.

- `get_comments_number` (priority 10): subtracts webmention-type comments from count
- `pre_get_comments` (priority 9): saves original `type__not_in` before Webmention plugin overwrites at priority 10
- `pre_get_comments` (priority 11): restores or strips webmention types
- `semantic_linkbacks_enhance_comment_types`: adds `'like'` for Bridgy Bluesky likes
- `get_comment_text` (priority 13): suppresses "Bridgy Response" text on likes
- `get_comment_text` (priority 13): appends ` (via Mastodon)` or ` (via Bluesky)`
- `webmention_comment_data` (priority 22): marks Bridgy Fed self-comments as spam

## `loopback-fix.php`

WordPress loopback HTTPS fails inside Docker — `wp_safe_remote_get()` resolves public domain to nginx container private IP.

- `http_request_args` (priority 1): disables `reject_unsafe_urls` for own domain
- `pre_http_request` (priority 10): rewrites `https://domain` → `http://nginx`, sets `Host` header
- Static `$in_progress` guard prevents infinite recursion

## `books.php`

Book display and Open Library cover fetching.

- `possee_book_get_data($post_id)` — reads `mf2_read-of` (h-cite), extracts title, author, isbn, status, rating
- `possee_book_cover_url($isbn, $size)` — `https://covers.openlibrary.org/b/isbn/{isbn}-{size}.jpg?default=false`
- `possee_book_cover_img_html()` — `<img>` with SVG placeholder, real cover as `data-cover-src`
- `possee_book_cover_loader_script()` — `wp_footer` JS; swaps on `onload`
- `possee_book_card_html($post_id, $data, $context)` — `'single'`: 140px cover + links; `'archive'`: 80px cover
- `possee_book_stars_html($rating)` — `★`/`☆` with `aria-label`
- `has_excerpt` filter: suppresses Blocksy hero excerpt on book posts
- `blocksy:archive:render-card-layers` (priority 10): renders `book-archive-row`
- `blocksy:archive:render-card-layer` (priority 10): suppresses `post_meta` on book archive
- `book-status--{slug}` CSS class drives status badge theming

**`mf2_*` meta caveat**: The Micropub plugin stores all meta values as serialized arrays. `get_post_meta('mf2_hardcover-slug', true)` returns `['exit-strategy']`, not a string. Use the `$possee_meta()` closure in `possee_book_get_data()` for safe unwrapping, or add an `is_array()` check before using raw `get_post_meta()` results. Without this, `esc_attr($array_val)` renders "Array" in HTML.

**Open Library attribution**: Single book pages link to `https://openlibrary.org/isbn/{isbn}`. Rate limit: 100 req/IP/5 min.

## `post-types.php`

CPT registration and URL rewriting for `book`, `note`, `checkin`.

- Notes have no title — `wp_insert_post_data` blanks `post_title` for `note` type
- `possee_note_read_more` (priority 11): injects Read More button on note archives
- `possee_note_type_badge` (priority 8): injects "Note" SVG badge before excerpt
- CPT archives: `/books/`, `/notes/`, `/checkins/`

## `homepage-highlights.php`

Shows a highlights section on the homepage with the latest 3 posts from each content type (articles, notes, books). Fires on `blocksy:loop:before` only when `is_home()`. Fetches posts by tag and renders a grid of cards.

## `header-post-counts.php`

Registers the `mu-plugins/header-items/` path with Blocksy via `blocksy:header:items-paths` filter. The actual post-counts header item lives at `mu-plugins/header-items/post-counts/` (config.php, options.php, view.php).

## `mermaid.php`

Loads the Mermaid.js library (CDN) on posts that contain a `<pre class="mermaid">` or similar block. Enqueued via `wp_footer`. Small utility — 19 lines.

## `pretty-archives.php`

Adds rewrite rules for pretty CPT archive URLs. Maps `/articles/` → tag `article`, `/books/` → tag `book`, `/notes/` → tag `note`, `/checkins/` → tag `checkin`. Also handles pagination (`/page/N/`) and feed URLs. 150 lines — most complex of the undocumented mu-plugins.
