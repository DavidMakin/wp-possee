<?php

/**
 * Custom post types: book, checkin, note.
 *
 * URL structure:
 *   /books/<slug>/
 *   /checkins/<yyyy-mm-dd>/<slug>/
 *   /checkins/<yyyy-mm-dd>/          (date archive)
 *   /notes/<yyyy-mm-dd>/<hh-mm>/
 */

add_action('init', 'possee_register_post_types');
function possee_register_post_types()
{
    register_post_type('book', array(
        'labels'       => array(
            'name'          => 'Books',
            'singular_name' => 'Book',
        ),
        'public'       => true,
        'has_archive'  => 'books',
        'rewrite'      => array( 'slug' => 'books', 'with_front' => false ),
        'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'comments' ),
        'show_in_rest' => true,
        'menu_icon'    => 'dashicons-book-alt',
    ));

    register_post_type('checkin', array(
        'labels'       => array(
            'name'          => 'Checkins',
            'singular_name' => 'Checkin',
        ),
        'public'       => true,
        'has_archive'  => 'checkins',
        'rewrite'      => array( 'slug' => 'checkins', 'with_front' => false ),
        'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'comments' ),
        'show_in_rest' => true,
        'menu_icon'    => 'dashicons-location',
    ));

    register_post_type('note', array(
        'labels'       => array(
            'name'          => 'Notes',
            'singular_name' => 'Note',
        ),
        'public'       => true,
        'has_archive'  => 'notes',
        'rewrite'      => array( 'slug' => 'notes', 'with_front' => false ),
        'supports'     => array( 'editor', 'custom-fields', 'comments' ),
        'show_in_rest' => true,
        'menu_icon'    => 'dashicons-edit',
    ));
}

add_filter('post_type_link', 'possee_post_type_link', 10, 2);
function possee_post_type_link($url, $post)
{
    if ('book' === $post->post_type) {
        return home_url('/books/' . $post->post_name . '/');
    }
    if ('checkin' === $post->post_type) {
        $date = get_post_time('Y-m-d', false, $post);
        return home_url('/checkins/' . $date . '/' . $post->post_name . '/');
    }
    if ('note' === $post->post_type) {
        return home_url('/notes/' . $post->post_name . '/');
    }
    return $url;
}

add_action('init', 'possee_add_rewrite_rules');
function possee_add_rewrite_rules()
{
    // /book/<slug>/
    add_rewrite_rule(
        '^books/([^/]+)/?$',
        'index.php?post_type=book&name=$matches[1]',
        'top'
    );

    // /checkin/<yyyy-mm-dd>/<slug>/
    add_rewrite_rule(
        '^checkins/(\d{4}-\d{2}-\d{2})/([^/]+)/?$',
        'index.php?post_type=checkin&name=$matches[2]',
        'top'
    );

    add_rewrite_rule(
        '^checkins/(\d{4}-\d{2}-\d{2})/?$',
        'index.php?post_type=checkin&checkin_date=$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^checkins/?$',
        'index.php?post_type=checkin',
        'top'
    );

    add_rewrite_rule(
        '^notes/([^/]+)/?$',
        'index.php?post_type=note&name=$matches[1]',
        'top'
    );

    add_rewrite_rule(
        '^notes/?$',
        'index.php?post_type=note',
        'top'
    );
}

add_filter('query_vars', 'possee_query_vars');
function possee_query_vars($vars)
{
    $vars[] = 'checkin_date';
    return $vars;
}

add_filter('rest_post_search_query', 'possee_cpts_in_rest_search', 10, 2);
function possee_cpts_in_rest_search($args, $request)
{
    if (isset($_GET['ct_live_search']) && sanitize_key($_GET['ct_live_search']) === 'true') {
        $args['post_type'] = array( 'post', 'book', 'checkin', 'note' );
    }
    return $args;
}

