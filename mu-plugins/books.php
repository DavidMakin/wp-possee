<?php
defined( 'ABSPATH' ) || exit;

function possee_book_get_data( $post_id ) {
	$all_meta = get_post_meta( $post_id );

	$possee_meta = function( $key ) use ( $all_meta ) {
		if ( ! isset( $all_meta[ $key ][0] ) ) {
			return null;
		}
		$val = maybe_unserialize( $all_meta[ $key ][0] );
		return is_array( $val ) ? ( $val[0] ?? null ) : ( $val ?: null );
	};

	$ro_raw = isset( $all_meta['mf2_read-of'][0] ) ? maybe_unserialize( $all_meta['mf2_read-of'][0] ) : null;
	if ( ! is_array( $ro_raw ) || empty( $ro_raw[0]['properties'] ) ) {
		return null;
	}

	$props  = $ro_raw[0]['properties'];
	$title  = $props['name'][0]   ?? '';
	$author = $props['author'][0] ?? '';
	$uid    = $props['uid'][0]    ?? '';
	$isbn   = str_starts_with( $uid, 'isbn:' ) ? substr( $uid, 5 ) : '';

	$status_val = $possee_meta( 'mf2_read-status' );
	$status     = $status_val ?: '';

	$rating  = null;
	$excerpt = get_post( $post_id )->post_excerpt;
	if ( preg_match( '/\((\d(?:\.\d)?)\s*\/\s*5\)/', $excerpt, $m ) ) {
		$rating = (float) $m[1];
	}

	$hc_cover = $possee_meta( 'mf2_hardcover-cover' ) ?? '';
	if ( ! $hc_cover ) {
		$hc_slug = $possee_meta( 'mf2_hardcover-slug' );
		if ( $hc_slug ) {
			$hc_cover = possee_book_fetch_hc_cover( $post_id, $hc_slug );
		}
	}

	$progress_pages = null;
	$progress_total = null;
	$progress_pct   = null;
	if ( $status === 'reading' ) {
		$pp = $possee_meta( 'mf2_reading_progress_pages' );
		if ( $pp !== null && $pp !== '' ) {
			$progress_pages = (int) $pp;
		}
		$pt = $possee_meta( 'mf2_reading_total_pages' );
		if ( $pt !== null && $pt !== '' ) {
			$progress_total = (int) $pt;
		}
		$pct = $possee_meta( 'mf2_reading_progress_pct' );
		if ( $pct !== null && $pct !== '' ) {
			$progress_pct = (int) $pct;
		} elseif ( $progress_pages !== null && $progress_total ) {
			$progress_pct = (int) round( ( $progress_pages / $progress_total ) * 100 );
		}
	}

	$finished_at = $possee_meta( 'mf2_finished-at' );

	$series_name      = null;
	$series_pos       = null;
	$series_count     = null;
	$series_completed = null;
	$sn               = $possee_meta( 'mf2_book-series' );
	if ( $sn ) {
		$series_name = $sn;
		$sp          = $possee_meta( 'mf2_book-series-position' );
		if ( $sp !== null && $sp !== '' ) {
			$series_pos = (float) $sp;
		}
		$sc = $possee_meta( 'mf2_book-series-count' );
		if ( $sc !== null && $sc !== '' ) {
			$series_count = (int) $sc;
		}
		$scomp = $possee_meta( 'mf2_book-series-completed' );
		if ( $scomp !== null ) {
			$series_completed = filter_var( $scomp, FILTER_VALIDATE_BOOLEAN );
		}
	}

	$pages       = $possee_meta( 'mf2_book-pages' );
	if ( $pages !== null && $pages !== '' ) {
		$pages = (int) $pages;
	}

	$release_year = $possee_meta( 'mf2_book-release-year' );
	if ( $release_year !== null && $release_year !== '' ) {
		$release_year = (int) $release_year;
	}

	$category_id = $possee_meta( 'mf2_book-category-id' );
	if ( $category_id !== null && $category_id !== '' ) {
		$category_id = (int) $category_id;
	}

	$hc_cover_url = $possee_meta( 'mf2_book-cover-url' );

	$genres = null;
	if ( isset( $all_meta['mf2_book-genres'][0] ) ) {
		$gv = maybe_unserialize( $all_meta['mf2_book-genres'][0] );
		if ( is_array( $gv ) && ! empty( $gv ) ) {
			$genres = $gv;
		}
	}

	return compact( 'title', 'author', 'isbn', 'uid', 'status', 'rating', 'hc_cover', 'hc_cover_url', 'progress_pages', 'progress_total', 'progress_pct', 'finished_at', 'series_name', 'series_pos', 'series_count', 'series_completed', 'pages', 'release_year', 'category_id', 'genres' );
}

