<?php

defined('ABSPATH') || exit;

add_action('blocksy:loop:before', 'possee_homepage_highlights');
function possee_homepage_highlights()
{
    if (! is_home()) {
        return;
    }

    $posts = possee_highlights_fetch();
    if (empty($posts)) {
        return;
    }

    global $post;
    $original_post = $post;

    echo '<div class="possee-highlights">';
    echo '<div class="possee-highlights__grid entries" style="--grid-template-columns: repeat(4, 1fr)" data-archive="default" data-layout="grid" data-cards="boxed" data-hover="zoom-in">';
    echo '<div class="possee-highlights__label entry-card"><span>Recent activity</span></div>';

    add_filter('theme_mod_blog_has_posts_reveal', '__return_empty_string');
    add_filter('blocksy:archive:render-card-layer', 'possee_highlights_slim_card', 10, 2);

    foreach ($posts as $highlight_post) {
        $post = $highlight_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        setup_postdata($post);
        blocksy_render_archive_card();
    }

    remove_filter('blocksy:archive:render-card-layer', 'possee_highlights_slim_card', 10);
    remove_filter('theme_mod_blog_has_posts_reveal', '__return_empty_string');

    wp_reset_postdata();
    $post = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

    echo '</div>';
    echo '</div>';
}

function possee_highlights_slim_card($output, $component)
{
    if ($component['id'] === 'post_meta') {
        return '';
    }
    return $output;
}

function possee_highlights_fetch()
{
    $cached = get_transient('possee_homepage_highlights');
    if (false !== $cached) {
        return $cached;
    }

    $posts = array();

    $checkins = get_posts(array(
        'post_type'      => 'checkin',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ));
    if ($checkins) {
        $posts[] = $checkins[0];
    }

    $reading = get_posts(array(
        'post_type'      => 'book',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
        'meta_query'     => array(
            array(
                'key'   => 'mf2_read-status',
                'value' => 'reading',
            ),
        ),
    ));
    if (! $reading) {
        $reading = get_posts(array(
            'post_type'      => 'book',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ));
    }
    if ($reading) {
        $posts[] = $reading[0];
    }

    $notes = get_posts(array(
        'post_type'      => 'note',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ));
    if ($notes) {
        $posts[] = $notes[0];
    }

    set_transient('possee_homepage_highlights', $posts, 5 * MINUTE_IN_SECONDS);
    return $posts;
}

add_action('save_post', 'possee_invalidate_highlights_cache', 10, 2);
function possee_invalidate_highlights_cache($post_id, $post)
{
    if (in_array($post->post_type, array( 'book', 'checkin', 'note' ), true)) {
        delete_transient('possee_homepage_highlights');
    }
}