add_action('rest_api_init', 'possee_register_search_placeholder_image');
function possee_register_search_placeholder_image()
{
    // SVG icons per CPT, returned as data URIs for Blocksy live search thumbnail slot.
    $icons = array(
        'book'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%23555" stroke-width="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
        'checkin' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%23555" stroke-width="1.5"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>',
        'note'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%23555" stroke-width="1.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
    );

    register_rest_field('search-result', 'placeholder_image', array(
        'get_callback' => function ($post) use ($icons) {
            $subtype = $post['subtype'] ?? '';
            if (! isset($icons[ $subtype ])) {
                return null;
            }
            if (get_post_thumbnail_id($post['id'])) {
                return null;
            }
            return 'data:image/svg+xml,' . $icons[ $subtype ];
        },
        'schema' => array( 'type' => 'string' ),
    ));
}

add_action('pre_get_posts', 'possee_cpts_on_homepage');
function possee_cpts_on_homepage($query)
{
    if (is_admin() || ! $query->is_main_query()) {
        return;
    }
    if ($query->is_home()) {
        $query->set('post_type', array( 'post', 'book', 'note' ));
    } elseif ($query->is_feed() || $query->is_search()) {
        $query->set('post_type', array( 'post', 'book', 'checkin', 'note' ));
    }
}

add_action('pre_get_posts', 'possee_date_archive_query');
function possee_date_archive_query($query)
{
    if (is_admin() || ! $query->is_main_query()) {
        return;
    }

    $checkin_date = $query->get('checkin_date');
    if ($checkin_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin_date)) {
        list( $y, $m, $d ) = explode('-', $checkin_date);
        $query->set('post_type', 'checkin');
        $query->set('year', (int) $y);
        $query->set('monthnum', (int) $m);
        $query->set('day', (int) $d);
        $query->is_archive = true;
        $query->is_post_type_archive = true;
        return;
    }
}

add_filter('micropub_post_type', 'possee_micropub_post_type', 10, 2);
function possee_micropub_post_type($post_type, $input)
{
    $props    = $input['properties'] ?? [];
    $cats     = $props['category'] ?? [];
    $checkin  = $props['checkin'] ?? ( $input['checkin'] ?? null );
    $read_of  = $props['read-of'] ?? null;

    if ($read_of || in_array('book', $cats, true)) {
        return 'book';
    }
    if ($checkin || in_array('checkin', $cats, true)) {
        return 'checkin';
    }
    return 'note';
}

