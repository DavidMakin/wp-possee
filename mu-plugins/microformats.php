<?php

defined('SYNDICATION_LINKS_BRIDGY_WEBMENTION') || define('SYNDICATION_LINKS_BRIDGY_WEBMENTION', 1);

/**
 * Delay Bridgy publish webmentions sent during Micropub post creation.
 *
 * When a post is created via Micropub (Quill etc.), Syndication Links fires
 * the Bridgy webmention immediately — before nginx/Cloudflare/WP-Optimize
 * caches have the page ready. Bridgy fetches a stale page, can't find the
 * u-syndication link, and caches the failure. Subsequent retries return the
 * same error without re-fetching (Bridgy deduplicates on source+target).
 *
 * Fix: hook micropub_syndication at priorities 1 and 99 to bracket Syndication
 * Links (priority 10). At priority 1, install a pre_http_request filter that
 * intercepts outbound requests to brid.gy/publish/* and returns a fake
 * response, blocking the actual send. At priority 99, remove the filter, clean
 * up any error log entries written by Syndication Links, and schedule the real
 * send 90 seconds later via WP-Cron — by which time caches are warm.
 */

/** UIDs of Bridgy providers we want to delay. */
define('POSSEE_BRIDGY_UIDS', array( 'webmention-bluesky-bridgy', 'webmention-mastodon-bridgy' ));

/** Whether we are currently inside a micropub_syndication call. */
$GLOBALS['possee_in_micropub_syndication'] = false;

add_action('micropub_syndication', 'possee_micropub_syndication_start', 1, 2);
function possee_micropub_syndication_start($post_id, $syndicate_to)
{
    $pending = array_intersect($syndicate_to, POSSEE_BRIDGY_UIDS);
    if (empty($pending)) {
        return;
    }
    $GLOBALS['possee_in_micropub_syndication'] = array(
        'post_id'     => $post_id,
        'pending'     => array_values($pending),
        'intercepted' => array(),
    );
    add_filter('pre_http_request', 'possee_intercept_bridgy_request', 1, 3);
}

add_action('micropub_syndication', 'possee_micropub_syndication_end', 99, 2);
function possee_micropub_syndication_end($post_id, $syndicate_to)
{
    if (! $GLOBALS['possee_in_micropub_syndication']) {
        return;
    }
    remove_filter('pre_http_request', 'possee_intercept_bridgy_request', 1);

    $ctx         = $GLOBALS['possee_in_micropub_syndication'];
    $intercepted = $ctx['intercepted'];
    $GLOBALS['possee_in_micropub_syndication'] = false;

    if (empty($intercepted)) {
        return;
    }

    // Syndication Links wrote a WP_Error log entry because our fake interception
    // caused endpoint discovery to return null (no webmention link found in response).
    // Remove those entries — the real send will write its own when cron fires.
    $log     = get_post_meta($post_id, 'syndication_log', true) ?: array();
    $new_log = array_filter($log, function ($entry) use ($intercepted) {
        return ! in_array($entry['uid'] ?? '', $intercepted, true);
    });
    update_post_meta($post_id, 'syndication_log', array_values($new_log));

    // Schedule the real send 90 seconds later.
    wp_schedule_single_event(time() + 90, 'possee_bridgy_delayed', array( $post_id, $intercepted ));
}

/**
 * Intercept outbound HTTP requests to brid.gy/publish/* and return a fake 202.
 * Records which Bridgy UID was intercepted so we can schedule it for real later.
 */
function possee_intercept_bridgy_request($preempt, $args, $url)
{
    if (strpos($url, 'brid.gy/publish') === false) {
        return $preempt;
    }
    // Identify which provider this is for.
    $uid = null;
    if (strpos($url, '/bluesky') !== false) {
        $uid = 'webmention-bluesky-bridgy';
    } elseif (strpos($url, '/mastodon') !== false) {
        $uid = 'webmention-mastodon-bridgy';
    }
    if ($uid && is_array($GLOBALS['possee_in_micropub_syndication'])) {
        $GLOBALS['possee_in_micropub_syndication']['intercepted'][] = $uid;
    }
    // Return a fake 202 Accepted so Syndication Links doesn't log an error.
    return array(
        'headers'  => array( 'content-type' => 'application/json' ),
        'body'     => '{"status":"deferred"}',
        'response' => array( 'code' => 202, 'message' => 'Accepted' ),
        'cookies'  => array(),
        'filename' => null,
    );
}

add_action('possee_bridgy_delayed', 'possee_bridgy_delayed_handler', 10, 2);
function possee_bridgy_delayed_handler($post_id, $syndicate_to)
{
    do_action('syn_syndication', $post_id, $syndicate_to);

    // After syndication, clear WP-Optimize page cache for this post so that
    // when Bridgy crawls it (see below) it gets a fresh page with the new
    // u-syndication link rather than a stale cached copy.
    // (Nginx already bypasses its fastcgi cache for Bridgy's user-agent.)
    possee_clear_post_page_cache($post_id);

    // Ping Bridgy's /discover endpoint so it re-crawls the post and learns the
    // mapping between the Bluesky AT URI and the blog post URL. Without this,
    // Bridgy may have crawled the page before the 90-second Bluesky cron fired
    // and stored the post without a Bluesky syndication link — causing every
    // subsequent like/reply to report "No webmention targets".
    //
    // Source key is the Bridgy datastore key for the Bluesky account. Store it
    // via: wp option set possee_bridgy_bluesky_source_key '<value>'
    // Retrieve the current value from: https://brid.gy/bluesky/<handle> → page source.
    possee_ping_bridgy_discover($post_id);
}

/**
 * Clear WP-Optimize disk page cache for a single post URL.
 */
function possee_clear_post_page_cache($post_id)
{
    $url = get_permalink($post_id);
    if (! $url) {
        return;
    }
    // WP-Optimize 3.x
    if (class_exists('WPO_Page_Cache') && method_exists('WPO_Page_Cache', 'delete_single_page_cache')) {
        WPO_Page_Cache::delete_single_page_cache($url);
        return;
    }
    // WP-Optimize via main class
    if (function_exists('WP_Optimize')) {
        $cache = WP_Optimize()->get_page_cache();
        if ($cache && method_exists($cache, 'delete_single_page_cache')) {
            $cache->delete_single_page_cache($url);
        }
    }
}

/**
 * POST to Bridgy's /discover endpoint, asking it to re-crawl the blog post
 * and update its syndication link → blog post URL mapping.
 */
function possee_ping_bridgy_discover($post_id)
{
    $source_key = get_option('possee_bridgy_bluesky_source_key', '');
    if (! $source_key) {
        return;
    }
    $url = get_permalink($post_id);
    if (! $url) {
        return;
    }
    wp_remote_post(
        'https://brid.gy/discover',
        array(
            'body'    => array(
                'url'        => $url,
                'source_key' => $source_key,
            ),
            'timeout' => 15,
        )
    );
}

add_filter('pre_insert_micropub_post', 'possee_log_micropub_payload', 1);
function possee_log_micropub_payload($params)
{
    $log_file = WP_CONTENT_DIR . '/micropub-log.json';
    file_put_contents($log_file, json_encode($params) . "\n", FILE_APPEND);
    return $params;
}

/**
 * Extract Gutenberg image blocks into mf2_photo metadata for Micropub posts.
 *
 * When the Micropub plugin creates a post with Gutenberg image blocks in the
 * post_content, extract them into mf2_photo array format so they can be:
 * 1. Rendered via possee_render_micropub_photos
 * 2. Syndicated to social networks (Bluesky, Mastodon)
 *
 * Hook at priority 2 (after logging at 1) to process before post insertion.
 */
