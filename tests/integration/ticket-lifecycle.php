<?php

/**
 * Paid order issues one ticket per unit; the buyer holds the first one.
 * Assigning, transferring, checking in and refunding behave as the plan says.
 *
 * Run: wp eval-file tests/integration/ticket-lifecycle.php
 */

use Bileto\Ticket\TicketRepository;
use Bileto\Ticket\TicketService;

require_once __DIR__ . '/bootstrap.php';

$repo    = new TicketRepository();
$service = new TicketService($repo);
$event   = bileto_test_event('PL');
$product = bileto_test_ticket_product($event, '100');

$order = wc_create_order();
$order->add_product($product, 3);
$order->set_billing_first_name('Ada');
$order->set_billing_last_name('Buyer');
$order->set_billing_email('ada@example.com');
$order->set_billing_company('ACME');
$order->calculate_totals();
$order->save();

$order->update_status('processing');
$tickets = $repo->forOrder($order->get_id());
bileto_test_assert(count($tickets) === 3, 'paid order with quantity 3 issues 3 tickets');
bileto_test_assert($tickets[0]['email'] === 'ada@example.com' && $tickets[1]['email'] === '', 'buyer holds the first ticket, the rest wait for assignment');
bileto_test_assert(strlen($tickets[0]['token']) === 32 && $tickets[0]['token'] !== $tickets[1]['token'], 'each ticket has its own 128-bit token');
bileto_test_assert($tickets[0]['code'] !== $tickets[0]['token'] && $repo->byToken($tickets[0]['code']) === null, 'the QR code cannot open or transfer the ticket');
bileto_test_assert($repo->create(['order_id' => $order->get_id(), 'order_item_id' => (int) $tickets[0]['order_item_id'], 'seat' => 0, 'product_id' => $product->get_id(), 'event_id' => $event]) === 0, 'a second payment callback cannot issue the same seat twice');

$order->update_status('completed');
bileto_test_assert(count($repo->forOrder($order->get_id())) === 3, 'a second paid status does not issue tickets twice');

$service->assign((int) $tickets[1]['id'], ['first_name' => 'Bob', 'last_name' => 'Guest', 'email' => 'bob@example.com', 'company' => '']);
$bob = $repo->find((int) $tickets[1]['id']);
bileto_test_assert($bob !== null && $bob['email'] === 'bob@example.com' && $bob['token'] === $tickets[1]['token'], 'assigning an empty seat keeps its token');

$service->transfer((int) $tickets[1]['id'], ['first_name' => 'Cy', 'last_name' => 'New', 'email' => 'cy@example.com', 'company' => ''], 0);
$cy = $repo->find((int) $tickets[1]['id']);
bileto_test_assert($cy !== null && $cy['email'] === 'cy@example.com' && $cy['token'] !== $tickets[1]['token'] && $cy['code'] !== $tickets[1]['code'], 'transfer gives the new holder a new link and a new QR code');
bileto_test_assert($repo->byCode($tickets[1]['code']) === null, 'the old QR code stops working after a transfer');
bileto_test_assert($repo->byToken($tickets[1]['token']) === null, 'the old token stops working after a transfer');
bileto_test_assert((float) wc_get_order($order->get_id())->get_total() === (float) $order->get_total(), 'transfer leaves the order and its invoice untouched');

$first  = $service->checkIn($repo->byCode($cy['code']), $event, 0);
$second = $service->checkIn($repo->byCode($cy['code']), $event, 0);
bileto_test_assert($first['code'] === 'ok' && $second['code'] === 'already', 'second scan on the same day reports "already checked in"');
bileto_test_assert($service->checkIn($repo->byCode('nope'), $event, 0)['code'] === 'unknown', 'unknown code is refused');
bileto_test_assert($service->checkIn($repo->byCode($cy['code']), $event + 999, 0)['code'] === 'other_event', 'a ticket for another event is refused at this door');
bileto_test_assert($repo->stats($event) === ['total' => 3, 'in' => 1], 'door counter counts this event only');

$order->update_status('refunded');
bileto_test_assert($service->checkIn($repo->find((int) $tickets[0]['id']), $event, 0)['code'] === 'cancelled', 'refunded order cancels its tickets');

$order->update_status('processing');
bileto_test_assert($repo->find((int) $tickets[0]['id'])['status'] === 'valid' && count($repo->forOrder($order->get_id())) === 3, 'an order paid again gets its tickets back, without new ones');

global $wpdb;
$ids = implode(',', array_map('intval', array_column($repo->forOrder($order->get_id()), 'id')));
$wpdb->query("DELETE FROM {$wpdb->prefix}bileto_checkins WHERE ticket_id IN ($ids)");
$wpdb->query("DELETE FROM {$wpdb->prefix}bileto_transfers WHERE ticket_id IN ($ids)");
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bileto_tickets WHERE order_id = %d", $order->get_id()));
$order->delete(true);
bileto_test_cleanup();
bileto_test_done();
