<?php
/**
 * Plugin Name: Storer Motors Branding
 * Description: Custom styling, fonts, icons, scripts, and custom post types for Storer Motors Ltd.
 */


// ─── VEHICLE CUSTOM POST TYPE ────────────────────────────────────────────────
add_action('init', function () {

    // Register 'vehicle' CPT
    register_post_type('vehicle', [
        'labels' => [
            'name'               => 'Vehicles',
            'singular_name'      => 'Vehicle',
            'add_new'            => 'Add New Vehicle',
            'add_new_item'       => 'Add New Vehicle',
            'edit_item'          => 'Edit Vehicle',
            'new_item'           => 'New Vehicle',
            'view_item'          => 'View Vehicle',
            'search_items'       => 'Search Vehicles',
            'not_found'          => 'No vehicles found',
            'not_found_in_trash' => 'No vehicles found in Trash',
            'all_items'          => 'All Vehicles',
            'archives'           => 'Used Vehicles',
            'menu_name'          => 'Vehicles',
        ],
        'public'             => true,
        'has_archive'        => true,
        'show_in_rest'       => true,
        'menu_icon'          => 'dashicons-car',
        'menu_position'      => 5,
        'supports'           => ['title', 'editor', 'thumbnail', 'custom-fields', 'excerpt'],
        'rewrite'            => ['slug' => 'inventory'],
        'show_in_nav_menus'  => true,
    ]);

    // Register vehicle meta fields for block bindings (core/post-meta).
    // auth_callback returning true is required for underscore-prefixed (protected) keys.
    $meta_args = [
        'object_subtype' => 'vehicle',
        'type'           => 'string',
        'single'         => true,
        'show_in_rest'   => true,
        'auth_callback'  => function() { return true; },
    ];
    foreach ([
        '_vehicle_price', '_vehicle_weekly', '_vehicle_year', '_vehicle_make',
        '_vehicle_model', '_vehicle_odometer', '_vehicle_transmission', '_vehicle_fuel',
        '_vehicle_body', '_vehicle_engine', '_vehicle_rego', '_vehicle_featured', '_vehicle_sold',
        '_vehicle_gallery',
    ] as $key) {
        register_post_meta('vehicle', $key, $meta_args);
    }

    // Register taxonomy: Vehicle Type (SUV, Sedan, Hatchback, etc.)
    register_taxonomy('vehicle_type', 'vehicle', [
        'labels' => [
            'name'          => 'Vehicle Types',
            'singular_name' => 'Vehicle Type',
            'all_items'     => 'All Vehicle Types',
            'edit_item'     => 'Edit Vehicle Type',
            'update_item'   => 'Update Vehicle Type',
            'add_new_item'  => 'Add New Vehicle Type',
            'new_item_name' => 'New Vehicle Type Name',
            'search_items'  => 'Search Vehicle Types',
            'menu_name'     => 'Vehicle Types',
        ],
        'hierarchical'  => true,
        'public'        => true,
        'show_in_rest'  => true,
        'rewrite'       => ['slug' => 'vehicle-type'],
    ]);

});

// ─── VEHICLE CUSTOM META FIELDS ─────────────────────────────────────────────
add_action('add_meta_boxes', function () {
    add_meta_box(
        'vehicle_details',
        'Vehicle Details',
        'sm_vehicle_meta_box_html',
        'vehicle',
        'normal',
        'high'
    );
    add_meta_box(
        'vehicle_gallery',
        'Vehicle Gallery',
        'sm_vehicle_gallery_meta_box_html',
        'vehicle',
        'side',
        'low'
    );
});

function sm_vehicle_meta_box_html($post) {
    $fields = [
        '_vehicle_price'        => ['label' => 'Price ($)', 'type' => 'text', 'placeholder' => 'e.g. 9995'],
        '_vehicle_weekly'       => ['label' => 'Weekly Finance ($/wk)', 'type' => 'text', 'placeholder' => 'e.g. 49'],
        '_vehicle_year'         => ['label' => 'Year', 'type' => 'text', 'placeholder' => 'e.g. 2013'],
        '_vehicle_make'         => ['label' => 'Make', 'type' => 'text', 'placeholder' => 'e.g. Subaru'],
        '_vehicle_model'        => ['label' => 'Model', 'type' => 'text', 'placeholder' => 'e.g. Forester AWD'],
        '_vehicle_odometer'     => ['label' => 'Odometer (km)', 'type' => 'text', 'placeholder' => 'e.g. 79571'],
        '_vehicle_transmission' => ['label' => 'Transmission', 'type' => 'select', 'options' => ['Auto', 'Manual', 'CVT']],
        '_vehicle_fuel'         => ['label' => 'Fuel Type', 'type' => 'select', 'options' => ['Petrol', 'Diesel', 'Hybrid', 'Plug-In Hybrid', 'Electric']],
        '_vehicle_body'         => ['label' => 'Body Type', 'type' => 'select', 'options' => ['SUV', 'Sedan', 'Hatchback', 'Convertible', 'Station Wagon', 'Coupe', 'Ute']],
        '_vehicle_engine'       => ['label' => 'Engine (cc)', 'type' => 'text', 'placeholder' => 'e.g. 2500'],
        '_vehicle_rego'         => ['label' => 'Registration', 'type' => 'text', 'placeholder' => 'e.g. KQA431'],
        '_vehicle_featured'     => ['label' => 'Show on Homepage', 'type' => 'checkbox'],
        '_vehicle_sold'         => ['label' => 'Sold', 'type' => 'checkbox'],
    ];

    wp_nonce_field('sm_vehicle_meta', 'sm_vehicle_nonce');
    echo '<table class="form-table" style="width:100%;">';
    foreach ($fields as $key => $field) {
        $value = get_post_meta($post->ID, $key, true);
        echo '<tr><th style="width:200px;padding:8px 0;"><label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label></th><td style="padding:8px 0;">';

        if ($field['type'] === 'text') {
            echo '<input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($field['placeholder'] ?? '') . '" style="width:300px;" />';
        } elseif ($field['type'] === 'select') {
            echo '<select id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" style="width:200px;">';
            echo '<option value="">— Select —</option>';
            foreach ($field['options'] as $opt) {
                echo '<option value="' . esc_attr($opt) . '"' . selected($value, $opt, false) . '>' . esc_html($opt) . '</option>';
            }
            echo '</select>';
        } elseif ($field['type'] === 'checkbox') {
            echo '<input type="checkbox" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="1"' . checked($value, '1', false) . ' />';
            echo '<label for="' . esc_attr($key) . '"> Yes</label>';
        }

        echo '</td></tr>';
    }
    echo '</table>';
}

function sm_vehicle_gallery_meta_box_html($post) {
    $gallery_json = get_post_meta($post->ID, '_vehicle_gallery', true);
    $ids_array    = ($gallery_json && $gallery_json !== '[]') ? json_decode($gallery_json, true) : [];
    wp_nonce_field('sm_vehicle_gallery', 'sm_vehicle_gallery_nonce');
    ?>
    <div id="sm-gallery-container">
        <div id="sm-gallery-preview" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
            <?php foreach ($ids_array as $id) :
                $thumb = wp_get_attachment_image_src($id, 'thumbnail');
                if (!$thumb) continue; ?>
                <div class="sm-gallery-thumb" data-id="<?php echo esc_attr($id); ?>" style="position:relative;width:70px;height:70px;">
                    <img src="<?php echo esc_url($thumb[0]); ?>" style="width:70px;height:70px;object-fit:cover;border-radius:4px;" />
                    <span class="sm-gallery-remove" data-id="<?php echo esc_attr($id); ?>" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.65);color:#fff;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:12px;line-height:1;">&times;</span>
                </div>
            <?php endforeach; ?>
        </div>
        <input type="hidden" id="sm-gallery-ids" name="_vehicle_gallery" value="<?php echo esc_attr($gallery_json ?: '[]'); ?>" />
        <button type="button" id="sm-gallery-add" class="button">Add / Edit Images</button>
        <p class="description" style="margin-top:8px;">These images appear in the gallery on the vehicle page. The Featured Image is always shown first.</p>
    </div>
    <script>
    (function ($) {
        var frame;
        var galleryIds = <?php echo ($gallery_json && $gallery_json !== '[]') ? wp_json_encode($ids_array) : '[]'; ?>;

        $('#sm-gallery-add').on('click', function () {
            if (frame) { frame.open(); return; }
            frame = wp.media({
                title: 'Select Gallery Images',
                button: { text: 'Add to Gallery' },
                multiple: true,
                library: { type: 'image' }
            });
            frame.on('select', function () {
                frame.state().get('selection').each(function (attachment) {
                    var id = attachment.id;
                    if (galleryIds.indexOf(id) === -1) {
                        galleryIds.push(id);
                        var sizes = attachment.attributes.sizes;
                        var url   = (sizes && sizes.thumbnail) ? sizes.thumbnail.url : attachment.attributes.url;
                        addThumb(id, url);
                    }
                });
                updateHidden();
            });
            frame.open();
        });

        $(document).on('click', '.sm-gallery-remove', function () {
            var id = parseInt($(this).data('id'), 10);
            galleryIds = galleryIds.filter(function (i) { return i !== id; });
            $(this).closest('.sm-gallery-thumb').remove();
            updateHidden();
        });

        function addThumb(id, url) {
            $('#sm-gallery-preview').append(
                '<div class="sm-gallery-thumb" data-id="' + id + '" style="position:relative;width:70px;height:70px;">' +
                '<img src="' + url + '" style="width:70px;height:70px;object-fit:cover;border-radius:4px;" />' +
                '<span class="sm-gallery-remove" data-id="' + id + '" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.65);color:#fff;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:12px;line-height:1;">&times;</span>' +
                '</div>'
            );
        }

        function updateHidden() {
            $('#sm-gallery-ids').val(JSON.stringify(galleryIds));
        }
    }(jQuery));
    </script>
    <?php
}