add_filter('pre_insert_micropub_post', 'possee_extract_micropub_gutenberg_photos', 2);
function possee_extract_micropub_gutenberg_photos($args)
{
    if (empty($args['post_content'])) {
        return $args;
    }

    // Parse Gutenberg <!-- wp:image --> blocks for image IDs
    $photos = array();
    if (preg_match_all('/<!-- wp:image \{([^}]*?"id":(\d+),[^}]*)\} -->/', $args['post_content'], $matches)) {
        foreach ($matches[2] as $attachment_id) {
            $src = wp_get_attachment_image_src($attachment_id, 'full');
            if ($src && ! empty($src[0])) {
                $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                $photos[] = array(
                    'value' => $src[0],
                    'alt'   => $alt ? $alt : '',
                );
            }
        }
    }

    // Store extracted photos in meta_input for the post_insert
    if (! empty($photos)) {
        if (! isset($args['meta_input'])) {
            $args['meta_input'] = array();
        }
        // Only set if not already in the Micropub payload
        if (empty($args['meta_input']['mf2_photo'])) {
            $args['meta_input']['mf2_photo'] = $photos;
        }
    }

    return $args;
}

add_action('wp_head', 'possee_wp_head_microformats');
function possee_wp_head_microformats()
{
    echo '<link rel="me" href="https://hachyderm.io/@_sleeper" />' . "\n";
    echo '<link rel="me" href="https://bsky.app/profile/sleep-er.bsky.social" />' . "\n";
    echo '<meta name="theme-color" content="#263959" />' . "\n";

    $site_name = esc_attr(get_bloginfo('name'));
    $locale    = esc_attr(str_replace('-', '_', get_locale()));

    if (is_singular('post')) {
        $post        = get_queried_object();
        $title       = esc_attr(strip_tags(get_the_title($post)));
        $url         = esc_url(get_permalink($post));
        $description = esc_attr(strip_tags(has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(strip_tags($post->post_content), 30)));

        $image      = '';
        $img_width  = 0;
        $img_height = 0;
        $img_alt    = $title;

        if ('book' === $post->post_type) {
            $book_data = possee_book_get_data($post->ID);
            if ($book_data) {
                if ($book_data['isbn']) {
                    $image = possee_book_cover_url($book_data['isbn'], 'L');
                    $img_width  = 180;
                    $img_height = 270;
                    $img_alt    = esc_attr($book_data['title'] . ' cover');
                } elseif (! empty($book_data['hc_cover'])) {
                    $image   = $book_data['hc_cover'];
                    $img_alt = esc_attr($book_data['title'] . ' cover');
                }
            }
        }
        if (! $image && has_post_thumbnail($post)) {
            $thumb_id = get_post_thumbnail_id($post);
            $src      = wp_get_attachment_image_src($thumb_id, 'full');
            if ($src) {
                $image      = esc_url($src[0]);
                $img_width  = (int) $src[1];
                $img_height = (int) $src[2];
                $alt_text   = get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
                if ($alt_text) {
                    $img_alt = esc_attr($alt_text);
                }
            }
        }
        if (! $image) {
            $logo_id = get_theme_mod('custom_logo');
            if ($logo_id) {
                $src = wp_get_attachment_image_src($logo_id, 'full');
                if ($src) {
                    $image      = esc_url($src[0]);
                    $img_width  = (int) $src[1];
                    $img_height = (int) $src[2];
                }
            }
        }

        $published = esc_attr(get_the_date('c', $post));
        $modified  = esc_attr(get_the_modified_date('c', $post));
        $author    = esc_attr(get_the_author_meta('display_name', $post->post_author));

        echo '<meta name="description" content="' . $description . '" />' . "\n";
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:site_name" content="' . $site_name . '" />' . "\n";
        echo '<meta property="og:locale" content="' . $locale . '" />' . "\n";
        echo '<meta property="og:title" content="' . $title . '" />' . "\n";
        echo '<meta property="og:url" content="' . $url . '" />' . "\n";
        echo '<meta property="og:description" content="' . $description . '" />' . "\n";
        echo '<meta property="article:published_time" content="' . $published . '" />' . "\n";
        echo '<meta property="article:modified_time" content="' . $modified . '" />' . "\n";
        if ($image) {
            echo '<meta property="og:image" content="' . $image . '" />' . "\n";
            if ($img_width && $img_height) {
                echo '<meta property="og:image:width" content="' . $img_width . '" />' . "\n";
                echo '<meta property="og:image:height" content="' . $img_height . '" />' . "\n";
            }
            echo '<meta property="og:image:alt" content="' . $img_alt . '" />' . "\n";
        }
        $card_type = $image && $img_width >= 600 ? 'summary_large_image' : 'summary';
        echo '<meta name="twitter:card" content="' . $card_type . '" />' . "\n";
        echo '<meta name="twitter:site" content="@_sleeper" />' . "\n";
        echo '<meta name="twitter:title" content="' . $title . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . $description . '" />' . "\n";
        if ($image) {
            echo '<meta name="twitter:image" content="' . $image . '" />' . "\n";
            echo '<meta name="twitter:image:alt" content="' . $img_alt . '" />' . "\n";
        }

        $ld = array(
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => strip_tags(get_the_title($post)),
            'url'              => get_permalink($post),
            'datePublished'    => get_the_date('c', $post),
            'dateModified'     => get_the_modified_date('c', $post),
            'author'           => array(
                '@type' => 'Person',
                'name'  => get_the_author_meta('display_name', $post->post_author),
                'url'   => get_author_posts_url($post->post_author),
            ),
            'publisher'        => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo('name'),
                'url'   => home_url('/'),
            ),
            'description'      => strip_tags(has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(strip_tags($post->post_content), 30)),
        );
        if ($image) {
            $ld['image'] = $image;
        }
        echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    } elseif (is_front_page() || is_home()) {
        $description = esc_attr(get_bloginfo('description'));
        echo '<meta name="description" content="' . $description . '" />' . "\n";
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:site_name" content="' . $site_name . '" />' . "\n";
        echo '<meta property="og:locale" content="' . $locale . '" />' . "\n";
        echo '<meta property="og:title" content="' . $site_name . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url(home_url('/')) . '" />' . "\n";
        echo '<meta property="og:description" content="' . $description . '" />' . "\n";
        echo '<meta name="twitter:card" content="summary" />' . "\n";
        echo '<meta name="twitter:site" content="@_sleeper" />' . "\n";
    } elseif (is_archive()) {
        $title       = esc_attr(strip_tags(get_the_archive_title()));
        $description = esc_attr(strip_tags(get_the_archive_description()));
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:site_name" content="' . $site_name . '" />' . "\n";
        echo '<meta property="og:locale" content="' . $locale . '" />' . "\n";
        echo '<meta property="og:title" content="' . $title . '" />' . "\n";
        if ($description) {
            echo '<meta name="description" content="' . $description . '" />' . "\n";
            echo '<meta property="og:description" content="' . $description . '" />' . "\n";
        }
    }
}

