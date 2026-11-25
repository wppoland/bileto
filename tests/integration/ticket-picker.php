<?php

/**
 * [bileto_tickets] lists the event's purchasable tickets, cheapest first, and
 * leaves out other events and plain products.
 *
 * Run: wp eval-file tests/integration/ticket-picker.php
 */

require_once __DIR__ . '/bootstrap.php';

$event   = bileto_test_event('PL');
$other   = bileto_test_event('PL');
$regular = bileto_test_ticket_product($event, '150');
$blind   = bileto_test_ticket_product($event, '50');
$foreign = bileto_test_ticket_product($other, '10');

$plain = new WC_Product_Simple();
$plain->set_name('Bileto test mug');
$plain->set_regular_price('5');
$plain->save();
$GLOBALS['bileto_test_posts'][] = $plain->get_id();

$ids = array_map(static fn (WC_Product $p): int => $p->get_id(), Bileto\Front\TicketPicker::products($event));
bileto_test_assert($ids === [$blind->get_id(), $regular->get_id()], 'event tickets only, cheapest first');

$html = do_shortcode('[bileto_tickets event="' . $event . '"]');
bileto_test_assert(substr_count($html, 'bileto-picker__row') === 2, 'one row per ticket');
bileto_test_assert(str_contains($html, 'data-product="' . $blind->get_id() . '"'), 'row carries the product ID');
bileto_test_assert(do_shortcode('[bileto_tickets event="999999999"]') === '', 'no markup without tickets');

update_post_meta($event, '_bileto_start', '2027-09-23T09:00');
update_post_meta($event, '_bileto_end', '2027-09-25T18:00');
bileto_test_assert(str_ends_with(Bileto\Event\EventPostType::days($event), '2027') && str_starts_with(Bileto\Event\EventPostType::days($event), '23-25 '), 'days in one month read "23-25 Month 2027"');
update_post_meta($other, '_bileto_start', '2027-09-30T09:00');
update_post_meta($other, '_bileto_end', '2027-10-02T18:00');
bileto_test_assert(substr_count(Bileto\Event\EventPostType::days($other), ' - ') === 1, 'days across months use " - "');
bileto_test_assert(str_contains(do_shortcode('[bileto_event id="' . $event . '"]'), 'bileto-event__facts'), 'event shortcode prints days and venue');

$item = new WC_Order_Item_Product();
$item->set_name($blind->get_name());
(new Bileto\Product\TicketProduct())->nameOrderLine($item, 'k', ['data' => $blind]);
bileto_test_assert($item->get_name() === 'Bileto test event - Bileto test ticket', 'order line carries the event name');
(new Bileto\Product\TicketProduct())->nameOrderLine($item, 'k', ['data' => $blind]);
bileto_test_assert($item->get_name() === 'Bileto test event - Bileto test ticket', 'event name added once');

$savedNote = get_option('bileto_onepage_note', '');
update_option('bileto_onepage_note', "Secure payment.\n<a href=\"https://example.com/refunds\">Refund policy</a>");
add_filter('woocommerce_is_checkout', '__return_true');
$wrapped = (new Bileto\Front\OnePageCheckout())->wrapCheckout('<div class="wc-checkout"></div>');
remove_filter('woocommerce_is_checkout', '__return_true');
update_option('bileto_onepage_note', $savedNote);
bileto_test_assert(str_contains($wrapped, '<ul class="bileto-onepage__note"><li>Secure payment.</li><li><a href="https://example.com/refunds">Refund policy</a></li></ul>'), 'note lines print under the form, links kept');

bileto_test_cleanup();
bileto_test_done();
