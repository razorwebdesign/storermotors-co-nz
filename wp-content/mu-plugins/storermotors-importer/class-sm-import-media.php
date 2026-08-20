<?php
/**
 * Vehicle photo sync.
 *
 * Photos are matched to vehicles by the {StockNo}_{n}.jpg filename convention.
 * Dedupe is filename + CRC32 read from the ZIP central directory, so a re-import
 * of an unchanged feed downloads and sideloads nothing, while a rephotographed
 * vehicle (same filenames, new content) is correctly replaced.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Import_Media {

    const QUEUE_OPTION = 'sm_vehicle_import_queue';

    /**
     * Group the flat image index by stock number, ordered numerically.
     *
     * Numeric ordering matters: lexically, "8958_10.jpg" sorts before
     * "8958_2.jpg", which would scramble every gallery.
     *
     * @return array [ stock_no => [ ['key'=>…,'path'=>…,'n'=>…,'crc'=>…], … ] ]
     */
    public static function group_by_stock(array $images) {
        $grouped = [];

        foreach ($images as $key => $image) {
            $grouped[(string) $image['stock']][] = [
                'key'   => (string) $key,
                'path'  => $image['path'],
                'name'  => $image['name'],
                'stock' => (string) $image['stock'],
                'n'     => (int) $image['n'],
                'crc'   => $image['crc'],
            ];
        }

        foreach ($grouped as &$list) {
            usort($list, function ($a, $b) {
                return $a['n'] <=> $b['n'];
            });
        }
        unset($list);

        return $grouped;
    }

    /**
     * Stable signature of a vehicle's photo set, folded into the vehicle's
     * import hash so that a photo change alone still counts as a change.
     */
    public static function signature(array $list) {
        $parts = [];
        foreach ($list as $image) {
            $parts[] = $image['key'] . ':' . $image['crc'];
        }
        sort($parts);

        return md5(implode('|', $parts));
    }

    /**
     * [ import_key => ['id'=>int,'crc'=>string] ] for every attachment the
     * importer has previously created. One query for the whole run.
     */
    public static function existing_map() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT pm.post_id AS id, pm.meta_value AS import_key, COALESCE(crc.meta_value, '') AS crc
               FROM {$wpdb->postmeta} pm
          LEFT JOIN {$wpdb->postmeta} crc
                 ON crc.post_id = pm.post_id AND crc.meta_key = '_sm_import_crc'
              WHERE pm.meta_key = '_sm_import_key'"
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->import_key] = [
                'id'  => (int) $row->id,
                'crc' => (string) $row->crc,
            ];
        }

        return $map;
    }

    // ─── Queue ───────────────────────────────────────────────────────────────

    public static function queue(array $queue) {
        update_option(self::QUEUE_OPTION, $queue, false);
    }

    public static function get_queue() {
        $queue = get_option(self::QUEUE_OPTION, []);
        return is_array($queue) ? $queue : [];
    }

    public static function clear_queue() {
        delete_option(self::QUEUE_OPTION);
    }

    public static function queue_remaining() {
        $queue = self::get_queue();
        return isset($queue['jobs']) ? count($queue['jobs']) : 0;
    }

    /**
     * Work the image queue for a bounded slice of wall-clock time, then hand
     * back so the caller can either reschedule (cron) or poll again (admin).
     *
     * @return array{done:int,remaining:int,finished:bool}
     */
    public static function process_queue($seconds = 20) {
        $queue = self::get_queue();

        if (empty($queue['jobs'])) {
            self::finish($queue);
            return ['done' => 0, 'remaining' => 0, 'finished' => true];
        }

        // The staging directory can vanish between slices (GC, a deploy, a
        // disk clear). Without it there is nothing to import.
        if (!empty($queue['run_dir']) && !is_dir($queue['run_dir'])) {
            SM_Import_Log::warn('Image staging directory has disappeared — abandoning the queue. The next scheduled run will re-download the feed.');
            delete_option('sm_vehicle_import_last_fingerprint');
            self::clear_queue();
            return ['done' => 0, 'remaining' => 0, 'finished' => true];
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        // The gallery block only ever requests 'thumbnail' and 'large'.
        // Skipping the biggest subsizes removes roughly 40% of the work.
        $trim_sizes = function ($sizes) {
            unset($sizes['1536x1536'], $sizes['2048x2048'], $sizes['medium_large']);
            return $sizes;
        };
        add_filter('intermediate_image_sizes_advanced', $trim_sizes);

        $existing = self::existing_map();
        $started  = microtime(true);
        $done     = 0;

        while (!empty($queue['jobs']) && (microtime(true) - $started) < $seconds) {
            $job = array_shift($queue['jobs']);
            self::run_job($job, $existing);
            $done++;

            // Persist after each vehicle so a fatal error costs one job, not the run.
            self::queue($queue);
        }

        remove_filter('intermediate_image_sizes_advanced', $trim_sizes);

        // Photo tallies belong to the run that queued the work, which was
        // summarised before this slice started.
        SM_Import_Log::flush_deferred();

        $remaining = count($queue['jobs']);
        if ($remaining === 0) {
            self::finish($queue);
        }

        return ['done' => $done, 'remaining' => $remaining, 'finished' => $remaining === 0];
    }

    /** Sync one vehicle's photo set. */
    private static function run_job(array $job, array $existing) {
        $post_id = (int) $job['post_id'];
        if (!$post_id || !get_post($post_id)) {
            return;
        }

        $title      = get_the_title($post_id);
        $gallery    = [];
        $featured   = 0;

        foreach ($job['images'] as $image) {
            $key = (string) $image['key'];
            $id  = 0;

            if (isset($existing[$key])) {
                $known = $existing[$key];

                if ($known['crc'] !== '' && $image['crc'] !== '' && $known['crc'] === $image['crc']) {
                    // Byte-identical photo already in the library.
                    $id = $known['id'];
                    SM_Import_Log::bump('images_skipped');
                } else {
                    $id = self::sideload($image, $post_id, $title);
                    if ($id) {
                        wp_delete_attachment($known['id'], true);
                        SM_Import_Log::bump('images_replaced');
                    }
                }
            } else {
                $id = self::sideload($image, $post_id, $title);
                if ($id) {
                    SM_Import_Log::bump('images_added');
                }
            }

            if (!$id) {
                continue;
            }

            if ((int) $image['n'] === 1 && !$featured) {
                $featured = $id;
            } else {
                $gallery[] = $id;
            }
        }

        // No _1 in the set: promote the first photo so the card is never blank.
        if (!$featured && $gallery) {
            $featured = array_shift($gallery);
        }

        if ($featured && (int) get_post_thumbnail_id($post_id) !== $featured) {
            set_post_thumbnail($post_id, $featured);
        }

        // The gallery block prepends the featured image itself, so it must not
        // also appear in the stored array.
        $encoded = wp_json_encode(array_values(array_map('intval', $gallery)));
        if ((string) get_post_meta($post_id, '_vehicle_gallery', true) !== $encoded) {
            update_post_meta($post_id, '_vehicle_gallery', $encoded);
        }
    }

    /**
     * Move a staged file into the media library.
     *
     * media_handle_sideload() moves rather than copies, so the run directory
     * shrinks as work completes and a crash cannot double-import.
     */
    private static function sideload(array $image, $post_id, $title) {
        if (!file_exists($image['path'])) {
            SM_Import_Log::warn(sprintf('Staged photo %s is missing — skipped.', $image['name']));
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file = [
            'name'     => $image['name'],
            'tmp_name' => $image['path'],
        ];

        $id = media_handle_sideload($file, $post_id, $title);

        if (is_wp_error($id)) {
            SM_Import_Log::warn(sprintf('Could not import photo %s: %s', $image['name'], $id->get_error_message()));
            return 0;
        }

        update_post_meta($id, '_sm_import_key', (string) $image['key']);
        update_post_meta($id, '_sm_import_crc', (string) $image['crc']);
        update_post_meta($id, '_sm_import_stock', (string) $image['stock']);
        update_post_meta($id, '_wp_attachment_image_alt', sprintf('%s — photo %d', $title, (int) $image['n']));

        return (int) $id;
    }

    /** Queue drained: drop the staging directory and clear the option. */
    private static function finish(array $queue) {
        if (!empty($queue['run_dir'])) {
            SM_Import_Package::cleanup($queue['run_dir']);
        }
        self::clear_queue();
    }
}