add_action('save_post_vehicle', function ($post_id) {
    if (!isset($_POST['sm_vehicle_nonce']) || !wp_verify_nonce($_POST['sm_vehicle_nonce'], 'sm_vehicle_meta')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $meta_keys = [
        '_vehicle_price', '_vehicle_weekly', '_vehicle_year', '_vehicle_make',
        '_vehicle_model', '_vehicle_odometer', '_vehicle_transmission', '_vehicle_fuel',
        '_vehicle_body', '_vehicle_engine', '_vehicle_rego', '_vehicle_featured', '_vehicle_sold',
    ];

    foreach ($meta_keys as $key) {
        if (isset($_POST[$key])) {
            update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
        } else {
            // Unchecked checkboxes won't be in $_POST
            update_post_meta($post_id, $key, '');
        }
    }

    // Save gallery IDs (stored as a JSON array of integers)
    if (
        isset($_POST['sm_vehicle_gallery_nonce']) &&
        wp_verify_nonce($_POST['sm_vehicle_gallery_nonce'], 'sm_vehicle_gallery') &&
        isset($_POST['_vehicle_gallery'])
    ) {
        $raw  = stripslashes($_POST['_vehicle_gallery']);
        $ids  = json_decode($raw, true);
        $ids  = is_array($ids) ? array_map('absint', $ids) : [];
        update_post_meta($post_id, '_vehicle_gallery', wp_json_encode($ids));
    }
});

// ─── FEATURED VEHICLES SHORTCODE ─────────────────────────────────────────────
function sm_featured_vehicles_shortcode() {
    $ids = get_posts([
        'post_type'      => 'vehicle',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [['key' => '_vehicle_featured', 'value' => '1']],
        'fields'         => 'ids',
    ]);
    if (!$ids) return '<p>No featured vehicles found.</p>';

    $h = '<div class="sm-vehicle-cards">';
    foreach ($ids as $id) {
        $title  = get_the_title($id);
        $link   = get_permalink($id);
        $img    = get_the_post_thumbnail($id, 'large', ['style' => 'width:100%;height:210px;object-fit:cover;']);
        $price  = get_post_meta($id, '_vehicle_price', true);
        $weekly = get_post_meta($id, '_vehicle_weekly', true);
        $odo    = get_post_meta($id, '_vehicle_odometer', true);
        $trans  = get_post_meta($id, '_vehicle_transmission', true);
        $fuel   = get_post_meta($id, '_vehicle_fuel', true);

        $specs = array_filter([
            $odo ? number_format((float) $odo) . ' km' : '',
            $trans,
            $fuel,
        ]);
        $spec_html = '';
        if ($specs) {
            $spans = '';
            foreach ($specs as $s) {
                $spans .= '<span>' . esc_html($s) . '</span>';
            }
            $spec_html = '<p class="sm-vehicle-specs">' . $spans . '</p>';
        }

        $h .= '<div class="sm-vehicle-card">';
        $h .= '<a href="' . esc_url($link) . '">' . $img . '</a>';
        $h .= '<div class="sm-vehicle-card-body">';
        $h .= '<h3><a href="' . esc_url($link) . '">' . esc_html($title) . '</a></h3>';
        $h .= $spec_html;
        $h .= '<div class="sm-vehicle-card-footer">';
        $h .= '<div class="sm-vehicle-prices">';
        if ($price)  $h .= '<p class="sm-vehicle-price">$' . esc_html(number_format((float) $price)) . '</p>';
        if ($weekly) $h .= '<p class="sm-vehicle-weekly">$' . esc_html($weekly) . '/wk</p>';
        $h .= '</div>';
        $h .= '<a class="sm-vehicle-more-link" href="' . esc_url($link) . '">View Vehicle</a>';
        $h .= '</div>';
        $h .= '</div>';
        $h .= '</div>';
    }
    $h .= '</div>';
    return $h;
}
add_shortcode('featured_vehicles', 'sm_featured_vehicles_shortcode');

// ─── VEHICLE META BLOCKS ──────────────────────────────────────────────────────
// Custom server-side blocks with uses_context['postId'] so the render callback
// always receives the correct post ID inside a query loop.
add_action('init', function () {

    register_block_type('sm/vehicle-price', [
        'uses_context'    => ['postId'],
        'render_callback' => function ($attrs, $content, $block) {
            $price = get_post_meta($block->context['postId'] ?? 0, '_vehicle_price', true);
            return $price
                ? '<p class="sm-vehicle-price">$' . esc_html(number_format((float) $price)) . '</p>'
                : '';
        },
    ]);

    register_block_type('sm/vehicle-weekly', [
        'uses_context'    => ['postId'],
        'render_callback' => function ($attrs, $content, $block) {
            $weekly = get_post_meta($block->context['postId'] ?? 0, '_vehicle_weekly', true);
            return $weekly
                ? '<p class="sm-vehicle-weekly">$' . esc_html($weekly) . '/wk</p>'
                : '';
        },
    ]);

    register_block_type('sm/vehicle-specs', [
        'uses_context'    => ['postId'],
        'render_callback' => function ($attrs, $content, $block) {
            $id    = $block->context['postId'] ?? 0;
            $odo   = get_post_meta($id, '_vehicle_odometer', true);
            $trans = get_post_meta($id, '_vehicle_transmission', true);
            $fuel  = get_post_meta($id, '_vehicle_fuel', true);
            $specs = array_filter([
                $odo ? number_format((float) $odo) . ' km' : '',
                $trans,
                $fuel,
            ]);
            // Output hidden filter data on the same element so no extra block is needed
            $meta = '<div class="sm-card-meta"'
                . ' data-make="'         . esc_attr(get_post_meta($id, '_vehicle_make', true))  . '"'
                . ' data-body="'         . esc_attr(get_post_meta($id, '_vehicle_body', true))  . '"'
                . ' data-fuel="'         . esc_attr($fuel)                                      . '"'
                . ' data-transmission="' . esc_attr($trans)                                     . '"'
                . ' data-price="'        . esc_attr(get_post_meta($id, '_vehicle_price', true)) . '"'
                . ' hidden></div>';
            if (!$specs) return $meta;
            $spans = implode('', array_map(fn($s) => '<span>' . esc_html($s) . '</span>', $specs));
            return $meta . '<p class="sm-vehicle-specs">' . $spans . '</p>';
        },
    ]);

    // Filter bar — dropdowns built from live vehicle stock
    register_block_type('sm/featured-vehicles', [
        'render_callback' => function () {
            return sm_featured_vehicles_shortcode();
        },
    ]);

    // Gallery — main image switcher + thumbnails + lightbox trigger
    register_block_type('sm/vehicle-gallery', [
        'uses_context'    => ['postId'],
        'render_callback' => function ($attrs, $content, $block) {
            $post_id      = $block->context['postId'] ?? get_the_ID();
            $gallery_json = get_post_meta($post_id, '_vehicle_gallery', true);
            $ids          = ($gallery_json && $gallery_json !== '[]') ? json_decode($gallery_json, true) : [];

            // Prepend the featured image if not already in the list
            $featured_id = get_post_thumbnail_id($post_id);
            if ($featured_id && !in_array((int) $featured_id, array_map('intval', $ids))) {
                array_unshift($ids, (int) $featured_id);
            }

            if (empty($ids)) return '';

            $all_imgs = [];
            foreach ($ids as $id) {
                $full = wp_get_attachment_image_src($id, 'large');
                $alt  = get_post_meta($id, '_wp_attachment_image_alt', true) ?: get_the_title($post_id);
                if ($full) $all_imgs[] = ['src' => $full[0], 'alt' => $alt];
            }
            if (empty($all_imgs)) return '';

            $h = '<div class="sm-vehicle-gallery">';

            // Main viewer
            $h .= '<div class="sm-gallery-main">';
            foreach ($all_imgs as $i => $img) {
                $h .= '<img class="sm-gallery-main-img' . ($i === 0 ? ' active' : '') . '"'
                    . ' src="' . esc_url($img['src']) . '"'
                    . ' alt="' . esc_attr($img['alt']) . '"'
                    . ' data-index="' . $i . '" />';
            }
            if (count($all_imgs) > 1) {
                $h .= '<button class="sm-gallery-nav sm-gallery-prev" aria-label="Previous image"></button>';
                $h .= '<button class="sm-gallery-nav sm-gallery-next" aria-label="Next image"></button>';
            }
            $h .= '</div>';

            // Thumbnails (only if more than one image)
            if (count($ids) > 1) {
                $h .= '<div class="sm-gallery-thumbs">';
                foreach ($ids as $i => $id) {
                    $thumb = wp_get_attachment_image_src($id, 'thumbnail');
                    if (!$thumb) continue;
                    $h .= '<img class="sm-gallery-thumb' . ($i === 0 ? ' active' : '') . '"'
                        . ' src="' . esc_url($thumb[0]) . '"'
                        . ' data-index="' . $i . '" />';
                }
                $h .= '</div>';
            }

            $h .= '</div>';
            return $h;
        },
    ]);

    // Full spec table for single vehicle page
    register_block_type('sm/vehicle-details', [
        'uses_context'    => ['postId'],
        'render_callback' => function ($attrs, $content, $block) {
            $id    = $block->context['postId'] ?? get_the_ID();
            $rows  = [
                'Year'         => get_post_meta($id, '_vehicle_year', true),
                'Make'         => get_post_meta($id, '_vehicle_make', true),
                'Model'        => get_post_meta($id, '_vehicle_model', true),
                'Body Type'    => get_post_meta($id, '_vehicle_body', true),
                'Odometer'     => (function () use ($id) {
                    $v = get_post_meta($id, '_vehicle_odometer', true);
                    return $v ? number_format((float) $v) . ' km' : '';
                })(),
                'Transmission' => get_post_meta($id, '_vehicle_transmission', true),
                'Fuel Type'    => get_post_meta($id, '_vehicle_fuel', true),
                'Engine'       => (function () use ($id) {
                    $v = get_post_meta($id, '_vehicle_engine', true);
                    return $v ? $v . ' cc' : '';
                })(),
                'Registration' => get_post_meta($id, '_vehicle_rego', true),
            ];
            $rows = array_filter($rows);
            if (empty($rows)) return '';

            $h = '<dl class="sm-vehicle-details">';
            foreach ($rows as $label => $value) {
                $h .= '<div class="sm-vehicle-detail-row">'
                    . '<dt>' . esc_html($label) . '</dt>'
                    . '<dd>' . esc_html($value) . '</dd>'
                    . '</div>';
            }
            $h .= '</dl>';
            return $h;
        },
    ]);

    register_block_type('sm/vehicle-filters', [
        'render_callback' => function () {
            $ids = get_posts([
                'post_type'      => 'vehicle',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
            ]);

            $makes         = [];
            $bodies        = [];
            $fuels         = [];
            $transmissions = [];
            $prices        = [];

            foreach ($ids as $id) {
                $make  = get_post_meta($id, '_vehicle_make', true);
                $body  = get_post_meta($id, '_vehicle_body', true);
                $fuel  = get_post_meta($id, '_vehicle_fuel', true);
                $trans = get_post_meta($id, '_vehicle_transmission', true);
                $price = get_post_meta($id, '_vehicle_price', true);
                if ($make)  $makes[]         = $make;
                if ($body)  $bodies[]        = $body;
                if ($fuel)  $fuels[]         = $fuel;
                if ($trans) $transmissions[] = $trans;
                if ($price) $prices[]        = (float) $price;
            }

            $makes         = array_unique($makes);
            $bodies        = array_unique($bodies);
            $fuels         = array_unique($fuels);
            $transmissions = array_unique($transmissions);
            sort($makes);
            sort($bodies);
            sort($fuels);
            sort($transmissions);

            $h = '<div class="sm-vehicle-filters"><div class="sm-vehicle-filters-inner">';

            if ($makes) {
                $h .= '<select class="sm-filter" data-filter="make"><option value="">Any Make</option>';
                foreach ($makes as $v) $h .= '<option value="' . esc_attr($v) . '">' . esc_html($v) . '</option>';
                $h .= '</select>';
            }
            if ($bodies) {
                $h .= '<select class="sm-filter" data-filter="body"><option value="">Any Body Type</option>';
                foreach ($bodies as $v) $h .= '<option value="' . esc_attr($v) . '">' . esc_html($v) . '</option>';
                $h .= '</select>';
            }
            if ($fuels) {
                $h .= '<select class="sm-filter" data-filter="fuel"><option value="">Any Fuel Type</option>';
                foreach ($fuels as $v) $h .= '<option value="' . esc_attr($v) . '">' . esc_html($v) . '</option>';
                $h .= '</select>';
            }
            if ($transmissions) {
                $h .= '<select class="sm-filter" data-filter="transmission"><option value="">Any Transmission</option>';
                foreach ($transmissions as $v) $h .= '<option value="' . esc_attr($v) . '">' . esc_html($v) . '</option>';
                $h .= '</select>';
            }
            if ($prices) {
                $min = min($prices);
                $h .= '<select class="sm-filter" data-filter="max-price"><option value="">Any Price</option>';
                foreach ([10000, 15000, 20000, 25000, 30000, 40000, 50000] as $band) {
                    if ($min < $band) {
                        $h .= '<option value="' . $band . '">Under $' . number_format($band) . '</option>';
                    }
                }
                $h .= '</select>';
            }

            $h .= '<input class="sm-filter-search" type="search" placeholder="Search vehicles..." autocomplete="off">';
            $h .= '<div class="sm-filter-actions">';
            $h .= '<span class="sm-filter-count"></span>';
            $h .= '<button class="sm-filter-reset" type="button">Reset</button>';
            $h .= '</div></div></div>';
            return $h;
        },
    ]);

});

// ─── FRONT-END ASSETS ────────────────────────────────────────────────────────
// ─── FINANCE APPLICATION (CONTACT FORM 7) ───────────────────────────────────
// Contact Form 7 owns submission/validation; the markup below adds the
// two-step experience and Flamingo stores submissions when it is active.
function sm_finance_application_form_markup() {
    return <<<'HTML'
<div class="sm-finance-form" data-sm-finance-form>
<div class="sm-finance-progress" aria-label="Application progress">
<div class="sm-finance-progress-item is-active" data-progress-step="1"><span>1</span>About your purchase</div>
<div class="sm-finance-progress-item" data-progress-step="2"><span>2</span>More about you</div>
</div>
<p class="sm-finance-required-note">Fields marked with <strong>*</strong> are mandatory.</p>

<section class="sm-finance-step is-active" data-finance-step="1">
<h2>About your purchase</h2>
<div class="sm-form-row">
<div class="sm-form-group" data-sm-required><label>What is your loan for? *</label>[select* loan-purpose first_as_label "Select..." "Car" "Motorbike" "Boat" "Wedding" "Renovation" "Debt Consolidation" "Travel" "Caravan" "Other"]</div>
<div class="sm-form-group sm-conditional" data-sm-show="vehicle-information" data-sm-required><label>What vehicle information do you have? *</label>[select vehicle-information first_as_label "Select..." "None - I'm still looking" "Registration" "Trade Me Listing" "Make, Model, Year" "Auto Trader" "VIN"]</div>
</div>
<div class="sm-form-group sm-conditional" data-sm-show="vehicle-registration" data-sm-required><label>Registration *</label>[text vehicle-registration placeholder "Registration"]</div>
<div class="sm-form-group sm-conditional" data-sm-show="vehicle-trademe" data-sm-required><label>Trade Me listing # *</label>[text vehicle-trademe placeholder "e.g. 790451937"]</div>
<div class="sm-form-row sm-conditional" data-sm-show="vehicle-make-model">
<div class="sm-form-group" data-sm-required><label>Make *</label>[text vehicle-make placeholder "Make"]</div>
<div class="sm-form-group" data-sm-required><label>Model *</label>[text vehicle-model placeholder "Model"]</div>
<div class="sm-form-group" data-sm-required><label>Year *</label>[number vehicle-year min:1900 max:2100 placeholder "Year"]</div>
</div>

<hr><h2>About the loan</h2>
<div class="sm-form-row">
<div class="sm-form-group" data-sm-required><label>Purchase/Refinance amount *</label>[number* purchase-amount min:1 step:1 placeholder "Purchase/Refinance amount"]</div>
<div class="sm-form-group"><label>Deposit</label>[number deposit-amount min:0 step:1 placeholder "Deposit"]</div>
</div>
<div class="sm-finance-total"><span>Loan amount</span><strong data-sm-loan-total>$0</strong></div>
[hidden loan-amount]

<hr><h2>About you</h2>
<div class="sm-form-row">
<div class="sm-form-group" data-sm-required><label>Title *</label>[select* title first_as_label "Select..." "Mr" "Mrs" "Ms" "Miss" "Mx"]</div>
<div class="sm-form-group" data-sm-required><label>First name *</label>[text* first-name autocomplete:given-name placeholder "As appears on your driver licence"]</div>
<div class="sm-form-group"><label>Middle name</label>[text middle-name placeholder "As appears on your driver licence"]</div>
<div class="sm-form-group" data-sm-required><label>Last name *</label>[text* last-name autocomplete:family-name placeholder "As appears on your driver licence"]</div>
<div class="sm-form-group" data-sm-required><label>Date of birth *</label>[date* date-of-birth]</div>
<div class="sm-form-group" data-sm-required><label>Email address *</label>[email* email-address autocomplete:email placeholder "Email address"]</div>
<div class="sm-form-group" data-sm-required><label>Mobile number *</label>[tel* mobile-number autocomplete:tel placeholder "e.g. 021 123456"]</div>
<div class="sm-form-group" data-sm-required><label>Relationship status *</label>[select* relationship-status first_as_label "Select..." "Married" "Separated" "Single" "De Facto"]</div>
<div class="sm-form-group" data-sm-required><label>No. of dependants *</label>[select* dependants first_as_label "Select..." "0" "1" "2" "3" "4" "5" "6" "7" "8" "9+"]</div>
</div>
<div class="sm-form-consent" data-sm-required>[acceptance terms-accepted] I consent to Storer Motors Ltd collecting and using this information to assess and respond to my finance application. [/acceptance]</div>
<div class="sm-finance-actions sm-finance-actions-next"><button type="button" class="sm-finance-next">Next Step</button></div>
</section>

<section class="sm-finance-step" data-finance-step="2" hidden>
<h2>Driver licence details</h2>
<div class="sm-form-row">
<div class="sm-form-group" data-sm-required><label>Driver licence *</label>[select* licence-type first_as_label "Select..." "None" "Learner" "Restricted" "Full" "Overseas" "Provisional"]</div>
<div class="sm-form-group sm-conditional" data-sm-show="licence-details" data-sm-required><label>Driver licence number *</label>[text licence-number placeholder "e.g. DC123456"]</div>
<div class="sm-form-group sm-conditional" data-sm-show="licence-details" data-sm-required><label>Version number *</label>[text licence-version placeholder "e.g. 345"]</div>
</div>

<hr><h2>Personal details</h2>
<div class="sm-form-row">
<div class="sm-form-group" data-sm-required><label>Living situation *</label>[select* living-situation first_as_label "Select..." "Rent" "Board" "Own" "Parents" "Other"]</div>
<div class="sm-form-group sm-form-group-wide" data-sm-required><label>Current address *</label>[text* current-address autocomplete:street-address placeholder "Street address, suburb, city and postcode"]</div>
<div class="sm-form-group" data-sm-required><label>Years at current address *</label>[number* address-years min:0 max:99 placeholder "Years"]</div>
<div class="sm-form-group" data-sm-required><label>Months *</label>[number* address-months min:0 max:11 placeholder "Months"]</div>
<div class="sm-form-group"><label>Mortgage or rent payment</label>[number housing-payment min:0 step:1 placeholder "Amount"]</div>
<div class="sm-form-group"><label>Payment frequency</label>[select housing-frequency first_as_label "Select..." "Weekly" "Fortnightly" "Monthly"]</div>
</div>

<hr><h2>Employment details</h2>
<div class="sm-form-row">
<div class="sm-form-group" data-sm-required><label>Employment type *</label>[select* employment-type first_as_label "Select..." "Full time" "Part time" "Self employed" "Benefit" "Other"]</div>
<div class="sm-form-group" data-sm-required><label>Occupation *</label>[text* occupation placeholder "Occupation"]</div>
<div class="sm-form-group sm-conditional" data-sm-show="employer" data-sm-required><label>Employer *</label>[text current-employer placeholder "Employer"]</div>
<div class="sm-form-group sm-conditional" data-sm-show="employer"><label>Employer address</label>[text employer-address placeholder "Employer address"]</div>
<div class="sm-form-group sm-conditional" data-sm-show="self-employed" data-sm-required><label>Name of business *</label>[text business-name placeholder "Name of business"]</div>
<div class="sm-form-group sm-conditional" data-sm-show="self-employed"><label>Business address</label>[text business-address placeholder "Business address"]</div>
<div class="sm-form-group" data-sm-required><label>Years at current job *</label>[number* employment-years min:0 max:99 placeholder "Years"]</div>
<div class="sm-form-group" data-sm-required><label>Months *</label>[number* employment-months min:0 max:11 placeholder "Months"]</div>
<div class="sm-form-group" data-sm-required><label>Net income *</label>[number* net-income min:0 step:1 placeholder "Amount"]</div>
<div class="sm-form-group" data-sm-required><label>Income frequency *</label>[select* income-frequency first_as_label "Select..." "Weekly" "Fortnightly" "Monthly"]</div>
<div class="sm-form-group"><label>Previous employer</label>[text previous-employer placeholder "Previous employer or business"]</div>
<div class="sm-form-group"><label>Previous employer address</label>[text previous-employer-address placeholder "Previous employer address"]</div>
</div>

<hr><h2>Assets and liabilities</h2>
<p class="sm-finance-help">Leave a row blank when it does not apply.</p>
<div class="sm-finance-assets" role="group" aria-label="Assets and liabilities">
<div class="sm-finance-assets-head"><span>Item</span><span>Value</span><span>Amount owed</span><span>Monthly repayments</span></div>
<div class="sm-finance-asset-row"><strong>Cash in bank</strong>[number cash-value min:0 step:1 placeholder "Value"]<span></span><span></span></div>
<div class="sm-finance-asset-row"><strong>Home</strong>[number home-value min:0 step:1 placeholder "Value"][number home-owed min:0 step:1 placeholder "Amount owed"][number home-repayment min:0 step:1 placeholder "Monthly"]</div>
<div class="sm-finance-asset-row"><strong>Vehicles</strong>[number vehicles-value min:0 step:1 placeholder "Value"][number vehicles-owed min:0 step:1 placeholder "Amount owed"][number vehicles-repayment min:0 step:1 placeholder "Monthly"]</div>
<div class="sm-finance-asset-row"><strong>Boat</strong>[number boat-value min:0 step:1 placeholder "Value"][number boat-owed min:0 step:1 placeholder "Amount owed"][number boat-repayment min:0 step:1 placeholder "Monthly"]</div>
<div class="sm-finance-asset-row"><strong>Hire purchases</strong><span></span>[number hire-purchase-owed min:0 step:1 placeholder "Amount owed"][number hire-purchase-repayment min:0 step:1 placeholder "Monthly"]</div>
<div class="sm-finance-asset-row"><strong>Personal loan</strong><span></span>[number personal-loan-owed min:0 step:1 placeholder "Amount owed"][number personal-loan-repayment min:0 step:1 placeholder "Monthly"]</div>
<div class="sm-finance-asset-row"><strong>Credit card</strong><span></span>[number credit-card-owed min:0 step:1 placeholder "Amount owed"][number credit-card-repayment min:0 step:1 placeholder "Monthly"]</div>
<div class="sm-finance-asset-row"><strong>Other</strong>[number other-value min:0 step:1 placeholder "Value"][number other-owed min:0 step:1 placeholder "Amount owed"][number other-repayment min:0 step:1 placeholder "Monthly"]</div>
</div>

<hr><h2>Additional notes</h2>
<div class="sm-form-group"><label>Anything else that will help us assess your application</label>[textarea additional-notes placeholder "Additional information"]</div>
<div class="sm-form-consent" data-sm-required>[acceptance declaration-accepted] I confirm that the information supplied is correct, complete and not misleading, and that I am not an undischarged bankrupt. [/acceptance]</div>
<div class="sm-finance-actions"><button type="button" class="sm-finance-back">Previous Step</button>[submit "Complete my application"]</div>
</section>
</div>
HTML;
}

function sm_finance_application_mail_body() {
    return <<<'MAIL'
A new finance application has been submitted.

Applicant: [title] [first-name] [middle-name] [last-name]
Email: [email-address]
Mobile: [mobile-number]
Loan purpose: [loan-purpose]
Purchase/refinance amount: $[purchase-amount]
Deposit: $[deposit-amount]
Requested loan amount: $[loan-amount]

The complete application, including sensitive financial information, is stored in WordPress under Flamingo > Inbound Messages. It is intentionally not reproduced in this email.

Submitted from: [_url]
MAIL;
}

function sm_finance_page_content() {
    return <<<'BLOCKS'
<!-- wp:group {"className":"sm-page-hero","layout":{"type":"default"}} -->
<div class="wp-block-group sm-page-hero"><!-- wp:group {"className":"sm-page-hero-inner","layout":{"type":"default"}} -->
<div class="wp-block-group sm-page-hero-inner"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">APPLY FOR FINANCE ONLINE</h1>
<!-- /wp:heading --><!-- wp:paragraph -->
<p>Complete our two-step application and our team will be in touch.</p>
<!-- /wp:paragraph --></div><!-- /wp:group --></div>
<!-- /wp:group -->
<!-- wp:group {"className":"sm-finance-page","layout":{"type":"constrained"}} -->
<div class="wp-block-group sm-finance-page"><!-- wp:heading -->
<h2 class="wp-block-heading">Finance Application</h2>
<!-- /wp:heading --><!-- wp:paragraph -->
<p>Please provide accurate information. Your application will be reviewed privately by Storer Motors Ltd.</p>
<!-- /wp:paragraph --><!-- wp:shortcode -->
[contact-form-7 title="Storer Motors Finance Application"]
<!-- /wp:shortcode --></div><!-- /wp:group -->
BLOCKS;
}

// Versioned provisioning creates the CF7 form and its dedicated application
// page. The branded navigation adds it beneath Finance as a submenu.
add_action('init', function () {
    $version = 5;
    if ((int) get_option('sm_finance_application_version', 0) >= $version || !class_exists('WPCF7_ContactForm')) return;

    $contact_form = null;
    $saved_id = absint(get_option('sm_finance_application_form_id', 0));
    if ($saved_id) $contact_form = WPCF7_ContactForm::get_instance($saved_id);
    if (!$contact_form) {
        foreach (WPCF7_ContactForm::find() as $candidate) {
            if ($candidate->title() === 'Storer Motors Finance Application') {
                $contact_form = $candidate;
                break;
            }
        }
    }
    if (!$contact_form) {
        $contact_form = WPCF7_ContactForm::get_template(['title' => 'Storer Motors Finance Application', 'locale' => determine_locale()]);
    }

    $contact_form->set_title('Storer Motors Finance Application');
    $properties = $contact_form->get_properties();
    $properties['form'] = sm_finance_application_form_markup();
    $properties['mail'] = array_merge($properties['mail'], [
        'subject' => 'New finance application - [first-name] [last-name]',
        'recipient' => 'sales@storermotors.co.nz',
        'additional_headers' => 'Reply-To: [email-address]',
        'body' => sm_finance_application_mail_body(),
        'use_html' => 0,
        'exclude_blank' => 1,
    ]);
    $properties['mail_2']['active'] = false;
    $contact_form->set_properties($properties);
    $form_id = $contact_form->save();
    if (!$form_id || is_wp_error($form_id)) return;

    update_option('sm_finance_application_form_id', (int) $form_id, false);
    $finance_page = get_page_by_path('finance', OBJECT, 'page');

    // Version 1 temporarily replaced the Finance information page. If that
    // version ran, restore the latest pre-form revision before adding the child.
    if ($finance_page && str_contains($finance_page->post_content, 'Storer Motors Finance Application')) {
        $revisions = wp_get_post_revisions($finance_page->ID, [
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        foreach ($revisions as $revision) {
            if (
                trim($revision->post_content) !== '' &&
                !str_contains($revision->post_content, 'Storer Motors Finance Application')
            ) {
                wp_update_post([
                    'ID'           => $finance_page->ID,
                    'post_content' => $revision->post_content,
                ]);
                $finance_page = get_post($finance_page->ID);
                break;
            }
        }
    }

    $application_page = get_page_by_path('finance-application', OBJECT, 'page');
    if (!$application_page) {
        $application_page = get_page_by_path('finance/finance-application', OBJECT, 'page');
    }
    $page_data = [
        'post_type' => 'page',
        'post_title' => 'Apply for Finance',
        'post_name' => 'finance-application',
        'post_parent' => 0,
        'post_content' => sm_finance_page_content(),
        'post_status' => 'publish',
    ];
    if ($application_page) $page_data['ID'] = $application_page->ID;
    $page_id = $application_page ? wp_update_post($page_data) : wp_insert_post($page_data);
    if (!$page_id || is_wp_error($page_id)) return;
    update_post_meta($page_id, '_wp_page_template', 'page-storermotors');
    update_option('sm_finance_application_version', $version, false);
}, 99);

// Keep the generated child page on the same branded block template as every
// other public Storer Motors page, even after future page edits/imports.
add_action('init', function () {
    $application_page = get_page_by_path('finance-application', OBJECT, 'page');
    if (!$application_page) {
        $application_page = get_page_by_path('finance/finance-application', OBJECT, 'page');
    }
    if ($application_page && get_post_meta($application_page->ID, '_wp_page_template', true) !== 'page-storermotors') {
        update_post_meta($application_page->ID, '_wp_page_template', 'page-storermotors');
        clean_post_cache($application_page->ID);
    }
}, 100);

// ─── TRADE-IN FORM (CONTACT FORM 7) ─────────────────────────────────────────
function sm_trade_in_form_markup() {
    return <<<'HTML'
<div class="sm-trade-in-form">
<h2>Your details</h2>
<div class="sm-form-row">
<div class="sm-form-group"><label>Name *</label>[text* trade-name autocomplete:name placeholder "Your full name"]</div>
<div class="sm-form-group"><label>Contact number</label>[tel trade-phone autocomplete:tel placeholder "e.g. 021 123456"]</div>
<div class="sm-form-group sm-form-group-wide"><label>Email *</label>[email* trade-email autocomplete:email placeholder "Your email address"]</div>
</div>

<hr><h2>Car details</h2>
<p class="sm-trade-in-help">All car detail fields are required.</p>
<div class="sm-form-row">
<div class="sm-form-group"><label>Make *</label>[text* vehicle-make placeholder "e.g. Toyota"]</div>
<div class="sm-form-group"><label>Model *</label>[text* vehicle-model placeholder "e.g. Corolla"]</div>
<div class="sm-form-group"><label>Year *</label>[number* vehicle-year min:1900 max:2100 placeholder "e.g. 2018"]</div>
<div class="sm-form-group"><label>Colour *</label>[text* vehicle-colour placeholder "e.g. White"]</div>
<div class="sm-form-group"><label>Kilometres *</label>[number* vehicle-kms min:0 step:1 placeholder "e.g. 85000"]</div>
<div class="sm-form-group"><label>Condition *</label>[text* vehicle-condition placeholder "e.g. Good"]</div>
</div>

<div class="sm-trade-in-actions">[submit "Submit Trade-In"]</div>
</div>
HTML;
}

function sm_trade_in_mail_body() {
    return <<<'MAIL'
A new trade-in enquiry has been submitted.

Name: [trade-name]
Contact number: [trade-phone]
Email: [trade-email]

Car details
Make: [vehicle-make]
Model: [vehicle-model]
Year: [vehicle-year]
Colour: [vehicle-colour]
Kilometres: [vehicle-kms]
Condition: [vehicle-condition]

Submitted from: [_url]
MAIL;
}

function sm_trade_in_page_content() {
    return <<<'BLOCKS'
<!-- wp:group {"className":"sm-page-hero","layout":{"type":"default"}} -->
<div class="wp-block-group sm-page-hero"><!-- wp:group {"className":"sm-page-hero-inner","layout":{"type":"default"}} -->
<div class="wp-block-group sm-page-hero-inner"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">TRADE IN YOUR VEHICLE</h1>
<!-- /wp:heading --><!-- wp:paragraph -->
<p>Tell us about your vehicle and our team will be in touch.</p>
<!-- /wp:paragraph --></div><!-- /wp:group --></div>
<!-- /wp:group -->
<!-- wp:group {"className":"sm-trade-in-page","layout":{"type":"constrained"}} -->
<div class="wp-block-group sm-trade-in-page"><!-- wp:heading -->
<h2 class="wp-block-heading">Trade-In Form</h2>
<!-- /wp:heading --><!-- wp:paragraph -->
<p>Complete the details below to request a trade-in appraisal.</p>
<!-- /wp:paragraph --><!-- wp:shortcode -->
[contact-form-7 title="Storer Motors Trade-In Form"]
<!-- /wp:shortcode --></div><!-- /wp:group -->
BLOCKS;
}

add_action('init', function () {
    $version = 1;
    if ((int) get_option('sm_trade_in_form_version', 0) >= $version || !class_exists('WPCF7_ContactForm')) return;

    $contact_form = null;
    $saved_id = absint(get_option('sm_trade_in_form_id', 0));
    if ($saved_id) $contact_form = WPCF7_ContactForm::get_instance($saved_id);
    if (!$contact_form) {
        foreach (WPCF7_ContactForm::find() as $candidate) {
            if ($candidate->title() === 'Storer Motors Trade-In Form') {
                $contact_form = $candidate;
                break;
            }
        }
    }
    if (!$contact_form) {
        $contact_form = WPCF7_ContactForm::get_template([
            'title'  => 'Storer Motors Trade-In Form',
            'locale' => determine_locale(),
        ]);
    }

    $contact_form->set_title('Storer Motors Trade-In Form');
    $properties = $contact_form->get_properties();
    $properties['form'] = sm_trade_in_form_markup();
    $properties['mail'] = array_merge($properties['mail'], [
        'subject'            => 'New trade-in enquiry - [vehicle-year] [vehicle-make] [vehicle-model]',
        'recipient'          => 'sales@storermotors.co.nz',
        'additional_headers' => 'Reply-To: [trade-email]',
        'body'               => sm_trade_in_mail_body(),
        'use_html'           => 0,
        'exclude_blank'      => 1,
    ]);
    $properties['mail_2']['active'] = false;
    $contact_form->set_properties($properties);
    $form_id = $contact_form->save();
    if (!$form_id || is_wp_error($form_id)) return;
    update_option('sm_trade_in_form_id', (int) $form_id, false);

    $trade_in_page = get_page_by_path('trade-in', OBJECT, 'page');
    $page_data = [
        'post_type'    => 'page',
        'post_title'   => 'Trade-In',
        'post_name'    => 'trade-in',
        'post_parent'  => 0,
        'post_content' => sm_trade_in_page_content(),
        'post_status'  => 'publish',
    ];
    if ($trade_in_page) $page_data['ID'] = $trade_in_page->ID;
    $page_id = $trade_in_page ? wp_update_post($page_data) : wp_insert_post($page_data);
    if (!$page_id || is_wp_error($page_id)) return;
    update_post_meta($page_id, '_wp_page_template', 'page-storermotors');
    update_option('sm_trade_in_form_version', $version, false);
}, 101);

add_action('init', function () {
    $trade_in_page = get_page_by_path('trade-in', OBJECT, 'page');
    if ($trade_in_page && get_post_meta($trade_in_page->ID, '_wp_page_template', true) !== 'page-storermotors') {
        update_post_meta($trade_in_page->ID, '_wp_page_template', 'page-storermotors');
        clean_post_cache($trade_in_page->ID);
    }
}, 102);

// Recalculate the requested amount server-side rather than trusting the hidden browser field.
add_filter('wpcf7_posted_data', function ($posted_data) {
    if (!isset($posted_data['purchase-amount'])) return $posted_data;
    $purchase = max(0, (float) $posted_data['purchase-amount']);
    $deposit = max(0, (float) ($posted_data['deposit-amount'] ?? 0));
    $posted_data['loan-amount'] = (string) max(0, $purchase - $deposit);
    return $posted_data;
});

function sm_validate_finance_conditional_field($result, $tag) {
    $submission = class_exists('WPCF7_Submission') ? WPCF7_Submission::get_instance() : null;
    if (!$submission) return $result;
    $data = $submission->get_posted_data();
    $required = [];
    $vehicle_info = $data['vehicle-information'] ?? '';
    if ($vehicle_info === 'Registration') $required[] = 'vehicle-registration';
    if ($vehicle_info === 'Trade Me Listing') $required[] = 'vehicle-trademe';
    if (in_array($vehicle_info, ['Make, Model, Year', 'Auto Trader', 'VIN'], true)) {
        $required = array_merge($required, ['vehicle-make', 'vehicle-model', 'vehicle-year']);
    }
    if (($data['licence-type'] ?? '') !== '' && ($data['licence-type'] ?? '') !== 'None') {
        $required = array_merge($required, ['licence-number', 'licence-version']);
    }
    if (in_array($data['employment-type'] ?? '', ['Full time', 'Part time'], true)) $required[] = 'current-employer';
    if (($data['employment-type'] ?? '') === 'Self employed') $required[] = 'business-name';
    if (in_array($tag->name, $required, true) && trim((string) ($data[$tag->name] ?? '')) === '') {
        $result->invalidate($tag, 'Please complete this field.');
    }
    return $result;
}
foreach (['text', 'number'] as $sm_finance_tag_type) {
    add_filter('wpcf7_validate_' . $sm_finance_tag_type, 'sm_validate_finance_conditional_field', 20, 2);
}

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('sm-google-fonts',
        'https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,400;0,600;0,700;0,800;0,900;1,700&family=Barlow:wght@300;400;500;600;700&display=swap',
        [], null);
    wp_enqueue_style('sm-font-awesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
        [], '6.5.1');
    wp_register_style('sm-branding', false);
    wp_enqueue_style('sm-branding');
    wp_add_inline_style('sm-branding', sm_get_css());
    wp_register_script('sm-scripts', false, [], false, true);
    wp_enqueue_script('sm-scripts');
    wp_add_inline_script('sm-scripts', sm_get_js());
});

// ─── EDITOR ASSETS ───────────────────────────────────────────────────────────
add_action('enqueue_block_editor_assets', function () {
    wp_enqueue_style('sm-google-fonts',
        'https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,400;0,600;0,700;0,800;0,900;1,700&family=Barlow:wght@300;400;500;600;700&display=swap',
        [], null);
    wp_enqueue_style('sm-font-awesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
        [], '6.5.1');
    wp_register_style('sm-branding-editor', false);
    wp_enqueue_style('sm-branding-editor');
    wp_add_inline_style('sm-branding-editor', sm_get_css());
    wp_register_style('sm-editor-layout', false);
    wp_enqueue_style('sm-editor-layout');
    wp_add_inline_style('sm-editor-layout', '
        .block-editor-block-list__layout.is-root-container > :where(:not(.alignleft):not(.alignright):not(.alignfull)) {
            max-width: 100vw !important;
            margin-left: auto !important;
            margin-right: auto !important;
        }
    ');
});

// ─── CSS ─────────────────────────────────────────────────────────────────────
function sm_get_css() {
    return '
/* === CSS CUSTOM PROPERTIES === */
:root {
    --sm-yellow:        #F5C518;
    --sm-yellow-dark:   #D9A800;
    --sm-bronze:   #644C00;
    --sm-yellow-pale:   #FFF8DC;
    --sm-black:         #111111;
    --sm-dark:          #1A1A1A;
    --sm-dark-2:        #222222;
    --sm-white:         #FFFFFF;
    --sm-light:         #E2E2E4;
    --sm-light-2:       #F3F3F5;
    --sm-bg:         #F9F9F9;
    --sm-gray:          #7D7D7D;
    --sm-gray-light:    #999999;
    --sm-border:        #E0E0E0;
    --sm-font-head:     "Bebas-Neue", sans-serif;
    --sm-font-body:     "Inter", sans-serif;
    --sm-max:           1300px;
    --sm-radius:        8px;
    --sm-radius-lg:     12px;
    --sm-shadow:        0 2px 12px rgba(0,0,0,0.08);
    --sm-shadow-md:     0 4px 20px rgba(0,0,0,0.12);
    --sm-shadow-lg:     0 8px 32px rgba(0,0,0,0.16);
    --sm-transition:    all 0.25s ease;
}

/* === RESET & BASE === */
.has-global-padding {
    padding-right: var(--wp--style--root--padding-right);
    padding-left: var(--wp--style--root--padding-left);
    margin-top: 0px !important;
}
#wpadminbar {
    display: none !important;
}
    html {
    margin-top: 0px !important;
}
*,
*::before,
*::after {
    box-sizing: border-box;
}
.BadgeContainer__Inner-sc-80e5fb37-1 .cneNwX .es-badge-container {
margin: 0px !important;
}

body {
    font-family: var(--sm-font-body), system-ui, sans-serif !important;
    color: var(--sm-black);
    line-height: 1.6;
    margin: 0 !important;
    padding: 0 !important;
    background: var(--sm-white);
    -webkit-font-smoothing: antialiased;
}

.wp-site-blocks {
    padding: 0 !important;
}

.has-global-padding > .alignfull {
    margin-left: 0px !important;
    margin-right: 0px !important;
}

:root :where(.is-layout-flow) > * {
    margin-block-start: 0;
    margin-block-end: 0;
}

a {
    color: inherit;
    text-decoration: none;
}

html {
    scroll-behavior: smooth;
}
main {
    // padding-top: 100px !important;
}

img {
    max-width: 100%;
    height: auto;
    display: block;
}

/* === TYPOGRAPHY === */
h1 {
    font-family: var(--sm-font-head);
    font-size: clamp(3.4rem, 6vw, 4.2rem);
    font-weight: 400;
    text-transform: uppercase;
    line-height: 1.0;
    letter-spacing: -0.01em;
    margin: 0;
}

h2 {
    font-family: var(--sm-font-head);
    font-size: clamp(2.4rem, 4vw, 3rem);
    font-weight: 400;
    text-transform: uppercase;
    line-height: 1.05;
    letter-spacing: 0.01em;
    margin: 0;
}

h3 {
    font-family: var(--sm-font-head);
    font-size: clamp(1.8rem, 4vw, 2.2rem);
    font-style: normal;
    font-weight: 400;
    line-height: normal;
}
h4 {
    font-size: 20px;
    font-style: normal;
    font-weight: 600;
    line-height: normal;
}
p {
    font-size: 16px;
    color: var(--sm-gray);
    line-height: 1.7;
    margin: 0;
    font-weight: 400;
}

/* === CONTAINER === */
.sm-container {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
}

/* === SHARED BUTTONS === */
.sm-btn .wp-block-button__link,
.wp-block-button.sm-btn .wp-block-button__link,
.sm-btn-dark .wp-block-button__link,
.wp-block-button.sm-btn-dark .wp-block-button__link,
.sm-btn-outline .wp-block-button__link,
.wp-block-button.sm-btn-outline .wp-block-button__link,
.sm-btn-white .wp-block-button__link,
.wp-block-button.sm-btn-white .wp-block-button__link,
.sm-btn-grey .wp-block-button__link,
.wp-block-button.sm-btn-grey .wp-block-button__link,
.sm-btn-clear .wp-block-button__link,
.wp-block-button.sm-btn-clear .wp-block-button__link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 22px;
    font-family: var(--sm-font-body);
    font-size: 15px;
    font-weight: 600;
    border-radius: 6px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: var(--sm-transition);
    text-decoration: none;
    white-space: nowrap;
}

.sm-btn .wp-block-button__link,
.wp-block-button.sm-btn .wp-block-button__link {
    background: var(--sm-yellow);
    color: var(--sm-black) !important;
    border-color: var(--sm-yellow);
}

.sm-btn .wp-block-button__link:hover,
.wp-block-button.sm-btn .wp-block-button__link:hover {
    border-color: var(--sm-yellow-dark);
    transform: translateY(-1px);
    box-shadow: var(--sm-shadow-md);
}

.sm-btn-dark .wp-block-button__link,
.wp-block-button.sm-btn-dark .wp-block-button__link {
    background: var(--sm-black);
    color: var(--sm-white) !important;
    border-color: var(--sm-black);
}

.sm-btn-dark .wp-block-button__link:hover,
.wp-block-button.sm-btn-dark .wp-block-button__link:hover {
    background: var(--sm-dark-2);
    transform: translateY(-1px);
    box-shadow: var(--sm-shadow-md);
}

.sm-btn-outline .wp-block-button__link,
.wp-block-button.sm-btn-outline .wp-block-button__link {
    background: transparent;
    color: var(--sm-black) !important;
    border-color: var(--sm-black);
}

.sm-btn-outline .wp-block-button__link:hover,
.wp-block-button.sm-btn-outline .wp-block-button__link:hover {
    background: var(--sm-black);
    color: var(--sm-white) !important;
}

.sm-btn-white .wp-block-button__link,
.wp-block-button.sm-btn-white .wp-block-button__link {
    background: var(--sm-white);
    color: var(--sm-black) !important;
    border-color: var(--sm-white);
}

.sm-btn-white .wp-block-button__link:hover,
.wp-block-button.sm-btn-white .wp-block-button__link:hover {
    background: var(--sm-light-1);
    border-color: var(--sm-light-1);
    transform: translateY(-1px);
    box-shadow: var(--sm-shadow-md);
}

.sm-btn-grey .wp-block-button__link,
.wp-block-button.sm-btn-grey .wp-block-button__link {
    background: var(--sm-light);
    color: var(--sm-black) !important;
    border-color: var(--sm-light);
}

.sm-btn-grey .wp-block-button__link:hover,
.wp-block-button.sm-btn-grey .wp-block-button__link:hover {
    background: var(--sm-light-1);
    border-color: var(--sm-light-1);
    transform: translateY(-1px);
    box-shadow: var(--sm-shadow-md);
}

.sm-btn-clear .wp-block-button__link,
.wp-block-button.sm-btn-clear .wp-block-button__link {
    background: transparent;
    color: var(--sm-black) !important;
    border-color: transparent;
    padding: 0;
}

.sm-btn-clear .wp-block-button__link:hover,
.wp-block-button.sm-btn-clear .wp-block-button__link:hover {
    background: transparent;
    border-color: transparent;
    transform: none;
    box-shadow: none;
}

/* === SECTION LABEL === */
.sm-section-label {
    font-family: var(--sm-font-body);
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--sm-gray);
    margin-bottom: 10px;
    display: block;
}

