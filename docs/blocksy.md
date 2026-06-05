# Blocksy Theme Patterns

Card layout, customizer options, meta elements, CSS quirks, and filter priorities.

## CPT card consistency rule

CPT cards on the homepage (`is_home()`) **must render identically** to their standalone archive pages (`/books/`, `/notes/`, `/checkins/`, etc.). Never branch on `is_home()` to render a simpler or different card layout for a CPT. If a CPT needs a custom card, implement it once and let it run everywhere — homepage, archive, and search.

**Exception**: Titleless CPTs (e.g. `note`) may need different card elements on homepage vs archive (e.g. "Read More" button on archive only). If you must branch, keep it minimal and document why.

## Blocksy CPT card layout key naming

CPT card layout keys follow this pattern:
- **Blog/homepage**: `blog_archive_order`
- **CPT archives**: `{cpt}_archive_archive_order` (double `archive`)

Example: `note_archive_archive_order`, `checkin_archive_archive_order`, `book_archive_archive_order`.

The prefix for a CPT is `{cpt}_archive`, and Blocksy appends `_archive_order` to make `{cpt}_archive_archive_order`. The prefix for the blog is just `blog`, making `blog_archive_order`.

**Key absent from DB** → Blocksy silently falls back to hardcoded default with `read_more` disabled. Customizer settings ignored. Fix: copy `blog_archive_order` into CPT key:

```php
set_theme_mod('note_archive_archive_order', get_theme_mod('blog_archive_order'));
```

Same for `checkin_archive_archive_order` and `book_archive_archive_order`. All three set in DB.

Before assuming `set_theme_mod()` write took effect, verify exact key name Blocksy reads at runtime — no warnings on wrong key (`get_theme_mod('note_archive_archive_order')` returns null silently if key is wrong).

## Homepage vs archive card rendering

Don't branch on `is_home()`. Same code path everywhere, suppress unwanted slots. Branching = two code paths + easy-to-miss filter interactions (e.g. excerpt filters).

## How customizer options work

Blocksy stores card/meta layout in `blog_archive_order` theme mod. **Persisted to DB when user saves Customizer.** PHP filters only set defaults — don't affect already-saved values.

### Adding a new card element

1. Filter `blocksy:options:posts-listing-archive-order` to add to `value` and `settings` arrays (default for fresh installs)
2. **Also patch stored DB value** via `set_theme_mod` — existing installs won't see new element otherwise

### Adding an item inside Post Meta

1. Filter `blocksy:options:meta:meta_default_elements` — adds to default value (fresh installs only)
2. Filter `blocksy:options:meta:meta_elements` — adds settings panel in Customizer
3. Action `blocksy:post-meta:render-meta` — render `<li>` when `$id === 'your_id'`
4. **Also patch stored `blog_archive_order`** — find every `post_meta` item, append element to its `meta_elements` array

## Relevant filters

| Filter / Action | Purpose |
|---|---|
| `blocksy:options:posts-listing-archive-order` | Card elements order + Customizer panels |
| `blocksy:archive:render-card-layers` | Build `$outputs` array (fires once per card) |
| `blocksy:archive:render-card-layer` | Render single card element |
| `blocksy:options:meta:meta_default_elements` | Default items in post meta layer |
| `blocksy:options:meta:meta_elements` | Customizer settings panels for meta items |
| `blocksy:post-meta:render-meta` | Render single meta `<li>` item |
| `blocksy:post-meta:items` | Filter full `<ul>` HTML after rendering |

## CSS quirks

- Featured image on single posts: `aspect-ratio: 3/1` applied inline by Blocksy — images crop, not letterbox
- Blocksy nginx + OPcache both cache; clear both after mu-plugin changes
- `blocksy_trim_excerpt` strips HTML — can't inject HTML via `get_the_excerpt`
- Blocksy enqueues external CSS **after** `wp_head` inline `<style>` — ID selectors often needed to beat specificity

## Titleless CPTs (Notes): Read More button

Post type with no title (e.g. `note`) → Blocksy skips `read_more` slot — `$outputs['read_more']` absent. Inject the standard button on note archives:

```php
add_filter( 'blocksy:archive:render-card-layers', 'possee_note_read_more', 11, 3 );
function possee_note_read_more( $outputs, $prefix, $featured_image_args ) {
    if ( get_post_type() !== 'note' || is_feed() ) {
        return $outputs;
    }
    if ( ! empty( $outputs['read_more'] ) ) {
        return $outputs;
    }
    // Only fires on note archives (not homepage or feed).
    // is_home() check was removed — CPT card consistency rule applies.
    $outputs['read_more'] = sprintf(
        '<a class="entry-button wp-element-button ct-button" href="%s">Read More<span class="screen-reader-text"> %s</span></a>',
        esc_url( get_permalink() ),
        esc_html( get_the_date( 'j M Y' ) )
    );
    return $outputs;
}
```

**Only works if `read_more` enabled in archive order** for that CPT. See "CPT card layout key naming" above.

**Do not** append to `$outputs['excerpt']` — puts link inside excerpt div, not Blocksy button slot, breaks when likes facepile injected after excerpt.

## Blocksy header search: CPTs excluded from `search_through`

Blocksy's header search element has a "Search Through Criteria" setting (Customizer → Header → Search → Search Results → Search Through Criteria) that defaults to `post`, `page`, `product` only. CPTs are set to `false` by default in `options.php` (`$cpt_options[$single_cpt] = false`). The saved value lives in `header_placements` theme mod at `sections[0].items[<idx>].values.search_through`.

**Fix via WP-CLI** — read `header_placements`, find the search item, set each CPT to `1`:
```php
$placements = get_theme_mod('header_placements');
// Walk sections[0].items, find ['id'=>'search'], enable values.search_through[$cpt] = 1
set_theme_mod('header_placements', $placements);
```

On the search results page, the search form reads `ct_post_type` from the URL and perpetuates it. If the initial form is correct (includes all CPTs), subsequent searches stay correct.

## Live search overlay: injecting book covers via `placeholder_image`

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
            // fallback to SVG icon placeholder
        },
    ) );
}
```

`unregister_rest_field()` returns `WP_Error` if the field wasn't registered — check with `is_wp_error()` and proceed regardless.

## Filter priorities used

| Filter | Priority | Reason |
|---|---|---|
| `the_content` (e-content wrapper) | 20 | After Simple Location map at 11/12 |
| `the_content` (syndication strip) | 999 | Must run last on non-singular |
| `get_the_excerpt` (checkin) | 5 | Before default excerpt processing |
| `blocksy:archive:render-card-layers` (checkin map) | 10 | Default |
| `blocksy:archive:render-card-layers` (note read_more) | 11 | After checkin map at 10 |
| `blocksy:archive:render-card-layers` (note type badge) | 8 | Before read_more/excerpt |
| `get_comment_text` (suppress "Bridgy Response") | 13 | Runs before via_label (same priority, registers first) |
| `get_comment_text` (via label) | 13 | Runs after suppress (registers second) |
| `pre_get_comments` (save type__not_in) | 9 | Before Webmention plugin at 10 |
| `pre_get_comments` (restore/strip) | 11 | After Webmention plugin at 10 |
| `webmention_comment_data` (spam bsky.app) | 22 | After default processing |