function possee_book_fetch_hc_cover( $post_id, $hc_slug ) {
	$existing = get_post_meta( $post_id, 'mf2_hardcover-cover', true );
	if ( $existing ) {
		return $existing;
	}

	// Only attempt lookup once per post.
	if ( get_post_meta( $post_id, '_hc_cover_lookup_done', true ) ) {
		return '';
	}

	$url     = 'https://hardcover.app/oembed?url=' . rawurlencode( 'https://hardcover.app/books/' . $hc_slug ) . '&format=json';
	$resp    = wp_remote_get( $url, array( 'timeout' => 5 ) );

	if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! empty( $data['thumbnail_url'] ) ) {
			update_post_meta( $post_id, 'mf2_hardcover-cover', $data['thumbnail_url'] );
			update_post_meta( $post_id, '_hc_cover_lookup_done', 1 );
			return $data['thumbnail_url'];
		}
	}

	update_post_meta( $post_id, '_hc_cover_lookup_done', 1 );
	return '';
}

function possee_book_cover_url( $isbn, $size = 'M' ) {
	if ( ! $isbn ) {
		return '';
	}
	return 'https://covers.openlibrary.org/b/isbn/' . rawurlencode( $isbn ) . '-' . $size . '.jpg?default=false';
}

function possee_book_cover_placeholder_url() {
	return 'data:image/svg+xml,' . rawurlencode(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 180 245" width="180" height="245">'
		. '<rect width="180" height="245" fill="#e8e4df"/>'
		. '<rect x="20" y="20" width="140" height="205" rx="3" fill="#d4cfc9"/>'
		. '<rect x="30" y="40" width="80" height="8" rx="2" fill="#a09890"/>'
		. '<rect x="30" y="56" width="60" height="6" rx="2" fill="#b8b0a8"/>'
		. '<rect x="30" y="160" width="100" height="6" rx="2" fill="#a09890"/>'
		. '<rect x="30" y="174" width="70" height="5" rx="2" fill="#b8b0a8"/>'
		. '</svg>'
	);
}

function possee_book_cover_img_html( $isbn, $alt, $size = 'M', $extra_class = '', $fallback_url = '', $use_direct_src = false ) {
	$placeholder = possee_book_cover_placeholder_url();
	$class       = trim( 'book-cover-img ' . $extra_class );

	$ol_src      = $isbn ? possee_book_cover_url( $isbn, $size ) : '';

	$dim_map = [ 'S' => [ 60, 90 ], 'M' => [ 80, 120 ], 'L' => [ 140, 210 ] ];
	[ $w, $h ] = $dim_map[ $size ] ?? [ 80, 120 ];

	if ( $use_direct_src && ( $fallback_url || $ol_src ) ) {
		// Direct src approach — no JS swap. Use best URL as src, onerror falls back.
		$primary = $fallback_url ?: $ol_src;
		$onerror = $fallback_url && $ol_src ? sprintf( "this.onerror=null;this.src='%s'", esc_url( $ol_src ) ) : '';
		return sprintf(
			'<img src="%s" alt="%s" class="%s" loading="lazy" width="%d" height="%d"%s/>',
			esc_url( $primary ),
			esc_attr( $alt ),
			esc_attr( $class ),
			$w,
			$h,
			$onerror ? ' onerror="' . esc_attr( $onerror ) . '"' : ''
		);
	}

	if ( $ol_src || $fallback_url ) {
		possee_book_enqueue_cover_loader();
		$attrs = sprintf( 'src="%s" alt="%s" class="%s" loading="lazy" width="%d" height="%d"', $placeholder, esc_attr( $alt ), esc_attr( $class ), $w, $h );
		if ( $ol_src ) {
			$attrs .= sprintf( ' data-cover-src="%s"', esc_url( $ol_src ) );
		}
		if ( $fallback_url ) {
			$attrs .= sprintf( ' data-cover-fallback="%s"', esc_url( $fallback_url ) );
		}
		return "<img $attrs/>";
	}

	return sprintf(
		'<img src="%s" alt="%s" class="%s" loading="lazy" width="%d" height="%d"/>',
		$placeholder,
		esc_attr( $alt ),
		esc_attr( $class ),
		$w,
		$h
	);
}