/* === HEADER / NAV === */
header {
    position: fixed;
    width: 100%;
    margin-top: 12px !important;
    z-index: 999;
}
.sm-header {
    position: sticky;
    top: 0;
    z-index: 1000;
    padding: 12px 20px;
    background: transparent;
    pointer-events: none;
}

.sm-header-inner {
    pointer-events: all;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px;
    gap: 20px;
    background: var(--sm-white);
    border-radius: 8px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.10), 0 1px 6px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.06);
    max-width: var(--sm-max);
    margin: 0 auto;
    max-width: 94%;
    transition: box-shadow 0.25s ease;
}

.sm-header.scrolled .sm-header-inner {
    box-shadow: 0 8px 36px rgba(0,0,0,0.14), 0 2px 8px rgba(0,0,0,0.08);
}

.sm-logo img {
    height: 44px;
    width: auto;
    display: block;
}

.sm-nav {
    display: flex;
    align-items: center;
    gap: 2px;
    margin-left: auto;
}

.sm-nav a {
    font-size: 14px;
    font-weight: 600;
    color: var(--sm-grey);
    padding: 8px 12px;
    border-radius: 6px;
    transition: var(--sm-transition);
    white-space: nowrap;
    text-decoration: none;
    position: relative;
}

.sm-nav a:hover,
.sm-nav a.active {
    color: var(--sm-black);
    background: var(--sm-light);
}

