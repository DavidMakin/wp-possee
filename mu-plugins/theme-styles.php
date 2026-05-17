<?php
add_action( 'wp_head', function () {
	?>
<style>
body {
	color: #515151;
	-webkit-font-smoothing: antialiased;
}

h1, h2, h3, h4, h5, h6,
.entry-title,
.site-title,
.page-title {
	font-family: 'PT Serif', serif;
	color: #263959;
	font-weight: 400;
}

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
}

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
.single .ct-featured-image .ct-media-container {
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

/* Bluesky-style likes facepile — compact, small round avatars */
.likes {
	margin: 1.5em 0;
	display: flex;
	align-items: center;
	gap: 0.4em;
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

/* Checkin card map image — inside entry-excerpt, after the text */
.entry-card .sloc-map-thumb {
	display: block;
	width: 100%;
	margin-top: 0.75em;
	aspect-ratio: 16/9;
	object-fit: cover;
	border-radius: 4px;
}
</style>
	<?php
}, 5 );

add_action( 'wp_footer', function () {
	if ( ! is_singular() ) return;
	?>
<div id="blx-overlay" role="dialog" aria-modal="true" aria-label="Full size image">
	<img src="" alt="" />
</div>
<script>
(function () {
	var overlay = document.getElementById('blx-overlay');
	var overlayImg = overlay.querySelector('img');

	// Add "Liked by" label before Bluesky-style like avatars
	var likesEl = document.querySelector('.likes');
	if (likesEl) {
		var count = likesEl.querySelectorAll('.mention-list li:not(.additional-facepile-button-list-item)').length;
		if (count > 0) {
			var label = document.createElement('span');
			label.className = 'likes-label';
			label.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28" style="vertical-align:middle;fill:#e25555;margin-right:2px"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg> ' + count;
			likesEl.insertBefore(label, likesEl.firstChild);
		}
	}


	// Open on click of featured image container
	document.querySelectorAll('.single .ct-featured-image .ct-media-container').forEach(function (el) {
		el.addEventListener('click', function () {
			var img = el.querySelector('img');
			if (!img) return;
			// Use largest srcset src, fall back to src
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
		});
	});

	// Close on click or Escape
	overlay.addEventListener('click', close);
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') close();
	});

	function close() {
		overlay.classList.remove('blx-open');
		document.body.style.overflow = '';
		overlayImg.src = '';
	}
}());
</script>
	<?php
} );