function possee_book_enqueue_cover_loader() {
	static $enqueued = false;
	if ( $enqueued ) {
		return;
	}
	$enqueued = true;
	add_action( 'wp_footer', 'possee_book_cover_loader_script', 20 );
}

function possee_book_cover_loader_script() {
	?>
	<script>
	(function(){
		function loadCovers(){
			document.querySelectorAll('img[data-cover-src],img[data-cover-fallback]').forEach(function(img){
				var primary=img.dataset.coverSrc;
				var fallback=img.dataset.coverFallback;
				if(primary){
					var real=new Image();
					real.onload=function(){img.src=real.src;};
					real.onerror=function(){
						if(fallback){var fb=new Image();fb.onload=function(){img.src=fb.src;};fb.src=fallback;}
					};
					real.src=primary;
				} else if(fallback){
					var fb=new Image();fb.onload=function(){img.src=fb.src;};fb.src=fallback;
				}
			});
		}
		if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',loadCovers);}
		else{loadCovers();}
	})();
	</script>
	<?php
}

add_action( 'wp_footer', 'possee_book_year_heading_script', 21 );
function possee_book_year_heading_script() {
	if ( ! is_post_type_archive( 'book' ) ) {
		return;
	}
	?>
	<script>
	(function(){
		document.querySelectorAll('.book-year-heading').forEach(function(h){
			var card=h.closest('.entry-card');
			if(card&&card.parentNode){card.parentNode.insertBefore(h,card);}
		});
	})();
	</script>
	<?php
}




function possee_book_category_label( $category_id ) {
	if ( ! $category_id ) {
		return '';
	}
	$labels = array(
		1  => 'Book',
		2  => 'Novella',
		3  => 'Short Story',
		4  => 'Graphic Novel',
		5  => 'Fan Fiction',
		6  => 'Research Paper',
		7  => 'Poetry',
		8  => 'Collection',
		9  => 'Web Novel',
		10 => 'Light Novel',
	);
	return isset( $labels[ $category_id ] ) ? $labels[ $category_id ] : '';
}

function possee_book_pages_html( $pages ) {
	if ( ! $pages ) {
		return '';
	}
	return '<span class="book-pages">' . esc_html( $pages ) . ' pages</span>';
}

function possee_book_genres_html( $genres ) {
	if ( empty( $genres ) ) {
		return '';
	}
	$tags = array();
	foreach ( $genres as $g ) {
		$tags[] = '<span class="book-genre-tag">' . esc_html( $g ) . '</span>';
	}
	return '<span class="book-genres">' . implode( '', $tags ) . '</span>';
}

function possee_book_stars_html( $rating ) {
	if ( $rating === null ) {
		return '';
	}
	$full  = (int) floor( $rating );
	$empty = 5 - $full;
	$html  = '<span class="book-rating" aria-label="' . esc_attr( $rating . ' out of 5' ) . '">';
	$html .= str_repeat( '<span class="star star-full">★</span>', $full );
	$html .= str_repeat( '<span class="star star-empty">☆</span>', $empty );
	$html .= '</span>';
	return $html;
}

