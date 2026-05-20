<?php
defined( 'ABSPATH' ) || exit;

add_action( 'init', 'possee_pretty_archives_rewrite_rules' );
function possee_pretty_archives_rewrite_rules() {
	$slugs = [
		'articles' => 'article',
		'books'    => 'book',
		'notes'    => 'note',
		'checkins' => 'checkin',
	];

	foreach ( $slugs as $pretty => $tag ) {
		add_rewrite_rule( "^{$pretty}/feed/(feed|rdf|rss|rss2|atom)/?$", "index.php?tag={$tag}&feed=\$matches[1]", 'top' );
		add_rewrite_rule( "^{$pretty}/(feed|rdf|rss|rss2|atom)/?$",      "index.php?tag={$tag}&feed=\$matches[1]", 'top' );
		add_rewrite_rule( "^{$pretty}/embed/?$",                          "index.php?tag=\$matches[1]&embed=true",  'top' );
		add_rewrite_rule( "^{$pretty}/page/?([0-9]{1,})/?$",             "index.php?tag={$tag}&paged=\$matches[1]", 'top' );
		add_rewrite_rule( "^{$pretty}/?$",                                "index.php?tag={$tag}",                   'top' );
	}
}

add_action( 'template_redirect', 'possee_pretty_archives_canonical_redirect' );
function possee_pretty_archives_canonical_redirect() {
	if ( ! is_tag() ) {
		return;
	}

	$map = [
		'article' => '/articles/',
		'book'    => '/books/',
		'note'    => '/notes/',
		'checkin' => '/checkins/',
	];

	$tag  = get_queried_object();
	$slug = $tag->slug ?? '';

	if ( ! isset( $map[ $slug ] ) ) {
		return;
	}

	$request = $_SERVER['REQUEST_URI'] ?? '';
	$pretty  = $map[ $slug ];

	$path = parse_url( $request, PHP_URL_PATH );
	if ( strpos( $path, $pretty ) !== 0 ) {
		$paged = get_query_var( 'paged' );
		$dest  = home_url( $pretty . ( $paged > 1 ? "page/{$paged}/" : '' ) );
		wp_safe_redirect( $dest, 301 );
		exit;
	}
}

add_filter( 'get_the_archive_title', 'possee_pretty_archive_titles' );
function possee_pretty_archive_titles( $title ) {
	if ( ! is_tag() ) {
		return $title;
	}

	$map = [
		'article' => 'Articles',
		'book'    => 'Books',
		'note'    => 'Notes',
		'checkin' => 'Checkins',
	];

	$slug = get_queried_object()->slug ?? '';
	return $map[ $slug ] ?? $title;
}

add_filter( 'term_link', 'possee_pretty_archive_term_link', 10, 3 );
function possee_pretty_archive_term_link( $url, $term, $taxonomy ) {
	if ( $taxonomy !== 'post_tag' ) {
		return $url;
	}

	$map = [
		'article' => '/articles/',
		'book'    => '/books/',
		'note'    => '/notes/',
		'checkin' => '/checkins/',
	];

	if ( isset( $map[ $term->slug ] ) ) {
		return home_url( $map[ $term->slug ] );
	}

	return $url;
}

add_action( 'pre_get_posts', 'possee_book_archive_filter_query' );
function possee_book_archive_filter_query( $query ) {
	if ( ! $query->is_main_query() || ! is_post_type_archive( 'book' ) ) {
		return;
	}

	$status = sanitize_key( $_GET['status'] ?? '' );
	if ( $status && in_array( $status, [ 'finished', 'reading', 'want-to-read' ], true ) ) {
		$meta_query   = $query->get( 'meta_query' ) ?: [];
		$meta_query[] = [
			'key'     => 'mf2_read-status',
			'value'   => serialize( [ $status ] ),
			'compare' => 'LIKE',
		];
		$query->set( 'meta_query', $meta_query );
	}
}

add_action( 'wp_footer', 'possee_book_archive_filter_bar_script' );
function possee_book_archive_filter_bar_script() {
	if ( ! is_post_type_archive( 'book' ) ) {
		return;
	}

	$current = sanitize_key( $_GET['status'] ?? '' );
	$base    = home_url( '/books/' );

	$filters = [
		''             => 'All',
		'finished'     => 'Finished',
		'reading'      => 'Reading',
		'want-to-read' => 'Want to read',
	];

	$bar_html = '<div class="book-filter-bar">';
	foreach ( $filters as $value => $label ) {
		$url    = $value ? add_query_arg( 'status', $value, $base ) : $base;
		$active = ( $current === $value ) ? ' book-filter--active' : '';
		$bar_html .= sprintf(
			'<a class="book-filter%s" href="%s">%s</a>',
			esc_attr( $active ),
			esc_url( $url ),
			esc_html( $label )
		);
	}
	$bar_html .= '</div>';

	printf(
		'<script>
		(function(){
			var bar=document.createElement("div");
			bar.innerHTML=%s;
			bar=bar.firstChild;
			var entries=document.querySelector(".entries[data-archive]");
			if(entries&&entries.parentNode){entries.parentNode.insertBefore(bar,entries);}
		})();
		</script>',
		wp_json_encode( $bar_html )
	);
}
