<?php

namespace CodeConfig\IDB\Updates;

use CodeConfig\IDB\Utils\Singleton;
use Exception;
use WP_Error;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');


class Update_1_2_9
{
    use Singleton;

    public function run_update()
    {
        try {
            $this->update_indbox_media_metadata();

            return '1.2.9';

        } catch (Exception $th) {
            return new WP_Error(400, 'Update to version 1.2.9 failed: ' . $th->getMessage());
        }
    }

    public function update_indbox_media_metadata()
    {
        $posts = get_posts([
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $meta_key = '_wp_attachment_metadata';

        foreach ($posts as $post_id) {
            $post_meta = get_post_meta($post_id, $meta_key, true);

            if (!is_array($post_meta) || empty($post_meta)) {
                continue;
            }

            $updated = false;

            if (isset($post_meta['width']) && is_string($post_meta['width'])) {
                $post_meta['width'] = intval($post_meta['width']);
                $updated            = true;
            }

            if (isset($post_meta['height']) && is_string($post_meta['height'])) {
                $post_meta['height'] = intval($post_meta['height']);
                $updated             = true;
            }

            if ($updated) {
                update_post_meta($post_id, $meta_key, $post_meta);
            }
        }
    }
}
