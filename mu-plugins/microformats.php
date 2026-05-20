<?php
defined( 'SYNDICATION_LINKS_BRIDGY_WEBMENTION' ) || define( 'SYNDICATION_LINKS_BRIDGY_WEBMENTION', 1 );


add_action( 'wp_head', 'possee_wp_head_microformats' );
function possee_wp_head_microformats() {
	echo '<link rel="me" href="https://hachyderm.io/@_sleeper" />' . "\n";
	echo '<link rel="me" href="https://bsky.app/profile/sleep-er.bsky.social" />' . "\n";

	if ( is_singular( 'post' ) ) {
		$post        = get_queried_object();
		$title       = esc_attr( get_the_title( $post ) );
		$url         = esc_url( get_permalink( $post ) );
		$description = esc_attr( has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( strip_tags( $post->post_content ), 30 ) );

		$image = '';
		if ( 'book' === $post->post_type ) {
			$book_data = possee_book_get_data( $post->ID );
			if ( $book_data ) {
				if ( $book_data['isbn'] ) {
					$image = possee_book_cover_url( $book_data['isbn'], 'L' );
				} elseif ( ! empty( $book_data['hc_cover'] ) ) {
					$image = $book_data['hc_cover'];
				}
			}
		}
		if ( ! $image && has_post_thumbnail( $post ) ) {
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
	} elseif ( is_archive() ) {
		$title       = esc_attr( get_the_archive_title() );
		$description = esc_attr( strip_tags( get_the_archive_description() ) );
		if ( $description ) {
			echo '<meta property="og:type" content="website" />' . "\n";
			echo '<meta property="og:title" content="' . $title . '" />' . "\n";
			echo '<meta property="og:description" content="' . $description . '" />' . "\n";
		}
	}
}

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

/**
 * Notes have no title and syndicated copies lack a link back.
 * Give Bridgy explicit content with the permalink so Mastodon
 * and Bluesky posts include a "blog.sleep-er.co.uk" link.
 */
add_action( 'wp_footer', 'possee_note_bridgy_content' );
function possee_note_bridgy_content() {
	if ( ! is_singular( 'note' ) ) {
		return;
	}
	$post        = get_queried_object();
	$permalink   = esc_url( get_permalink( $post ) );
	$content_raw = get_post_field( 'post_content', $post );
	$content     = esc_html( wp_trim_words( strip_tags( $content_raw ), 55 ) );

	printf(
		'<p class="p-bridgy-mastodon-content" style="display:none">%s %s</p>' . "\n",
		$content,
		$permalink
	);
	printf(
		'<p class="p-bridgy-bluesky-content" style="display:none">%s %s</p>' . "\n",
		$content,
		$permalink
	);
}

add_filter( 'blocksy:options:meta:meta_default_elements', 'possee_register_meta_defaults' );
function possee_register_meta_defaults( $elements ) {
	$elements[] = array(
		'id'      => 'syndication_links',
		'enabled' => false,
	);
	$elements[] = array(
		'id'      => 'reading_time',
		'enabled' => false,
	);
	return $elements;
}

add_filter( 'blocksy:options:meta:meta_elements', 'possee_register_meta_elements' );
function possee_register_meta_elements( $elements ) {
	$elements['syndication_links'] = array(
		'label'   => __( 'Syndication Links', 'blocksy' ),
		'options' => array(),
	);
	$elements['reading_time'] = array(
		'label'   => __( 'Reading Time', 'blocksy' ),
		'options' => array(),
	);
	return $elements;
}

add_action( 'blocksy:post-meta:render-meta', 'possee_render_meta' );
function possee_render_meta( $id ) {
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
}

/**
 * Helper: extract checkin venue and locality data from post meta.
 * Returns array with keys: venue_name, venue_url, locality, country, lat, lng, weather_temp, weather_summary, checked_in_by.
 */
function possee_checkin_data( $post_id ) {
	$data = array(
		'venue_name'      => '',
		'venue_url'       => '',
		'venue_icon'      => '',
		'locality'        => '',
		'country'         => '',
		'lat'             => '',
		'lng'             => '',
		'weather_temp'    => '',
		'weather_summary' => '',
		'checked_in_by'   => array(),
	);

	// Venue from mf2_checkin.
	$checkin = get_post_meta( $post_id, 'mf2_checkin', true );
	if ( is_array( $checkin ) && isset( $checkin['properties'] ) ) {
		$props = $checkin['properties'];
		if ( ! empty( $props['name'][0] ) ) {
			$data['venue_name'] = html_entity_decode( $props['name'][0], ENT_QUOTES );
		}
		if ( ! empty( $props['url'][0] ) ) {
			$data['venue_url'] = $props['url'][0];
		}
	}
	$data['venue_icon'] = get_post_meta( $post_id, 'swarm_venue_icon', true ) ?: '';

	// Locality + country from mf2_location.
	$location = get_post_meta( $post_id, 'mf2_location', true );
	if ( is_array( $location ) && isset( $location['properties'] ) ) {
		$props = $location['properties'];
		if ( ! empty( $props['locality'][0] ) ) {
			$data['locality'] = $props['locality'][0];
		}
		if ( ! empty( $props['country-name'][0] ) ) {
			$data['country'] = $props['country-name'][0];
		}
	}

	// Coordinates.
	$data['lat'] = get_post_meta( $post_id, 'geo_latitude', true );
	$data['lng'] = get_post_meta( $post_id, 'geo_longitude', true );

	// Weather.
	$temp = get_post_meta( $post_id, 'weather_temperature', true );
	if ( $temp !== '' ) {
		$data['weather_temp'] = round( (float) $temp ) . '°C';
	}
	$summary = get_post_meta( $post_id, 'weather_summary', true );
	if ( $summary ) {
		$data['weather_summary'] = $summary;
	}

	// Checked in by (mf2_checked-in-by: array of h-cards).
	$by = get_post_meta( $post_id, 'mf2_checked-in-by', true );
	if ( is_array( $by ) ) {
		foreach ( $by as $hcard ) {
			if ( ! isset( $hcard['properties'] ) ) {
				continue;
			}
			$props = $hcard['properties'];
			$name  = ! empty( $props['name'][0] ) ? $props['name'][0] : '';
			$url   = ! empty( $props['url'][0] ) ? $props['url'][0] : '';
			if ( $name ) {
				$data['checked_in_by'][] = array( 'name' => $name, 'url' => $url );
			}
		}
	}

	return $data;
}

add_filter( 'get_the_excerpt', 'possee_checkin_excerpt', 5, 2 );
function possee_checkin_excerpt( $excerpt, $post ) {
	if ( ! has_tag( 'checkin', $post ) ) {
		return $excerpt;
	}
	// On singular, suppress the excerpt so Blocksy's hero doesn't render it as a description.
	if ( is_singular() ) {
		return '';
	}

	$d = possee_checkin_data( $post->ID );

	$venue_html = $d['venue_name']
		? ( $d['venue_url']
			? '<a href="' . esc_url( $d['venue_url'] ) . '">' . esc_html( $d['venue_name'] ) . '</a>'
			: esc_html( $d['venue_name'] ) )
		: '';

	$parts = array_filter( array( $d['locality'], $d['country'] ) );
	$place = implode( ', ', $parts );

	$meta_parts = array();
	if ( $venue_html ) {
		$pin = $d['venue_icon']
			? '<img class="checkin-venue-icon" src="' . esc_url( $d['venue_icon'] ) . '" alt="" width="20" height="20" loading="lazy">'
			: '<span aria-hidden="true">📍</span>';
		$meta_parts[] = $pin . ' at ' . $venue_html;
	}
	if ( $place ) {
		$meta_parts[] = esc_html( $place );
	}
	if ( $d['weather_temp'] ) {
		$meta_parts[] = esc_html( $d['weather_temp'] );
	}

	$coins_total = get_post_meta( $post->ID, 'swarm_score_total', true );
	if ( $coins_total ) {
		$meta_parts[] = '<img src="https://ss1.4sqi.net/img/points/coin_icon_coin.png" alt="" width="16" height="16" style="vertical-align:middle"> +' . (int) $coins_total;
	}

	if ( empty( $meta_parts ) ) {
		return wp_strip_all_tags( $post->post_content );
	}

	$venue_part = array_shift( $meta_parts );
	$rest       = $meta_parts ? '<span class="checkin-excerpt-meta">' . implode( ' · ', $meta_parts ) . '</span>' : '';

	return '<span class="checkin-excerpt"><span class="checkin-excerpt-venue">' . $venue_part . '</span>' . $rest . '</span>';
}

add_filter( 'blocksy:excerpt:output', 'possee_checkin_excerpt_blocksy', 10, 1 );
function possee_checkin_excerpt_blocksy( $excerpt ) {
	if ( is_singular() ) {
		return $excerpt;
	}
	$post = get_post();
	if ( ! $post || ! has_tag( 'checkin', $post ) ) {
		return $excerpt;
	}
	return possee_checkin_excerpt( $excerpt, $post );
}

add_filter( 'blocksy:archive:render-card-layers', 'possee_archive_likes', 9, 3 );
function possee_archive_likes( $outputs, $prefix, $featured_image_args ) {
	if ( ! function_exists( 'get_linkbacks' ) || ! function_exists( 'list_linkbacks' ) ) {
		return $outputs;
	}
	$likes = get_linkbacks( 'like' );
	if ( empty( $likes ) ) {
		return $outputs;
	}
	$links_html = list_linkbacks(
		array(
			'type' => 'like',
			'echo' => false,
		),
		$likes
	);
	if ( $links_html ) {
		$likes_html = '<div class="likes">' . $links_html . '</div>';
		if ( isset( $outputs['excerpt'] ) ) {
			$outputs['excerpt'] .= $likes_html;
		} else {
			$outputs['excerpt'] = $likes_html;
		}
	}
	return $outputs;
}

add_filter( 'blocksy:archive:render-card-layers', 'possee_checkin_map_layer', 10, 3 );
function possee_checkin_map_layer( $outputs, $prefix, $featured_image_args ) {
	if ( is_singular() || ! has_tag( 'checkin' ) ) {
		return $outputs;
	}
	if ( empty( $outputs['excerpt'] ) ) {
		return $outputs;
	}
	// If the post has a featured image, Blocksy will show that — no need for a map thumbnail.
	if ( has_post_thumbnail() ) {
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
	// Use stored zoom (typically 18 = street level from OwnYourSwarm); fall back to 16.
	$zoom = get_post_meta( $post_id, 'geo_zoom', true ) ?: 16;
	$map->set( array( 'latitude' => (float) $lat, 'longitude' => (float) $lng, 'zoom' => (int) $zoom ) );
	$url = $map->get_the_static_map();
	if ( ! $url || is_wp_error( $url ) ) {
		return $outputs;
	}
	$osm_url = 'https://www.openstreetmap.org/?mlat=' . urlencode( $lat ) . '&mlon=' . urlencode( $lng ) . '#map=' . (int) $zoom . '/' . urlencode( $lat ) . '/' . urlencode( $lng );
	$img = '<a href="' . esc_url( $osm_url ) . '" target="_blank" rel="noopener">'
		. '<img class="sloc-map-thumb" src="' . esc_url( $url ) . '" alt="" loading="lazy" />'
		. '</a>';
	$outputs['excerpt'] = preg_replace( '|</div>\s*$|', $img . '</div>', $outputs['excerpt'] );
	return $outputs;
}

// On singular checkin posts, suppress Simple Location's map/location text and
// Micropub plugin's dynamic render — our checkin-header block replaces them all.
add_action( 'wp', 'possee_remove_sloc_content_on_checkin' );
function possee_remove_sloc_content_on_checkin() {
	if ( ! is_singular() || ! has_tag( 'checkin' ) ) {
		return;
	}
	remove_filter( 'the_content', array( 'Geo_Data', 'content_map' ), 11 );
	remove_filter( 'the_content', array( 'Geo_Data', 'location_content' ), 12 );
	remove_filter( 'the_content', array( 'Micropub\Render', 'render_content' ), 1 );
}

add_filter( 'the_content', 'possee_checkin_header', 5 );
function possee_checkin_header( $content ) {
	if ( ! is_singular() || ! in_the_loop() || ! has_tag( 'checkin' ) ) {
		return $content;
	}

	static $done = array();
	$post_id = get_the_ID();
	if ( isset( $done[ $post_id ] ) ) {
		return $content;
	}
	$done[ $post_id ] = true;

	$post_id = get_the_ID();

	// Strip the auto-generated "Checked in at X, Y" prose if that's all the content is —
	// the header block replaces it. Real notes added by the user are preserved.
	$stripped = trim( wp_strip_all_tags( $content ) );
	if ( preg_match( '/^Checked in at /i', $stripped ) ) {
		$content = '';
	}

	$post_id = get_the_ID();
	$d       = possee_checkin_data( $post_id );

	// Venue line.
	$venue_html = '';
	if ( $d['venue_name'] ) {
		$venue_html = $d['venue_url']
			? '<a class="checkin-venue-link" href="' . esc_url( $d['venue_url'] ) . '">' . esc_html( $d['venue_name'] ) . '</a>'
			: '<span class="checkin-venue-link">' . esc_html( $d['venue_name'] ) . '</span>';
	}

	// Locality + country.
	$place_parts = array_filter( array( $d['locality'], $d['country'] ) );
	$place       = implode( ', ', $place_parts );

	// Weather.
	$weather = '';
	if ( $d['weather_temp'] || $d['weather_summary'] ) {
		$w_parts = array_filter( array( $d['weather_temp'], $d['weather_summary'] ) );
		$weather = implode( ' · ', $w_parts );
	}

	// Coordinates.
	$coords = '';
	if ( $d['lat'] && $d['lng'] ) {
		$osm_url = 'https://www.openstreetmap.org/?mlat=' . urlencode( $d['lat'] ) . '&mlon=' . urlencode( $d['lng'] ) . '#map=18/' . urlencode( $d['lat'] ) . '/' . urlencode( $d['lng'] );
		$coords  = '<a class="checkin-coords" href="' . esc_url( $osm_url ) . '" target="_blank" rel="noopener">'
			. esc_html( $d['lat'] ) . ' ' . esc_html( $d['lng'] )
			. '</a>';
	}

	// Static map.
	$map_html = '';
	if ( $d['lat'] && $d['lng'] && class_exists( 'Loc_Config' ) && class_exists( 'Map_Provider' ) ) {
		$map  = Loc_Config::map_provider();
		if ( $map instanceof Map_Provider ) {
			$zoom = get_post_meta( $post_id, 'geo_zoom', true ) ?: 16;
			$map->set( array( 'latitude' => (float) $d['lat'], 'longitude' => (float) $d['lng'], 'zoom' => (int) $zoom ) );
			$url = $map->get_the_static_map();
			if ( $url && ! is_wp_error( $url ) ) {
				$osm_url  = 'https://www.openstreetmap.org/?mlat=' . urlencode( $d['lat'] ) . '&mlon=' . urlencode( $d['lng'] ) . '#map=' . (int) $zoom . '/' . urlencode( $d['lat'] ) . '/' . urlencode( $d['lng'] );
				$map_html = '<a href="' . esc_url( $osm_url ) . '" target="_blank" rel="noopener">'
					. '<img class="checkin-map" src="' . esc_url( $url ) . '" alt="Map showing location of ' . esc_attr( $d['venue_name'] ) . '" loading="lazy" />'
					. '</a>';
			}
		}
	}

	// Build header block.
	$header = '<div class="checkin-header">';
	if ( $map_html ) {
		$header .= $map_html;
	}
	$header .= '<div class="checkin-meta">';
	if ( $venue_html ) {
		$pin = $d['venue_icon']
			? '<img class="checkin-venue-icon" src="' . esc_url( $d['venue_icon'] ) . '" alt="" width="24" height="24" loading="lazy">'
			: '<span aria-hidden="true">📍</span>';
		$header .= '<div class="checkin-venue">' . $pin . 'at ' . $venue_html . '</div>';
	}
	// Checked in by.
	if ( ! empty( $d['checked_in_by'] ) ) {
		$by_parts = array();
		foreach ( $d['checked_in_by'] as $person ) {
			$by_parts[] = $person['url']
				? '<a href="' . esc_url( $person['url'] ) . '">' . esc_html( $person['name'] ) . '</a>'
				: esc_html( $person['name'] );
		}
		$checkin_dt = get_the_date( 'j M Y' ) . ' at ' . get_the_time( 'H:i' );
		$header .= '<div class="checkin-by">Checked in by ' . implode( ', ', $by_parts ) . ' <span class="checkin-by-date">' . esc_html( $checkin_dt ) . '</span></div>';
	}
	if ( $place ) {
		$header .= '<div class="checkin-place">' . esc_html( $place ) . '</div>';
	}
	if ( $weather ) {
		$header .= '<div class="checkin-weather">' . esc_html( $weather ) . '</div>';
	}
	if ( $coords ) {
		$header .= '<div class="checkin-coords-wrap">' . $coords . '</div>';
	}
	$header .= '</div>'; // .checkin-meta

	// Swarm coins block.
	$coins_items = get_post_meta( $post_id, 'swarm_score_items', true );
	$coins_total = get_post_meta( $post_id, 'swarm_score_total', true );
	if ( $coins_items && is_array( $coins_items ) ) {
		$header .= '<div class="checkin-coins">';
		$header .= '<div class="checkin-coins-total"><img src="https://ss1.4sqi.net/img/points/coin_icon_coin.png" alt="" width="20" height="20" loading="lazy">+' . (int) $coins_total . ' <span>Coins</span></div>';
		$header .= '<ul class="checkin-coins-list">';
		foreach ( $coins_items as $coin ) {
			$icon_html = ! empty( $coin['icon'] )
				? '<img src="' . esc_url( $coin['icon'] ) . '" alt="" width="18" height="18" loading="lazy">'
				: '';
			$header .= '<li><span class="coin-points">+' . (int) $coin['points'] . '</span>'
				. $icon_html
				. '<span class="coin-message">' . esc_html( $coin['message'] ) . '</span></li>';
		}
		$header .= '</ul>';
		$header .= '</div>'; // .checkin-coins
	}

	$header .= '</div>'; // .checkin-header

	$footer = '<p class="checkin-via"><a href="https://ownyourswarm.p3k.io/" rel="noopener">Added via OwnYourSwarm</a></p>';

	return $header . $content . $footer;
}

add_filter( 'the_content', 'possee_quill_footer', 15 );
function possee_quill_footer( $content ) {
	if ( ! is_singular() || ! in_the_loop() ) {
		return $content;
	}
	// Don't double-up on checkin posts — they already have their own footer.
	if ( has_tag( 'checkin' ) ) {
		return $content;
	}
	$auth = get_post_meta( get_the_ID(), 'micropub_auth_response', true );
	if ( empty( $auth['client_id'] ) || $auth['client_id'] !== 'https://quill.p3k.io/' ) {
		return $content;
	}
	return $content . '<p class="checkin-via"><a href="https://quill.p3k.io/" rel="noopener">Added via Quill</a></p>';
}

add_filter( 'the_content', 'possee_strip_syndication_links', 999 );
function possee_strip_syndication_links( $content ) {
	if ( is_singular() ) {
		return $content;
	}
	return preg_replace( '/<div[^>]+class="[^"]*syndication-links[^"]*"[^>]*>.*?<\/div>/is', '', $content );
}


add_filter( 'syn_link_mapping', 'possee_syn_link_mapping', 10, 2 );
function possee_syn_link_mapping( $return, $url ) {
	$domain = str_replace( 'www.', '', wp_parse_url( strtolower( $url ), PHP_URL_HOST ) ?? '' );
	if ( 'hachyderm.io' === $domain ) {
		return 'mastodon';
	}
	if ( 'bsky.app' === $domain || 'bsky.social' === $domain ) {
		return 'bluesky';
	}
	return $return;
}

add_filter( 'get_the_terms', 'possee_hide_default_category', 10, 3 );
function possee_hide_default_category( $terms, $post_id, $taxonomy ) {
	if ( is_admin() || 'category' !== $taxonomy || ! is_array( $terms ) ) {
		return $terms;
	}
	$non_default = array_filter( $terms, fn( $c ) => ! in_array( $c->slug, array( 'uncategorized', 'uncategorised' ), true ) );
	return empty( $non_default ) ? array() : $terms;
}

add_filter( 'pre_insert_micropub_post', 'possee_sanitize_micropub' );
function possee_sanitize_micropub( $args ) {
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
}

/*
 * Micropub: route read-of posts to 'books' CPT, with ISBN dedup.
 */

add_filter( 'micropub_post_type', 'possee_micropub_book_post_type', 10, 2 );
function possee_micropub_book_post_type( $post_type, $input ) {
	if ( isset( $input['properties']['read-of'] ) || isset( $input['properties']['read-status'] ) ) {
		return 'book';
	}
	return $post_type;
}

add_filter( 'micropub_suggest_title', 'possee_micropub_book_slug', 10, 2 );
function possee_micropub_book_slug( $title, $props ) {
	if ( ! isset( $props['read-of'][0]['properties']['name'][0] ) ) {
		return $title;
	}
	return $props['read-of'][0]['properties']['name'][0];
}

add_filter( 'pre_insert_micropub_post', 'possee_micropub_book_deduplicate' );
function possee_micropub_book_deduplicate( $args ) {
	if ( ! isset( $args['meta_input']['mf2_read-of'] ) ) {
		return $args;
	}

	$read_of = $args['meta_input']['mf2_read-of'];
	if ( ! is_array( $read_of ) || empty( $read_of ) ) {
		return $args;
	}

	$item  = $read_of[0];
	$props = isset( $item['properties'] ) ? $item['properties'] : array();

	// Set post_title from the book name inside read-of.
	if ( isset( $props['name'][0] ) && empty( $args['post_title'] ) ) {
		$args['post_title'] = $props['name'][0];
	}

	// Set post_content from summary if no content.
	if ( isset( $args['post_excerpt'] ) && empty( $args['post_content'] ) ) {
		$args['post_content'] = $args['post_excerpt'];
	}

	// Extract ISBN for dedup.
	if ( ! isset( $props['uid'][0] ) ) {
		return $args;
	}

	$uid = $props['uid'][0];
	if ( strpos( $uid, 'isbn:' ) !== 0 && strpos( $uid, 'ISBN:' ) !== 0 ) {
		return $args;
	}

	$isbn = substr( $uid, 5 );

	// Check for existing book with this ISBN.
	$existing = get_posts( array(
		'post_type'        => 'book',
		'post_status'      => 'any',
		'meta_key'         => 'isbn',
		'meta_value'       => $isbn,
		'fields'           => 'ids',
		'posts_per_page'   => 1,
		'update_meta_cache' => false,
		'no_found_rows'    => true,
	) );

	if ( ! empty( $existing ) ) {
		// Short-circuit — book already exists.
		$args['ID'] = $existing[0];

		// When transitioning to "finished", update post_date to match.
		$new_status = $args['meta_input']['mf2_read-status'] ?? null;
		if ( 'finished' === $new_status ) {
			$finished_at = $args['meta_input']['mf2_finished-at'] ?? null;
			if ( $finished_at ) {
				$date = date( 'Y-m-d H:i:s', strtotime( $finished_at ) );
				$args['post_date']     = $date;
				$args['post_date_gmt'] = get_gmt_from_date( $date );
			}
		}
	} else {
		// Store ISBN as accessible meta for future dedup queries.
		if ( ! isset( $args['meta_input'] ) ) {
			$args['meta_input'] = array();
		}
		$args['meta_input']['isbn'] = $isbn;

		// Store author as accessible meta.
		if ( isset( $props['author'][0] ) ) {
			$args['meta_input']['book_author'] = $props['author'][0];
		}
	}

	return $args;
}

add_filter( 'post_class', 'possee_add_hentry_class' );
function possee_add_hentry_class( $classes ) {
	$classes[] = 'h-entry';
	return $classes;
}

add_filter( 'the_title', 'possee_title_pname', 10, 2 );
function possee_title_pname( $title, $id = null ) {
	if ( ! is_singular() ) {
		return $title;
	}
	return '<span class="p-name">' . $title . '</span>';
}

add_filter( 'the_content', 'possee_wrap_econtent', 20 );
function possee_wrap_econtent( $content ) {
	if ( ! is_singular() || ! in_the_loop() ) {
		return $content;
	}
	static $done = array();
	$post_id = get_the_ID();
	if ( isset( $done[ $post_id ] ) ) {
		return $content;
	}
	$done[ $post_id ] = true;
	$iso       = get_post_time( 'c', true );
	$permalink = get_permalink();
	$hidden    = '<div style="display:none">'
		. '<time class="dt-published" datetime="' . esc_attr( $iso ) . '">' . esc_html( $iso ) . '</time>'
		. '<a class="u-url" href="' . esc_url( $permalink ) . '">' . esc_html( $permalink ) . '</a>'
		. '</div>';
	return '<div class="e-content">' . $hidden . $content . '</div>';
}

add_filter( 'the_content', 'possee_venue_recent_checkins', 20 );
function possee_venue_recent_checkins( $content ) {
	if ( ! is_singular( 'venue' ) || ! in_the_loop() ) {
		return $content;
	}

	$venue_id = get_the_ID();

	$checkins = get_posts( array(
		'post_type'      => 'post',
		'posts_per_page' => 5,
		'post_status'    => 'publish',
		'meta_key'       => 'venue_id',
		'meta_value'     => $venue_id,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	if ( empty( $checkins ) ) {
		return $content;
	}

	$html = '<div class="venue-checkins">';
	$html .= '<h3 class="venue-checkins-title">Recent check-ins</h3>';
	$html .= '<ul class="venue-checkins-list">';

	foreach ( $checkins as $checkin ) {
		$d           = possee_checkin_data( $checkin->ID );
		$url         = get_permalink( $checkin->ID );
		$date        = get_the_date( 'j M Y', $checkin->ID );
		$coins_total = get_post_meta( $checkin->ID, 'swarm_score_total', true );

		$by_html = '';
		if ( ! empty( $d['checked_in_by'] ) ) {
			$names = array();
			foreach ( $d['checked_in_by'] as $person ) {
				$names[] = $person['url']
					? '<a href="' . esc_url( $person['url'] ) . '">' . esc_html( $person['name'] ) . '</a>'
					: esc_html( $person['name'] );
			}
			$by_html = ' by ' . implode( ', ', $names );
		}

		$meta_parts = array();
		if ( $d['locality'] ) {
			$meta_parts[] = esc_html( $d['locality'] );
		}
		if ( $coins_total ) {
			$meta_parts[] = '<img src="https://ss1.4sqi.net/img/points/coin_icon_coin.png" alt="" width="14" height="14" style="vertical-align:middle"> +' . (int) $coins_total;
		}

		$html .= '<li class="venue-checkin-item">';
		$html .= '<a class="venue-checkin-date" href="' . esc_url( $url ) . '">' . esc_html( $date ) . '</a>';
		if ( $by_html ) {
			$html .= '<span class="venue-checkin-by">' . $by_html . '</span>';
		}
		if ( $meta_parts ) {
			$html .= '<span class="venue-checkin-meta">' . implode( ' · ', $meta_parts ) . '</span>';
		}
		$html .= '</li>';
	}

	$html .= '</ul>';
	$html .= '</div>';

	return $content . $html;
}
