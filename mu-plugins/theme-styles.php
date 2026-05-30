<?php
add_action( 'wp_head', function () {
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
.entry-card.type-checkin::before { background-color: #20c997; }

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
.is-book-post .book-card--single .book-cover-img {
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

/* Note photo galleries — smaller images with lightbox */
.single-note .gallery {
	max-width: 420px;
	margin: 1em 0;
}
.single-note .gallery .gallery-icon a {
	cursor: zoom-in;
	display: inline-block;
	line-height: 0;
}
.single-note .gallery .gallery-icon img {
	height: auto;
	border-radius: 4px;
	border: 1px solid #eee;
	box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

/* ── Book single: card layout ──────────────────────────────── */
.is-book-post .book-card--single {
	display: flex;
	gap: 1.5rem;
	margin: 1.5em 0;
	padding: 1.25rem;
	background: #f9f9f9;
	border: 1px solid #e0e0e0;
	border-radius: 8px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.is-book-post .book-card--single .u-read-of.h-cite {
	display: flex;
	gap: 1.5rem;
	width: 100%;
}

.is-book-post .book-card--single .book-cover {
	flex-shrink: 0;
	width: 120px;
}

.is-book-post .book-card--single .book-cover-img {
	display: block;
	width: 100%;
	height: auto;
	border-radius: 4px;
	box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.is-book-post .book-card--single .book-meta {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
}

.is-book-post .book-card--single .book-title {
	font-size: 1.25rem;
	font-weight: 700;
	color: #222;
	line-height: 1.3;
}

.is-book-post .book-card--single .book-author {
	font-size: 0.95rem;
	color: #666;
}

.is-book-post .book-card--single .book-status {
	display: inline-flex;
	align-items: center;
	gap: 0.3em;
	font-size: 0.85rem;
	font-weight: 600;
	padding: 0.2em 0.6em;
	border-radius: 4px;
	width: fit-content;
	margin-top: 0.15rem;
}

.is-book-post .book-card--single .book-status--finished {
	background: #e5f6df;
	color: #2e7d32;
}

.is-book-post .book-card--single .book-status--reading {
	background: #fff3e0;
	color: #e65100;
}

.is-book-post .book-card--single .book-status-icon {
	vertical-align: middle;
}

.is-book-post .book-card--single .book-date {
	font-size: 0.85rem;
	color: #999;
}

.is-book-post .book-card--single .book-hardcover-link,
.is-book-post .book-card--single .book-ol-link {
	font-size: 0.85rem;
	color: #5c7cfa;
	text-decoration: none;
	margin-top: 0.15rem;
}

.is-book-post .book-card--single .book-hardcover-link:hover,
.is-book-post .book-card--single .book-ol-link:hover {
	text-decoration: underline;
}

/* ── Book archive card: row layout ────────────────────────── */
.book-archive-row {
	display: flex;
	gap: 0.75rem;
	align-items: flex-start;
}

.book-archive-row .book-archive-cover {
	flex-shrink: 0;
	width: 96px;
}

.book-archive-row .book-cover-img {
	display: block;
	width: 100%;
	height: auto;
	border-radius: 3px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.12);
}

.book-archive-row .book-archive-info {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
}

.book-archive-row .book-title {
	font-size: 0.95rem;
	font-weight: 600;
	color: #333;
}

.book-archive-row .book-author {
	font-size: 0.8rem;
	color: #888;
}

.book-archive-row .book-status {
	display: inline-flex;
	align-items: center;
	gap: 0.25em;
	font-size: 0.75rem;
	padding: 0.1em 0.4em;
	border-radius: 3px;
	width: fit-content;
}

.book-archive-row .book-status--finished {
	background: #e5f6df;
	color: #2e7d32;
}

.book-archive-row .book-status--reading {
	background: #fff3e0;
	color: #e65100;
}

.book-archive-row .book-status-icon {
	vertical-align: middle;
}

/* ── Book progress bar ────────────────────────────────────── */
.book-progress {
	height: 6px;
	background: #e0e0e0;
	border-radius: 3px;
	overflow: hidden;
	margin: 0.5em 0;
}

.book-progress-bar {
	height: 100%;
	background: #5c7cfa;
	border-radius: 3px;
}

/* Bluesky-style likes facepile — compact, small round avatars */
.likes {
	margin: 1.5em 0;
	display: flex;
	align-items: center;
	gap: 0.4em;
}

/* Likes inside archive cards — tighter spacing */
.entry-card .likes {
	margin: 0.5em 0 0.3em;
	line-height: 1;
}

.likes h3 {
	display: none;
}

.likes .mention-list {
	display: inline-flex;
	align-items: center;
	padding: 0;
	margin: 0;
	list-style: none;
}

.likes .mention-list li {
	margin: 0 0 0 -8px;
}

.likes .mention-list li:first-child {
	margin-left: 0;
}

.likes .mention-list li a {
	display: block;
	line-height: 0;
}

.likes .mention-list li img {
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

/* also restyle reposts section to match */
.reposts {
	margin: 0.5em 0;
	display: flex;
	align-items: center;
	gap: 0.4em;
}

.reposts h3 {
	display: none;
}

.reposts .mention-list {
	display: inline-flex;
	align-items: center;
	padding: 0;
	margin: 0;
	list-style: none;
}

.reposts .mention-list li {
	margin: 0 0 0 -8px;
}

.reposts .mention-list li:first-child {
	margin-left: 0;
}

.reposts .mention-list li a {
	display: block;
	line-height: 0;
}

.reposts .mention-list li img {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	border: 2px solid #fff;
	object-fit: cover;
	display: block;
	background: #f0f0f0;
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
	height: 280px;
	overflow: hidden;
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
}, 5 );

add_action( 'wp_footer', function () {
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
}, 5 );
add_action( 'wp_footer', function () {
	if ( ! is_singular() ) return;
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
			openLightbox(img);
		});
	});

	// Open on click of gallery images inside note/checkin content
	document.querySelectorAll(
		'.single-note .entry-content .gallery-icon a, ' +
		'.single-checkin .entry-content .gallery-icon a, ' +
		'.single-post .entry-content .gallery-icon a'
	).forEach(function (el) {
		el.addEventListener('click', function (e) {
			e.preventDefault();
			var img = el.querySelector('img');
			if (!img) return;
			triggerEl = el;
			openLightbox(img);
		});
	});

	function openLightbox(img) {
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
	}

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
} );
