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

bileto_test_cleanup();
bileto_test_done();
