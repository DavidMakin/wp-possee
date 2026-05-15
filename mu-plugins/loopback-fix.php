<?php
/**
 * Plugin Name: Loopback Request Fix
 * Description: Rewrites internal WordPress loopback requests from https://domain:443
 *              to http://nginx:80 so WordPress can reach itself behind a reverse proxy.
 *
 * wp_safe_remote_get() resolves DNS before pre_http_request fires. Inside Docker,
 * the site domain resolves to a private 172.x.x.x IP (nginx container), which
 * WordPress rejects as unsafe. We disable reject_unsafe_urls for our own domain via
 * http_request_args (fires before URL validation), then rewrite in pre_http_request.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'http_request_args', function ( $args, $url ) {
	$site_host = parse_url( home_url(), PHP_URL_HOST );
	if ( $site_host && strpos( $url, 'https://' . $site_host ) === 0 ) {
		$args['reject_unsafe_urls'] = false;
	}
	return $args;
}, 1, 2 );

add_filter( 'pre_http_request', function ( $preempt, $parsed_args, $url ) {
	static $in_progress = false;
	if ( $in_progress ) {
		return $preempt;
	}

	$site_host = parse_url( home_url(), PHP_URL_HOST );

	if ( ! $site_host || strpos( $url, 'https://' . $site_host ) !== 0 ) {
		return $preempt;
	}

	$in_progress = true;

	$internal_url = preg_replace( '#^https://' . preg_quote( $site_host, '#' ) . '#', 'http://nginx', $url );

	if ( ! isset( $parsed_args['headers'] ) || is_string( $parsed_args['headers'] ) ) {
		$parsed_args['headers'] = [];
	}
	$parsed_args['headers']['Host'] = $site_host;

	$response    = wp_remote_request( $internal_url, $parsed_args );
	$in_progress = false;

	return $response;
}, 10, 3 );
