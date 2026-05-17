<?php
defined( 'SYNDICATION_LINKS_BRIDGY_WEBMENTION' ) || define( 'SYNDICATION_LINKS_BRIDGY_WEBMENTION', 1 );

add_action( 'wp_head', function () {
	echo '<link rel="me" href="https://hachyderm.io/@_sleeper" />' . "\n";
	echo '<link rel="me" href="https://bsky.app/profile/sleep-er.bsky.social" />' . "\n";

	if ( is_singular( 'post' ) ) {
		$post        = get_queried_object();
		$title       = esc_attr( get_the_title( $post ) );
		$url         = esc_url( get_permalink( $post ) );
		$description = esc_attr( has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( strip_tags( $post->post_content ), 30 ) );

		$image = '';
		if ( has_post_thumbnail( $post ) ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post ), 'large' );
			if ( $src ) {
				$image = esc_url( $src[0] );
			}
		}
		if ( ! $image ) {
			$logo_id = get_theme_mod( 'custom_logo' );
			if ( $logo_id ) {
				$src = wp_get_attachment_image_src( $logo_id, 'full' );
				if ( $src ) {
					$image = esc_url( $src[0] );
				}
			}
		}

		echo '<meta property="og:type" content="article" />' . "\n";
		echo '<meta property="og:title" content="' . $title . '" />' . "\n";
		echo '<meta property="og:url" content="' . $url . '" />' . "\n";
		echo '<meta property="og:description" content="' . $description . '" />' . "\n";
		if ( $image ) {
			echo '<meta property="og:image" content="' . $image . '" />' . "\n";
		}
		echo '<meta name="twitter:card" content="' . ( $image ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
	} elseif ( is_front_page() || is_home() ) {
		echo '<meta property="og:type" content="website" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( home_url( '/' ) ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( get_bloginfo( 'description' ) ) . '" />' . "\n";
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'SynProvider_Webmention_Bridgy' ) ) {
		return;
	}

	class SynProvider_Webmention_Bridgy_Bluesky extends SynProvider_Webmention_Bridgy {
		public function __construct( $args = array() ) {
			$this->name = 'Bluesky via Bridgy';
			$this->uid  = 'webmention-bluesky-bridgy';

			$option = get_option( 'syndication_provider_enable' );
			$enable = is_array( $option ) ? in_array( $this->uid, $option ) : false;

			if ( $enable ) {
				add_action( 'wp_footer', array( $this, 'wp_footer' ) );
			}

			parent::__construct( $args );
		}

		public function wp_footer() {
			if ( ( 1 === (int) get_option( 'syndication_use_excerpt' ) ) && has_excerpt() ) {
				printf( '<p class="p-bridgy-bluesky-content" style="display:none">%1$s</p>', get_the_excerpt() ); // phpcs:ignore
			}
		}

		public function get_target() {
			return 'https://brid.gy/publish/bluesky';
		}
	}

	if ( function_exists( 'register_syndication_provider' ) ) {
		register_syndication_provider( new SynProvider_Webmention_Bridgy_Bluesky() );
	}
}, 20 );

// Register "Syndication Links" inside Post Meta → Card Elements.
add_filter( 'blocksy:options:meta:meta_default_elements', function ( $elements ) {
	$elements[] = array(
		'id'      => 'syndication_links',
		'enabled' => false,
	);
	$elements[] = array(
		'id'      => 'reading_time',
		'enabled' => false,
	);
	return $elements;
} );

add_filter( 'blocksy:options:meta:meta_elements', function ( $elements ) {
	$elements['syndication_links'] = array(
		'label'   => __( 'Syndication Links', 'blocksy' ),
		'options' => array(),
	);
	$elements['reading_time'] = array(
		'label'   => __( 'Reading Time', 'blocksy' ),
		'options' => array(),
	);
	return $elements;
} );