.sm-nav a.active::after {
    content: "";
    position: absolute;
    bottom: 2px;
    left: 12px;
    right: 12px;
    height: 2px;
    background: var(--sm-yellow);
    border-radius: 2px;
}

.sm-nav-dropdown {
    display: inline-block;
    position: relative;
}
.sm-nav-dropdown > a::before {
    content: "\f078";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    font-size: 9px;
    margin-right: 6px;
}
.sm-nav-submenu {
    position: absolute;
    top: calc(100% + 8px);
    left: 50%;
    min-width: 180px;
    padding: 7px;
    background: var(--sm-white);
    border: 1px solid var(--sm-border);
    border-radius: 8px;
    box-shadow: var(--sm-shadow-md);
    opacity: 0;
    visibility: hidden;
    transform: translate(-50%, 5px);
    transition: var(--sm-transition);
}
.sm-nav-submenu a { display: block; }
.sm-nav-dropdown:hover .sm-nav-submenu,
.sm-nav-dropdown:focus-within .sm-nav-submenu {
    opacity: 1;
    visibility: visible;
    transform: translate(-50%, 0);
}

.sm-nav-cta-wrap {
    flex-shrink: 0;
}

.sm-nav-btn .wp-block-button__link {
    background: var(--sm-yellow) !important;
    color: var(--sm-black) !important;
    border-color: var(--sm-yellow) !important;
    padding: 10px 20px !important;
    font-weight: 600 !important;
    font-size: 14px !important;
    border-radius: 6px !important;
}

