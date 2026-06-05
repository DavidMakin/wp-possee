# Features

## Book series HTML: `esc_html` on HTML-containing strings

`possee_book_series_html()` builds the position label as `#3 <span class="book-series-of">of</span> 12`. Using `esc_html()` on the full label double-escapes the inner span. Fix: escape only the raw position number, concatenate the HTML parts directly:
```php
$p = $pos == (int) $pos ? (int) $pos : esc_html( $pos );
$parts[] = '<span class="book-series-position">#' . $p . ' <span class="book-series-of">of</span> ' . (int) $data['series_count'] . '</span>';
```

## Flex children stretching with `align-self: flex-start`

In a flex column (`display: flex; flex-direction: column`), all children stretch to full width by default (`align-items: stretch`). Elements that should only wrap their content width (series accent bar, link buttons) need `align-self: flex-start`. This applies to `.book-series`, `.book-hardcover-link`, `.book-ol-link` in `theme-styles.php`.

## Dot separators in metadata lines

Metadata lines like "Novella · 160 pages · 2018" use `::after` pseudo-elements on `<span>` children (except the last) with `content: "\00b7"`. The parent must be `display: flex; align-items: center;` and children use `:not(:last-child)::after` to avoid a trailing dot.

## Header Post Counts widget

"Post Counts" element in header (Blocksy header builder, middle-row, end column). Configured **entirely in Customizer UI** — no PHP filter for item list.

### Config storage

All header builder state in `header_placements` theme mod. `post-counts` item in `items` array with optional `values` key. When absent, widget uses Blocksy default (standard posts only, labelled "Articles").

### Adding CPTs to count

1. **Customizer UI** (preferred) — Appearance → Customize → Header → middle row → Post Counts. Writes `values` to DB automatically.

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

Not built-in Blocksy. Registered by mu-plugin:

```
mu-plugins/header-items/post-counts/
  config.php   — registers item with Blocksy's header builder
  options.php  — Customizer panel options
  view.php     — renders HTML
```

Blocksy discovers via `blocksy:header:items-paths` filter.

### Counts and sparklines

`view.php` runs single SQL query against `wp_posts` grouped by `post_type` and `DATE_FORMAT(post_date, '%x%v')` (ISO week). Builds:

- **Total counts** via `wp_count_posts()` per CPT
- **52-week sparklines** — one `int` per ISO week, oldest first — inline SVG `<polyline>`, pure PHP

Cached in transient `possee_header_post_counts_v2` for 12 hours. Force refresh: `wp transient delete possee_header_post_counts_v2`.

Sparkline SVG: 52×16px, `preserveAspectRatio="none"`, styled via `.post-counts-sparkline` in `theme-styles.php` (35% opacity, brightens on hover).

## Book display

### Open Library Covers API

Book covers from `covers.openlibrary.org`. No API key.

**URL**: `https://covers.openlibrary.org/b/isbn/{isbn}-{S|M|L}.jpg`
- `?default=false` → 404 instead of blank placeholder; JS swap only fires on real load
- Rate limit: **100 req/IP/5 min**; over limit returns 403
- Sizes: `S` (small), `M` (medium, 80px), `L` (large, 140px)

**Attribution**: Link each single book page to `https://openlibrary.org/isbn/{isbn}` (`.book-ol-link`). Built-with page also credits API.

### Book card rendering

- **Single view**: 140px cover (L) + Hardcover + Open Library links
- **Archive view**: 80px cover (M)
- Cover images: SVG placeholder as `src`, real cover as `data-cover-src`; JS swaps on `onload`
- `?default=false` means failed loads return 404 (no `onload` event) — the placeholder remains. This is acceptable since failed covers are invisible.

### Book data

`possee_book_get_data($post_id)` reads:
- `mf2_read-of` — serialized h-cite (title, author, isbn from `uid: isbn:XXXXX`)
- `mf2_read-status` — reading status
- Rating parsed from excerpt `(N/5)` pattern
