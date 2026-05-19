<?php

if ( ! isset( $device ) ) {
	$device = 'desktop';
}

$visibility = blocksy_default_akg( 'visibility', $atts, [
	'tablet' => true,
	'mobile' => false,
] );

$class = 'ct-header-post-counts ' . blocksy_visibility_classes( $visibility );

$items = [
	[
		'key'       => 'articles',
		'label'     => 'Articles',
		'url'       => home_url( '/articles/' ),
		'post_type' => 'post',
	],
	[
		'key'       => 'books',
		'label'     => 'Books',
		'url'       => home_url( '/books/' ),
		'post_type' => 'book',
	],
	[
		'key'       => 'notes',
		'label'     => 'Notes',
		'url'       => home_url( '/notes/' ),
		'post_type' => 'note',
	],
	[
		'key'       => 'checkins',
		'label'     => 'Checkins',
		'url'       => home_url( '/checkins/' ),
		'post_type' => 'checkin',
	],
];

$transient_key = 'possee_header_post_counts_v2';
$data          = get_transient( $transient_key );

if ( false === $data ) {
	global $wpdb;

	$weeks = [];
	for ( $i = 51; $i >= 0; $i-- ) {
		$ts      = strtotime( "-{$i} weeks" );
		$weeks[] = date( 'oW', $ts );
	}

	$raw = $wpdb->get_results(
		"SELECT post_type,
		        DATE_FORMAT(post_date, '%x%v') AS yw,
		        COUNT(*) AS n
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','book','note','checkin')
		   AND post_date >= DATE_SUB(NOW(), INTERVAL 52 WEEK)
		 GROUP BY post_type, yw
		 ORDER BY yw",
		ARRAY_A
	);

	$by_type = [];
	foreach ( $raw as $row ) {
		$by_type[ $row['post_type'] ][ $row['yw'] ] = (int) $row['n'];
	}

	$data = [
		'counts'     => [],
		'sparklines' => [],
	];

	$data['counts']['articles'] = (int) wp_count_posts( 'post' )->publish;
	$data['counts']['books']    = (int) wp_count_posts( 'book' )->publish;
	$data['counts']['notes']    = (int) wp_count_posts( 'note' )->publish;
	$data['counts']['checkins'] = (int) wp_count_posts( 'checkin' )->publish;

	$type_map = [
		'articles' => 'post',
		'books'    => 'book',
		'notes'    => 'note',
		'checkins' => 'checkin',
	];

	foreach ( $type_map as $key => $post_type ) {
		$series = [];
		foreach ( $weeks as $yw ) {
			$series[] = $by_type[ $post_type ][ $yw ] ?? 0;
		}
		$data['sparklines'][ $key ] = $series;
	}

	set_transient( $transient_key, $data, 12 * HOUR_IN_SECONDS );
}

function possee_sparkline_svg( array $series, int $w = 52, int $h = 16 ): string {
	$n = count( $series );
	if ( $n < 2 ) {
		return '';
	}

	$max     = max( $series );
	$max     = $max === 0 ? 1 : $max;
	$pad     = 1.5;
	$inner_h = $h - $pad * 2;

	$points = [];
	foreach ( $series as $i => $v ) {
		$x        = round( $i / ( $n - 1 ) * $w, 2 );
		$y        = round( $pad + $inner_h - ( $v / $max ) * $inner_h, 2 );
		$points[] = "{$x},{$y}";
	}

	return sprintf(
		'<svg class="post-counts-sparkline" viewBox="0 0 %d %d" width="%d" height="%d" aria-hidden="true" preserveAspectRatio="none">'
		. '<polyline points="%s" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
		. '</svg>',
		$w, $h, $w, $h,
		esc_attr( implode( ' ', $points ) )
	);
}

?>
<div class="<?php echo esc_attr( $class ); ?>" <?php echo blocksy_attr_to_html( $attr ); ?>>
	<ul class="post-counts-list">
		<?php foreach ( $items as $item ) :
			$count = $data['counts'][ $item['key'] ] ?? 0;
			if ( $count < 1 ) {
				continue;
			}
			$sparkline = possee_sparkline_svg( $data['sparklines'][ $item['key'] ] ?? [] );
		?>
		<li class="post-counts-item post-counts-<?php echo esc_attr( $item['key'] ); ?>">
			<a href="<?php echo esc_url( $item['url'] ); ?>">
				<span class="post-counts-number"><?php echo number_format( $count ); ?></span>
				<span class="post-counts-label"><?php echo esc_html( $item['label'] ); ?></span>
				<?php echo $sparkline; ?>
			</a>
		</li>
		<?php endforeach; ?>
	</ul>
</div>