.sm-nav-btn .wp-block-button__link:hover {
    background: var(--sm-yellow-dark) !important;
    border-color: var(--sm-yellow-dark) !important;
}

/* Hamburger */
.sm-nav-toggle {
    display: none;
    flex-direction: column;
    gap: 5px;
    cursor: pointer;
    padding: 6px;
    background: none;
    border: none;
    pointer-events: all;
}

.sm-nav-toggle span {
    display: block;
    width: 24px;
    height: 2px;
    background: var(--sm-black);
    border-radius: 2px;
    transition: var(--sm-transition);
}

.sm-nav-toggle.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.sm-nav-toggle.open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
.sm-nav-toggle.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

/* Mobile Nav */
.sm-mobile-nav {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: var(--sm-white);
    z-index: 999;
    flex-direction: column;
    padding: 120px 32px 32px;
    gap: 4px;
}

.sm-mobile-nav.open {
    display: flex;
}

.sm-mobile-nav a {
    font-family: var(--sm-font-body);
    font-size: 18px;
    font-weight: 600;
    color: var(--sm-black);
    padding: 12px 0;
    border-bottom: 1px solid var(--sm-border);
    text-decoration: none;
    transition: var(--sm-transition);
}

.sm-mobile-nav a:hover {
    color: var(--sm-yellow-dark);
    padding-left: 8px;
}

.sm-mobile-nav-cta {
    margin-top: 24px;
}

/* === HERO SECTION === */
.sm-hero {
    background: var(--sm-bg);
    padding: 72px 0 48px;
    padding-top: 172px;
    overflow: hidden;
}

.sm-hero-inner {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 60px;
    align-items: center;
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
}

.sm-hero-content {
}

.sm-hero h1 {
    color: var(--sm-black);
    margin-bottom: 20px;
}

.sm-hero h1 span {
    color: var(--sm-yellow);
}

.sm-hero-text {
    font-size: 16px;
    color: var(--sm-gray);
    line-height: 1.7;
    margin-bottom: 32px;
}

.sm-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 36px;
}

.sm-google-badge {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: var(--sm-white);
    border: 1px solid var(--sm-border);
    border-radius: 8px;
    padding: 10px 16px;
    box-shadow: var(--sm-shadow);
}

.sm-google-badge-rating {
    font-size: 18px;
    font-weight: 700;
    color: var(--sm-black);
}

.sm-stars {
    color: #FBBC04;
    font-size: 16px;
    letter-spacing: 1px;
}

.sm-google-badge-count {
    font-size: 12px;
    color: var(--sm-gray);
}

.sm-hero-image {
    border-radius: var(--sm-radius-lg);
    overflow: hidden;
    height: 480px;
    box-shadow: var(--sm-shadow-lg);
}

.sm-hero-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* === WHY CHOOSE SECTION === */
.sm-why {
    padding: 80px 0;
    background: var(--sm-white);
}

.sm-why-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
}

.sm-why-header {
    text-align: center;
    margin-bottom: 52px;
}

.sm-why-header h2 {
    margin-bottom: 12px;
}

.sm-why-header p {
    max-width: 560px;
    margin: 0 auto;
}

.sm-why-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

.sm-why-card {
    background: var(--sm-light-2);
    border-radius: var(--sm-radius-lg);
    overflow: hidden;
    transition: var(--sm-transition);
    display: flex;
    flex-direction: column;
    height: 100%;
    max-height: 100%;
}
.sm-why-card img {
    padding: 8px;
    border-radius: 12px;
}

.sm-why-card:hover {
    box-shadow: var(--sm-shadow-md);
    transform: translateY(-3px);
}

.sm-why-card img {
    width: 100%;
    height: 200px;
    object-fit: cover;
}

.sm-why-card-body {
    padding: 24px;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.sm-why-card-body h3 {
    margin-bottom: 10px;
    color: var(--sm-black);
    font-family: var(--sm-font-body) !important;
    font-size: 22px;
    font-weight: 600;
}

.sm-why-card-body p {
    font-size: 14px;
    margin-bottom: auto;
    padding-bottom: 30px;
    line-height: 1.65;
}

.sm-why-card-link {
    font-size: 14px;
    font-weight: 600;
    color: var(--sm-black);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: var(--sm-transition);
}

.sm-why-card-link:hover {
    color: var(--sm-yellow-dark);
}
ul.wp-block-list {
    padding-top: 20px;
}
ul.wp-block-list li {
    font-size: 18px;
    margin-top: 0px;
    color: var(--sm-gray) !important;
}

/* === VEHICLES SECTION === */
.sm-vehicles {
    padding: 80px 0;
    background: var(--sm-bg);
}

.sm-vehicles-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
}

.sm-vehicles-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 40px;
    gap: 16px;
    flex-wrap: wrap;
    row-gap: 32px;
}

.sm-vehicle-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

.sm-vehicle-card {
    background: var(--sm-white);
    border-radius: var(--sm-radius-lg);
    overflow: hidden;
    border: 1px solid #DBDBDB;
    transition: var(--sm-transition);
    padding: 0;
}

.sm-vehicle-card:hover {
    box-shadow: var(--sm-shadow-md);
    transform: translateY(-3px);
}

.sm-vehicle-card img {
    width: 100%;
    height: 210px;
    object-fit: cover;
    background: var(--sm-light-2);
}

.sm-vehicle-card-body {
    padding: 20px;
}

.sm-vehicle-card h3 {
    margin-top: 0;
    margin-bottom: 8px;
    font-family: var(--sm-font-body) !important;
    font-size: 20px;
    font-style: normal;
    font-weight: 600;
    line-height: normal;
}

.sm-vehicle-specs {
    font-size: 14px;
    font-style: normal;
    font-weight: 400;
    line-height: 28px;
    color: var(--sm-gray);
    margin-bottom: 12px;
    margin-top: 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px 12px;
}
.sm-vehicle-specs span {
    display: flex;
}
.sm-vehicle-specs span::before {
    content: "";
    background-color: var(--sm-gray);
    height: 56%;
    align-self: center;
    display: flex;
    width: 1px;
    margin-right: 12px;
}
.sm-vehicle-specs span:first-child::before {
    display: none;
}

.sm-vehicle-price-row {
    display: flex;
    align-items: flex-end;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
}

.sm-vehicle-price {
    font-family: var(--sm-font-body);
    font-size: 24px;
    font-weight: 600;
    color: var(--sm-bronze);
    line-height: 1;
}

.sm-vehicle-weekly {
    font-size: 14px !important;
    color: var(--sm-gray);
    font-weight: 500;
}

.sm-vehicle-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.sm-vehicle-more-link {
    font-size: 13px;
    font-weight: 600;
    color: var(--sm-black);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: var(--sm-transition);
}

.sm-vehicle-more-link:hover {
    color: var(--sm-yellow-dark);
}

/* === VEHICLE FILTERS === */
.sm-vehicle-filters {
    margin: 0 auto;
    max-width: var(--sm-max) !important;
    padding: 0 40px !important;
}
.sm-vehicle-filters-inner {
background: var(--sm-bg);
    border-bottom: 1px solid var(--sm-border);
    border-radius: var(--sm-radius);
    border: 1px solid var(--sm-border);
    margin: 0 auto 40px !important;
    padding:    16px !important;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.sm-filter {
    appearance: none;
    -webkit-appearance: none;
    background: var(--sm-white);
    border: 1px solid var(--sm-border);
    border-radius: var(--sm-radius);
    padding: 9px 36px 9px 14px;
    font-family: var(--sm-font-body);
    font-size: 14px;
    font-weight: 500;
    color: var(--sm-black);
    cursor: pointer;
    transition: var(--sm-transition);
    min-width: 150px;
    flex: 1;
}
.sm-filter:hover,
.sm-filter:focus {
    border-color: var(--sm-black);
    outline: none;
}
.sm-filter-search {
    border: 1px solid var(--sm-border);
    border-radius: var(--sm-radius);
    padding: 9px 14px;
    font-family: var(--sm-font-body);
    font-size: 14px;
    font-weight: 500;
    color: var(--sm-black);
    background: var(--sm-white);
    transition: var(--sm-transition);
    flex: 1;
}
.sm-filter-search:focus {
    border-color: var(--sm-black);
    outline: none;
}
.sm-filter-actions {
    display: flex;
    align-items: center;
    gap: 16px;
}
.sm-filter-count {
    font-size: 13px;
    color: var(--sm-gray);
    font-weight: 500;
    white-space: nowrap;
}
.sm-filter-reset {
    background: none;
    border: 1px solid var(--sm-border);
    border-radius: var(--sm-radius);
    padding: 9px 16px;
    font-family: var(--sm-font-body);
    font-size: 13px;
    font-weight: 600;
    color: var(--sm-black);
    cursor: pointer;
    transition: var(--sm-transition);
    white-space: nowrap;
}
.sm-filter-reset:hover {
    border-color: var(--sm-black);
    background: var(--sm-black);
    color: var(--sm-white);
}

/* === USED VEHICLES === */
.post-type-archive-vehicle main {
    padding: 172px 0 0 0 !important;
}
.template-heading  {
max-width: var(--sm-max) !important;
    padding: 40px 40px 60px !important;
    margin: 0 auto !important;
}
.wp-block-query-title {
    max-width: var(--sm-max) !important;
    padding: 40px 40px 60px !important;
}
.vehicle-archive-header {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0px 40px !important;
}

.vehicle-archive-header-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
}

.vehicle-index ul.wp-block-post-template {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
    list-style: none;
    padding: 0px 40px !important;
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
}
.vehicle-index .sm-vehicle-card {
    padding-bottom: 20px !important;}
.vehicle-index .sm-vehicle-card .sm-vehicle-card-footer {
    margin: 14px 20px 0 !important;
    padding: 0px !important;
}
.vehicle-index .sm-vehicle-card .sm-vehicle-specs {
    margin: 8px 20px 0 !important;
    padding: 0px !important;
}
.vehicle-index .sm-vehicle-card h3 {
margin: 20px 20px 0px 20px !important;
}
.vehicle-index .wp-block-read-more {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 22px;
    font-family: var(--sm-font-body);
    font-size: 15px;
    font-weight: 600;
    border-radius: 6px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: var(--sm-transition);
    text-decoration: none;
    white-space: nowrap;
    background: transparent;
    color: var(--sm-black) !important;
    border-color: transparent;
    padding: 0;
}
.vehicle-index .wp-block-read-more:hover {
    background: transparent;
    border-color: transparent;
    transform: none;
    box-shadow: none;
}

.vehicle-index .sm-vehicle-specs {
    margin: 8px 32px 0;
    padding: 0;
}

.vehicle-index .sm-vehicle-card-footer {
    margin: 16px 32px 0;
    gap: 12px;
}

.sm-vehicle-prices {
display: flex;
    gap: 6px;
}

.vehicle-index .sm-vehicle-price {
    font-size: 22px;
    font-weight: 700;
    color: var(--sm-bronze);
    margin: 0;
    line-height: 1;
}

.vehicle-index .sm-vehicle-weekly {
    font-size: 13px;
    color: var(--sm-gray);
    font-weight: 500;
    margin: 0;
}

/* === VEHICLE VIEW === */
.vehicle-template-default main {
    padding: 172px 0 0 0 !important;
}

/* === SERVICE CTA BANNER === */
.sm-service-cta {
    position: relative;
    overflow: hidden;
    padding: 120px 0 0 0;
}
.sm-service-cta img {
    position: absolute;
    right: 0px;
    bottom: 0px;
    z-index: -1;
}
.sm-service-cta-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 60px 64px !important;
    display: flex;
    border-radius: 20px;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0px;
    position: relative;
    z-index: 1;
    background-color: var(--sm-yellow);
}

.sm-service-cta h2 {
    margin-bottom: 12px;
    color: var(--sm-black);
}
.sm-service-cta .wp-block-group:has(h2) {
    row-gap: 0px;
    margin-bottom: 0px;
}
.sm-service-cta p {
    color: var(--sm-dark);
    opacity: 0.75;
    max-width: 520px;
    margin-bottom: 24px;
}

/* === PARTNERS STRIP === */
.sm-partners {
    padding: 48px 0 120px 0;
}

.sm-partners-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
    text-align: center;
}

.sm-partners-label {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--sm-gray-light);
    margin-bottom: 28px;
}

.sm-partner-logos {
    display: flex;
    align-items: center;
    justify-content: center !important;
    gap: 60px;
    flex-wrap: wrap;
}
.sm-partner-logos img {
    opacity: 0.7;
}

/* === 50 YEARS SECTION === */
.sm-fifty {
    padding: 120px 0;
    background: var(--sm-bg);
}

.sm-fifty-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 72px;
    align-items: center;
}

.sm-fifty h2 {
    margin-bottom: 24px;
    color: var(--sm-black);
}

.sm-fifty h2 span {
    color: var(--sm-yellow);
}

.sm-fifty p {
    margin-bottom: 16px;
}
.sm-fifty ul {
    padding: 0px !important;
}
.sm-fifty ul li {
    list-style: none;
    font-size: 16px;
    font-weight: 500;
    color: var(--sm-gray);
}
.sm-fifty ul li::before {
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    content: "\f061";
    color: var(--sm-yellow);
    margin-right: 10px;
}
.sm-stats-row {
    display: flex;
    gap: 40px;
}
.sm-stats-row {
    display: flex;
}
.sm-stats-row strong {
    font-family: var(--sm-font-body);
    font-size: 20px;
    font-weight: 600;
    color: var(--sm-black);
    text-transform: capitalize;
    letter-spacing: 0px;
    margin-left: 3px;
}
.sm-stats-row p {
    display: flex;
    flex-direction: column;
    row-gap: 6px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--sm-bronze);
    margin-top: 4px;
}
.sm-stats-row p:before {
    content: "";
    display: flex;
    height: 100%;
    background-color: var(--sm-gray);
    width: 1px;
}
.sm-stats div {
    display: flex;
    flex-direction: column;
    row-gap: 6px;
}

