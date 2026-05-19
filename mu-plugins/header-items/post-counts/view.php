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
		'key'   => 'articles',
		'label' => 'Articles',
		'url'   => home_url( '/articles/' ),
	],
	[
		'key'   => 'books',
		'label' => 'Books',
		'url'   => home_url( '/books/' ),
	],
	[
		'key'   => 'notes',
		'label' => 'Notes',
		'url'   => home_url( '/notes/' ),
	],
	[
		'key'   => 'checkins',
		'label' => 'Checkins',
		'url'   => home_url( '/checkins/' ),
	],
];

$transient_key = 'possee_header_post_counts';
$counts        = get_transient( $transient_key );

if ( false === $counts ) {
	$counts['articles'] = (int) wp_count_posts( 'post' )->publish;
	$counts['books']    = (int) wp_count_posts( 'book' )->publish;
	$counts['notes']    = (int) wp_count_posts( 'note' )->publish;
	$counts['checkins'] = (int) wp_count_posts( 'checkin' )->publish;

	set_transient( $transient_key, $counts, 12 * HOUR_IN_SECONDS );
}

?>
<div class="<?php echo esc_attr( $class ); ?>" <?php echo blocksy_attr_to_html( $attr ); ?>>
	<ul class="post-counts-list">
		<?php foreach ( $items as $item ) :
			$count = $counts[ $item['key'] ] ?? 0;
			if ( $count < 1 ) continue;
		?>
		<li class="post-counts-item post-counts-<?php echo esc_attr( $item['key'] ); ?>">
			<a href="<?php echo esc_url( $item['url'] ); ?>">
				<span class="post-counts-number"><?php echo number_format( $count ); ?></span>
				<span class="post-counts-label"><?php echo esc_html( $item['label'] ); ?></span>
			</a>
		</li>
		<?php endforeach; ?>
	</ul>
</div>
