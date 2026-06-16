<?php
defined('ABSPATH') || exit;

add_action('wp_footer', 'possee_mermaid_init', 20);
function possee_mermaid_init()
{
    global $post;
    if (! $post || ! has_block('core/html', $post)) {
        return;
    }
    if (strpos($post->post_content, 'class="mermaid"') === false) {
        return;
    }
    ?>
<script type="module">
import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
mermaid.initialize({ startOnLoad: true, theme: 'neutral' });
</script>
    <?php
}