.sm-fifty-images {
    display: flex;
    gap: 12px;
    height: 100%;
}
.sm-fifty .wp-block-group:has(h2) {
    padding: 80px 0;
}
.sm-fifty-img {
    border-radius: var(--sm-radius);
    overflow: hidden;
}
.sm-fifty-images {
    display: flex;
    gap: 12px;
    height: 100%;
}
.sm-fifty-img:first-child {
  padding-bottom: 80px;
}
.sm-fifty-img:last-child {
    padding-top: 80px;
}

.sm-fifty-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: var(--sm-radius);
}

/* === HOURS + CONTACT SECTION === */
.sm-hours-contact {
    padding: 120px 0;
}
.wp-block-group:has(.hours-inner) {
    width: 100%;
}
.sm-hours-contact-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 64px;
}

.sm-hours-contact h2 {
    margin-bottom: 28px;
}

.hours-inner {
    width: 100%;
    column-gap: 32px;
    display: flex;
    align-items: flex-start;
}
.hours-mid {
    row-gap; 22px;
    width: 100%;
}
.hours-wrapper {
    display: flex;
    flex-direction: column;
    row-gap: 16px;
}
.hours-block {
    display: flex;
    width: 100%;
    justify-content: space-between;
    padding-bottom: 16px;
    border-bottom: 1px solid #DBDBDB;
}
.hours-block p {
    color: var(--sm-dark);
}

.sm-hours-col-label {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--sm-bronze);
    margin-bottom: 22px;
    font-family: var(--sm-font-body);
}
.sm-hours-contact iframe {
    border-radius: 8px;
}

.sm-hours-row:last-child {
    border-bottom: none;
}

.sm-hours-day {
    font-weight: 500;
    color: var(--sm-black);
}

.sm-hours-map {
    margin-top: 20px;
    border-radius: var(--sm-radius);
    overflow: hidden;
    height: 160px;
}

.sm-hours-map iframe {
    width: 100%;
    height: 100%;
    border: none;
}
.sm-contact-detail {
    display: flex;
    align-items: flex-start;
    gap: 14px;
}
.sm-hours-contact .contact-info-wrapper {
    display: flex;
    flex-direction: column;
    padding: 48px;
    background-color: var(--sm-light-2);
    row-gap: 40px;
    border-radius: 14px;
    height: fit-content;
}
.sm-hours-contact .contact-info-wrapper .wp-block-group:has(.contact-info), .contact-wrapper .wp-block-group:has(.contact-info) {
    margin-left: 0px !important;
    margin-right: 0px !important;
}
.sm-hours-contact .contact-info-inner {
    display: flex;
    flex-wrap: wrap;
    padding: 0px;
    gap: 16px;
}
.sm-hours-contact .contact-info-inner:before {
    color: var(--sm-bronze);
}
.sm-hours-contact .contact-info-inner:has(.address)::before {
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    content: "\f3c5";
    background-color: #fff;
    width: 50px;
    height: 50px;
    flex-shrink: 0;
    border: 1px solid var(--sm-light);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.sm-hours-contact .contact-info-inner:has(.email)::before {
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    content: "\f0e0";
    background-color: #fff;
    width: 50px;
    height: 50px;
    flex-shrink: 0;
    border: 1px solid var(--sm-light);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.sm-hours-contact .contact-info-inner:has(.phone)::before {
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    content: "\f095";
    background-color: #fff;
    width: 50px;
    height: 50px;
    flex-shrink: 0;
    border: 1px solid var(--sm-light);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.sm-hours-contact .contact-info {
    display: flex;
    flex-direction: column;
    row-gap: 2px;
    margin: 0px !important;
}
.sm-contact-icon {
    width: 42px;
    height: 42px;
    background: var(--sm-black);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--sm-white);
    font-size: 18px;
}

.sm-contact-detail-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--sm-gray-light);
}

.sm-contact-detail-value {
    font-size: 15px;
    font-weight: 500;
    color: var(--sm-black);
    margin-top: 2px;
}

.sm-contact-detail-value a {
    color: var(--sm-black);
    transition: var(--sm-transition);
}

.sm-contact-detail-value a:hover {
    color: var(--sm-yellow-dark);
}

.sm-social-links {
    display: flex;
    gap: 10px;
    margin-top: 8px;
}

.sm-social-link {
    width: 38px;
    height: 38px;
    background: var(--sm-black);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--sm-white);
    transition: var(--sm-transition);
}

.sm-social-link:hover {
    background: var(--sm-yellow);
    color: var(--sm-black);
}
.team-profiles {
    padding-top: 40px;
}
.team-profile img {
    max-width: 80px;
    min-width: 80px;
    max-height: 80px;
    min-height: 80px;
    object-fit: cover;
    border-radius: 50%;
}
.team-profile {
    flex-direction: row;
    flex-wrap: nowrap;
    align-items: flex-start;
}
.team-profile .wp-block-group {
    row-gap: 8px;
}
.team-profile p {
    font-size: 14px !important;
}
.team-profile .wp-block-group:has(h4) {
    row-gap: 4px;
}
.team-profile a {
    color: var(--sm-yellow-dark);
    text-decoration: underline;
    font-size: 16px !important;
}
.profile-title p {
    font-size: 16px !important;
}

/* === PAGE HERO (inner pages) === */
.sm-page-hero {
    background: var(--sm-bg);
    padding: 72px 0 60px;
    padding-top: 172px;
}

.sm-page-hero-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
    max-width: 680px;
}

.sm-page-hero h1 {
    margin-bottom: 16px;
    color: var(--sm-black);
}

.sm-page-hero p {
    font-size: 17px;
    max-width: 560px;
}

/* === FOOTER === */
.sm-footer {
    background: var(--sm-dark);
    color: var(--sm-white);
    padding: 64px 0 0;
}

.sm-footer-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 120px 60px !important;
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1.2fr;
    gap: 48px;
    padding-bottom: 80px !important;
}

.sm-footer-logo img {
    height: 52px;
    width: auto;
    margin-bottom: 20px;
}

.sm-footer-tagline {
    font-size: 14px;
    color: rgba(255,255,255,0.5);
    line-height: 1.7;
    max-width: 280px;
}

.sm-footer-col-title {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #fff;
    margin-bottom: 16px;
}

.sm-footer-links {
    display: flex;
    flex-direction: column;
    gap: 0px;
    list-style: none;
    padding: 0;
    margin: 0;
    font-size: 16px;
}
.sm-footer-links a {
    font-size: 14px;
    color: rgba(255,255,255,0.9);
    transition: var(--sm-transition);
    text-decoration: none;
}

.sm-footer-links a:hover {
    color: var(--sm-yellow);
}

.sm-footer-map {
    border-radius: var(--sm-radius);
    overflow: hidden;
    height: 120px;
    margin-top: 8px;
}
.sm-footer iframe {
    border-radius: 8px;
}
.sm-footer-map iframe {
    width: 100%;
    height: 100%;
    border: none;
}

.sm-footer-bottom {
    border-top: 1px solid rgba(255,255,255,0.08);
    padding: 20px 0;
}

.sm-footer-bottom-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.sm-footer-copy {
    font-size: 13px;
    color: rgba(255,255,255,0.35);
}

.sm-footer-legal {
    display: flex;
    gap: 24px;
}

.sm-footer-legal a {
    font-size: 13px;
    color: rgba(255,255,255,0.35);
    transition: var(--sm-transition);
    text-decoration: none;
}

.sm-footer-legal a:hover {
    color: rgba(255,255,255,0.7);
}

/* === INVENTORY PAGE === */
.sm-inventory {
    padding: 64px 0;
    background: var(--sm-light);
}

.sm-inventory-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
}

.sm-inventory-filters {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 40px;
}

.sm-filter-btn {
    padding: 8px 18px;
    background: var(--sm-white);
    border: 1px solid var(--sm-border);
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    color: var(--sm-black);
    cursor: pointer;
    transition: var(--sm-transition);
    text-decoration: none;
}

.sm-filter-btn:hover,
.sm-filter-btn.active {
    background: var(--sm-black);
    color: var(--sm-white);
    border-color: var(--sm-black);
}

.sm-inventory-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

/* === CONTACT PAGE === */
.sm-contact-hero {
    background: var(--sm-light);
    padding: 56px 0 48px;
    border-bottom: 1px solid var(--sm-border);
}

.sm-contact-hero-inner {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
}

.sm-contact-hero h1 {
    margin-bottom: 12px;
    color: var(--sm-black);
}

.sm-contact-hero p {
    max-width: 520px;
    font-size: 15px;
}

.sm-contact-main {
    padding: 56px 0 72px;
}

.sm-contact-grid {
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
    padding: 0 24px !important;
    display: grid;
    grid-template-columns: 1fr 1.1fr;
    gap: 80px;
    align-items: start;
}

.sm-contact-info h2 {
    margin-bottom: 24px;
}
.contact-wrapper {
    display: flex;
    row-gap: 0px;
    flex-direction: column;
    width: 100%;
}
.contact-wrapper .contact-info-inner {
    margin-bottom: 24px;
}
.sm-enquiry-form-card {
    background: var(--sm-bg);
    border-radius: var(--sm-radius-lg);
    padding: 32px;
    height: fit-content;
}
.sm-enquiry-form-card .form-heading {
    margin-bottom: 32px;
}

/* CF7 form styles */
.wpcf7-form br {
    display: none;
}
.wpcf7-form .sm-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.wpcf7-form .sm-form-group {
    margin-bottom: 18px;
}

.wpcf7-form label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--sm-dark);
    margin-bottom: 6px;
}

.wpcf7-form input[type="text"],
.wpcf7-form input[type="email"],
.wpcf7-form input[type="tel"],
.wpcf7-form select,
.wpcf7-form textarea {
    width: 100%;
    padding: 13px 16px;
    font-family: var(--sm-font-body);
    font-size: 15px;
    color: var(--sm-black);
    background: #ECECEF;
    border: 1px solid #DAD9D9;
    border-radius: var(--sm-radius);
    transition: var(--sm-transition);
    outline: none;
    -webkit-appearance: none;
}

.wpcf7-form select {
    padding-right: 40px;
    cursor: pointer;
}
.wpcf7-form-control-wrap:has(select) {
    position: relative;
    display: block;
}
.wpcf7-form-control-wrap:has(select)::after {
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    content: "\f078";
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    color: var(--sm-gray);
    font-size: 13px;
}

.wpcf7-form input:focus,
.wpcf7-form select:focus,
.wpcf7-form textarea:focus {
    border-color: var(--sm-yellow);
    box-shadow: 0 0 0 3px rgba(245,197,24,0.15);
}

.wpcf7-form textarea {
    min-height: 120px;
    resize: vertical;
}

.wpcf7-form input[type="submit"] {
    background: var(--sm-yellow);
    color: var(--sm-black);
    border: 2px solid var(--sm-yellow);
    padding: 14px 28px;
    font-family: var(--sm-font-body);
    font-size: 15px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    transition: var(--sm-transition);
    width: fit-content;
}

.wpcf7-form input[type="submit"]:hover {
    border-color: var(--sm-yellow-dark);
}

