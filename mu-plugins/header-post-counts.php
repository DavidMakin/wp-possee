<?php
defined( 'ABSPATH' ) || exit;

add_filter( 'blocksy:header:items-paths', function ( $paths ) {
	$paths[] = __DIR__ . '/header-items';
	return $paths;
} );