function possee_book_progress_bar_html( $data ) {
	if ( ( $data['progress_pct'] ?? null ) === null ) {
		return '';
	}
	$pct   = min( 100, max( 0, $data['progress_pct'] ) );
	$label = $data['progress_pages'] !== null && $data['progress_total']
		? sprintf( 'Page %d of %d', $data['progress_pages'], $data['progress_total'] )
		: $pct . '%';
	return sprintf(
		'<div class="book-progress" role="progressbar" aria-valuenow="%d" aria-valuemin="0" aria-valuemax="100" aria-label="%s">'
		. '<div class="book-progress-bar" style="width:%d%%"></div>'
		. '</div>',
		$pct,
		esc_attr( $label ),
		$pct
	);
}

function possee_book_series_html( $data ) {
	if ( empty( $data['series_name'] ) ) {
		return '';
	}
	$parts = array( esc_html( $data['series_name'] ) );
	if ( $data['series_pos'] !== null ) {
		$pos   = $data['series_pos'];
		$p     = $pos == (int) $pos ? (int) $pos : esc_html( $pos );
		if ( $data['series_count'] !== null ) {
			$parts[] = '<span class="book-series-position">#' . $p . ' <span class="book-series-of">of</span> ' . (int) $data['series_count'] . '</span>';
		} else {
			$parts[] = '<span class="book-series-position">#' . $p . '</span>';
		}
	}
	if ( $data['series_completed'] === true ) {
		$parts[] = '<span class="book-series-completed">completed</span>';
	}
	return '<span class="book-series">' . implode( ' ', $parts ) . '</span>';
}