add_action('plugins_loaded', function () {
    if (! class_exists('SynProvider_Webmention_Bridgy')) {
        return;
    }

    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR1.Methods.CamelCapsMethodName, Squiz.Classes.ValidClassName.NotCamelCaps
    // Class extends Syndication Links plugin — naming dictated by WP plugin API, not PSR-12.
    class SynProvider_Webmention_Bridgy_Bluesky extends SynProvider_Webmention_Bridgy
    {
        public function __construct($args = array())
        {
            $this->name = 'Bluesky via Bridgy';
            $this->uid  = 'webmention-bluesky-bridgy';

            $option = get_option('syndication_provider_enable');
            $enable = is_array($option) ? in_array($this->uid, $option) : false;

            if ($enable) {
                add_action('wp_footer', array( $this, 'wp_footer' ));
            }

            parent::__construct($args);
        }

        public function wp_footer()
        {
            // Always output p-bridgy-bluesky-content so Bridgy uses this text
            // rather than parsing e-content (which may contain metadata or footers).
            // Only on singular posts — no need on archives/feeds.
            if (! is_singular()) {
                return;
            }
            // Notes have dedicated p-bridgy-*-content from possee_note_bridgy_content()
            // that includes the permalink. Skip here to avoid duplicate output.
            if (is_singular('note')) {
                return;
            }
            if (( 1 === (int) get_option('syndication_use_excerpt') ) && has_excerpt()) {
                $text = get_the_excerpt();
            } else {
                $text = wp_strip_all_tags(get_the_content());
                $text = trim($text);
            }
            if ($text) {
				printf( '<p class="p-bridgy-bluesky-content" style="display:none">%s</p>', esc_html( $text ) ); // phpcs:ignore
            }
        }

        public function get_target()
        {
            return 'https://brid.gy/publish/bluesky';
        }
    }
    // phpcs:enable

    if (function_exists('register_syndication_provider')) {
        register_syndication_provider(new SynProvider_Webmention_Bridgy_Bluesky());
    }
}, 20);

/**
 * Extract Gutenberg image blocks into mf2_photo post meta.
 *
 * When posts are edited in wp-admin and Gutenberg image blocks are added/modified,
 * extract them into mf2_photo meta so they can be:
 * 1. Rendered via possee_render_micropub_photos
 * 2. Re-syndicated to social networks (if block extraction didn't already happen)
 *
 * Hook at priority 10, before syndication (20), so photos are available for re-syndication.
 */
add_action('save_post', 'possee_extract_gutenberg_photos', 10, 2);
function possee_extract_gutenberg_photos($post_id, $post)
{
    // Only process published posts with content
    if (! in_array($post->post_status, array( 'publish', 'future' ), true)) {
        return;
    }
    if (empty($post->post_content)) {
        return;
    }

    // Check if post already has mf2_photo meta from Micropub payload
    $existing_photos = get_post_meta($post_id, 'mf2_photo', true);
    if (! empty($existing_photos) && is_array($existing_photos)) {
        return; // Already has photos from Micropub, don't override
    }

    // Parse Gutenberg <!-- wp:image --> blocks for image IDs
    $photos = array();
    if (preg_match_all('/<!-- wp:image \{([^}]*?"id":(\d+),[^}]*)\} -->/', $post->post_content, $matches)) {
        foreach ($matches[2] as $attachment_id) {
            $src = wp_get_attachment_image_src($attachment_id, 'full');
            if ($src && ! empty($src[0])) {
                $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                $photos[] = array(
                    'value' => $src[0],
                    'alt'   => $alt ? $alt : '',
                );
            }
        }
    }

    // Store extracted photos in mf2_photo meta
    if (! empty($photos)) {
        update_post_meta($post_id, 'mf2_photo', $photos);
    }
}

/**
 * Trigger syndication when custom post types (note, checkin, book) are saved
 * from wp-admin with syndication checkboxes checked.
 *
 * The Syndication Links plugin depends on WordPress's do_pings action, which
 * only fires for the native 'post' type via _publish_post_hook.  Custom post
 * types are never processed, so _syndicate-to meta accumulates unhandled.
 *
 * Hook at priority 20 to run after Syndication Links' own save_post (10) has
 * stored the _syndicate-to meta, then fire syn_syndication immediately.
 */
add_action('save_post', 'possee_syndicate_save_post', 20, 2);
function possee_syndicate_save_post($post_id, $post)
{
    if (! in_array($post->post_type, array( 'note', 'book', 'checkin' ), true)) {
        return;
    }
    if (! in_array($post->post_status, array( 'publish', 'future' ), true)) {
        return;
    }
    $syndicate_to = get_post_meta($post_id, '_syndicate-to', true);
    if (empty($syndicate_to) || ! is_array($syndicate_to)) {
        return;
    }
    // Don't fire if this post is already being processed via micropub_syndication
    // (the Micropub creation path fires before save_post so it would have cleared
    // or already processed the meta by now).
    if (is_array($GLOBALS['possee_in_micropub_syndication'] ?? false)) {
        return;
    }
    do_action('syn_syndication', $post_id, $syndicate_to);
}

/**
 * Sync the Syndication Links per-post checkbox state from _syndicate-to meta.
 *
 * The base SynProvider::is_checked() returns false unconditionally, so
 * checkboxes in the editor sidebar appear unchecked even when the post
 * has _syndicate-to meta set. This filter reads the meta and returns
 * the correct state for our Bridgy providers.
 */
add_filter('syndication_link_checked', 'possee_syndication_link_checked', 10, 3);
function possee_syndication_link_checked($checked, $uid, $post_id)
{
    if (! in_array($uid, POSSEE_BRIDGY_UIDS, true)) {
        return $checked;
    }
    if (! $post_id) {
        return $checked;
    }
    $syndicate_to = get_post_meta($post_id, '_syndicate-to', true);
    if (! is_array($syndicate_to)) {
        return $checked;
    }
    return in_array($uid, $syndicate_to, true);
}

/**
 * Notes have no title and syndicated copies lack a link back.
 * Give Bridgy explicit content with the permalink so Mastodon
 * and Bluesky posts include a "blog.sleep-er.co.uk" link.
 */
add_action('wp_footer', 'possee_note_bridgy_content', 0);
function possee_note_bridgy_content()
{
    if (! is_singular('note')) {
        return;
    }

    global $wp_filter;
    if (! empty($wp_filter['wp_footer']) && isset($wp_filter['wp_footer']->callbacks)) {
        foreach ($wp_filter['wp_footer']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'] ?? null;
                if (! is_array($function) || ! is_object($function[0] ?? null)) {
                    continue;
                }

                if (
                    'wp_footer' !== ($function[1] ?? null)
                    || ! ($function[0] instanceof SynProvider_Webmention_Bridgy)
                ) {
                    continue;
                }

                remove_action('wp_footer', $function, $priority);
            }
        }
    }

    $post        = get_queried_object();
    $permalink   = esc_url(get_permalink($post));
    $content_raw = get_post_field('post_content', $post);
    $content     = esc_html(wp_trim_words(strip_tags($content_raw), 55));

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

