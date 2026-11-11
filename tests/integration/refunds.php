<?php

/**
 * Seats follow the order: partial refunds, quantity edits in admin, deleted
 * refunds, and gateways whose refunds are asynchronous (PlanetPay statuses).
 *
 * Run: wp eval-file tests/integration/refunds.php
 */

use Bileto\Ticket\TicketRepository;

require_once __DIR__ . '/bootstrap.php';

// The statuses PlanetPay registers (wc-requested-status, wc-approved-status, wc-rejected-status).
add_filter('wc_order_statuses', static fn (array $s): array => $s + ['wc-requested-status' => 'Refund requested', 'wc-approved-status' => 'Refund approved', 'wc-rejected-status' => 'Refund rejected']);

$repo    = new TicketRepository();
$event   = bileto_test_event('PL');
$product = bileto_test_ticket_product($event, '100');

$valid = static function (WC_Order $order) use ($repo): int {
    return count(array_filter($repo->forOrder($order->get_id()), static fn (array $t): bool => $t['status'] === 'valid'));
};

$order = wc_create_order();
$itemId = $order->add_product($product, 3);
$order->set_billing_email('ada@example.com');
$order->set_billing_first_name('Ada');
$order->set_billing_last_name('Buyer');
$order->calculate_totals();
$order->update_status('processing');
$tickets = $repo->forOrder($order->get_id());

// Partial refund of one seat cancels one seat, and an unassigned one first.
$refund = wc_create_refund([
    'order_id'   => $order->get_id(),
    'amount'     => 123,
    'line_items' => [$itemId => ['qty' => 1, 'refund_total' => 100, 'refund_tax' => [array_key_first(WC_Tax::find_rates(['country' => 'PL', 'tax_class' => ''])) => 23]]],
]);
$order = wc_get_order($order->get_id());
bileto_test_assert($valid($order) === 2, 'partial refund of 1 of 3 seats leaves 2 valid tickets');
bileto_test_assert($repo->find((int) $tickets[0]['id'])['status'] === 'valid', 'the buyer\'s own (assigned) ticket survives a partial refund');

// Deleting the refund brings the seat back.
$refund->delete(true);
do_action('woocommerce_refund_deleted', $refund->get_id(), $order->get_id());
bileto_test_assert($valid(wc_get_order($order->get_id())) === 3, 'deleting the refund restores the seat');

// Quantity lowered in admin: one seat less.
$item = $order->get_item($itemId);
$item->set_quantity(2);
$item->save();
do_action('woocommerce_saved_order_items', $order->get_id(), []);
bileto_test_assert($valid(wc_get_order($order->get_id())) === 2, 'lowering the quantity in admin cancels a seat');

// Quantity raised again: a new seat is issued.
$item->set_quantity(4);
$item->save();
do_action('woocommerce_saved_order_items', $order->get_id(), []);
bileto_test_assert($valid(wc_get_order($order->get_id())) === 4, 'raising the quantity in admin issues the missing seats');

// Asynchronous gateway refund (PlanetPay): requested keeps tickets, approved cancels, rejected restores.
$order = wc_get_order($order->get_id());
$order->update_status('requested-status');
bileto_test_assert($valid(wc_get_order($order->get_id())) === 4, 'refund requested at the gateway: tickets stay valid until it is approved');
wc_create_refund(['order_id' => $order->get_id(), 'amount' => 0, 'line_items' => [$itemId => ['qty' => 4, 'refund_total' => 0]]]);
$order = wc_get_order($order->get_id());
$order->update_status('approved-status');
bileto_test_assert($valid(wc_get_order($order->get_id())) === 0, 'refund approved at the gateway cancels the refunded seats');
$order = wc_get_order($order->get_id());
$order->update_status('rejected-status');
bileto_test_assert($valid(wc_get_order($order->get_id())) === 4, 'refund rejected at the gateway restores the seats');

// Full cancel.
$order = wc_get_order($order->get_id());
$order->update_status('cancelled');
bileto_test_assert($valid(wc_get_order($order->get_id())) === 0, 'cancelled order cancels every seat');

global $wpdb;
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bileto_tickets WHERE order_id = %d", $order->get_id()));
$order->delete(true);
bileto_test_cleanup();
bileto_test_done();