function possee_book_card_html( $post_id, $data, $context = 'single' ) {
	$size      = $context === 'single' ? 'L' : 'M';
	$hc_fallback = $data['hc_cover_url'] ?: ( $data['hc_cover'] ?? '' );
	$cover_img = possee_book_cover_img_html( $data['isbn'], 'Cover of ' . $data['title'], $size, '', $hc_fallback );
	$stars     = possee_book_stars_html( $data['rating'] );
	$isbn_attr = $data['uid'] ? ' data-isbn="' . esc_attr( $data['uid'] ) . '"' : '';

	$category_label = possee_book_category_label( $data['category_id'] ?? 0 );
	$pages_html     = possee_book_pages_html( $data['pages'] ?? 0 );
	$genres_html    = possee_book_genres_html( $data['genres'] ?? array() );

	ob_start();
	?>
	<div class="book-card book-card--<?php echo esc_attr( $context ); ?>">
		<div class="u-read-of h-cite"<?php echo $isbn_attr; ?>>
			<div class="book-cover">
				<?php echo $cover_img; ?>
			</div>
			<div class="book-meta">
				<span class="p-name book-title"><?php echo esc_html( $data['title'] ); ?></span>
				<span class="p-author book-author"><?php echo esc_html( $data['author'] ); ?></span>
				<?php echo possee_book_series_html( $data ); ?>
				<?php if ( $stars ) : ?>
				<div class="book-rating-wrap"><?php echo $stars; ?></div>
				<?php endif; ?>
				<div class="book-metadata-line">
					<?php if ( $category_label ) : ?>
					<span class="book-category"><?php echo esc_html( $category_label ); ?></span>
					<?php endif; ?>
					<?php echo $pages_html; ?>
					<?php if ( $data['release_year'] ) : ?>
					<span class="book-release-year"><?php echo esc_html( $data['release_year'] ); ?></span>
					<?php endif; ?>
				</div>
				<?php echo $genres_html; ?>
				<?php
			$status_slug  = sanitize_html_class( $data['status'] );
			$status_labels = [ 'reading' => 'Currently reading', 'finished' => 'Finished', 'want-to-read' => 'Want to read' ];
			$status_label  = $status_labels[ $data['status'] ] ?? ucfirst( str_replace( '-', ' ', $data['status'] ) );
			$checkmark    = ( $data['status'] === 'finished' ) ? '<svg class="book-status-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 12 12" width="12" height="12"><polyline points="1.5,6 4.5,9 10.5,3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' : '';
			?>
			<span class="p-read-status book-status book-status--<?php echo esc_attr( $status_slug ); ?>"><?php echo $checkmark; echo esc_html( $status_label ); ?></span>
			<?php echo possee_book_progress_bar_html( $data ); ?>
			<?php if ( $data['isbn'] ) : ?>
			<data class="u-uid" value="<?php echo esc_attr( $data['uid'] ); ?>"></data>
			<?php endif; ?>
			<?php if ( $post_id ) : ?>
			<span class="book-date"><?php echo esc_html( get_the_date( 'j F Y', $post_id ) ); ?></span>
			<?php endif; ?>
			<?php if ( $context === 'single' && $post_id ) : ?>
			<?php
			$hc_slug = get_post_meta( $post_id, 'mf2_hardcover-slug', true );
			if ( ! $hc_slug ) {
				$hc_slug = get_post_field( 'post_name', $post_id );
			}
			?>
			<a class="book-hardcover-link" href="https://hardcover.app/books/<?php echo esc_attr( $hc_slug ); ?>" rel="noopener noreferrer" target="_blank">View on Hardcover</a>
			<?php endif; ?>
			<?php if ( $context === 'single' && $data['isbn'] ) : ?>
			<a class="book-ol-link" href="https://openlibrary.org/isbn/<?php echo esc_attr( $data['isbn'] ); ?>" rel="noopener noreferrer" target="_blank">View on Open Library</a>
			<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

function possee_is_book_post( $post_id = null ) {
	$post_id = $post_id ?: get_the_ID();
	$post    = get_post( $post_id );
	if ( $post && 'book' === $post->post_type ) {
		return true;
	}
	$tags = wp_get_post_tags( $post_id, [ 'fields' => 'slugs' ] );
	return in_array( 'book', $tags, true );
}

add_filter( 'the_content', 'possee_book_content', 15 );
function possee_book_content( $content ) {
	if ( ! is_singular( array( 'post', 'book' ) ) || ! in_the_loop() || ! possee_is_book_post() ) {
		return $content;
	}

	$data = possee_book_get_data( get_the_ID() );
	if ( ! $data ) {
		return $content;
	}

	return possee_book_card_html( get_the_ID(), $data, 'single' ) . $content;
}

add_filter( 'has_excerpt', 'possee_book_suppress_hero_excerpt', 10, 2 );
function possee_book_suppress_hero_excerpt( $has, $post ) {
	if ( ! $has ) {
		return false;
	}
	$post_id = is_object( $post ) ? $post->ID : (int) $post;
	return $post_id && possee_is_book_post( $post_id ) ? false : $has;
}

add_filter( 'get_the_excerpt', 'possee_book_suppress_excerpt', 6, 2 );
function possee_book_suppress_excerpt( $excerpt, $post ) {
	if ( ! possee_is_book_post( $post->ID ) ) {
		return $excerpt;
	}
	return '';
}

add_filter( 'blocksy:excerpt:output', 'possee_book_suppress_blocksy_excerpt' );
function possee_book_suppress_blocksy_excerpt( $excerpt ) {
	if ( ! is_singular( array( 'post', 'book' ) ) ) {
		return $excerpt;
	}
	if ( possee_is_book_post( get_the_ID() ) ) {
		return '';
	}
	return $excerpt;
}

add_filter( 'body_class', 'possee_book_body_class' );
function possee_book_body_class( $classes ) {
	if ( is_singular( array( 'post', 'book' ) ) && possee_is_book_post( get_the_ID() ) ) {
		$classes[] = 'is-book-post';
	}
	return $classes;
}

add_filter( 'micropub_dynamic_render', 'possee_book_suppress_micropub_render', 10, 2 );
function possee_book_suppress_micropub_render( $should, $post ) {
	if ( possee_is_book_post( $post->ID ) ) {
		return false;
	}
	return $should;
}

add_filter( 'blocksy:archive:render-card-layers', 'possee_book_card_layer', 10, 3 );
function possee_book_card_layer( $outputs, $prefix, $featured_image_args ) {
	if ( ! possee_is_book_post( get_the_ID() ) ) {
		return $outputs;
	}
	if ( is_feed() ) {
		return $outputs;
	}

	$data = possee_book_get_data( get_the_ID() );
	if ( ! $data ) {
		return $outputs;
	}

	$hc_fallback = $data['hc_cover_url'] ?: ( $data['hc_cover'] ?? '' );
	// Archive/search: use direct src so covers show immediately without JS swap.
	$cover_img = possee_book_cover_img_html( $data['isbn'], '', 'M', '', $hc_fallback, true );
	$stars     = possee_book_stars_html( $data['rating'] );
	$read_more = sprintf(
		'<a class="entry-button wp-element-button ct-button" href="%s">Read More<span class="screen-reader-text"> %s</span></a>',
		esc_url( get_permalink() ),
		esc_html( $data['title'] )
	);

	// Full horizontal layout — same everywhere (homepage, /books/, search).
	$cover_html = sprintf(
		'<a class="book-archive-cover" href="%s" tabindex="-1" aria-hidden="true">%s</a>',
		esc_url( get_permalink() ),
		$cover_img
	);

	$title_html = sprintf(
		'<a class="book-archive-title" href="%s">%s</a>',
		esc_url( get_permalink() ),
		esc_html( $data['title'] )
	);

	$tags_html = blocksy_post_meta(
		[ [ 'id' => 'categories', 'enabled' => true, 'taxonomy' => 'post_tag' ] ],
		[ 'meta_type' => 'simple', 'meta_divider' => 'slash', 'has_term_class' => true, 'attr' => [ 'data-id' => 'book' ] ]
	);

	$status_html = $data['status'] ? sprintf(
		'<span class="book-status book-status--%s">%s%s</span>',
		esc_attr( $data['status'] ),
		$data['status'] === 'finished' ? '<svg class="book-status-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 12 12" width="12" height="12"><polyline points="1.5,6 4.5,9 10.5,3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' : '',
		esc_html( ( [ 'reading' => 'Currently reading', 'finished' => 'Finished', 'want-to-read' => 'Want to read' ] )[ $data['status'] ] ?? ucfirst( str_replace( '-', ' ', $data['status'] ) ) )
	) : '';

	// Year heading — only on /books/ archive, not homepage or search.
	$year_heading = '';
	if ( is_post_type_archive( 'book' ) ) {
		static $last_year = null;
		$current_year = get_the_date( 'Y' );
		if ( $current_year !== $last_year ) {
			$last_year    = $current_year;
			$year_heading = sprintf( '<h2 class="book-year-heading">%s</h2>', esc_html( $current_year ) );
		}
	}

	$pages_html  = possee_book_pages_html( $data['pages'] ?? 0 );
	$genres_html = possee_book_genres_html( $data['genres'] ?? array() );
	$archive_meta = array();
	if ( $pages_html ) {
		$archive_meta[] = $pages_html;
	}
	if ( $genres_html ) {
		$archive_meta[] = $genres_html;
	}
	$archive_meta_html = $archive_meta ? '<div class="book-archive-metadata">' . implode( '', $archive_meta ) . '</div>' : '';

	$outputs['featured_image'] = sprintf(
		'%s%s<div class="book-archive-row">%s<div class="book-archive-info">%s<span class="book-author">%s</span>%s%s%s%s%s</div></div>',
		$year_heading,
		$tags_html,
		$cover_html,
		$title_html,
		esc_html( $data['author'] ),
		possee_book_series_html( $data ),
		$stars ? '<div class="book-rating-wrap">' . $stars . '</div>' : '',
		$status_html,
		$archive_meta_html,
		possee_book_progress_bar_html( $data )
	);

	$outputs['title']     = '';
	$outputs['excerpt']   = '';
	$outputs['read_more'] = $read_more;

	return $outputs;
}

// No suppress filter needed — post_meta output is controlled via $outputs['post_meta'] in possee_book_card_layer.

// ── REST API: expose book meta fields ────────────────────────

add_action( 'init', 'possee_register_book_meta' );
function possee_register_book_meta() {
	$args = array(
		'object_subtype' => 'book',
		'type'           => 'string',
		'single'         => true,
		'show_in_rest'   => true,
		'auth_callback'  => '__return_true',
	);
	register_meta( 'post', 'isbn', $args );
	register_meta( 'post', 'mf2_read-status', $args );
	register_meta( 'post', 'mf2_finished-at', $args );
}

// ── REST API: update book status by ISBN ─────────────────────

add_action( 'rest_api_init', 'possee_register_book_update_route' );
function possee_register_book_update_route() {
	register_rest_route( 'possee/v1', '/book-update-status', array(
		'methods'             => 'POST',
		'callback'            => 'possee_rest_book_update_status',
		'permission_callback' => 'possee_rest_book_auth_check',
		'args'                => array(
			'isbn'        => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'read_status' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					return in_array( $value, array( 'reading', 'finished', 'want-to-read' ), true );
				},
			),
			'finished_at' => array(
				'required' => false,
				'type'     => 'string',
			),
		),
	) );
}