add_filter('pre_insert_micropub_post', 'possee_micropub_slug', 5);
function possee_micropub_slug($args)
{
    $meta     = $args['meta_input'] ?? [];
    $tags     = $args['tags_input'] ?? [];
    $type     = $args['post_type'] ?? 'post';
    $pub_date = $args['post_date'] ?? current_time('mysql');

    if ('book' === $type) {
        $ro = $meta['mf2_read-of'] ?? null;
        if ($ro) {
            $ro = is_string($ro) ? maybe_unserialize($ro) : $ro;
        }
        $title = $ro[0]['properties']['name'][0] ?? ( $args['post_title'] ?? '' );
        if ($title) {
            $args['post_name'] = sanitize_title($title);
        }

        $finished_raw = $meta['mf2_finished-at'] ?? null;
        if ($finished_raw) {
            $finished_arr = is_string($finished_raw) ? maybe_unserialize($finished_raw) : $finished_raw;
            $finished_val = is_array($finished_arr) ? ( $finished_arr[0] ?? null ) : $finished_raw;
            if ($finished_val) {
                $ts = strtotime($finished_val);
                if ($ts) {
                    $args['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
                    $args['post_date']     = get_date_from_gmt($args['post_date_gmt']);
                }
            }
        }

        return $args;
    }

    if ('checkin' === $type) {
        $checkin = $meta['mf2_checkin'] ?? null;
        $venue   = '';
        if (is_array($checkin) && ! empty($checkin['properties']['name'][0])) {
            $venue = $checkin['properties']['name'][0];
        }
        $date = substr($pub_date, 0, 10);
        $slug = $date . ( $venue ? '-' . sanitize_title($venue) : '' );
        $args['post_name'] = $slug;
        return $args;
    }

    if ('note' === $type) {
        $date = substr($pub_date, 0, 10);
        $time = substr($pub_date, 11, 5);
        $args['post_name'] = $date . '-' . str_replace(':', '-', $time);
        return $args;
    }

    return $args;
}

add_action('init', 'possee_enable_cpt_plugin_support');
function possee_enable_cpt_plugin_support()
{
    foreach (array( 'book', 'checkin', 'note' ) as $type) {
        add_post_type_support($type, 'webmentions');
        register_taxonomy_for_object_type('post_tag', $type);
    }
}

add_filter('syndication_post_types', 'possee_syndication_post_types');
function possee_syndication_post_types($types)
{
    return array_unique(array_merge($types, array( 'book', 'checkin', 'note' )));
}

add_filter('webmention_support_post_types', 'possee_webmention_post_types');
function possee_webmention_post_types($types)
{
    return array_unique(array_merge($types, array( 'book', 'checkin', 'note' )));
}

/**
 * On the homepage (mixed-CPT stream), prevent Blocksy from detecting the
 * current post's type as the archive prefix. Without this, Blocksy sets
 * data-prefix="checkin_archive" (or similar) which has no grid column CSS,
 * causing the homepage to render as a single-column list instead of a grid.
 * Returning null here lets Blocksy fall back to data-prefix="blog", which is
 * configured in the Customizer and has the correct grid column settings.
 */
add_filter('blocksy:custom_post_types:current_post_type:compute', 'possee_homepage_blocksy_prefix');
function possee_homepage_blocksy_prefix($post_type)
{
    if (is_home()) {
        return null;
    }
    return $post_type;
}

/**
 * Notes have no title, so Blocksy skips the read_more button on note archives.
 * Inject the standard Blocksy button into the read_more slot.
 * Only fires on note post-type archives (not homepage stream).
 */
add_filter('blocksy:archive:render-card-layers', 'possee_note_read_more', 11, 3);
function possee_note_read_more($outputs, $prefix, $featured_image_args)
{
    if (get_post_type() !== 'note' || is_feed()) {
        return $outputs;
    }
    if (! empty($outputs['read_more'])) {
        return $outputs;
    }
    $outputs['read_more'] = sprintf(
        '<a class="entry-button wp-element-button ct-button" href="%s">Read More<span class="screen-reader-text"> %s</span></a>',
        esc_url(get_permalink()),
        esc_html(get_the_date('j M Y'))
    );
    return $outputs;
}

add_filter('blocksy:archive:render-card-layers', 'possee_note_type_badge', 8, 3);
function possee_note_type_badge($outputs, $prefix, $featured_image_args)
{
    if (get_post_type() !== 'note' || is_feed()) {
        return $outputs;
    }
    $badge = '<span class="post-type-badge post-type-badge--note"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="1" width="10" height="14" rx="1.5"/><line x1="5.5" y1="5" x2="10.5" y2="5"/><line x1="5.5" y1="8" x2="10.5" y2="8"/><line x1="5.5" y1="11" x2="8.5" y2="11"/></svg>Note</span>';
    $outputs['excerpt'] = $badge . ( $outputs['excerpt'] ?? '' );
    return $outputs;
}

/**
 * Notes must never have a title — empty string in the DB.
 * Catches all paths: Micropub, REST API, auto-drafts.
 */
add_filter('wp_insert_post_data', 'possee_note_blank_title', 10, 2);
function possee_note_blank_title($data, $postarr)
{
    if ('note' === ( $data['post_type'] ?? '' )) {
        $data['post_title'] = '';
    }
    return $data;
}