/* Finance application */
.sm-finance-page {
    width: calc(100% - 48px);
    max-width: 1080px !important;
    margin-left: auto !important;
    margin-right: auto !important;
    padding: 64px 24px 88px;
    box-sizing: border-box;
}
.sm-finance-page > .wpcf7,
.sm-finance-page > .wp-block-shortcode,
.sm-finance-page .wpcf7 { width: 100%; max-width: none; margin-left: auto; margin-right: auto; }
.sm-finance-page > h2,
.sm-finance-page > p { text-align: center; }
.sm-finance-page > p { margin-bottom: 36px; }
.sm-finance-form {
    background: var(--sm-white);
    border: 1px solid #E2E2E2;
    border-radius: var(--sm-radius-lg);
    box-shadow: 0 12px 38px rgba(0,0,0,.08);
    padding: 36px;
}
.sm-finance-progress {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 24px;
}
.sm-finance-progress-item {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--sm-gray);
    font-weight: 600;
    border-bottom: 3px solid #DDD;
    padding: 0 0 14px;
}
.sm-finance-progress-item > p {
    display: flex;
    align-items: center;
    gap: 14px;
    margin: 0;
}
.sm-finance-progress-item span {
    display: inline-grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #DDD;
    color: var(--sm-dark);
}
.sm-finance-progress-item.is-active { color: var(--sm-dark); border-color: var(--sm-yellow); }
.sm-finance-progress-item.is-active span { background: var(--sm-yellow); }
.sm-finance-required-note,
.sm-finance-help { color: var(--sm-gray); font-size: 14px; }
.sm-finance-step[hidden],
.sm-conditional[hidden] { display: none !important; }
.sm-finance-step h2 { margin: 20px 0 22px; font-size: 28px; }
.sm-finance-step hr { border: 0; border-top: 1px solid #E2E2E2; margin: 32px 0; }
.sm-finance-form .sm-form-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.sm-finance-form .sm-form-group-wide { grid-column: 1 / -1; }
.sm-finance-form input[type="number"],
.sm-finance-form input[type="date"] {
    width: 100%;
    padding: 13px 16px;
    font-family: var(--sm-font-body);
    font-size: 15px;
    color: var(--sm-black);
    background: #ECECEF;
    border: 1px solid #DAD9D9;
    border-radius: var(--sm-radius);
    outline: none;
}
.sm-finance-total {
    display: flex;
    justify-content: flex-start;
    align-items: center;
    gap: 12px;
    background: var(--sm-yellow-pale);
    border-left: 4px solid var(--sm-yellow);
    padding: 16px 18px;
    font-size: 18px;
}
.sm-finance-total strong {
    font-family: var(--sm-font-body);
    font-size: 20px;
    font-weight: 700;
    line-height: 1;
    letter-spacing: 0;
    color: var(--sm-dark);
    font-variant-numeric: tabular-nums;
}
.sm-finance-total > p {
    width: 100%;
    margin: 0;
    display: flex;
    justify-content: flex-start;
    align-items: center;
    gap: 12px;
}
.sm-form-consent {
    background: #F5F5F5;
    border: 1px solid transparent;
    border-radius: var(--sm-radius);
    padding: 16px;
    margin: 22px 0;
}
.sm-form-consent .wpcf7-list-item { margin: 0; }
.sm-form-consent label { display: flex; align-items: flex-start; gap: 10px; text-transform: none; letter-spacing: 0; font-size: 14px; }
.sm-form-consent input { margin-top: 3px; flex: 0 0 auto; }
.sm-finance-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 14px;
    margin-top: 30px;
    position: relative;
}
.sm-finance-actions > p {
    width: 100%;
    margin: 0;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 14px;
}
.sm-finance-actions .wpcf7-spinner {
    position: static;
    flex: 0 0 auto;
    order: 2;
    margin: 0 -4px 0 0;
    pointer-events: none;
}
.sm-finance-actions .sm-finance-back { order: 1; }
.sm-finance-actions input[type="submit"] { order: 3; }
.sm-finance-actions-next { justify-content: flex-end; }
.sm-finance-next,
.sm-finance-back {
    border: 2px solid var(--sm-yellow);
    border-radius: 6px;
    padding: 14px 28px;
    font: 600 15px var(--sm-font-body);
    cursor: pointer;
}
.sm-finance-next { background: var(--sm-yellow); color: var(--sm-black); }
.sm-finance-back { background: transparent; color: var(--sm-dark); }
.sm-finance-assets {
    max-width: 760px;
    margin: 24px auto 8px;
}
.sm-finance-assets-head,
.sm-finance-asset-row {
    display: block;
    padding: 0;
}
.sm-finance-assets-head > p,
.sm-finance-asset-row > p {
    display: grid;
    grid-template-columns: 150px repeat(3, minmax(130px, 1fr));
    gap: 16px;
    align-items: center;
    padding: 6px 0;
    margin: 0;
}
.sm-finance-assets-head {
    color: var(--sm-gray);
    font-size: 13px;
    font-weight: 600;
    padding-bottom: 2px;
}
.sm-finance-assets-head span:first-child { visibility: hidden; }
.sm-finance-asset-row strong {
    color: var(--sm-dark);
    font-size: 14px;
    font-weight: 500;
    text-align: right;
}
.sm-finance-asset-row .wpcf7-form-control-wrap {
    position: relative;
    display: block;
    min-width: 0;
}
.sm-finance-asset-row .wpcf7-form-control-wrap::before {
    content: "$";
    position: absolute;
    z-index: 1;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #C5C7CA;
    font-size: 17px;
    font-weight: 600;
    pointer-events: none;
}
.sm-finance-asset-row input {
    min-height: 42px;
    padding: 10px 12px 10px 32px !important;
    background: var(--sm-white) !important;
    border-color: #D6D8DB !important;
    border-radius: 5px !important;
}
.sm-finance-asset-row input:focus {
    border-color: var(--sm-yellow) !important;
    box-shadow: 0 0 0 3px rgba(245,197,24,.14);
}
.sm-finance-asset-row input::placeholder { color: transparent; }
.sm-finance-assets + hr { margin-top: 34px; }
.sm-finance-assets + hr + h2 { margin-top: 0; }
.sm-finance-assets + hr + h2 + .sm-form-group {
    max-width: 760px;
    margin-left: auto;
    margin-right: auto;
}
.sm-finance-form .sm-form-group:has(textarea[name="additional-notes"]) {
    width: 100%;
    max-width: none !important;
    margin-left: 0;
    margin-right: 0;
}
.sm-finance-form .wpcf7-form-control-wrap[data-name="additional-notes"],
.sm-finance-form textarea[name="additional-notes"] {
    display: block;
    width: 100% !important;
    max-width: none !important;
}
.sm-form-group.sm-has-error input,
.sm-form-group.sm-has-error select,
.sm-form-consent.sm-has-error { border-color: #B3261E; box-shadow: 0 0 0 2px rgba(179,38,30,.12); }
.sm-step-error { color: #B3261E; font-size: 13px; margin-top: 6px; }

/* Trade-in form */
.sm-trade-in-page {
    width: calc(100% - 48px);
    max-width: 960px !important;
    margin-left: auto !important;
    margin-right: auto !important;
    padding: 64px 24px 88px;
    box-sizing: border-box;
}
.sm-trade-in-page > h2,
.sm-trade-in-page > p { text-align: center; }
.sm-trade-in-page > p { margin-bottom: 36px; }
.sm-trade-in-page .wpcf7 { width: 100%; max-width: none; }
.sm-trade-in-form {
    width: 100%;
    padding: 36px;
    background: var(--sm-white);
    border: 1px solid #E2E2E2;
    border-radius: var(--sm-radius-lg);
    box-shadow: 0 12px 38px rgba(0,0,0,.08);
}
.sm-trade-in-form h2 { margin: 0 0 22px; font-size: 28px; }
.sm-trade-in-form hr { border: 0; border-top: 1px solid #E2E2E2; margin: 32px 0; }
.sm-trade-in-form .sm-form-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.sm-trade-in-form .sm-form-group-wide { grid-column: 1 / -1; }
.sm-trade-in-form input[type="number"] {
    width: 100%;
    padding: 13px 16px;
    font-family: var(--sm-font-body);
    font-size: 15px;
    color: var(--sm-black);
    background: #ECECEF;
    border: 1px solid #DAD9D9;
    border-radius: var(--sm-radius);
    outline: none;
}
.sm-trade-in-form input[type="number"]:focus {
    border-color: var(--sm-yellow);
    box-shadow: 0 0 0 3px rgba(245,197,24,.15);
}
.sm-trade-in-help { margin: -10px 0 22px; color: var(--sm-gray); font-size: 14px; }
.sm-trade-in-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
    position: relative;
}
.sm-trade-in-actions > p {
    width: 100%;
    margin: 0;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
}
.sm-trade-in-actions input[type="submit"] {
    order: 2;
    margin-left: 0;
}
.sm-trade-in-actions .wpcf7-spinner {
    position: static;
    flex: 0 0 auto;
    order: 1;
    margin: 0 0 0 auto;
    pointer-events: none;
}

/* === SINGLE VEHICLE PAGE === */
.single-vehicle main {
    padding-top: 80px;
}

.sm-single-vehicle {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0px;
    align-items: start;
    padding-bottom: 100px;
    max-width: var(--sm-max) !important;
    margin: 0 auto !important;
}

.sm-single-vehicle-media { min-width: 0; }

.sm-single-vehicle-info {
    position: sticky;
    top: 100px;
}

.sm-single-vehicle-info h1 {
    font-size: 2.8rem;
    margin-bottom: 16px;
}

.sm-single-vehicle-price-row {
    display: flex;
    align-items: baseline;
    gap: 12px;
    margin-bottom: 20px;
}

.sm-single-vehicle-price-row .sm-vehicle-price {
    font-family: var(--sm-font-body);
    font-size: 24px;
    font-weight: 600;
    color: var(--sm-bronze);
    line-height: 1;
}

.sm-single-vehicle-price-row .sm-vehicle-weekly {
    font-size: 1rem;
    color: var(--sm-gray);
}

.sm-single-vehicle-desc {
    margin-bottom: 28px;
    padding: 0px;
    color: var(--sm-gray);
    font-size: 15px;
    line-height: 1.7;
}

/* === VEHICLE GALLERY === */
.sm-vehicle-gallery { width: 100%; }

.sm-gallery-main {
    position: relative;
    width: 100%;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    border-radius: var(--sm-radius-lg);
    background: var(--sm-light-2);
    cursor: zoom-in;
}

.sm-gallery-main-img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}

.sm-gallery-main-img.active {
    opacity: 1;
}

.sm-gallery-nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(0,0,0,0.45);
    color: #fff;
    border: none;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
    z-index: 2;
}

.sm-gallery-nav:hover { background: rgba(0,0,0,0.7); }
.sm-gallery-prev { left: 12px; }
.sm-gallery-next { right: 12px; }

.sm-gallery-prev::after,
.sm-gallery-next::after {
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    font-size: 14px;
}
.sm-gallery-prev::after { content: "\f053"; }
.sm-gallery-next::after { content: "\f054"; }

.sm-gallery-thumbs {
    display: flex;
    gap: 8px;
    margin-top: 10px;
    overflow-x: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--sm-border) transparent;
    padding-bottom: 4px;
}

.sm-gallery-thumbs::-webkit-scrollbar { height: 4px; }
.sm-gallery-thumbs::-webkit-scrollbar-track { background: transparent; }
.sm-gallery-thumbs::-webkit-scrollbar-thumb { background: var(--sm-border); border-radius: 4px; }

.sm-gallery-thumb {
    width: 80px;
    height: 60px;
    object-fit: cover;
    border-radius: 6px;
    cursor: pointer;
    opacity: 0.55;
    border: 2px solid transparent;
    flex-shrink: 0;
    transition: opacity 0.2s, border-color 0.2s;
}

.sm-gallery-thumb.active,
.sm-gallery-thumb:hover {
    opacity: 1;
    border-color: var(--sm-yellow);
}

/* === VEHICLE DETAILS TABLE === */
.sm-vehicle-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    border: 1px solid var(--sm-border);
    border-radius: var(--sm-radius);
    overflow: hidden;
    margin: 0 0 28px;
}

.sm-vehicle-detail-row {
    display: contents;
}

.sm-vehicle-detail-row dt,
.sm-vehicle-detail-row dd {
    padding: 10px 14px;
    margin: 0;
    font-size: 14px;
    border-bottom: 1px solid var(--sm-border);
}

.sm-vehicle-detail-row dt {
    font-weight: 600;
    color: var(--sm-dark);
    background: var(--sm-light-2);
}

.sm-vehicle-detail-row dd {
font-weight: 400;
    color: var(--sm-black);
    background: var(--sm-white);
}

.sm-vehicle-detail-row:last-child dt,
.sm-vehicle-detail-row:last-child dd {
    border-bottom: none;
}

/* === LIGHTBOX === */
.sm-lightbox {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.93);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
}

.sm-lightbox.open {
    opacity: 1;
    pointer-events: all;
}

.sm-lightbox-img {
    max-width: 90vw;
    max-height: 88vh;
    object-fit: contain;
    border-radius: 4px;
    display: block;
}

.sm-lightbox-close {
    position: absolute;
    top: 18px;
    right: 22px;
    color: #fff;
    font-size: 36px;
    cursor: pointer;
    line-height: 1;
    opacity: 0.8;
    user-select: none;
}

.sm-lightbox-close:hover { opacity: 1; }

.sm-lightbox-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    color: #fff;
    font-size: 44px;
    cursor: pointer;
    opacity: 0.65;
    padding: 12px;
    user-select: none;
    line-height: 1;
}

.sm-lightbox-btn:hover { opacity: 1; }
.sm-lightbox-prev { left: 12px; }
.sm-lightbox-next { right: 12px; }

.sm-lightbox-prev::after,
.sm-lightbox-next::after {
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    font-size: 24px;
}
.sm-lightbox-prev::after { content: "\f053"; }
.sm-lightbox-next::after { content: "\f054"; }

.sm-lightbox-counter {
    position: absolute;
    bottom: 18px;
    left: 50%;
    transform: translateX(-50%);
    color: rgba(255,255,255,0.6);
    font-size: 13px;
}

