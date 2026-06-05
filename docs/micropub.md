# Micropub & Microformats

e-content handling, Bridgy Bluesky content extraction, and related filter interactions.

## e-content nesting (accepted behavior)

Micropub plugin's `Render::render_content` hooks `the_content` at **priority 1**. For any post with `micropub_version` meta set and no `mf2_content` meta, it wraps content in `<div class="e-content">`.

Our `possee_wrap_econtent` hooks `the_content` at **priority 20** and also wraps content in `<div class="e-content">`.

This creates **nested `e-content` divs**. The nesting is intentional and accepted:

- `remove_filter()` does not reliably remove the Micropub renderer — the filter stays registered.
- Regex-stripping the inner wrapper is fragile — Micropub's `generate_post_content` may emit other content before/after the `e-content` div (interactions, gallery shortcodes).
- Bridgy reads `p-bridgy-bluesky-content` instead of parsing `e-content` (see below), so the nesting doesn't affect syndication.

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

## Bridgy Bluesky content: always emit `p-bridgy-bluesky-content`

Bridgy reads the `e-content` microformat value to determine what to post to Bluesky. The `e-content` div includes all inner text — including `dt-published` ISO timestamp, `u-url`, "Added via Quill" footers, and Micropub wrappers.

**Fix**: `SynProvider_Webmention_Bridgy_Bluesky::wp_footer()` always emits `<p class="p-bridgy-bluesky-content" style="display:none">` on singular posts. Bridgy treats this as authoritative and ignores `e-content`. The text is `wp_strip_all_tags(get_the_content())` — raw post text with no metadata or footers.

**Hidden div placement**: The hidden `p-bridgy-bluesky-content` must be placed **outside** `e-content` (before its opening tag). If inside `e-content`, the timestamp/footer text is still included in what Bridgy reads.

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
| `the_content` | 999 | Syndication strip | Removes syndication-links div on non-singular |
