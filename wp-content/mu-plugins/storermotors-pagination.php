<?php
/**
 * Plugin Name: Storer Motors Pagination Styling
 * Description: Styles the query pagination on the vehicle inventory archive.
 *
 * Kept out of storermotors-branding.php deliberately: that file has diverged
 * between the local tree and the live host, so additive work goes in its own
 * file rather than risking a whole-file overwrite.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Priority 20 — sm-branding registers its handle at the default 10.
add_action('wp_enqueue_scripts', function () {
    if (!wp_style_is('sm-branding', 'registered')) {
        return;
    }

    wp_add_inline_style('sm-branding', '
.wp-block-query-pagination {
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 48px;
    padding-top: 28px;
    border-top: 1px solid var(--sm-border);
}

.wp-block-query-pagination-numbers {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.wp-block-query-pagination .page-numbers,
.wp-block-query-pagination-previous,
.wp-block-query-pagination-next {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-width: 42px;
    height: 42px;
    padding: 0 14px;
    font-family: var(--sm-font-body);
    font-size: 14px;
    font-weight: 600;
    line-height: 1;
    color: var(--sm-dark);
    text-decoration: none;
    background: var(--sm-white);
    border: 1px solid var(--sm-border);
    border-radius: var(--sm-radius);
    transition: var(--sm-transition);
}

.wp-block-query-pagination a.page-numbers:hover,
a.wp-block-query-pagination-previous:hover,
a.wp-block-query-pagination-next:hover {
    color: var(--sm-black);
    background: var(--sm-yellow-pale);
    border-color: var(--sm-yellow);
}

.wp-block-query-pagination .page-numbers.current {
    color: var(--sm-black);
    background: var(--sm-yellow);
    border-color: var(--sm-yellow);
}

/* Ellipsis is not a control — strip the button chrome. */
.wp-block-query-pagination .page-numbers.dots {
    min-width: 0;
    padding: 0 4px;
    color: var(--sm-gray-light);
    background: none;
    border: none;
}

.wp-block-query-pagination-previous,
.wp-block-query-pagination-next {
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

/* Disabled arrows: WordPress renders these as a span, not a link. */
span.wp-block-query-pagination-previous,
span.wp-block-query-pagination-next {
    color: var(--sm-gray-light);
    background: var(--sm-bg);
    cursor: default;
}

@media (max-width: 600px) {
    .wp-block-query-pagination {
        justify-content: center;
        gap: 8px;
    }

    .wp-block-query-pagination .page-numbers,
    .wp-block-query-pagination-previous,
    .wp-block-query-pagination-next {
        min-width: 38px;
        height: 38px;
        padding: 0 10px;
        font-size: 13px;
    }
}
');
}, 20);
