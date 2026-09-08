# Micropub & Microformats

e-content handling, what gets syndicated, and related filter interactions.

## e-content nesting (accepted behavior)

Micropub plugin's `Render::render_content` hooks `the_content` at **priority 1**. For any post with `micropub_version` meta set and no `mf2_content` meta, it wraps content in `<div class="e-content">`.

Our `possee_wrap_econtent` hooks `the_content` at **priority 20** and also wraps content in `<div class="e-content">`.

This creates **nested `e-content` divs**. The nesting is intentional and accepted:

- `remove_filter()` does not reliably remove the Micropub renderer — the filter stays registered.
- Regex-stripping the inner wrapper is fragile — Micropub's `generate_post_content` may emit other content before/after the `e-content` div (interactions, gallery shortcodes).
- Syndication never sees the wrappers: they are added by `the_content` filters that bail unless `is_singular()` and `in_the_loop()`, and syndication runs outside the loop (see below).

Accept the nesting. The `e-content` wrapper code in `microformats.php`:

```php
add_filter( 'the_content', 'possee_wrap_econtent', 20 );
function possee_wrap_econtent( $content ) {
    // ...is_singular guard, static $done flag...
    $hidden = '<div style="display:none">'
        . '<time class="dt-published" datetime="' . esc_attr( $iso ) . '">...</time>'
        . '<a class="u-url" href="' . esc_url( $permalink ) . '">...</a>'
        . '</div>';
    return $hidden . '<div class="e-content">' . $content . '</div>';
}
```

The hidden div (dt-published, u-url) is placed **before** the e-content opening tag, not inside it. This keeps ISO timestamps and URLs out of what Bridgy parses as e-content text.

## What Bridgy publishes

Publishing goes over Micropub, so Bridgy posts the `content` property from the request and never parses the page. Bridgy always uses `content`, even when `name` is also sent, so titles do not become the post text.

Syndication Links builds that content in `SynProvider_Micropub::get_content()`:

- `syn_get_post_content()` runs the raw content through `the_content`. Our filters all bail unless `is_singular()` and `in_the_loop()`, so none of the on-page furniture reaches Bridgy.
- With `syndication_use_excerpt` set and a post excerpt present, the excerpt replaces the content and the backlink is dropped. Otherwise the text is truncated (300 chars for Bluesky, 500 for Mastodon) with the shortlink in brackets.

## `get_comment_text` filter order (priority 13)

Two filters run at the same priority (13). Registration order determines execution:

1. `possee_suppress_bridgy_response` (registers first) — returns `''` for 'like' comments whose text is "Bridgy Response"
2. `possee_via_label` (registers second) — appends ` (via Bluesky)` or ` (via Mastodon)` based on `webmention_source_url` meta

The suppress-then-append order is correct: likes get cleared of the generic "Bridgy Response" text and tagged with the actual platform name.

## Relevant filter interactions

| Hook | Priority | Filter | Purpose |
|---|---|---|---|
| `the_content` | 1 | Micropub `Render::render_content` | Wraps in e-content (if micropub_version meta) |
| `the_content` | 11, 12 | Simple Location | Map + location text |
| `the_content` | 15 | `possee_book_card_html` | Prepends book card on 'book' posts |
| `the_content` | 20 | `possee_wrap_econtent` | Wraps in e-content + hidden dt-published/u-url |
| `the_content` | 21 | `possee_render_micropub_photos` | Renders mf2_photo entries as u-photo figures |
| `the_content` | 999 | Syndication strip | Removes syndication-links div on non-singular |

## Micropub photo extraction for syndication

When Micropub posts contain Gutenberg image blocks, the images must be extracted into `mf2_photo` post meta for syndication to Bluesky/Mastodon to work correctly.

**Two extraction paths:**

### 1. During Micropub post creation
`possee_extract_micropub_gutenberg_photos()` hooks `pre_insert_micropub_post` at priority 2 (after logging, before insertion):
- Parses Gutenberg `<!-- wp:image {id:...} -->` blocks via regex
- Extracts image IDs and fetches full URLs via `wp_get_attachment_image_src()`
- Stores as `mf2_photo` serialized array in `meta_input` before post insert
- Skips if `mf2_photo` already in payload (don't override Micropub request photos)

### 2. When posts edited in wp-admin
`possee_extract_gutenberg_photos()` hooks `save_post` at priority 10:
- Runs when posts saved from WordPress editor
- Extracts Gutenberg image blocks from post_content into `mf2_photo` meta
- Only processes if post is published and doesn't already have `mf2_photo`

### Photo rendering
`possee_render_micropub_photos()` hooks `the_content` at priority 21:
- Reads `mf2_photo` post meta (serialized array with 'value' and 'alt' keys)
- Renders `<figure class="micropub-photo"><img class="u-photo" ... ></figure>` after content
- Photos available to microformat readers and Bridgy for syndication

**Schema**: `mf2_photo` is stored as serialized PHP array per Micropub spec:
```php
[
    ['value' => 'https://...', 'alt' => 'photo description'],
    ['value' => 'https://...', 'alt' => ''],
]
```

**Known issue**: Syndication Links plugin has a "post too old" check that blocks re-syndication of existing posts. The gate lives in `Post_Syndication::syndication()` and rejects posts whose `post_date` is older than 1 day (`DAY_IN_SECONDS`, hardcoded at class-post-syndication.php:202, measured from original publish date, NOT `post_modified`). Posts edited after creation won't re-syndicate via the normal path. Note: the gate only exists in `Post_Syndication::syndication()` — the provider `posse()` method has no age check, so a direct provider call bypasses it. Recovery: set `_syndicate-to` + brid.gy/publish target links, then call the provider `posse()` directly (protected `$targets` requires ReflectionProperty), then prune target links and clear stale `syndication_log` entries. The `syn_syndication` action does NOT bypass the gate — it routes through `Post_Syndication::syndication()`.