/**
 * Auth: accept logged-in users or Micropub Bearer token (used by n8n).
 */
function possee_rest_book_auth_check() {
	if ( is_user_logged_in() ) {
		return true;
	}

	// Check for Micropub-style Bearer token in Authorization header.
	if ( ! class_exists( 'Micropub_Plugin' ) ) {
		return false;
	}
	$auth = Micropub_Plugin::get_auth();
	return ! empty( $auth );
}

function possee_rest_book_update_status( $request ) {
	$isbn        = $request->get_param( 'isbn' );
	$read_status = $request->get_param( 'read_status' );
	$finished_at = $request->get_param( 'finished_at' );

	// Find existing book by ISBN.
	$posts = get_posts( array(
		'post_type'      => 'book',
		'meta_key'       => 'isbn',
		'meta_value'     => $isbn,
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	if ( empty( $posts ) ) {
		return new WP_Error( 'not_found', 'No book found with this ISBN', array( 'status' => 404 ) );
	}

	$post_id = $posts[0];

	// Update read status.
	update_post_meta( $post_id, 'mf2_read-status', $read_status );

	// Update finished-at if provided.
	if ( $finished_at ) {
		update_post_meta( $post_id, 'mf2_finished-at', $finished_at );
	}

	// Update post date to match finished-at.
	if ( $finished_at ) {
		$date = date( 'Y-m-d H:i:s', strtotime( $finished_at ) );
		wp_update_post( array(
			'ID'            => $post_id,
			'post_date'     => $date,
			'post_date_gmt' => get_gmt_from_date( $date ),
		) );
	}

	return array(
		'success' => true,
		'post_id' => $post_id,
		'isbn'    => $isbn,
		'status'  => $read_status,
	);
}

// ── Live search overlay: inject book cover images ─────────────

add_action( 'rest_api_init', 'possee_search_cover_field', 20 );
function possee_search_cover_field() {
	if ( ! isset( $_GET['ct_live_search'] ) || 'true' !== $_GET['ct_live_search'] ) {
		return;
	}

	// Silence: de-register Blocksy's placeholder_image (may not exist if WooCommerce absent).
	if ( function_exists( 'unregister_rest_field' ) ) {
		$unregistered = unregister_rest_field( 'search-result', 'placeholder_image' );
		if ( is_wp_error( $unregistered ) ) {
			// Field was not registered — that's fine, we'll register it fresh.
		}
	}

	register_rest_field( 'search-result', 'placeholder_image', array(
		'get_callback' => function ( $post, $field_name, $request ) {
			// Books: return the actual cover URL.
			if ( isset( $post['subtype'] ) && 'book' === $post['subtype'] ) {
				$cover = get_post_meta( $post['id'], 'mf2_book-cover-url', true );
				if ( $cover ) {
					return $cover;
				}
			}

			// Products: fall back to WooCommerce placeholder.
			if ( isset( $post['type'] ) && 'product' === $post['type'] ) {
				if ( function_exists( 'wc_placeholder_img_src' ) ) {
					return wc_placeholder_img_src( 'thumbnail' );
				}
			}

			// Default: book-shaped SVG icon.
			return 'data:image/svg+xml,' . rawurlencode(
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%23555" stroke-width="1.5">'
				. '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>'
				. '<path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>'
				. '</svg>'
			);
		},
	) );
}