add_action( 'blocksy:post-meta:render-meta', function ( $id ) {
	if ( 'syndication_links' === $id ) {
		if ( ! function_exists( 'get_syndication_links' ) ) {
			return;
		}
		$links = get_syndication_links(
			get_the_ID(),
			array(
				'show_text_before' => false,
				'icons'            => true,
				'text'             => false,
				'style'            => 'span',
				'container-css'    => 'syn-links',
				'single-css'       => 'syn-link',
			)
		);
		if ( ! $links ) {
			return;
		}
		echo '<li class="meta-syndication-links">' . $links . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	if ( 'reading_time' === $id ) {
		$words   = str_word_count( wp_strip_all_tags( get_post_field( 'post_content', get_the_ID() ) ) );
		$minutes = max( 1, (int) ceil( $words / 200 ) );
		echo '<li class="meta-reading-time">' . esc_html( $minutes . ' min read' ) . '</li>';
	}
}, 10, 1 );

add_filter( 'get_the_excerpt', function ( $excerpt, $post ) {
	if ( is_singular() || ! has_tag( 'checkin', $post ) ) {
		return $excerpt;
	}
	return wp_strip_all_tags( $post->post_content );
}, 5, 2 );

add_filter( 'blocksy:archive:render-card-layers', function ( $outputs, $prefix, $featured_image_args ) {
	if ( is_singular() || ! has_tag( 'checkin' ) ) {
		return $outputs;
	}
	if ( empty( $outputs['excerpt'] ) ) {
		return $outputs;
	}
	if ( ! class_exists( 'Loc_Config' ) || ! class_exists( 'Map_Provider' ) ) {
		return $outputs;
	}
	$post_id = get_the_ID();
	$lat     = get_post_meta( $post_id, 'geo_latitude', true );
	$lng     = get_post_meta( $post_id, 'geo_longitude', true );
	if ( ! $lat || ! $lng ) {
		return $outputs;
	}
	$map = Loc_Config::map_provider();
	if ( ! $map instanceof Map_Provider ) {
		return $outputs;
	}
	$zoom = get_post_meta( $post_id, 'geo_zoom', true ) ?: 14;
	$map->set( array( 'latitude' => (float) $lat, 'longitude' => (float) $lng, 'zoom' => (int) $zoom ) );
	$url = $map->get_the_static_map();
	if ( ! $url || is_wp_error( $url ) ) {
		return $outputs;
	}
	$img = '<img class="sloc-map-thumb" src="' . esc_url( $url ) . '" alt="" loading="lazy" />';
	// Inject img inside the entry-excerpt div, after the text, before closing tag.
	$outputs['excerpt'] = preg_replace( '|</div>\s*$|', $img . '</div>', $outputs['excerpt'] );
	return $outputs;
}, 10, 3 );

add_filter( 'the_content', function ( $content ) {
	if ( is_singular() ) {
		return $content;
	}
	return preg_replace( '/<div[^>]+class="[^"]*syndication-links[^"]*"[^>]*>.*?<\/div>/is', '', $content );
}, 999 );


add_filter( 'syn_link_mapping', function ( $return, $url ) {
	$domain = str_replace( 'www.', '', wp_parse_url( strtolower( $url ), PHP_URL_HOST ) ?? '' );
	if ( 'hachyderm.io' === $domain ) {
		return 'mastodon';
	}
	return $return;
}, 10, 2 );

add_filter( 'get_the_terms', function ( $terms, $post_id, $taxonomy ) {
	if ( is_admin() || 'category' !== $taxonomy || ! is_array( $terms ) ) {
		return $terms;
	}
	$non_default = array_filter( $terms, fn( $c ) => ! in_array( $c->slug, array( 'uncategorized', 'uncategorised' ), true ) );
	return empty( $non_default ) ? array() : $terms;
}, 10, 3 );

add_filter( 'pre_insert_micropub_post', function ( $args ) {
	if ( isset( $args['tags_input'] ) && is_array( $args['tags_input'] ) ) {
		$args['tags_input'] = array_values( array_filter( $args['tags_input'], 'is_string' ) );
	}

	$meta    = isset( $args['meta_input'] ) ? $args['meta_input'] : array();
	$checkin = isset( $meta['mf2_checkin'] ) ? $meta['mf2_checkin'] : null;
	if ( ! $checkin ) {
		return $args;
	}

	if ( empty( $args['post_content'] ) && empty( $args['post_title'] ) ) {
		$name = '';
		if ( is_array( $checkin ) && isset( $checkin['properties']['name'][0] ) ) {
			$name = $checkin['properties']['name'][0];
		}
		$locality = '';
		if ( is_array( $checkin ) && isset( $checkin['properties']['locality'][0] ) ) {
			$locality = $checkin['properties']['locality'][0];
		}
		$parts                = array_filter( array( $name, $locality ) );
		$args['post_content'] = 'Checked in at ' . implode( ', ', $parts );
	}

	if ( ! isset( $args['tax_input'] ) || ! is_array( $args['tax_input'] ) ) {
		$args['tax_input'] = array();
	}
	$args['tax_input']['post_format'] = array( 'post-format-status' );

	return $args;
} );

add_filter( 'post_class', function ( $classes ) {
	$classes[] = 'h-entry';
	return $classes;
} );

add_filter( 'the_title', function ( $title, $id = null ) {
	if ( ! is_singular() ) {
		return $title;
	}
	return '<span class="p-name">' . $title . '</span>';
}, 10, 2 );

// Don't count webmention types (like, repost, mention, etc.) in the comment number.
// WordPress get_comments_number() counts all comment_type values, but likes/reposts
// are displayed separately via Semantic Linkbacks facepile, not as real comments.
add_filter( 'get_comments_number', function ( $count, $post_id ) {
	if ( ! $post_id ) {
		return $count;
	}
	$webmention_types = function_exists( 'get_webmention_comment_type_names' )
		? get_webmention_comment_type_names()
		: array( 'webmention', 'pingback', 'trackback' );
	$placeholders     = implode( ',', array_fill( 0, count( $webmention_types ), '%s' ) );
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$non_count = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_type IN ({$placeholders}) AND comment_approved = '1'",
		array_merge( array( $post_id ), $webmention_types )
	) );
	return (int) $count - (int) $non_count;
}, 10, 2 );

