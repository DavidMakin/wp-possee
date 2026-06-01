<?php

$options = [
	blocksy_rand_md5() => [
		'title'   => __( 'General', 'blocksy' ),
		'type'    => 'tab',
		'options' => [

			'visibility' => [
				'label'   => __( 'Element Visibility', 'blocksy' ),
				'type'    => 'ct-visibility',
				'design'  => 'block',
				'setting' => [ 'transport' => 'postMessage' ],
				'value'   => blocksy_default_responsive_value( [
					'tablet' => true,
					'mobile' => false,
				] ),
				'choices' => blocksy_ordered_keys( [
					'tablet' => __( 'Tablet', 'blocksy' ),
					'mobile' => __( 'Mobile', 'blocksy' ),
				] ),
			],

		],
	],
];