add_filter('blocksy:options:meta:meta_default_elements', 'possee_register_meta_defaults');
function possee_register_meta_defaults($elements)
{
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

add_filter('blocksy:options:meta:meta_elements', 'possee_register_meta_elements');
function possee_register_meta_elements($elements)
{
    $elements['syndication_links'] = array(
        'label'   => __('Syndication Links', 'blocksy'),
        'options' => array(),
    );
    $elements['reading_time'] = array(
        'label'   => __('Reading Time', 'blocksy'),
        'options' => array(),
    );
    return $elements;
}

add_action('blocksy:post-meta:render-meta', 'possee_render_meta');
function possee_render_meta($id)
{
    if ('syndication_links' === $id) {
        if (! function_exists('get_syndication_links')) {
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
        if (! $links) {
            return;
        }
        echo '<li class="meta-syndication-links">' . $links . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    if ('reading_time' === $id) {
        $words   = str_word_count(wp_strip_all_tags(get_post_field('post_content', get_the_ID())));
        $minutes = max(1, (int) ceil($words / 200));
        echo '<li class="meta-reading-time">' . esc_html($minutes . ' min read') . '</li>';
    }
}

/**
 * Helper: extract checkin venue and locality data from post meta.
 * Returns array with keys: venue_name, venue_url, locality, country, lat, lng, weather_temp, weather_summary, checked_in_by.
 */
function possee_checkin_data($post_id)
{
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
    $checkin = get_post_meta($post_id, 'mf2_checkin', true);
    if (is_array($checkin) && isset($checkin['properties'])) {
        $props = $checkin['properties'];
        if (! empty($props['name'][0])) {
            $data['venue_name'] = html_entity_decode($props['name'][0], ENT_QUOTES);
        }
        if (! empty($props['url'][0])) {
            $data['venue_url'] = $props['url'][0];
        }
    }
    $data['venue_icon'] = get_post_meta($post_id, 'swarm_venue_icon', true) ?: '';

    // Locality + country from mf2_location.
    $location = get_post_meta($post_id, 'mf2_location', true);
    if (is_array($location) && isset($location['properties'])) {
        $props = $location['properties'];
        if (! empty($props['locality'][0])) {
            $data['locality'] = $props['locality'][0];
        }
        if (! empty($props['country-name'][0])) {
            $data['country'] = $props['country-name'][0];
        }
    }

    // Coordinates.
    $data['lat'] = get_post_meta($post_id, 'geo_latitude', true);
    $data['lng'] = get_post_meta($post_id, 'geo_longitude', true);

    // Weather.
    $temp = get_post_meta($post_id, 'weather_temperature', true);
    if ($temp !== '') {
        $data['weather_temp'] = round((float) $temp) . '°C';
    }
    $summary = get_post_meta($post_id, 'weather_summary', true);
    if ($summary) {
        $data['weather_summary'] = $summary;
    }

    // Checked in by (mf2_checked-in-by: array of h-cards).
    $by = get_post_meta($post_id, 'mf2_checked-in-by', true);
    if (is_array($by)) {
        foreach ($by as $hcard) {
            if (! isset($hcard['properties'])) {
                continue;
            }
            $props = $hcard['properties'];
            $name  = ! empty($props['name'][0]) ? $props['name'][0] : '';
            $url   = ! empty($props['url'][0]) ? $props['url'][0] : '';
            if ($name) {
                $data['checked_in_by'][] = array( 'name' => $name, 'url' => $url );
            }
        }
    }

    return $data;
}

add_filter('get_the_excerpt', 'possee_checkin_excerpt', 5, 2);
function possee_checkin_excerpt($excerpt, $post)
{
    if (! has_tag('checkin', $post)) {
        return $excerpt;
    }
    // On singular, suppress the excerpt so Blocksy's hero doesn't render it as a description.
    if (is_singular()) {
        return '';
    }

    $d = possee_checkin_data($post->ID);

    $venue_html = $d['venue_name']
        ? ( $d['venue_url']
            ? '<a href="' . esc_url($d['venue_url']) . '">' . esc_html($d['venue_name']) . '</a>'
            : esc_html($d['venue_name']) )
        : '';

    $parts = array_filter(array( $d['locality'], $d['country'] ));
    $place = implode(', ', $parts);

    $meta_parts = array();
    if ($venue_html) {
        $pin = $d['venue_icon']
            ? '<img class="checkin-venue-icon" src="' . esc_url($d['venue_icon']) . '" alt="" width="20" height="20" loading="lazy">'
            : '<span aria-hidden="true">📍</span>';
        $meta_parts[] = $pin . ' at ' . $venue_html;
    }
    if ($place) {
        $meta_parts[] = esc_html($place);
    }
    if ($d['weather_temp']) {
        $meta_parts[] = esc_html($d['weather_temp']);
    }

    $coins_total = get_post_meta($post->ID, 'swarm_score_total', true);
    if ($coins_total) {
        $meta_parts[] = '<img src="https://ss1.4sqi.net/img/points/coin_icon_coin.png" alt="" width="16" height="16" style="vertical-align:middle"> +' . (int) $coins_total;
    }

    if (empty($meta_parts)) {
        return wp_strip_all_tags($post->post_content);
    }

    $venue_part = array_shift($meta_parts);
    $rest       = $meta_parts ? '<span class="checkin-excerpt-meta">' . implode(' · ', $meta_parts) . '</span>' : '';

    return '<span class="checkin-excerpt"><span class="checkin-excerpt-venue">' . $venue_part . '</span>' . $rest . '</span>';
}

add_filter('blocksy:excerpt:output', 'possee_checkin_excerpt_blocksy', 10, 1);
function possee_checkin_excerpt_blocksy($excerpt)
{
    if (is_singular()) {
        return $excerpt;
    }
    $post = get_post();
    if (! $post || ! has_tag('checkin', $post)) {
        return $excerpt;
    }
    return possee_checkin_excerpt($excerpt, $post);
}

add_filter('blocksy:archive:render-card-layers', 'possee_archive_likes', 9, 3);
function possee_archive_likes($outputs, $prefix, $featured_image_args)
{
    if (! function_exists('get_linkbacks') || ! function_exists('list_linkbacks')) {
        return $outputs;
    }
    $likes = get_linkbacks('like');
    if (empty($likes)) {
        return $outputs;
    }
    $links_html = list_linkbacks(
        array(
            'type' => 'like',
            'echo' => false,
        ),
        $likes
    );
    if ($links_html) {
        $likes_html = '<div class="likes">' . $links_html . '</div>';
        if (isset($outputs['excerpt'])) {
            $outputs['excerpt'] .= $likes_html;
        } else {
            $outputs['excerpt'] = $likes_html;
        }
    }
    return $outputs;
}

add_filter('blocksy:archive:render-card-layers', 'possee_checkin_map_layer', 10, 3);
function possee_checkin_map_layer($outputs, $prefix, $featured_image_args)
{
    if (is_singular() || ! has_tag('checkin')) {
        return $outputs;
    }
    if (empty($outputs['excerpt'])) {
        return $outputs;
    }
    // If the post has a featured image, Blocksy will show that — no need for a map thumbnail.
    if (has_post_thumbnail()) {
        return $outputs;
    }
    if (! class_exists('Loc_Config') || ! class_exists('Map_Provider')) {
        return $outputs;
    }
    $post_id = get_the_ID();
    $lat     = get_post_meta($post_id, 'geo_latitude', true);
    $lng     = get_post_meta($post_id, 'geo_longitude', true);
    if (! $lat || ! $lng) {
        return $outputs;
    }
    $map = Loc_Config::map_provider();
    if (! $map instanceof Map_Provider) {
        return $outputs;
    }
    // Use stored zoom (typically 18 = street level from OwnYourSwarm); fall back to 16.
    $zoom = get_post_meta($post_id, 'geo_zoom', true) ?: 16;
    $map->set(array( 'latitude' => (float) $lat, 'longitude' => (float) $lng, 'zoom' => (int) $zoom ));
    $url = $map->get_the_static_map();
    if (! $url || is_wp_error($url)) {
        return $outputs;
    }
    $osm_url = 'https://www.openstreetmap.org/?mlat=' . urlencode($lat) . '&mlon=' . urlencode($lng) . '#map=' . (int) $zoom . '/' . urlencode($lat) . '/' . urlencode($lng);
    $img = '<a href="' . esc_url($osm_url) . '" target="_blank" rel="noopener">'
        . '<img class="sloc-map-thumb" src="' . esc_url($url) . '" alt="" loading="lazy" />'
        . '</a>';
    $outputs['excerpt'] = preg_replace('|</div>\s*$|', $img . '</div>', $outputs['excerpt']);
    return $outputs;
}

// On singular checkin posts, suppress Simple Location's map/location text and
// Micropub plugin's dynamic render — our checkin-header block replaces them all.
add_action('wp', 'possee_remove_sloc_content_on_checkin');
function possee_remove_sloc_content_on_checkin()
{
    if (! is_singular() || ! has_tag('checkin')) {
        return;
    }
    remove_filter('the_content', array( 'Geo_Data', 'content_map' ), 11);
    remove_filter('the_content', array( 'Geo_Data', 'location_content' ), 12);
    remove_filter('the_content', array( 'Micropub\Render', 'render_content' ), 1);
}

/**
 * Strip Simple Location's sloc-display output from content on non-singular
 * pages (archives, feeds) so location/weather text doesn't pollute excerpts.
 *
 * Runs at priority 13 — after Geo_Data::location_content (priority 12) has
 * appended the sloc-display div. Stripping here is more reliable than trying
 * to remove the filter early (wp action timing issues).
 */
add_filter('the_content', 'possee_strip_sloc_from_archive', 13);
function possee_strip_sloc_from_archive($content)
{
    if (is_singular() || is_feed()) {
        return $content;
    }

    // Strip Simple Location's sloc-display div and everything inside it.
    $content = preg_replace('~<div[^>]*class="[^"]*sloc-display[^"]*"[^>]*>.*?</div>\s*~si', '', $content);

    return $content;
}

/**
 * Render location and weather on note archive cards as a separate styled line.
 *
 * Geo_Data::location_content's sloc-display is stripped from the_content on
 * non-singular pages by possee_strip_sloc_from_archive (priority 13). This
 * filter reads the raw geo_address and weather meta directly and renders it
 * with a dedicated class for reduced visual weight.
 */
add_filter('blocksy:archive:render-card-layers', 'possee_note_location_weather', 12, 3);
function possee_note_location_weather($outputs, $prefix, $featured_image_args)
{
    if (get_post_type() !== 'note' || is_feed()) {
        return $outputs;
    }
    // Checkin posts have their own excerpt format with location/weather built in.
    if (has_tag('checkin')) {
        return $outputs;
    }

    $post_id = get_the_ID();
    $parts   = array();

    // Location from Simple Location's geo_address meta.
    $address = get_post_meta($post_id, 'geo_address', true);
    if ($address) {
        $parts[] = esc_html($address);
    }

    // Weather from Simple Location post meta.
    $temp    = get_post_meta($post_id, 'weather_temperature', true);
    $summary = get_post_meta($post_id, 'weather_summary', true);
    if ($temp !== '') {
        $parts[] = round((float) $temp) . '°C';
    }
    if ($summary) {
        $parts[] = esc_html($summary);
    }

    if (empty($parts)) {
        return $outputs;
    }

    $html = '<div class="note-location-weather">' . implode(' ', $parts) . '</div>';

    if (isset($outputs['excerpt'])) {
        $outputs['excerpt'] .= $html;
    } else {
        $outputs['excerpt'] = $html;
    }

    return $outputs;
}

// Micropub's render_content wraps content in <div class="e-content"> — we do that
// ourselves in possee_wrap_econtent, so suppress it on all singular posts.
add_action('wp', 'possee_remove_micropub_render');
function possee_remove_micropub_render()
{
    if (! is_singular()) {
        return;
    }
    // Try both array form (namespaced class) and string form.
    remove_filter('the_content', array( 'Micropub\Render', 'render_content' ), 1);
    remove_filter('the_content', 'Micropub\Render::render_content', 1);
}

// Belt-and-suspenders: remove Micropub's e-content wrapper at the start of the
// content filter chain on singular posts, right before it would fire at priority 1.
add_filter('the_content', 'possee_suppress_micropub_econtent', 0);
function possee_suppress_micropub_econtent($content)
{
    if (is_singular() && class_exists('Micropub\Render')) {
        remove_filter('the_content', array( 'Micropub\Render', 'render_content' ), 1);
    }
    return $content;
}

add_filter('the_content', 'possee_checkin_header', 5);
function possee_checkin_header($content)
{
    if (! is_singular() || ! in_the_loop() || ! has_tag('checkin')) {
        return $content;
    }

    static $done = array();
    $post_id = get_the_ID();
    if (isset($done[ $post_id ])) {
        return $content;
    }
    $done[ $post_id ] = true;

    $post_id = get_the_ID();

    // Strip the auto-generated "Checked in at X, Y" prose if that's all the content is —
    // the header block replaces it. Real notes added by the user are preserved.
    $stripped = trim(wp_strip_all_tags($content));
    if (preg_match('/^Checked in at /i', $stripped)) {
        $content = '';
    }

    $post_id = get_the_ID();
    $d       = possee_checkin_data($post_id);

    // Venue line.
    $venue_html = '';
    if ($d['venue_name']) {
        $venue_html = $d['venue_url']
            ? '<a class="checkin-venue-link" href="' . esc_url($d['venue_url']) . '">' . esc_html($d['venue_name']) . '</a>'
            : '<span class="checkin-venue-link">' . esc_html($d['venue_name']) . '</span>';
    }

    // Locality + country.
    $place_parts = array_filter(array( $d['locality'], $d['country'] ));
    $place       = implode(', ', $place_parts);

    // Weather.
    $weather = '';
    if ($d['weather_temp'] || $d['weather_summary']) {
        $w_parts = array_filter(array( $d['weather_temp'], $d['weather_summary'] ));
        $weather = implode(' · ', $w_parts);
    }

    // Coordinates.
    $coords = '';
    if ($d['lat'] && $d['lng']) {
        $osm_url = 'https://www.openstreetmap.org/?mlat=' . urlencode($d['lat']) . '&mlon=' . urlencode($d['lng']) . '#map=18/' . urlencode($d['lat']) . '/' . urlencode($d['lng']);
        $coords  = '<a class="checkin-coords" href="' . esc_url($osm_url) . '" target="_blank" rel="noopener">'
            . esc_html($d['lat']) . ' ' . esc_html($d['lng'])
            . '</a>';
    }

    // Static map.
    $map_html = '';
    if ($d['lat'] && $d['lng'] && class_exists('Loc_Config') && class_exists('Map_Provider')) {
        $map  = Loc_Config::map_provider();
        if ($map instanceof Map_Provider) {
            $zoom = get_post_meta($post_id, 'geo_zoom', true) ?: 16;
            $map->set(array( 'latitude' => (float) $d['lat'], 'longitude' => (float) $d['lng'], 'zoom' => (int) $zoom ));
            $url = $map->get_the_static_map();
            if ($url && ! is_wp_error($url)) {
                $osm_url  = 'https://www.openstreetmap.org/?mlat=' . urlencode($d['lat']) . '&mlon=' . urlencode($d['lng']) . '#map=' . (int) $zoom . '/' . urlencode($d['lat']) . '/' . urlencode($d['lng']);
                $map_html = '<a href="' . esc_url($osm_url) . '" target="_blank" rel="noopener">'
                    . '<img class="checkin-map" src="' . esc_url($url) . '" alt="Map showing location of ' . esc_attr($d['venue_name']) . '" loading="lazy" />'
                    . '</a>';
            }
        }
    }

    // Build header block.
    $header = '<div class="checkin-header">';
    if ($map_html) {
        $header .= $map_html;
    }
    $header .= '<div class="checkin-meta">';
    if ($venue_html) {
        $pin = $d['venue_icon']
            ? '<img class="checkin-venue-icon" src="' . esc_url($d['venue_icon']) . '" alt="" width="24" height="24" loading="lazy">'
            : '<span aria-hidden="true">📍</span>';
        $header .= '<div class="checkin-venue">' . $pin . 'at ' . $venue_html . '</div>';
    }
    // Checked in by.
    if (! empty($d['checked_in_by'])) {
        $by_parts = array();
        foreach ($d['checked_in_by'] as $person) {
            $by_parts[] = $person['url']
                ? '<a href="' . esc_url($person['url']) . '">' . esc_html($person['name']) . '</a>'
                : esc_html($person['name']);
        }
        $checkin_dt = get_the_date('j M Y') . ' at ' . get_the_time('H:i');
        $header .= '<div class="checkin-by">Checked in by ' . implode(', ', $by_parts) . ' <span class="checkin-by-date">' . esc_html($checkin_dt) . '</span></div>';
    }
    if ($place) {
        $header .= '<div class="checkin-place">' . esc_html($place) . '</div>';
    }
    if ($weather) {
        $header .= '<div class="checkin-weather">' . esc_html($weather) . '</div>';
    }
    if ($coords) {
        $header .= '<div class="checkin-coords-wrap">' . $coords . '</div>';
    }
    $header .= '</div>'; // .checkin-meta

    // Swarm coins block.
    $coins_items = get_post_meta($post_id, 'swarm_score_items', true);
    $coins_total = get_post_meta($post_id, 'swarm_score_total', true);
    if ($coins_items && is_array($coins_items)) {
        $header .= '<div class="checkin-coins">';
        $header .= '<div class="checkin-coins-total"><img src="https://ss1.4sqi.net/img/points/coin_icon_coin.png" alt="" width="20" height="20" loading="lazy">+' . (int) $coins_total . ' <span>Coins</span></div>';
        $header .= '<ul class="checkin-coins-list">';
        foreach ($coins_items as $coin) {
            $icon_html = ! empty($coin['icon'])
                ? '<img src="' . esc_url($coin['icon']) . '" alt="" width="18" height="18" loading="lazy">'
                : '';
            $header .= '<li><span class="coin-points">+' . (int) $coin['points'] . '</span>'
                . $icon_html
                . '<span class="coin-message">' . esc_html($coin['message']) . '</span></li>';
        }
        $header .= '</ul>';
        $header .= '</div>'; // .checkin-coins
    }

    $header .= '</div>'; // .checkin-header

    $footer = '<p class="checkin-via"><a href="https://ownyourswarm.p3k.io/" rel="noopener">Added via OwnYourSwarm</a></p>';

    return $header . $content . $footer;
}

add_filter('the_content', 'possee_quill_footer', 15);
function possee_quill_footer($content)
{
    if (! is_singular() || ! in_the_loop()) {
        return $content;
    }
    // Don't double-up on checkin posts — they already have their own footer.
    if (has_tag('checkin')) {
        return $content;
    }
    $auth = get_post_meta(get_the_ID(), 'micropub_auth_response', true);
    if (empty($auth['client_id']) || $auth['client_id'] !== 'https://quill.p3k.io/') {
        return $content;
    }
    return $content . '<p class="checkin-via"><a href="https://quill.p3k.io/" rel="noopener">Added via Quill</a></p>';
}

/**
 * Sideload mf2_photo URLs into the WordPress Media Library after Micropub
 * post creation.  Stores URL → attachment_id mapping so the render function
 * can serve medium-sized images with a lightbox link to full size.
 *
 * Runs at priority 20 to fire after the Micropub plugin has saved mf2_photo
 * meta (priority 10).  Skips if the post already has attachments stored.
 */
add_action('after_micropub', 'possee_sideload_micropub_photos', 20, 2);
function possee_sideload_micropub_photos($input, $args)
{
    if (! isset($args['ID'])) {
        return;
    }
    $post_id = $args['ID'];
    $photos  = get_post_meta($post_id, 'mf2_photo', true);
    if (empty($photos) || ! is_array($photos)) {
        return;
    }

    // Don't reprocess if we already have attachment mappings.
    if (get_post_meta($post_id, 'possee_photo_attachments', true)) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $mapping = array();
    foreach ($photos as $photo) {
        $url = '';
        if (is_string($photo) && $photo) {
            $url = $photo;
        } elseif (is_array($photo) && ! empty($photo['value'])) {
            $url = $photo['value'];
        }
        if (! $url) {
            continue;
        }

        // Skip URLs already hosted on this site (Quill uploads directly to Media Library).
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = wp_parse_url($url, PHP_URL_HOST);
        if ($home && $host && strtolower($host) === strtolower($home)) {
            $existing_id = attachment_url_to_postid($url);
            if ($existing_id) {
                $mapping[$url] = $existing_id;
            }
            continue;
        }

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            continue;
        }

        $file_array = array(
            'name'     => basename($url),
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            continue;
        }

        $mapping[$url] = (int) $attachment_id;
    }

    if ($mapping) {
        update_post_meta($post_id, 'possee_photo_attachments', $mapping);
    }
}

/**
 * Proxy an image URL through images.weserv.nl for bandwidth-friendly display.
 */
function possee_image_proxy_url($url, $w = 800)
{
    if (! $url) {
        return '';
    }
    return 'https://images.weserv.nl/?url=' . rawurlencode($url) . '&w=' . (int) $w;
}

add_filter('the_content', 'possee_render_micropub_photos', 21);
function possee_render_micropub_photos($content)
{
    if (! is_singular() || ! in_the_loop()) {
        return $content;
    }
    $post_id = get_the_ID();
    $photos  = get_post_meta($post_id, 'mf2_photo', true);
    if (empty($photos) || ! is_array($photos)) {
        return $content;
    }

    $attachments = get_post_meta($post_id, 'possee_photo_attachments', true);
    if (! is_array($attachments)) {
        $attachments = array();
    }

    $html = '';
    foreach ($photos as $photo) {
        $full = '';
        $alt  = '';
        if (is_string($photo) && $photo) {
            $full = $photo;
        } elseif (is_array($photo) && ! empty($photo['value'])) {
            $full = $photo['value'];
            $alt  = isset($photo['alt']) ? esc_attr($photo['alt']) : '';
        }
        if (! $full) {
            continue;
        }

        // Skip if this photo URL already appears in the post content (e.g.,
        // it's a Gutenberg image block that was extracted into mf2_photo).
        // Avoids duplicating images that are already visible in the editor.
        if (strpos($content, $full) !== false) {
            continue;
        }

        if (isset($attachments[$full])) {
            // Use WordPress-resized medium image with lightbox to full.
            $html .= '<figure class="micropub-photo">';
            $html .= wp_get_attachment_image(
                $attachments[$full],
                'medium',
                false,
                array(
                    'class'           => 'u-photo',
                    'loading'         => 'lazy',
                    'data-full-res'   => esc_url(wp_get_attachment_image_url($attachments[$full], 'full')),
                )
            );
            $html .= '</figure>';
        } else {
            // Fallback: proxy via weserv.nl, full-res in data attribute.
            $display = possee_image_proxy_url($full, 800);
            $html .= '<figure class="micropub-photo">'
                . '<img class="u-photo" src="' . esc_url($display) . '" alt="' . esc_attr($alt) . '" loading="lazy" data-full-res="' . esc_url($full) . '">'
                . '</figure>';
        }
    }

    return $content . $html;
}

add_filter('the_content', 'possee_strip_syndication_links', 999);
function possee_strip_syndication_links($content)
{
    if (is_singular()) {
        return $content;
    }
    return preg_replace('/<div[^>]+class="[^"]*syndication-links[^"]*"[^>]*>.*?<\/div>/is', '', $content);
}


add_filter('syn_link_mapping', 'possee_syn_link_mapping', 10, 2);
function possee_syn_link_mapping($return, $url)
{
    $domain = str_replace('www.', '', wp_parse_url(strtolower($url), PHP_URL_HOST) ?? '');
    if ('hachyderm.io' === $domain) {
        return 'mastodon';
    }
    if ('bsky.app' === $domain || 'bsky.social' === $domain) {
        return 'bluesky';
    }
    return $return;
}

/**
 * Ensure get_permalink() returns the pretty URL during webmention syndication.
 *
 * WordPress returns /?p=ID for posts in 'future', 'draft', or 'pending' status.
 * Bridgy appends the source URL it's given verbatim, so syndicating a scheduled
 * post produces "Title: https://blog.sleep-er.co.uk/?p=293" on Mastodon/Bluesky.
 *
 * Fix: temporarily clone the post in WP's object cache with post_status='publish'
 * so get_permalink() resolves the pretty URL, then restore the original on shutdown.
 */
add_action('pre_syndication_links_webmention', 'possee_syndication_force_pretty_permalink', 5);
function possee_syndication_force_pretty_permalink($post_id)
{
    $post = get_post($post_id);
    if (! $post) {
        return;
    }
    // Always force post_status='publish' and ensure a slug so get_permalink()
    // returns the pretty URL. This covers published posts where the permalink
    // cache or transition state can still produce /?p=ID.
    $mutated = clone $post;
    if ('publish' !== $mutated->post_status) {
        $mutated->post_status = 'publish';
    }
    if (empty($mutated->post_name)) {
        $mutated->post_name = sanitize_title($mutated->post_title);
    }
    // Only cache if something changed to avoid unnecessary work.
    if ($mutated != $post) {
        wp_cache_set($post_id, $mutated, 'posts');
        add_action('shutdown', static function () use ($post) {
            wp_cache_set($post->ID, $post, 'posts');
        });
    }
}

add_filter('get_the_terms', 'possee_hide_default_category', 10, 3);
function possee_hide_default_category($terms, $post_id, $taxonomy)
{
    if (is_admin() || 'category' !== $taxonomy || ! is_array($terms)) {
        return $terms;
    }
    $non_default = array_filter($terms, fn($c) => ! in_array($c->slug, array( 'uncategorized', 'uncategorised' ), true));
    return empty($non_default) ? array() : $terms;
}

add_filter('pre_insert_micropub_post', 'possee_sanitize_micropub');
function possee_sanitize_micropub($args)
{
    if (isset($args['tags_input']) && is_array($args['tags_input'])) {
        $args['tags_input'] = array_values(array_filter($args['tags_input'], 'is_string'));
    }

    $meta    = isset($args['meta_input']) ? $args['meta_input'] : array();
    $checkin = isset($meta['mf2_checkin']) ? $meta['mf2_checkin'] : null;
    if (! $checkin) {
        return $args;
    }

    if (empty($args['post_content']) && empty($args['post_title'])) {
        $name = '';
        if (is_array($checkin) && isset($checkin['properties']['name'][0])) {
            $name = $checkin['properties']['name'][0];
        }
        $locality = '';
        if (is_array($checkin) && isset($checkin['properties']['locality'][0])) {
            $locality = $checkin['properties']['locality'][0];
        }
        $parts                = array_filter(array( $name, $locality ));
        $args['post_content'] = 'Checked in at ' . implode(', ', $parts);
    }

    if (! isset($args['tax_input']) || ! is_array($args['tax_input'])) {
        $args['tax_input'] = array();
    }
    $args['tax_input']['post_format'] = array( 'post-format-status' );

    return $args;
}

/*
 * Micropub: route read-of posts to 'books' CPT, with ISBN dedup.
 */

add_filter('micropub_post_type', 'possee_micropub_book_post_type', 10, 2);
function possee_micropub_book_post_type($post_type, $input)
{
    if (isset($input['properties']['read-of']) || isset($input['properties']['read-status'])) {
        return 'book';
    }
    return $post_type;
}

add_filter('micropub_suggest_title', 'possee_micropub_book_slug', 10, 2);
function possee_micropub_book_slug($title, $props)
{
    if (! isset($props['read-of'][0]['properties']['name'][0])) {
        return $title;
    }
    return $props['read-of'][0]['properties']['name'][0];
}

/**
 * Normalise a book title for fuzzy comparison: strip entities, lowercase,
 * collapse whitespace, remove all non-alphanumeric characters.
 */
function possee_normalise_book_title($raw)
{
    $s = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Normalise word separators to spaces, then strip remaining non-alpha.
    $s = preg_replace('/[-\x{2013}\x{2014}]/u', ' ', strtolower($s));
    $s = preg_replace('/[^a-z0-9\s]/u', '', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

add_filter('pre_insert_micropub_post', 'possee_micropub_book_deduplicate');
function possee_micropub_book_deduplicate($args)
{
    if (! isset($args['meta_input']['mf2_read-of'])) {
        return $args;
    }

    $read_of = $args['meta_input']['mf2_read-of'];
    if (! is_array($read_of) || empty($read_of)) {
        return $args;
    }

    $item  = $read_of[0];
    $props = isset($item['properties']) ? $item['properties'] : array();

    // Set post_title from the book name inside read-of.
    if (isset($props['name'][0]) && empty($args['post_title'])) {
        $args['post_title'] = $props['name'][0];
    }

    // Set post_content from summary if no content.
    if (isset($args['post_excerpt']) && empty($args['post_content'])) {
        $args['post_content'] = $args['post_excerpt'];
    }

    // Extract identifiers and metadata for dedup.
    $uid    = isset($props['uid'][0]) ? $props['uid'][0] : '';
    $title  = isset($props['name'][0]) ? $props['name'][0] : '';
    $author = isset($props['author'][0]) ? $props['author'][0] : '';

    // Try to find existing book — first by ISBN, then by title+author.
    $existing_id = 0;

    if (strpos($uid, 'isbn:') === 0 || strpos($uid, 'ISBN:') === 0) {
        $isbn = substr($uid, 5);

        $existing = get_posts(array(
            'post_type'        => 'book',
            'post_status'      => 'any',
            'meta_key'         => 'isbn',
            'meta_value'       => $isbn,
            'fields'           => 'ids',
            'posts_per_page'   => 1,
            'update_meta_cache' => false,
            'no_found_rows'    => true,
        ));

        if (! empty($existing)) {
            $existing_id = $existing[0];
        }
    }

    // Fallback: fuzzy match by normalised title + LIKE author.
    // Different editions/sources use different ISBNs and some include
    // narrator names in the author field — exact match won't cut it.
    if (! $existing_id && $title && $author) {
        $norm_title = possee_normalise_book_title($title);

        // Match by search (s) to get candidates, then verify via PHP.
        $existing = get_posts(array(
            'post_type'        => 'book',
            'post_status'      => 'any',
            'fields'           => 'ids',
            'posts_per_page'   => 10,
            'update_meta_cache' => false,
            'no_found_rows'    => true,
            's'                => $title,
        ));

        foreach ($existing as $candidate_id) {
            $stored_title = possee_normalise_book_title(get_the_title($candidate_id));
            if ($norm_title !== $stored_title) {
                continue;
            }
            $stored_author = get_post_meta($candidate_id, 'book_author', true);
            if (empty($stored_author)) {
                continue;
            }
            // Author in incoming data should appear somewhere in the stored field
            // (which may include e.g. narrator names after a comma).
            if (false !== stripos($stored_author, $author)) {
                $existing_id = $candidate_id;
                break;
            }
        }
    }

    if ($existing_id) {
        // Short-circuit — book already exists.
        $args['ID'] = $existing_id;

        // When transitioning to "finished", update post_date to match.
        $new_status = $args['meta_input']['mf2_read-status'] ?? null;
        if ('finished' === $new_status) {
            $finished_at = $args['meta_input']['mf2_finished-at'] ?? null;
            if ($finished_at) {
                $date = date('Y-m-d H:i:s', strtotime($finished_at));
                $args['post_date']     = $date;
                $args['post_date_gmt'] = get_gmt_from_date($date);
            }
        }
    } else {
        // Store ISBN as accessible meta for future dedup queries.
        if (! isset($args['meta_input'])) {
            $args['meta_input'] = array();
        }
        if (! empty($isbn)) {
            $args['meta_input']['isbn'] = $isbn;
        }

        // Store author as accessible meta.
        if ($author) {
            $args['meta_input']['book_author'] = $author;
        }
    }

    // Extract series info from read-of properties.
    if (isset($props['book-series'][0])) {
        $args['meta_input']['mf2_book-series'] = $props['book-series'][0];
        if (isset($props['book-series-position'][0])) {
            $args['meta_input']['mf2_book-series-position'] = $props['book-series-position'][0];
        }
        if (isset($props['book-series-count'][0])) {
            $args['meta_input']['mf2_book-series-count'] = (int) $props['book-series-count'][0];
        }
        if (isset($props['book-series-completed'][0])) {
            $args['meta_input']['mf2_book-series-completed'] = filter_var($props['book-series-completed'][0], FILTER_VALIDATE_BOOLEAN);
        }
    }

    // Extract book metadata from read-of properties.
    $book_meta_map = array(
        'mf2_book-pages'        => 'book-pages',
        'mf2_book-release-year' => 'book-release-year',
        'mf2_book-category-id'  => 'book-category-id',
        'mf2_book-cover-url'    => 'book-cover-url',
        'mf2_hardcover-slug'    => 'hardcover-slug',
    );
    foreach ($book_meta_map as $meta_key => $prop_key) {
        if (isset($props[ $prop_key ][0])) {
            $args['meta_input'][ $meta_key ] = $props[ $prop_key ][0];
        }
    }

    // Extract genres (array).
    if (isset($props['book-genres']) && is_array($props['book-genres'])) {
        $args['meta_input']['mf2_book-genres'] = array_filter($props['book-genres'], 'is_string');
    }

    return $args;
}

add_filter('post_class', 'possee_add_hentry_class');
function possee_add_hentry_class($classes)
{
    $classes[] = 'h-entry';
    return $classes;
}

add_filter('the_title', 'possee_title_pname', 10, 2);
function possee_title_pname($title, $id = null)
{
    if (! is_singular()) {
        return $title;
    }
    return '<span class="p-name">' . $title . '</span>';
}

/**
 * Add p-category microformat for each WordPress category so Bridgy
 * converts them to hashtags on syndicated posts (Mastodon, Bluesky).
 * Prepended at priority 0 so they land inside the h-entry scope
 * before the e-content. Uses <data> elements that are invisible to users.
 */
add_filter('the_content', 'possee_category_pname', 0);
function possee_category_pname($content)
{
    if (! is_singular() || ! in_the_loop()) {
        return $content;
    }
    $categories = get_the_category();
    if (empty($categories)) {
        return $content;
    }
    $html = '';
    foreach ($categories as $cat) {
        if (in_array($cat->slug, array('uncategorized', 'uncategorised'), true)) {
            continue;
        }
        $html .= '<data class="p-category" value="' . esc_attr($cat->name) . '"></data>';
    }
    if (! $html) {
        return $content;
    }
    return $html . $content;
}

add_filter('the_content', 'possee_wrap_econtent', 20);
function possee_wrap_econtent($content)
{
    if (! is_singular() || ! in_the_loop()) {
        return $content;
    }
    static $done = array();
    $post_id = get_the_ID();
    if (isset($done[ $post_id ])) {
        return $content;
    }
    $done[ $post_id ] = true;
    $iso       = get_post_time('c', true);
    $permalink = get_permalink();
    $hidden    = '<div style="display:none">'
        . '<time class="dt-published" datetime="' . esc_attr($iso) . '">' . esc_html($iso) . '</time>'
        . '<a class="u-url" href="' . esc_url($permalink) . '">' . esc_html($permalink) . '</a>'
        . '</div>';
    return $hidden . '<div class="e-content">' . $content . '</div>';
}

add_filter('the_content', 'possee_venue_recent_checkins', 20);
function possee_venue_recent_checkins($content)
{
    if (! is_singular('venue') || ! in_the_loop()) {
        return $content;
    }

    $venue_id = get_the_ID();

    $checkins = get_posts(array(
        'post_type'      => 'post',
        'posts_per_page' => 5,
        'post_status'    => 'publish',
        'meta_key'       => 'venue_id',
        'meta_value'     => $venue_id,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ));

    if (empty($checkins)) {
        return $content;
    }

    $html = '<div class="venue-checkins">';
    $html .= '<h3 class="venue-checkins-title">Recent check-ins</h3>';
    $html .= '<ul class="venue-checkins-list">';

    foreach ($checkins as $checkin) {
        $d           = possee_checkin_data($checkin->ID);
        $url         = get_permalink($checkin->ID);
        $date        = get_the_date('j M Y', $checkin->ID);
        $coins_total = get_post_meta($checkin->ID, 'swarm_score_total', true);

        $by_html = '';
        if (! empty($d['checked_in_by'])) {
            $names = array();
            foreach ($d['checked_in_by'] as $person) {
                $names[] = $person['url']
                    ? '<a href="' . esc_url($person['url']) . '">' . esc_html($person['name']) . '</a>'
                    : esc_html($person['name']);
            }
            $by_html = ' by ' . implode(', ', $names);
        }

        $meta_parts = array();
        if ($d['locality']) {
            $meta_parts[] = esc_html($d['locality']);
        }
        if ($coins_total) {
            $meta_parts[] = '<img src="https://ss1.4sqi.net/img/points/coin_icon_coin.png" alt="" width="14" height="14" style="vertical-align:middle"> +' . (int) $coins_total;
        }

        $html .= '<li class="venue-checkin-item">';
        $html .= '<a class="venue-checkin-date" href="' . esc_url($url) . '">' . esc_html($date) . '</a>';
        if ($by_html) {
            $html .= '<span class="venue-checkin-by">' . $by_html . '</span>';
        }
        if ($meta_parts) {
            $html .= '<span class="venue-checkin-meta">' . implode(' · ', $meta_parts) . '</span>';
        }
        $html .= '</li>';
    }

    $html .= '</ul>';
    $html .= '</div>';

    return $content . $html;
}

// ── REST endpoint: n8n lastChecked watermark ──────────────────
// Stores the last time n8n checked Hardcover for new books.
// Persistent across n8n restarts (unlike $getWorkflowStaticData).

add_action('rest_api_init', 'possee_register_last_checked_routes');
function possee_register_last_checked_routes()
{
    register_rest_route('possee/v1', '/last-checked', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'possee_get_last_checked',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'possee_set_last_checked',
            'permission_callback' => 'possee_rest_book_auth_check',
            'args'                => array(
                'value' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ),
    ));
}

function possee_get_last_checked()
{
    $stored = get_option('possee_n8n_last_checked', '');
    if (! empty($stored)) {
        return array( 'lastChecked' => $stored );
    }

    // No stored value: return the most recent book post's date minus 24h.
    $posts = get_posts(array(
        'post_type'      => 'book',
        'posts_per_page' => 1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ));
    if (! empty($posts)) {
        $post_date = get_post_time('c', true, $posts[0]);
        $dt        = new DateTime($post_date);
        $dt->modify('-24 hours');
        return array( 'lastChecked' => $dt->format('c') );
    }

    // No books at all: return 30 days ago.
    $dt = new DateTime();
    $dt->modify('-30 days');
    return array( 'lastChecked' => $dt->format('c') );
}

function possee_set_last_checked($request)
{
    $value = $request->get_param('value');
    update_option('possee_n8n_last_checked', $value, false);
    return array( 'success' => true, 'lastChecked' => $value );
}
