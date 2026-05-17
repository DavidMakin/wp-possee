<?php
/**
 * Comment & Webmention handling.
 *
 * - Comment count excludes webmention types
 * - pre_get_comments fix (Semantic Linkbacks compatibility)
 * - Semantic Linkbacks type enhancement
 * - Suppress "Bridgy Response" text for likes
 * - "via [Platform]" label on webmention comments
 * - Bridgy Fed bsky.app self-comment spam filter
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'get_comments_number', 'possee_get_comments_number', 10, 2 );
function possee_get_comments_number( $count, $post_id ) {
	if ( ! $post_id ) {
		return $count;
	}
	$cache_key = 'possee_non_comment_count_' . $post_id;
	$non_count = wp_cache_get( $cache_key, 'comments' );
	if ( false !== $non_count ) {
		return (int) $count - (int) $non_count;
	}
	$webmention_types = function_exists( 'get_webmention_comment_type_names' )
		? get_webmention_comment_type_names()
		: array( 'webmention', 'pingback', 'trackback' );
	$placeholders     = implode( ',', array_fill( 0, count( $webmention_types ), '%s' ) );
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$non_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_type IN ({$placeholders}) AND comment_approved = '1'",
		array_merge( array( $post_id ), $webmention_types )
	) );
	wp_cache_set( $cache_key, $non_count, 'comments' );
	return (int) $count - $non_count;
}

add_action( 'wp_update_comment_count', 'possee_clear_comment_count_cache' );
function possee_clear_comment_count_cache( $post_id ) {
	wp_cache_delete( 'possee_non_comment_count_' . $post_id, 'comments' );
}

add_action( 'pre_get_comments', 'possee_pre_get_comments_save_type_not_in', 9 );
function possee_pre_get_comments_save_type_not_in( $query ) {
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
}

add_action( 'pre_get_comments', 'possee_pre_get_comments_restore_type_not_in', 11 );
function possee_pre_get_comments_restore_type_not_in( $query ) {
	$meta_query = $query->query_vars['meta_query'] ?? array();
	if ( empty( $meta_query ) || empty( $query->query_vars['type__not_in'] ) ) {
		return;
	}
	foreach ( $meta_query as $mq ) {
		if ( is_array( $mq ) && isset( $mq['key'] ) && 'semantic_linkbacks_type' === $mq['key'] ) {
			if ( isset( $query->_sl_saved_type_not_in ) ) {
				$query->query_vars['type__not_in'] = $query->_sl_saved_type_not_in;
			} else {
				$query->query_vars['type__not_in'] = array_diff(
					$query->query_vars['type__not_in'],
					get_webmention_comment_type_names()
				);
			}
			return;
		}
	}
}

add_filter( 'semantic_linkbacks_enhance_comment_types', 'possee_enhance_comment_types' );
function possee_enhance_comment_types( $types ) {
	$types[] = 'like';
	return $types;
}

add_filter( 'get_comment_text', 'possee_suppress_bridgy_response', 13, 2 );
function possee_suppress_bridgy_response( $text, $comment ) {
	if ( isset( $comment->comment_type ) && 'like' === $comment->comment_type && 'Bridgy Response' === trim( $text ) ) {
		return '';
	}
	return $text;
}

add_filter( 'get_comment_text', 'possee_via_label', 13, 2 );
function possee_via_label( $text, $comment ) {
	if ( 'webmention' !== get_comment_meta( $comment->comment_ID, 'protocol', true ) ) {
		return $text;
	}
	$source_url = get_comment_meta( $comment->comment_ID, 'webmention_source_url', true );
	if ( ! $source_url ) {
		return $text;
	}
	$via = 'webmention';
	if ( preg_match( '#/comment/mastodon/#i', $source_url ) || preg_match( '#/repost/mastodon/#i', $source_url ) ) {
		$via = 'Mastodon';
	} elseif ( preg_match( '#/comment/bluesky/#i', $source_url ) || preg_match( '#/like/bluesky/#i', $source_url ) ) {
		$via = 'Bluesky';
	}
	return $text . sprintf( ' (via %s)', $via );
}

add_filter( 'webmention_comment_data', 'possee_spam_bsky_self_comments', 22 );
function possee_spam_bsky_self_comments( $commentdata ) {
	if ( ! $commentdata || is_wp_error( $commentdata ) ) {
		return $commentdata;
	}
	if ( ( $commentdata['comment_author'] ?? '' ) === 'bsky.app' ) {
		$commentdata['comment_approved'] = 'spam';
	}
	return $commentdata;
}