// Webmention plugin's comment_query replaces type__not_in entirely (priority 10),
// which breaks Semantic Linkbacks' get_linkbacks() queries. For 'like'/'repost'
// we need to strip webmention types; for 'mention' we need to preserve SL's
// explicit type__not_in = 'comment'. Save the original before WM overwrites it.
add_action( 'pre_get_comments', function ( $query ) {
	$meta_query = $query->query_vars['meta_query'] ?? array();
	if ( empty( $meta_query ) ) {
		return;
	}
	foreach ( $meta_query as $mq ) {
		if ( is_array( $mq ) && isset( $mq['key'] ) && 'semantic_linkbacks_type' === $mq['key'] ) {
			if ( ! empty( $query->query_vars['type__not_in'] ) ) {
				$query->_sl_saved_type_not_in = $query->query_vars['type__not_in'];
			}
			return;
		}
	}
}, 9 );

add_action( 'pre_get_comments', function ( $query ) {
	$meta_query = $query->query_vars['meta_query'] ?? array();
	if ( empty( $meta_query ) || empty( $query->query_vars['type__not_in'] ) ) {
		return;
	}
	foreach ( $meta_query as $mq ) {
		if ( is_array( $mq ) && isset( $mq['key'] ) && 'semantic_linkbacks_type' === $mq['key'] ) {
			if ( isset( $query->_sl_saved_type_not_in ) ) {
				// 'mention' type query: SL set type__not_in, WM overwrote it — restore.
				$query->query_vars['type__not_in'] = $query->_sl_saved_type_not_in;
			} else {
				// 'like'/'repost' query: WM added webmention types — strip them.
				$query->query_vars['type__not_in'] = array_diff(
					$query->query_vars['type__not_in'],
					get_webmention_comment_type_names()
				);
			}
			return;
		}
	}
}, 11 );

