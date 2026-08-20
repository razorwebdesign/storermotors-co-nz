<?php
/**
 * Vehicle upsert, manual-edit preservation, and reconciliation.
 *
 * Keyed on the feed's StockNo. Re-importing an unchanged feed must be a true
 * no-op: no meta writes, no post_modified bump, no image work.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Import_Vehicles {

    /** Feed-owned meta. Overwritten on every run. */
    const FEED_META = [
        '_vehicle_stock_no', '_vehicle_make', '_vehicle_model', '_vehicle_variant',
        '_vehicle_variant_raw', '_vehicle_year', '_vehicle_odometer', '_vehicle_price',
        '_vehicle_engine', '_vehicle_fuel', '_vehicle_body', '_vehicle_doors',
        '_vehicle_colour', '_vehicle_rego', '_vehicle_vin', '_vehicle_chassis',
        '_vehicle_condition', '_vehicle_location', '_vehicle_wof_expiry', '_vehicle_rego_expiry',
    ];

    /** Importer bookkeeping, never shown as editable fields. */
    const CONTROL_META = [
        '_vehicle_import_hash', '_vehicle_import_title', '_vehicle_import_content_hash',
        '_vehicle_import_raw', '_vehicle_import_drafted_at', '_vehicle_weekly_auto',
    ];

    public static function init() {
        add_action('init', [__CLASS__, 'register'], 20);
    }

    /**
     * Register the importer's own meta keys and the features taxonomy.
     * Mirrors the $meta_args shape used in storermotors-branding.php.
     */
    public static function register() {
        $args = [
            'object_subtype' => 'vehicle',
            'type'           => 'string',
            'single'         => true,
            'show_in_rest'   => false, // feed bookkeeping is not public API
            'auth_callback'  => function () { return current_user_can('edit_posts'); },
        ];

        foreach (array_merge(self::FEED_META, self::CONTROL_META) as $key) {
            // The branding plugin already registers the original thirteen keys.
            if (registered_meta_key_exists('post', $key, 'vehicle')) {
                continue;
            }
            register_post_meta('vehicle', $key, $args);
        }

        // Extras from the feed. Kept out of the public query space: 29
        // auto-generated term archives would be thin-content SEO liability.
        register_taxonomy('vehicle_feature', 'vehicle', [
            'labels' => [
                'name'          => 'Vehicle Features',
                'singular_name' => 'Vehicle Feature',
                'all_items'     => 'All Features',
                'edit_item'     => 'Edit Feature',
                'add_new_item'  => 'Add New Feature',
                'search_items'  => 'Search Features',
                'menu_name'     => 'Features',
            ],
            'hierarchical'       => false,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_rest'       => true,
            'show_admin_column'  => false,
        ]);
    }

    /**
     * [stock_no => post_id] for every vehicle the importer owns.
     *
     * One query for the whole run rather than a WP_Query per item. Trashed
     * posts are excluded deliberately: a vehicle that reappears in the feed
     * should be inserted fresh, not resurrected out of the bin.
     */
    public static function lookup_map() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT pm.meta_value AS stock_no, p.ID AS id
               FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = '_vehicle_stock_no'
                AND pm.meta_value <> ''
                AND p.post_type = 'vehicle'
                AND p.post_status IN ('publish','draft','pending','private','future')
           ORDER BY p.ID ASC"
        );

        $map = [];
        foreach ($rows as $row) {
            $stock = (string) $row->stock_no;
            if (isset($map[$stock])) {
                SM_Import_Log::warn(sprintf(
                    'StockNo %s is on more than one vehicle (posts %d and %d) — using %d and leaving the other alone.',
                    $stock, $map[$stock], $row->id, $map[$stock]
                ));
                continue;
            }
            $map[$stock] = (int) $row->id;
        }

        return $map;
    }

    /**
     * Create or update one vehicle.
     *
     * @return array{post_id:int,status:string} status is added|updated|unchanged|skipped
     */
    public static function upsert(array $item, $post_id = 0) {
        $stock = (string) $item['stock_no'];

        // The payload hash covers everything the importer controls, so an
        // unchanged feed short-circuits before any write happens.
        $payload = [
            'meta'     => $item['meta'],
            'title'    => $item['title'],
            'content'  => $item['content'],
            'features' => $item['features'],
            'images'   => $item['image_signature'] ?? '',
        ];
        ksort($payload['meta']);
        $hash = md5(wp_json_encode($payload));

        if ($post_id) {
            $existing_hash = get_post_meta($post_id, '_vehicle_import_hash', true);
            $drafted       = get_post_meta($post_id, '_vehicle_import_drafted_at', true);

            if ($existing_hash === $hash && !$drafted) {
                SM_Import_Log::bump('unchanged');
                return ['post_id' => $post_id, 'status' => 'unchanged'];
            }
        }

        $is_new  = !$post_id;
        $postarr = [];

        if ($is_new) {
            $postarr = [
                'post_type'   => 'vehicle',
                'post_status' => 'publish',
                'post_title'  => $item['title'],
                'post_content'=> $item['content'],
                // Set once. Re-deriving a slug later breaks live URLs and any
                // search indexing already built against them.
                'post_name'   => sanitize_title(sprintf(
                    '%s-%s-%s-%s',
                    $item['meta']['_vehicle_year'],
                    $item['meta']['_vehicle_make'],
                    $item['meta']['_vehicle_model'],
                    $stock
                )),
            ];

            $post_id = wp_insert_post($postarr, true);
            if (is_wp_error($post_id)) {
                SM_Import_Log::error(sprintf('Could not create vehicle for StockNo %s: %s', $stock, $post_id->get_error_message()));
                SM_Import_Log::bump('skipped');
                return ['post_id' => 0, 'status' => 'skipped'];
            }
        } else {
            $update = self::post_fields_to_update($post_id, $item);

            // Republish a vehicle the importer previously drafted. A draft with
            // no drafted-at marker was drafted by a human — leave it alone.
            if (get_post_meta($post_id, '_vehicle_import_drafted_at', true)) {
                if (get_post_status($post_id) === 'draft') {
                    $update['post_status'] = 'publish';
                    SM_Import_Log::bump('republished');
                    SM_Import_Log::info(sprintf('StockNo %s is back in the feed — republished.', $stock));
                }
                delete_post_meta($post_id, '_vehicle_import_drafted_at');
            }

            // wp_update_post() unconditionally rewrites post_modified, so only
            // call it when something the post table actually holds has changed.
            if ($update) {
                $update['ID'] = $post_id;
                $result = wp_update_post($update, true);
                if (is_wp_error($result)) {
                    SM_Import_Log::error(sprintf('Could not update vehicle for StockNo %s: %s', $stock, $result->get_error_message()));
                    SM_Import_Log::bump('skipped');
                    return ['post_id' => $post_id, 'status' => 'skipped'];
                }
            }
        }

        self::write_meta($post_id, $item, $is_new);
        self::write_features($post_id, $item);
        self::write_body_term($post_id, $item);

        // Record what we wrote, so a later human edit can be detected.
        update_post_meta($post_id, '_vehicle_import_title', $item['title']);
        update_post_meta($post_id, '_vehicle_import_content_hash', md5($item['content']));
        update_post_meta($post_id, '_vehicle_import_raw', wp_json_encode($item['raw']));
        update_post_meta($post_id, '_vehicle_import_hash', $hash);

        SM_Import_Log::bump($is_new ? 'added' : 'updated');

        return ['post_id' => $post_id, 'status' => $is_new ? 'added' : 'updated'];
    }

    /**
     * Which post-table fields genuinely need rewriting.
     *
     * Title and content are only overwritten while they still match what the
     * importer last wrote — the moment someone edits them by hand, the feed
     * stops touching them.
     */
    private static function post_fields_to_update($post_id, array $item) {
        $update = [];
        $post   = get_post($post_id);
        if (!$post) {
            return $update;
        }

        $last_title = get_post_meta($post_id, '_vehicle_import_title', true);
        if ($post->post_title !== $item['title']) {
            if ($last_title === '' || $last_title === $post->post_title) {
                $update['post_title'] = $item['title'];
            } else {
                SM_Import_Log::info(sprintf(
                    'Keeping hand-edited title for StockNo %s ("%s").', $item['stock_no'], $post->post_title
                ));
            }
        }

        $last_content_hash = get_post_meta($post_id, '_vehicle_import_content_hash', true);
        if (md5($post->post_content) !== md5($item['content'])) {
            if ($last_content_hash === '' || $last_content_hash === md5($post->post_content)) {
                $update['post_content'] = $item['content'];
            } else {
                SM_Import_Log::info(sprintf(
                    'Keeping hand-edited description for StockNo %s.', $item['stock_no']
                ));
            }
        }

        return $update;
    }

    /**
     * Write feed-owned meta, skipping values that are already correct so we
     * never touch a row unnecessarily.
     */
    private static function write_meta($post_id, array $item, $is_new) {
        foreach ($item['meta'] as $key => $value) {
            if ($key === '_vehicle_transmission') {
                $value = self::resolve_transmission($post_id, $value);
            }

            if ((string) get_post_meta($post_id, $key, true) !== (string) $value) {
                update_post_meta($post_id, $key, $value);
            }
        }

        self::write_weekly($post_id, $item, $is_new);

        // _vehicle_featured and _vehicle_sold are human-curated and never
        // written by the importer. Seed them empty on insert only, so the
        // meta box renders predictably.
        if ($is_new) {
            foreach (['_vehicle_featured', '_vehicle_sold'] as $key) {
                if (get_post_meta($post_id, $key, true) === '') {
                    update_post_meta($post_id, $key, '');
                }
            }
        }
    }

    /**
     * The feed only emits A/M, so a CVT vehicle arrives as "Auto". Where a human
     * has corrected it to CVT, that correction must survive re-import.
     */
    private static function resolve_transmission($post_id, $value) {
        $current = (string) get_post_meta($post_id, '_vehicle_transmission', true);

        if ($current === 'CVT' && $value === 'Auto') {
            return 'CVT';
        }

        return $value;
    }

    /**
     * Weekly finance. The feed carries no figure, so it is calculated from the
     * price and stamped as auto-generated. Once a human edits the field, the
     * stamp no longer matches and the importer stops overwriting it.
     */
    private static function write_weekly($post_id, array $item, $is_new) {
        $current = (string) get_post_meta($post_id, '_vehicle_weekly', true);

        // A real figure in the feed always wins.
        if ($item['weekly_feed'] !== '') {
            if ($current !== $item['weekly_feed']) {
                update_post_meta($post_id, '_vehicle_weekly', $item['weekly_feed']);
                update_post_meta($post_id, '_vehicle_weekly_auto', '');
            }
            return;
        }

        if (!SM_Import_Settings::get('weekly_enabled')) {
            return;
        }

        $auto = (string) get_post_meta($post_id, '_vehicle_weekly_auto', true);

        // Hand-entered value: leave it alone forever.
        if ($current !== '' && $auto !== $current) {
            return;
        }

        $price     = (int) $item['meta']['_vehicle_price'];
        $calculated = $price ? (string) self::weekly_payment($price) : '';

        if ($current !== $calculated) {
            update_post_meta($post_id, '_vehicle_weekly', $calculated);
        }
        update_post_meta($post_id, '_vehicle_weekly_auto', $calculated);
    }

    /**
     * Amortised weekly repayment.
     *
     * NOTE: any advertised repayment must be displayed alongside the rate,
     * term, deposit and fees used to derive it — see the disclaimer rendered by
     * the sm/vehicle-weekly block.
     */
    public static function weekly_payment($price) {
        $term_months = (int) SM_Import_Settings::get('weekly_term_months');
        $annual_rate = (float) SM_Import_Settings::get('weekly_rate');
        $fees        = (float) SM_Import_Settings::get('weekly_fees');
        $deposit     = (float) SM_Import_Settings::get('weekly_deposit');

        $principal = max(0, $price - $deposit) + $fees;
        if ($principal <= 0 || $term_months <= 0) {
            return 0;
        }

        $weeks = (int) round($term_months / 12 * 52);
        $rate  = $annual_rate / 100 / 52;

        if ($rate <= 0) {
            return (int) ceil($principal / $weeks);
        }

        $factor  = pow(1 + $rate, $weeks);
        $payment = $principal * $rate * $factor / ($factor - 1);

        return (int) ceil($payment);
    }

    private static function write_features($post_id, array $item) {
        $current = wp_get_object_terms($post_id, 'vehicle_feature', ['fields' => 'names']);
        if (is_wp_error($current)) {
            $current = [];
        }
        sort($current);

        $wanted = $item['features'];
        sort($wanted);

        if ($current !== $wanted) {
            wp_set_object_terms($post_id, $wanted, 'vehicle_feature', false);
        }
    }

    private static function write_body_term($post_id, array $item) {
        $body = $item['body'];
        if ($body === '') {
            return;
        }

        $current = wp_get_object_terms($post_id, 'vehicle_type', ['fields' => 'names']);
        if (is_wp_error($current)) {
            $current = [];
        }

        if ($current !== [$body]) {
            wp_set_object_terms($post_id, [$body], 'vehicle_type', false);
        }
    }

    // ─── Reconciliation ──────────────────────────────────────────────────────

    /**
     * Draft published vehicles that have disappeared from the feed.
     *
     * Runs last and only after a clean parse. Five guards must all pass, or
     * nothing is drafted — a truncated download must never empty the website.
     *
     * @param array $feed_stock  Stock numbers present in this feed.
     * @param bool  $sentinel    Whether END.XML accompanied the feed.
     * @param bool  $force       Skip the ratio guards (admin override only).
     */
    public static function reconcile(array $feed_stock, $sentinel, $force = false) {
        $feed_stock = array_map('strval', $feed_stock);
        $item_count = count($feed_stock);

        // Guard 1 — the vendor's transfer-complete marker.
        if (!$sentinel && !$force) {
            return self::skip_reconcile('feed arrived without its END.XML completion marker');
        }

        // Guard 2 — a feed with no vehicles is a failure, not an empty yard.
        if ($item_count < 1) {
            return self::skip_reconcile('feed contained no vehicles');
        }

        // Guard 3 — a feed that suddenly halves is a truncated download.
        $last_count = (int) get_option('sm_vehicle_import_last_count', 0);
        $min_ratio  = (float) SM_Import_Settings::get('min_ratio');
        if (!$force && $last_count > 0 && $item_count < $last_count * $min_ratio) {
            return self::skip_reconcile(sprintf(
                'feed contained %d vehicles but the previous run saw %d (below the %d%% floor)',
                $item_count, $last_count, (int) ($min_ratio * 100)
            ));
        }

        $live    = self::live_stock_map();
        $missing = [];
        foreach ($live as $stock => $post_id) {
            if (!in_array((string) $stock, $feed_stock, true)) {
                $missing[(string) $stock] = $post_id;
            }
        }

        // Guard 4 — a dealer sells a handful a week, never a third of the yard.
        $max_ratio = (float) SM_Import_Settings::get('max_draft_ratio');
        $cap       = max(3, (int) floor(count($live) * $max_ratio));
        if (!$force && count($missing) > $cap) {
            return self::skip_reconcile(sprintf(
                '%d of %d published vehicles are missing from the feed, which exceeds the safety cap of %d',
                count($missing), count($live), $cap
            ));
        }

        foreach ($missing as $stock => $post_id) {
            wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
            update_post_meta($post_id, '_vehicle_import_drafted_at', current_time('mysql'));
            SM_Import_Log::bump('drafted');
            SM_Import_Log::info(sprintf('StockNo %s is no longer in the feed — moved to draft (post %d).', $stock, $post_id));
        }

        update_option('sm_vehicle_import_last_count', $item_count, false);

        return count($missing);
    }

    /**
     * Published vehicles that carry a stock number.
     *
     * Guard 5 lives here: a vehicle with no _vehicle_stock_no was created by
     * hand and is structurally invisible to reconciliation.
     */
    private static function live_stock_map() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT pm.meta_value AS stock_no, p.ID AS id
               FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = '_vehicle_stock_no'
                AND pm.meta_value <> ''
                AND p.post_type = 'vehicle'
                AND p.post_status = 'publish'"
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->stock_no] = (int) $row->id;
        }

        return $map;
    }

    private static function skip_reconcile($reason) {
        SM_Import_Log::set('skipped_reconcile', true);
        SM_Import_Log::warn(sprintf(
            'Reconciliation skipped — %s. Vehicle data was still updated; nothing was drafted. Review the feed and use "Reconcile now" to override.',
            $reason
        ));
        return 0;
    }
}

SM_Import_Vehicles::init();
