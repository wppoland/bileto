<?php

/**
 * Helpers for integration checks run inside a real site with `wp eval-file`.
 */

if (! defined('ABSPATH') || ! class_exists('WooCommerce')) {
    fwrite(STDERR, "Run with: wp eval-file <file> on a site with WooCommerce and Bileto active.\n");
    exit(1);
}

$GLOBALS['bileto_test_failures'] = 0;
$GLOBALS['bileto_test_posts']    = [];

if (! WC()->session) {
    WC()->initialize_session();
}
if (! WC()->customer) {
    WC()->customer = new WC_Customer(0, true);
}
if (! WC()->cart) {
    WC()->cart = new WC_Cart();
}

function bileto_test_assert(bool $ok, string $message): void
{
    echo ($ok ? 'OK   ' : 'FAIL ') . $message . "\n";
    if (! $ok) {
        $GLOBALS['bileto_test_failures']++;
    }
}

function bileto_test_event(string $country): int
{
    $id = wp_insert_post(['post_type' => 'bileto_event', 'post_status' => 'publish', 'post_title' => 'Bileto test event']);
    update_post_meta($id, '_bileto_country', $country);
    update_post_meta($id, '_bileto_start', '2027-05-20T09:00');
    $GLOBALS['bileto_test_posts'][] = $id;

    return $id;
}

function bileto_test_ticket_product(int $eventId, string $price): WC_Product_Simple
{
    $product = new WC_Product_Simple();
    $product->set_name('Bileto test ticket');
    $product->set_regular_price($price);
    $product->set_virtual(true);
    $product->update_meta_data('_bileto_is_ticket', 'yes');
    $product->update_meta_data('_bileto_event_id', $eventId);
    $product->save();
    $GLOBALS['bileto_test_posts'][] = $product->get_id();

    return $product;
}

function bileto_test_cleanup(): void
{
    foreach ($GLOBALS['bileto_test_posts'] as $id) {
        wp_delete_post($id, true);
    }
}

function bileto_test_done(): void
{
    $failures = $GLOBALS['bileto_test_failures'];
    echo $failures === 0 ? "All checks passed\n" : "{$failures} check(s) failed\n";
    exit($failures === 0 ? 0 : 1);
}
