<?php

/**
 * The admin list shows every ticket of one event with its check-in, and the
 * CSV export cannot carry a spreadsheet formula typed in by a buyer.
 *
 * Run: wp eval-file tests/integration/admin-tickets.php
 */

use Bileto\Admin\TicketsScreen;
use Bileto\Ticket\TicketRepository;
use Bileto\Ticket\TicketService;

require_once __DIR__ . '/bootstrap.php';

$repo    = new TicketRepository();
$service = new TicketService($repo);
$event   = bileto_test_event('PL');
$other   = bileto_test_event('PL');
$product = bileto_test_ticket_product($event, '100');

$order = wc_create_order();
$order->add_product($product, 2);
$order->set_billing_first_name('=HYPERLINK("x")');
$order->set_billing_last_name('Buyer');
$order->set_billing_email('ada@example.com');
$order->calculate_totals();
$order->update_status('processing');

$tickets = $repo->forOrder($order->get_id());
$service->checkIn($repo->find((int) $tickets[0]['id']), $event, 0);

$rows = $repo->forEvent($event);
bileto_test_assert(count($rows) === 2, 'the list has every seat of the event, assigned or not');
bileto_test_assert(count(array_filter(array_column($rows, 'checked_in'))) === 1, 'the checked-in seat carries its check-in time');
bileto_test_assert(! array_key_exists('token', $rows[0]) && ! array_key_exists('code', $rows[0]), 'the list carries no ticket secrets');
bileto_test_assert($repo->forEvent($other) === [], 'another event lists none of these tickets');
bileto_test_assert(TicketsScreen::csvCell('=HYPERLINK("x")') === '\'=HYPERLINK("x")' && TicketsScreen::csvCell('-1') === "'-1" && TicketsScreen::csvCell('Ada') === 'Ada', 'CSV cells starting with a formula character are defused');

global $wpdb;
$ids = implode(',', array_map('intval', array_column($tickets, 'id')));
$wpdb->query("DELETE FROM {$wpdb->prefix}bileto_checkins WHERE ticket_id IN ($ids)");
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bileto_tickets WHERE order_id = %d", $order->get_id()));
$order->delete(true);
bileto_test_cleanup();
bileto_test_done();