// Let Semantic Linkbacks process like-type webmentions too, not just
// webmention/pingback/trackback. Otherwise Bridgy likes from Bluesky
// land without semantic_linkbacks_type meta and display raw "Bridgy Response".
add_filter( 'semantic_linkbacks_enhance_comment_types', function ( $types ) {
	$types[] = 'like';
	return $types;
} );

// Suppress "Bridgy Response" body text for like-type webmentions that
// predate the semantic_linkbacks_enhance_comment_types fix. Bridgy sends
// likes with content "Bridgy Response" which has no value to readers —
// the like is already shown via avatar/facepile.
add_filter( 'get_comment_text', function ( $text, $comment ) {
	if ( isset( $comment->comment_type ) && 'like' === $comment->comment_type && 'Bridgy Response' === trim( $text ) ) {
		return '';
	}
	return $text;
}, 13, 2 );

// Append "via [Platform]" to webmention comments so readers know where the
// reply came from (Mastodon, Bluesky, etc.). Extract platform from the
// webmention_source_url meta.
add_filter( 'get_comment_text', function ( $text, $comment ) {
	if ( 'webmention' !== get_comment_meta( $comment->comment_ID, 'protocol', true ) ) {
		return $text;
	}
	$source_url = get_comment_meta( $comment->comment_ID, 'webmention_source_url', true );
	if ( ! $source_url ) {
		return $text;
	}
	// Determine platform from the Bridgy URL path
	$via = 'webmention';
	if ( preg_match( '#/comment/mastodon/#i', $source_url ) || preg_match( '#/repost/mastodon/#i', $source_url ) ) {
		$via = 'Mastodon';
	} elseif ( preg_match( '#/comment/bluesky/#i', $source_url ) || preg_match( '#/like/bluesky/#i', $source_url ) ) {
		$via = 'Bluesky';
	}
	return $text . sprintf( ' (via %s)', $via );
}, 13, 2 );

// Block Bridgy Fed Bluesky self-comments from appearing as regular comments.
// When Bridgy Fed reflects a syndicated post back as a webmention, the mf2
// handler classifies it as type 'comment' (in-reply-to mapping) with author
// "bsky.app". These aren't real user interactions — just Bridgy's own internal
// notification. Spam them so they don't clutter the comment list.
add_filter( 'webmention_comment_data', function ( $commentdata ) {
	if ( ! $commentdata || is_wp_error( $commentdata ) ) {
		return $commentdata;
	}

	if ( ( $commentdata['comment_author'] ?? '' ) === 'bsky.app' ) {
		$commentdata['comment_approved'] = 'spam';
	}

	return $commentdata;
}, 22 );

// Blocksy uses get_the_date() output as a datetime= attribute value, so injecting HTML
// via get_the_date filter causes double-encoding. Inject dt-published + u-url as hidden
// elements inside e-content instead.
// Run at priority 20 so Simple Location's map (appended at 11/12) is inside e-content.
// Use a static flag to only run on the FIRST the_content call (main display).
// Syndication Links calls apply_filters('the_content', ...) again when sending to
// Bridgy — without this guard, the hidden dt-published/u-url text leaks into
// Mastodon/Bluesky syndication posts after HTML is stripped.
add_filter( 'the_content', function ( $content ) {
	static $done = false;
	if ( $done ) {
		return $content;
	}
	$done = true;

	if ( ! is_singular() ) {
		return $content;
	}
	$iso       = get_post_time( 'c', true );
	$permalink = get_permalink();
	$hidden    = '<div style="display:none">'
		. '<time class="dt-published" datetime="' . esc_attr( $iso ) . '">' . esc_html( $iso ) . '</time>'
		. '<a class="u-url" href="' . esc_url( $permalink ) . '">' . esc_html( $permalink ) . '</a>'
		. '</div>';
	return '<div class="e-content">' . $hidden . $content . '</div>';
}, 20 );