/* === RESPONSIVE === */
@media (max-width: 1200px) {
    .sm-service-cta-inner {
        margin: 16px !important;
    }
}
@media (max-width: 1024px) {
    .sm-single-vehicle {
        grid-template-columns: 1fr;
    }
    .sm-single-vehicle-info {
        position: static;
    }
    .sm-nav-toggle {
        display: flex;
    }
    .sm-nav {
        display: none !important;
    }
    .sm-nav-cta-wrap {
        display: none !important;
    }
    .sm-hero-inner img {
        max-height: 250px;
        object-fit: cover;
        border-radius: 14px;
    }
    .sm-hero-inner {
        grid-template-columns: 1fr;
        gap: 40px;
    }
    .sm-why-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    .sm-vehicle-cards,
    .sm-inventory-grid,
    .vehicle-index ul.wp-block-post-template {
        grid-template-columns: repeat(2, 1fr);
    }
    .wp-block-query {
        margin: 0 !important;
    }
    .sm-filter-actions {
        margin-left: 0;
        width: 100%;
        justify-content: space-between;
    }
    .sm-fifty-inner {
        grid-template-columns: 1fr;
        gap: 48px;
    }
    .sm-fifty .wp-block-group:has(h2) {
        padding: 0;
    }
    .sm-fifty-images {
        flex-direction: column;
    }
    .sm-fifty-img {
        max-height: 320px;
    }
    .sm-fifty-img:first-child {
        padding-bottom: 0;
    }
    .sm-fifty-img:last-child {
        padding-top: 0;
    }
    .sm-hours-contact-inner {
        grid-template-columns: 1fr;
        gap: 48px;
    }
    .sm-footer-inner {
        grid-template-columns: 1fr 1fr;
        gap: 32px;
    }
    .sm-contact-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
.team-profile {
    flex-direction: column;
    flex-wrap: wrap;
    align-items: flex-start;
}
    .hours-inner {
    flex-direction: column;
    }
    .sm-why-cards {
        grid-template-columns: 1fr;
    }
    .sm-vehicle-cards,
    .sm-inventory-grid,
    .vehicle-index ul.wp-block-post-template {
        grid-template-columns: 1fr;
    }
    .vehicle-archive-header-inner {
        flex-direction: column;
        align-items: flex-start;
    }
    .sm-footer-inner {
        grid-template-columns: 1fr;
        gap: 28px;
        text-align: center;
        padding: 48px 24px !important;
    }
    .sm-footer-logo img {
        margin-left: auto;
        margin-right: auto;
    }
    .sm-footer-tagline {
        max-width: 100%;
    }
    .sm-footer-links {
        align-items: center;
    }
    .sm-footer-bottom-inner {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    .sm-footer-legal {
        justify-content: center;
    }
    .sm-service-cta-inner {
        flex-direction: column;
    }
    .sm-service-cta img {
        opacity: 0.05;
    }
    .wpcf7-form .sm-form-row {
        grid-template-columns: 1fr;
    }
    .sm-finance-page { width: calc(100% - 20px); padding: 42px 14px 64px; }
    .sm-finance-form { padding: 22px 16px; }
    .sm-finance-progress-item { align-items: flex-start; font-size: 13px; }
    .sm-finance-assets { margin-top: 16px; }
    .sm-finance-assets-head { display: none; }
    .sm-finance-asset-row > p {
        grid-template-columns: 1fr;
        gap: 8px;
        padding: 16px 0;
        border-bottom: 1px solid var(--sm-border);
    }
    .sm-finance-asset-row:last-child > p { border-bottom: 0; }
    .sm-finance-asset-row strong { text-align: left; font-weight: 700; }
    .sm-finance-asset-row > p > span:empty { display: none; }
    .sm-finance-asset-row input::placeholder { color: #A5A7AA; opacity: 1; }
    .sm-finance-actions { flex-direction: column-reverse; }
    .sm-finance-actions > p { flex-direction: column-reverse; }
    .sm-finance-actions button,
    .sm-finance-actions input[type="submit"] { width: 100%; }
    .sm-trade-in-page { width: calc(100% - 20px); padding: 42px 14px 64px; }
    .sm-trade-in-form { padding: 22px 16px; }
    .sm-trade-in-form .sm-form-row { grid-template-columns: 1fr; }
    .sm-trade-in-form .sm-form-group-wide { grid-column: auto; }
    .sm-trade-in-actions,
    .sm-trade-in-actions > p,
    .sm-trade-in-actions input[type="submit"] { width: 100%; }
    .sm-hours-cols {
        grid-template-columns: 1fr;
    }
    .wp-block-buttons {
        width: 100%;
    }
    .wp-block-button,
    .wp-block-button__link {
        width: 100%;
        text-align: center;
        justify-content: center;
    }
    .sm-hours-contact .contact-info-inner {
        flex-direction: column;
    }
}
';
}

// ─── JAVASCRIPT ───────────────────────────────────────────────────────────────
function sm_get_js() {
    return '
(function () {
    var forms = document.querySelectorAll("[data-sm-finance-form]");
    if (!forms.length) return;

    forms.forEach(function (form) {
        var steps = Array.from(form.querySelectorAll("[data-finance-step]"));
        var progress = Array.from(form.querySelectorAll("[data-progress-step]"));
        var purpose = form.querySelector("[name=loan-purpose]");
        var vehicleInfo = form.querySelector("[name=vehicle-information]");
        var licenceType = form.querySelector("[name=licence-type]");
        var employmentType = form.querySelector("[name=employment-type]");
        var purchase = form.querySelector("[name=purchase-amount]");
        var deposit = form.querySelector("[name=deposit-amount]");
        var loan = form.querySelector("[name=loan-amount]");
        var total = form.querySelector("[data-sm-loan-total]");

        function toggleGroup(name, visible) {
            form.querySelectorAll("[data-sm-show=\"" + name + "\"]").forEach(function (group) {
                group.hidden = !visible;
                group.querySelectorAll("input, select, textarea").forEach(function (field) {
                    field.disabled = !visible;
                    if (!visible) field.value = "";
                });
            });
        }

        function updateConditions() {
            var vehiclePurpose = purpose && ["Car", "Motorbike", "Boat", "Caravan"].indexOf(purpose.value) !== -1;
            toggleGroup("vehicle-information", vehiclePurpose);
            var info = vehiclePurpose && vehicleInfo ? vehicleInfo.value : "";
            toggleGroup("vehicle-registration", info === "Registration");
            toggleGroup("vehicle-trademe", info === "Trade Me Listing");
            toggleGroup("vehicle-make-model", ["Make, Model, Year", "Auto Trader", "VIN"].indexOf(info) !== -1);
            toggleGroup("licence-details", !!licenceType && licenceType.value !== "" && licenceType.value !== "None");
            var employment = employmentType ? employmentType.value : "";
            toggleGroup("employer", ["Full time", "Part time"].indexOf(employment) !== -1);
            toggleGroup("self-employed", employment === "Self employed");
        }

        function updateLoan() {
            var value = Math.max(0, parseFloat(purchase && purchase.value || 0) - parseFloat(deposit && deposit.value || 0));
            if (loan) loan.value = String(value);
            if (total) total.textContent = "$" + value.toLocaleString("en-NZ", { maximumFractionDigits: 2 });
        }

        function fieldValue(field) {
            if (!field) return "";
            if (field.type === "checkbox") return field.checked ? field.value : "";
            return String(field.value || "").trim();
        }

        function validateStep(step) {
            var firstInvalid = null;
            step.querySelectorAll("[data-sm-required]").forEach(function (group) {
                if (group.hidden || group.closest("[hidden]")) return;
                var fields = Array.from(group.querySelectorAll("input, select, textarea")).filter(function (field) { return !field.disabled; });
                var valid = fields.some(function (field) { return fieldValue(field) !== ""; });
                group.classList.toggle("sm-has-error", !valid);
                var message = group.querySelector(".sm-step-error");
                if (!valid && !message) {
                    message = document.createElement("div");
                    message.className = "sm-step-error";
                    message.textContent = "Please complete this field.";
                    group.appendChild(message);
                } else if (valid && message) {
                    message.remove();
                }
                if (!valid && !firstInvalid) firstInvalid = fields[0] || group;
            });
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: "smooth", block: "center" });
                if (firstInvalid.focus) firstInvalid.focus({ preventScroll: true });
            }
            return !firstInvalid;
        }

        function showStep(number, shouldScroll) {
            steps.forEach(function (step) {
                var active = Number(step.dataset.financeStep) === number;
                step.hidden = !active;
                step.classList.toggle("is-active", active);
            });
            progress.forEach(function (item) {
                item.classList.toggle("is-active", Number(item.dataset.progressStep) <= number);
            });
            if (shouldScroll !== false) form.scrollIntoView({ behavior: "smooth", block: "start" });
        }

        [purpose, vehicleInfo, licenceType, employmentType].forEach(function (field) {
            if (field) field.addEventListener("change", updateConditions);
        });
        [purchase, deposit].forEach(function (field) {
            if (field) field.addEventListener("input", updateLoan);
        });
        form.querySelectorAll("input, select, textarea").forEach(function (field) {
            field.addEventListener("input", function () {
                var group = field.closest("[data-sm-required]");
                if (group) {
                    group.classList.remove("sm-has-error");
                    var error = group.querySelector(".sm-step-error");
                    if (error) error.remove();
                }
            });
        });
        var next = form.querySelector(".sm-finance-next");
        var back = form.querySelector(".sm-finance-back");
        if (next) next.addEventListener("click", function () { if (validateStep(steps[0])) showStep(2); });
        if (back) back.addEventListener("click", function () { showStep(1); });

        updateConditions();
        updateLoan();
        showStep(1, false);
    });

    document.addEventListener("wpcf7mailsent", function (event) {
        var form = event.target.querySelector("[data-sm-finance-form]");
        if (form) form.querySelectorAll("[data-finance-step]").forEach(function (step, index) { step.hidden = index !== 0; });
    });
})();

(function () {
    // Sticky nav shadow
    var header = document.querySelector(".sm-header");
    if (header) {
        window.addEventListener("scroll", function () {
            header.classList.toggle("scrolled", window.scrollY > 10);
        });
    }

    // Mobile menu toggle
    var toggle = document.querySelector(".sm-nav-toggle");
    var mobileNav = document.querySelector(".sm-mobile-nav");

    // The branded header is intentionally simple block markup, so enhance its
    // Finance link with the generated application child page at runtime.
    var desktopFinance = Array.from(document.querySelectorAll(".sm-nav a")).find(function (link) {
        return new URL(link.href, window.location.origin).pathname.replace(/\/$/, "") === "/finance";
    });
    if (desktopFinance && !desktopFinance.closest(".sm-nav-dropdown")) {
        var dropdown = document.createElement("span");
        dropdown.className = "sm-nav-dropdown";
        desktopFinance.parentNode.insertBefore(dropdown, desktopFinance);
        dropdown.appendChild(desktopFinance);
        var submenu = document.createElement("span");
        submenu.className = "sm-nav-submenu";
        submenu.innerHTML = "<a href=\"/finance-application/\">Apply for Finance</a>";
        dropdown.appendChild(submenu);
    }
    if (mobileNav && !mobileNav.querySelector(".sm-mobile-finance-application")) {
        var mobileFinance = Array.from(mobileNav.querySelectorAll("a")).find(function (link) {
            return new URL(link.href, window.location.origin).pathname.replace(/\/$/, "") === "/finance";
        });
        if (mobileFinance) {
            var mobileApply = document.createElement("a");
            mobileApply.className = "sm-mobile-finance-application";
            mobileApply.href = "/finance-application/";
            mobileApply.textContent = "Apply for Finance";
            mobileFinance.insertAdjacentElement("afterend", mobileApply);
        }
    }
    if (toggle && mobileNav) {
        toggle.addEventListener("click", function () {
            mobileNav.classList.toggle("open");
            toggle.classList.toggle("open");
            document.body.style.overflow = mobileNav.classList.contains("open") ? "hidden" : "";
        });
    }

    // Close mobile nav on link click
    if (mobileNav) {
        mobileNav.querySelectorAll("a").forEach(function (link) {
            link.addEventListener("click", function () {
                mobileNav.classList.remove("open");
                if (toggle) toggle.classList.remove("open");
                document.body.style.overflow = "";
            });
        });
    }
})();

// Strip → arrows from text nodes inside links and buttons
document.querySelectorAll("a, button").forEach(function (el) {
    el.childNodes.forEach(function (node) {
        if (node.nodeType === 3) {
            node.textContent = node.textContent.replace(/\s*\u2192/g, "");
        }
    });
});

// ─── VEHICLE FILTERS ──────────────────────────────────────────────────────────
(function () {
    var filters = document.querySelectorAll(".sm-filter");
    if (!filters.length) return;

    var active = {};

    function apply() {
        var cards = document.querySelectorAll(".vehicle-index ul.wp-block-post-template > li");
        var shown = 0;
        var term = active.search ? active.search.toLowerCase() : "";
        cards.forEach(function (li) {
            var meta = li.querySelector(".sm-card-meta");
            if (!meta) { shown++; return; }
            var d = meta.dataset;
            var visible = true;
            if (active.make         && d.make         !== active.make)                         visible = false;
            if (active.body         && d.body         !== active.body)                         visible = false;
            if (active.fuel         && d.fuel         !== active.fuel)                         visible = false;
            if (active.transmission && d.transmission !== active.transmission)                 visible = false;
            if (active["max-price"] && parseFloat(d.price) > parseFloat(active["max-price"])) visible = false;
            if (term) {
                var title = li.querySelector(".wp-block-post-title");
                var text  = title ? title.textContent.toLowerCase() : "";
                if (text.indexOf(term) === -1) visible = false;
            }
            li.hidden = !visible;
            if (visible) shown++;
        });
        var countEl = document.querySelector(".sm-filter-count");
        if (countEl) {
            var total = cards.length;
            countEl.textContent = shown === total ? total + " vehicles" : shown + " of " + total + " vehicles";
        }
    }

    filters.forEach(function (el) {
        el.addEventListener("change", function () {
            active[this.dataset.filter] = this.value;
            apply();
        });
    });

    var search = document.querySelector(".sm-filter-search");
    if (search) {
        search.addEventListener("input", function () {
            active.search = this.value.trim();
            apply();
        });
    }

    var reset = document.querySelector(".sm-filter-reset");
    if (reset) {
        reset.addEventListener("click", function () {
            active = {};
            filters.forEach(function (el) { el.value = ""; });
            if (search) search.value = "";
            apply();
        });
    }

    apply();
})();

// ─── VEHICLE GALLERY ──────────────────────────────────────────────────────────
(function () {
    var galleries = document.querySelectorAll(".sm-vehicle-gallery");
    if (!galleries.length) return;

    // Build shared lightbox
    var lb = document.createElement("div");
    lb.className = "sm-lightbox";
    lb.innerHTML =
        "<span class=\"sm-lightbox-close\">&times;</span>" +
        "<span class=\"sm-lightbox-btn sm-lightbox-prev\"></span>" +
        "<img class=\"sm-lightbox-img\" src=\"\" alt=\"\" />" +
        "<span class=\"sm-lightbox-btn sm-lightbox-next\"></span>" +
        "<span class=\"sm-lightbox-counter\"></span>";
    document.body.appendChild(lb);

    var lbImg     = lb.querySelector(".sm-lightbox-img");
    var lbCounter = lb.querySelector(".sm-lightbox-counter");
    var lbActive  = null; // reference to current gallery context

    function lbOpen(ctx, idx) {
        lbActive = ctx;
        lbShow(idx);
        lb.classList.add("open");
        document.body.style.overflow = "hidden";
    }

    function lbClose() {
        lb.classList.remove("open");
        document.body.style.overflow = "";
        lbActive = null;
    }

    function lbShow(idx) {
        if (!lbActive) return;
        var total = lbActive.mainImgs.length;
        idx = ((idx % total) + total) % total;
        lbActive.lbIndex = idx;
        lbImg.src = lbActive.mainImgs[idx].src;
        lbImg.alt = lbActive.mainImgs[idx].alt;
        lbCounter.textContent = (idx + 1) + " / " + total;
    }

    lb.querySelector(".sm-lightbox-close").addEventListener("click", lbClose);
    lb.addEventListener("click", function (e) { if (e.target === lb) lbClose(); });
    lb.querySelector(".sm-lightbox-prev").addEventListener("click", function (e) {
        e.stopPropagation();
        if (lbActive) lbShow(lbActive.lbIndex - 1);
    });
    lb.querySelector(".sm-lightbox-next").addEventListener("click", function (e) {
        e.stopPropagation();
        if (lbActive) lbShow(lbActive.lbIndex + 1);
    });
    document.addEventListener("keydown", function (e) {
        if (!lb.classList.contains("open")) return;
        if (e.key === "Escape")      lbClose();
        if (e.key === "ArrowLeft")   lbShow(lbActive.lbIndex - 1);
        if (e.key === "ArrowRight")  lbShow(lbActive.lbIndex + 1);
    });

    galleries.forEach(function (gallery) {
        var mainImgs = Array.from(gallery.querySelectorAll(".sm-gallery-main-img"));
        var thumbs   = Array.from(gallery.querySelectorAll(".sm-gallery-thumb"));
        var prev     = gallery.querySelector(".sm-gallery-prev");
        var next     = gallery.querySelector(".sm-gallery-next");
        var current  = 0;

        var ctx = { mainImgs: mainImgs, lbIndex: 0 };

        function show(idx) {
            idx = ((idx % mainImgs.length) + mainImgs.length) % mainImgs.length;
            mainImgs[current].classList.remove("active");
            if (thumbs[current]) thumbs[current].classList.remove("active");
            current = idx;
            mainImgs[current].classList.add("active");
            if (thumbs[current]) {
                thumbs[current].classList.add("active");
                thumbs[current].scrollIntoView({ block: "nearest", inline: "nearest" });
            }
        }

        thumbs.forEach(function (thumb, i) {
            thumb.addEventListener("click", function () { show(i); });
        });

        if (prev) prev.addEventListener("click", function (e) { e.stopPropagation(); show(current - 1); });
        if (next) next.addEventListener("click", function (e) { e.stopPropagation(); show(current + 1); });

        var mainEl = gallery.querySelector(".sm-gallery-main");
        if (mainEl) {
            mainEl.addEventListener("click", function () { lbOpen(ctx, current); });
        }
    });
})();

';
}
