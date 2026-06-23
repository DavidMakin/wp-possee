<?php
// Enable the Image block "Expand on click" (lightbox) in WP 6.9+.
// Blocksy's theme.json doesn't declare settings.lightbox, so inject via filter.
// Two paths: theme_json for frontend rendering, block_editor_settings for editor UI toggle.
add_filter('wp_theme_json_data_theme', function ($theme_json) {
    $data = $theme_json->get_data();
    if (! isset($data['settings']['lightbox'])) {
        $data['settings']['lightbox'] = array(
            'enabled'      => true,
            'allowEditing' => true,
        );
    }
    $theme_json->update_with($data);
    return $theme_json;
});

// Ensure lightbox setting reaches the block editor's useSettings('lightbox') hook.
// __experimentalFeatures is set from wp_get_global_settings() which includes lightbox,
// but the store may not merge it to root level for all keys. Pass explicitly.
add_filter('block_editor_settings_all', function ($settings) {
    if (! isset($settings['lightbox'])) {
        $settings['lightbox'] = array(
            'enabled'      => true,
            'allowEditing' => true,
        );
    }
    return $settings;
});

add_action('wp_head', function () {
    ?>
<style>
code:not(pre code) {
    font-family: monospace, monospace;
    color: #ce887b;
    border: 1px solid #607D8B;
    border-radius: 3px;
    padding: 0.1rem 0.5rem;
    font-size: 0.9em;
}

pre {
    background-color: #faf9f9;
    border-radius: 3px;
    padding: 1rem 2rem;
    overflow: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}

pre code {
    border: none;
    padding: 0;
    color: inherit;
    background: none;
    font-size: 1em;
}

blockquote {
    border-left: 5px solid #263959;
    padding-left: 1.1rem;
    margin-left: 1rem;
    font-style: italic;
    color: #ada8a8;
}

.entry-card {
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 1px 0 rgba(31, 35, 46, 0.15);
    transition: all .3s ease;
    background-color: #ffffff;
    position: relative;
}

.entry-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background-color: transparent;
}

.entry-card.type-post::before    { background-color: #5c7cfa; }
.entry-card.type-note::before    { background-color: #94d82d; }
.entry-card.type-book::before    { background-color: #f59f00; }
.entry-card.type-checkin::before { background-color: #e64980; }

/* ── CPT single pages: same left-accent strip as archive cards ── */
.single article.type-post,
.single article.type-note,
.single article.type-book,
.single article.type-checkin {
    position: relative;
}
.single article::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background-color: transparent;
}
.single article.type-post::before    { background-color: #5c7cfa; }
.single article.type-note::before    { background-color: #94d82d; }
.single article.type-book::before    { background-color: #f59f00; }
.single article.type-checkin::before { background-color: #e64980; }

.entry-card:hover {
    transform: translate(0, -2px);
    box-shadow: 0 15px 45px -10px rgba(10, 16, 34, 0.2);
}

.entry-meta,
.entry-meta a,
.posted-on,
.byline {
    color: #a0a0a0;
    font-size: 12px;
}

table {
    border: 1px solid #aaa;
    background-color: #eee;
    width: 100%;
    text-align: left;
    border-collapse: collapse;
}

table td,
table th {
    border: 1px solid #aaa;
    padding: 3px 2px;
}

table tbody td {
    font-size: 13px;
}

table tr:nth-child(even) {
    background: #adbecc;
}

table thead {
    background: linear-gradient(to bottom, #bed3dc 0%, #b1cad5 66%, #a9c4d1 100%);
    border-bottom: 1px solid #8c8c8c;
}

table thead th {
    font-size: 14px;
    font-weight: bold;
    color: #fff;
    border-left: 1px solid #d0e4f5;
}

table thead th:first-child {
    border-left: none;
}

.meta-categories:empty {
    display: none;
}

.entry-card .syndication-links {
    display: none;
}

/* Syndication link icons in post meta */
.meta-syndication-links .syndication-link-icon {
    display: inline-flex;
    width: 1rem;
    height: 1rem;
}

.meta-syndication-links .syndication-link-icon svg {
    width: 100%;
    height: 100%;
}

/* Featured image lightbox */
.single .ct-featured-image .ct-media-container,
.is-book-post .book-card--single .book-cover-img,
.micropub-photo .u-photo {
    cursor: zoom-in;
}

#blx-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(0,0,0,0.9);
    cursor: zoom-out;
    align-items: center;
    justify-content: center;
}

#blx-overlay.blx-open {
    display: flex;
}

#blx-overlay img {
    max-width: 95vw;
    max-height: 95vh;
    object-fit: contain;
    border-radius: 4px;
    box-shadow: 0 4px 40px rgba(0,0,0,0.6);
}

/* Bluesky-style likes/reposts facepile — compact, small round avatars */
.likes,
.reposts {
    margin: 1.5em 0;
    display: flex;
    align-items: center;
    gap: 0.4em;
}

.entry-card .likes,
.entry-card .reposts {
    margin: 0.5em 0 0.3em;
    line-height: 1;
}

.likes h3,
.reposts h3 {
    display: none;
}

.likes .mention-list,
.reposts .mention-list {
    display: inline-flex;
    align-items: center;
    padding: 0;
    margin: 0;
    list-style: none;
}

.likes .mention-list li,
.reposts .mention-list li {
    margin: 0 0 0 -8px;
}

.likes .mention-list li:first-child,
.reposts .mention-list li:first-child {
    margin-left: 0;
}

.likes .mention-list li a,
.reposts .mention-list li a {
    display: block;
    line-height: 0;
}

.likes .mention-list li img,
.reposts .mention-list li img {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 2px solid #fff;
    object-fit: cover;
    display: block;
    background: #f0f0f0;
}

.likes .likes-label {
    font-size: 0.85em;
    color: #666;
    white-space: nowrap;
}

.likes .additional-facepile-button-list-item {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 2px solid #fff;
    background: #f0f0f0;
    margin: 0 0 0 -8px;
    font-size: 11px;
    color: #666;
    cursor: pointer;
    list-style: none;
}

.likes .additional-facepile-button-list-item button {
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    color: inherit;
    cursor: pointer;
}

.reposts {
    margin: 0.5em 0;
}

/* Tighten comments section spacing — higher specificity to override Blocksy external CSS */
#comments .ct-comments-title {
    margin-bottom: 20px;
}
.ct-comments-title {
    margin-bottom: 20px;
}
.ct-comment-inner {
    padding-block: 15px;
}
.comment-respond + .ct-comment-list {
    margin-top: 20px;
}
.comment-respond:not(:only-child) .comment-reply-title {
    padding-top: 20px;
}
#comments {
    margin-top: 25px;
    padding-top: 25px;
}

.checkin-excerpt {
    font-size: 0.9em;
    color: #555;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.checkin-excerpt-venue {
    display: flex;
    align-items: center;
    gap: 4px;
}

.checkin-excerpt-venue .checkin-venue-icon {
    flex-shrink: 0;
}

.checkin-excerpt-meta {
    font-size: 0.92em;
    color: #888;
}

.checkin-excerpt a {
    color: #333;
    font-weight: 600;
    text-decoration: none;
}

.checkin-excerpt a:hover {
    text-decoration: underline;
}

/* ── Note single: Simple Location map + location ──────────────── */
/* Notes have geo data from Quill — make the map a compact thumbnail,
   not a full-width 1024px behemoth. The note content is the focus. */
.single-note .sloc-map {
    display: block;
    max-width: 320px;
    width: 100%;
    height: auto;
    aspect-ratio: 4/3;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #ddd;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    margin: 1em 0 0.4em;
}
.single-note .sloc-display {
    font-size: 0.85em;
    color: #777;
    line-height: 1.5;
}
.single-note .sloc-display .sloc-weather {
    display: inline;
}
.single-note .sloc-display .sloc-temperature {
    display: inline;
}

/* ── Note archive card: location + weather ────────────────────── */
/* Added by possee_note_location_weather (microformats.php) after suppressing
   Simple Location's the_content injection on non-singular pages. The text
   appears on its own line, visually subordinate to the note body. */
.entry-card .note-location-weather {
    font-size: 0.85em;
    color: #888;
    margin-top: 0.5em;
    line-height: 1.4;
}

/* ── Checkin card: map thumbnail (no featured image) ──────────── */
.entry-card .sloc-map-thumb {
    display: block;
    width: 100%;
    margin-top: 0.75em;
    aspect-ratio: 4/3;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #ddd;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

/* ── Homepage highlights strip ───────────────────────────────── */
.possee-highlights {
    margin-bottom: 2.5rem;
    background-color: #1e2433;
    border-radius: 12px;
    padding: 1.25rem;
}

.possee-highlights__grid {
    margin-bottom: 0;
}

.possee-highlights .entry-card {
    background-color: #2d3a52;
    box-shadow: none;
    font-size: 0.875rem;
    --theme-text-color: #e8ecf4;
    --theme-link-initial-color: #e8ecf4;
    --theme-link-hover-color: #ffffff;
    --theme-headings-color: #e8ecf4;
    --theme-heading-color: #e8ecf4;
    color: #e8ecf4;
    min-height: 280px;
}

.possee-highlights .entry-card .entry-title {
    --theme-link-hover-color: #ffffff;
    --theme-heading-color: #e8ecf4;
}

.possee-highlights .sloc-map-thumb {
    aspect-ratio: 16/9;
    max-height: 110px;
    margin-top: 0.5em;
}

.possee-highlights .entry-card::before {
    width: 3px;
}

.possee-highlights .entry-card:hover {
    background-color: #3d4f6e;
    box-shadow: none;
    transform: translate(0, -2px);
}

.possee-highlights .entry-card:hover,
.possee-highlights .entry-card:hover *,
.possee-highlights .entry-card:hover a,
.possee-highlights .entry-card:hover a:hover {
    color: #e8ecf4 !important;
}

.possee-highlights [data-cards="boxed"] .entry-card:hover {
    background-color: #2e3650;
}

.possee-highlights .entry-card a:hover {
    color: #ffffff;
}

.possee-highlights__label {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    border-left: none !important;
    background-color: transparent !important;
    box-shadow: none !important;
}

.possee-highlights__label:hover {
    transform: none !important;
}

.possee-highlights__label span {
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #6b7a99;
    text-align: center;
}

.possee-highlights .card-content > * {
    --card-element-spacing: 10px;
}

@media (max-width: 689px) {
    .possee-highlights {
        display: none;
    }
}

/* ── Checkin single post: header block ───────────────────────── */
.checkin-header {
    margin-bottom: 1.5em;
}

.checkin-header .checkin-map {
    display: block;
    width: 100%;
    aspect-ratio: 3/1;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #ddd;
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
    margin-bottom: 0.75em;
}

.checkin-meta {
    display: flex;
    flex-direction: column;
    gap: 0.2em;
}

.checkin-venue {
    font-size: 1.15em;
    font-weight: 600;
    color: #222;
    display: flex;
    align-items: center;
    gap: 6px;
}

.checkin-by {
    font-size: 0.85em;
    color: #777;
    margin-top: 2px;
}

.checkin-by a {
    color: #555;
}

.checkin-venue-link {
    font-weight: 700;
    color: #1a1a1a;
    text-decoration: none;
}

.checkin-venue-link:hover {
    text-decoration: underline;
}

.checkin-place {
    font-size: 0.9em;
    color: #666;
}

.checkin-weather {
    font-size: 0.85em;
    color: #888;
}

.checkin-coords-wrap {
    font-size: 0.8em;
    color: #aaa;
}

.checkin-coords {
    color: inherit;
    text-decoration: none;
    font-family: monospace, monospace;
}

.checkin-coords:hover {
    color: #555;
}

.checkin-via {
    font-size: 0.8em;
    color: #aaa;
    margin-top: 1em;
}

.checkin-via a {
    color: #aaa;
}
.checkin-coins {
    margin-top: 12px;
    padding: 10px 14px;
    background: #fffbea;
    border-left: 3px solid #c8a000;
    border-radius: 4px;
    font-size: 0.88em;
}

.checkin-coins-total {
    font-weight: 700;
    font-size: 1.1em;
    color: #c8a000;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.checkin-coins-total span {
    font-weight: 400;
    color: #888;
    font-size: 0.85em;
    margin-left: 2px;
}

.checkin-coins-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.checkin-coins-list li {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3px 0;
    color: #555;
}

.coin-points {
    font-weight: 700;
    color: #c8a000;
    min-width: 28px;
}

.checkin-coins-list img {
    flex-shrink: 0;
}
/* ── Venue page: recent check-ins ────────────────────────────── */
.venue-checkins {
    margin-top: 2em;
    border-top: 1px solid #e5e5e5;
    padding-top: 1.25em;
}

.venue-checkins-title {
    font-size: 1em;
    font-weight: 600;
    color: #555;
    margin: 0 0 0.75em;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.venue-checkins-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.venue-checkin-item {
    display: flex;
    align-items: baseline;
    gap: 0.5em;
    padding: 0.35em 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 0.9em;
}

.venue-checkin-item:last-child {
    border-bottom: none;
}

.venue-checkin-date {
    font-weight: 600;
    color: #333;
    text-decoration: none;
    white-space: nowrap;
}

.venue-checkin-date:hover {
    text-decoration: underline;
}

.venue-checkin-by {
    color: #555;
}

.venue-checkin-meta {
    color: #999;
    margin-left: auto;
    white-space: nowrap;
}

/* Post type badge — notes on homepage/archive */
.post-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3em;
    margin-bottom: 0.4em;
    line-height: 1;
}

.post-type-badge--note {
    color: #1a6fa8;
    font-size: 0.75em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

</style>
    <?php
}, 5);

add_action('wp_head', function () { ?>
<style>
/* Post counts header widget */
.ct-header-post-counts .post-counts-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5em 1em;
    list-style: none;
    margin: 0;
    padding: 0;
}

.ct-header-post-counts .post-counts-item a {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-decoration: none;
    line-height: 1.2;
}

.ct-header-post-counts .post-counts-number {
    font-size: 1.1em;
    font-weight: 700;
    color: var(--theme-text-color);
}

.ct-header-post-counts .post-counts-label {
    font-size: 0.7em;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--theme-text-color);
    opacity: 0.6;
}

.ct-header-post-counts .post-counts-item a:hover .post-counts-number,
.ct-header-post-counts .post-counts-item a:hover .post-counts-label {
    color: var(--theme-link-hover-color);
    opacity: 1;
}

.ct-header-post-counts .post-counts-sparkline {
    display: block;
    margin-top: 4px;
    opacity: 0.35;
    color: var(--theme-text-color);
}

.ct-header-post-counts .post-counts-item a:hover .post-counts-sparkline {
    opacity: 0.8;
    color: var(--theme-link-hover-color);
}
</style>
    <?php
}, 6);

add_action('wp_head', function () {
    if (! is_singular('book') && ! is_post_type_archive('book') && ! is_home() && ! is_search()) {
        return;
    }   ?>
<style>
.is-book-post .page-description {
    display: none;
}

.book-card {
    display: flex;
}

.book-card--single {
    background: #fafaf8;
    border: 1px solid #eae8e4;
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
}

.book-card .h-cite {
    display: flex;
    gap: 1.5rem;
    align-items: flex-start;
}

.book-cover {
    flex-shrink: 0;
}

.ct-media-container--book {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e8e4df;
    overflow: hidden;
    max-height: 220px;
}

.book-home-cover {
    width: auto;
    height: auto;
    max-height: 220px;
    max-width: 100%;
    object-fit: contain;
    display: block;
}

.book-cover-img {
    display: block;
    border-radius: 4px;
    box-shadow: 2px 4px 12px rgba(0,0,0,0.18);
}

.book-card--single .book-cover-img {
    width: 160px;
}

.book-card--archive .book-cover-img {
    width: 80px;
}

.book-archive-row {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
}

.book-archive-cover {
    flex-shrink: 0;
}

.book-archive-cover .book-cover-img {
    width: 80px;
    height: auto;
    display: block;
    border-radius: 3px;
    box-shadow: 2px 3px 8px rgba(0,0,0,0.18);
}

.book-archive-info {
    display: flex;
    flex-direction: column;
    gap: 0.3em;
}

.book-archive-title {
    font-weight: 700;
    font-size: 1.05em;
    line-height: 1.3;
    color: var(--theme-text-color);
    text-decoration: none;
}

.book-archive-title:hover {
    color: var(--theme-link-hover-color);
}

.book-meta {
    display: flex;
    flex-direction: column;
    gap: 0.35em;
}

.book-title {
    font-size: 1.2em;
    font-weight: 700;
    color: var(--theme-text-color);
    line-height: 1.3;
}

.book-author {
    color: #777;
    font-size: 0.95em;
}

/* Series: subtle left accent — inline-width so bg doesn't span full cell */
.book-series {
    display: inline-block;
    font-size: 0.78em;
    color: #888;
    font-style: italic;
    margin-top: 0.25em;
    padding: 0.25em 0.5em 0.25em 0.6em;
    border-left: 2px solid #d4c8b0;
    background: #f6f4f0;
    border-radius: 0 4px 4px 0;
    align-self: flex-start;
}
.book-series-position {
    font-style: normal;
    font-weight: 600;
}
.book-series-of {
    font-style: normal;
    font-weight: 300;
    font-size: 0.9em;
}
.book-series-completed {
    font-style: normal;
    font-size: 0.85em;
    color: #2e7d32;
}

/* Metadata with dot separators */
.book-metadata-line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.4em 0;
    font-size: 0.82em;
    color: #888;
    margin-top: 0.3em;
}
.book-metadata-line > span:not(:last-child)::after {
    content: "\00b7";
    margin: 0 0.5em;
    color: #ccc;
}
.book-category {
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 0.9em;
    color: #888;
}
.book-pages {
    white-space: nowrap;
}
.book-release-year {
    white-space: nowrap;
}

.book-genres {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3em;
    margin-top: 0.4em;
}
.book-genre-tag {
    display: inline-block;
    padding: 0.15em 0.6em;
    font-size: 0.72em;
    border: 1px solid #ddd;
    border-radius: 999px;
    color: #777;
    background: #fff;
    transition: border-color 0.15s, color 0.15s, background 0.15s;
}
.book-genre-tag:hover {
    border-color: #aaa;
    color: #444;
    background: #f5f5f5;
}
.book-archive-metadata {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0 0;
    font-size: 0.78em;
    color: #888;
    margin-top: 0.2em;
}
.book-archive-metadata > span:not(:last-child)::after {
    content: "\00b7";
    margin: 0 0.5em;
    color: #ccc;
}

.book-status {
    display: inline-flex;
    align-items: center;
    gap: 0.3em;
    font-size: 0.82em;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0.02em;
    color: #999;
    margin-top: 0.2em;
}

.book-status--finished {
    background: #2e7d32;
    color: #fff;
    padding: 0.2em 0.55em 0.2em 0.4em;
    border-radius: 999px;
    align-self: flex-start;
}
.book-status--finished .book-status-icon {
    stroke: #fff;
    flex-shrink: 0;
}
.book-status--to-read    { color: #2196f3; }
.book-status--reading,
.book-status--inprogress { color: #ff9800; }

.book-progress {
    width: 100%;
    max-width: 200px;
    margin-top: 0.4em;
    height: 5px;
    background: #e0e0e0;
    border-radius: 999px;
}
.book-progress-bar {
    height: 5px;
    background: #ff9800;
    border-radius: 999px;
    transition: width 0.3s ease;
}

.book-date {
    font-size: 0.8em;
    color: #999;
}

.book-date-label {
    color: #bbb;
    font-weight: 400;
}

.checkin-by-date {
    color: #aaa;
    font-size: 0.9em;
}

.book-rating {
    display: inline-flex;
    gap: 1px;
    font-size: 1.1em;
    line-height: 1;
}

.star-full  { color: #f5a623; }
.star-empty { color: #ccc; }

.book-card--single {
    margin-bottom: 1.5rem;
    background: #faf8f5;
    border-radius: 8px;
    padding: 1rem;
}

.book-card--archive {
    padding: 0.5rem 0;
}

.book-hardcover-link,
.book-ol-link {
    display: inline-flex;
    align-items: center;
    gap: 0.3em;
    font-size: 0.78em;
    color: #888;
    text-decoration: none;
    padding: 0.2em 0.6em;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    background: #fff;
    transition: all 0.15s;
    align-self: flex-start;
}
.book-hardcover-link:hover,
.book-ol-link:hover {
    color: #444;
    border-color: #bbb;
    background: #f9f9f9;
}
.book-hardcover-link::before {
    content: "";
    display: inline-block;
    width: 14px;
    height: 14px;
    background: currentColor;
    mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M4 6h16v2H4zm0 5h16v2H4zm0 5h10v2H4z'/%3E%3C/svg%3E") center/contain no-repeat;
    -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M4 6h16v2H4zm0 5h16v2H4zm0 5h10v2H4z'/%3E%3C/svg%3E") center/contain no-repeat;
    flex-shrink: 0;
}
.book-ol-link::before {
    content: "";
    display: inline-block;
    width: 14px;
    height: 14px;
    background: currentColor;
    mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z'/%3E%3C/svg%3E") center/contain no-repeat;
    -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z'/%3E%3C/svg%3E") center/contain no-repeat;
    flex-shrink: 0;
}

/* Live search — CPT type icons */
.ct-search-item .ct-media-container img[src^="data:image/svg"] {
    background: #f5f5f5;
    padding: 18%;
    box-sizing: border-box;
    object-fit: contain;
}

/* Book archive: rating slightly larger and bolder for readability */
.book-archive-info .book-rating {
    font-size: 1.2em;
}

.book-archive-info .star-full {
    color: #e6951a;
}

/* Year headings on /books/ archive */
.book-year-heading {
    grid-column: 1 / -1;
    font-size: 1.1em;
    font-weight: 700;
    color: #555;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin: 0.4em 0 0;
    padding: 0.55em 1em;
    background: #ffffff;
    border-radius: 10px;
    box-shadow: 0 1px 1px 0 rgba(31, 35, 46, 0.15);
}

/* Status filter bar on /books/ archive */
.book-filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4em;
    margin-bottom: 1.5em;
}

.book-filter {
    display: inline-block;
    padding: 0.3em 0.8em;
    border-radius: 999px;
    border: 1px solid #d0d0d0;
    font-size: 0.85em;
    color: #555;
    text-decoration: none;
    background: #fff;
    transition: border-color 0.15s, background 0.15s, color 0.15s;
}

.book-filter:hover {
    border-color: #999;
    color: #222;
}

.book-filter--active {
    background: #263959;
    border-color: #263959;
    color: #fff;
}

.book-filter--active:hover {
    background: #1e2e46;
    border-color: #1e2e46;
    color: #fff;
}

/* Mute redundant Micropub body text on book posts (e.g. "Finished reading: ...") */
.is-book-post .e-content > p:first-of-type:last-of-type {
    color: #bbb;
    font-size: 0.85em;
    font-style: italic;
}
</style>
    <?php
}, 6);

add_action('wp_footer', function () {
    ?>
<script>
(function () {
    // Add "Liked by" heart label before each likes facepile
    document.querySelectorAll('.likes').forEach(function (likesEl) {
        var count = likesEl.querySelectorAll('.mention-list li:not(.additional-facepile-button-list-item)').length;
        if (count > 0) {
            var label = document.createElement('span');
            label.className = 'likes-label';
            label.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28" style="vertical-align:middle;fill:#e25555;margin-right:2px"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg> ' + count;
            likesEl.insertBefore(label, likesEl.firstChild);
        }
    });
}());
</script>
    <?php
}, 5);
add_action('wp_footer', function () {
    if (! is_singular()) {
        return;
    }
    ?>
<div id="blx-overlay" role="dialog" aria-modal="true" aria-label="Full size image" tabindex="-1">
    <img src="" alt="" tabindex="-1" />
</div>
<script>
(function () {
    var overlay = document.getElementById('blx-overlay');
    var overlayImg = overlay.querySelector('img');
    var triggerEl = null;

    // Open on click of featured image container
    document.querySelectorAll('.single .ct-featured-image .ct-media-container').forEach(function (el) {
        el.addEventListener('click', function () {
            var img = el.querySelector('img');
            if (!img) return;
            triggerEl = el;
            var src = img.src;
            if (img.srcset) {
                var parts = img.srcset.split(',').map(function (s) { return s.trim().split(/\s+/); });
                var best = parts.reduce(function (a, b) {
                    return (parseFloat(b[1]) > parseFloat(a[1])) ? b : a;
                });
                if (best[0]) src = best[0];
            }
            overlayImg.src = src;
            overlayImg.alt = img.alt;
            overlay.classList.add('blx-open');
            document.body.style.overflow = 'hidden';
            setTimeout(function () { overlay.focus(); }, 50);
        });
    });

    // Open on click of book cover on single book pages
    document.querySelectorAll('.is-book-post .book-card--single .book-cover-img').forEach(function (img) {
        img.addEventListener('click', function () {
            if (!img.src || img.src === window.location.href) return;
            triggerEl = img;
            overlayImg.src = img.dataset.fullRes || img.dataset.coverFallback || img.src;
            overlayImg.alt = img.alt;
            overlay.classList.add('blx-open');
            document.body.style.overflow = 'hidden';
            setTimeout(function () { overlay.focus(); }, 50);
        });
    });

    // Open on click of Micropub photos (Note images)
    document.querySelectorAll('.micropub-photo .u-photo').forEach(function (img) {
        img.addEventListener('click', function () {
            if (!img.src) return;
            triggerEl = img;
            overlayImg.src = img.dataset.fullRes || img.src;
            overlayImg.alt = img.alt;
            overlay.classList.add('blx-open');
            document.body.style.overflow = 'hidden';
            setTimeout(function () { overlay.focus(); }, 50);
        });
    });

    // Close on click or Escape
    overlay.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') close();
    });

    // Trap Tab focus inside overlay
    overlay.addEventListener('keydown', function (e) {
        if (e.key === 'Tab') {
            e.preventDefault();
        }
    });

    function close() {
        overlay.classList.remove('blx-open');
        document.body.style.overflow = '';
        overlayImg.src = '';
        if (triggerEl) {
            triggerEl.focus();
            triggerEl = null;
        }
    }
}());
</script>
    <?php
});
