<?php

/**
 * Orders sold elsewhere come in as history: amounts and VAT as booked (also
 * 0%), original dates, tickets only for completed orders, no e-mail at all,
 * and a second run imports nothing twice. Synthetic data only.
 *
 * Run: wp eval-file tests/integration/order-import.php
 */

use Bileto\Import\OrderImporter;
use Bileto\Ticket\TicketRepository;
use Bileto\Ticket\TicketService;

require_once __DIR__ . '/bootstrap.php';

$repo     = new TicketRepository();
$importer = new OrderImporter(new TicketService($repo));
$event    = bileto_test_event('PL');

// Completed orders do fire WooCommerce and Bileto mails; none may reach PHPMailer,
// and WooCommerce's handlers (send or queue for later) must be off during the run.
$mails = 0;
add_action('phpmailer_init', static function () use (&$mails): void {
    $mails++;
});
$handler      = static fn (): bool => (bool) (has_action('woocommerce_order_status_completed', ['WC_Emails', 'send_transactional_email']) || has_action('woocommerce_order_status_completed', ['WC_Emails', 'queue_transactional_email']));
$hookedBefore = $handler();
$hookedDuring = false;
add_action('bileto_issued', static function () use ($handler, &$hookedDuring): void {
    $hookedDuring = $hookedDuring || $handler();
});

$stockProduct = new WC_Product_Simple();
$stockProduct->set_name('Bileto stock ticket');
$stockProduct->set_manage_stock(true);
$stockProduct->set_stock_quantity(10);
\Bileto\Product\TicketProduct::link($stockProduct, $event);
$stockProduct->save();

$buyer = ['first_name' => 'Ada', 'last_name' => 'Test', 'email' => 'ada@example.com', 'company' => 'ACME', 'tax_id' => '1234567890', 'phone' => '', 'address' => 'Street 1', 'postcode' => '00-001', 'city' => 'Town', 'country' => 'PL'];
$data  = ['source' => 'bileto-test', 'orders' => [
    ['id' => 'T1', 'status' => 'completed', 'created_at' => '2026-03-01T10:00:00+01:00', 'paid_at' => '2026-03-01T10:05:00+01:00', 'currency' => 'eur', 'payment_method' => 'Card', 'buyer' => $buyer,
        'items' => [['name' => 'Bileto test ticket', 'quantity' => 2, 'net' => 20000, 'tax' => 4600, 'tax_rate' => 23]], 'invoices' => ['TEST/1/2026', 'TEST-K/1/2026']],
    ['id' => 'T2', 'status' => 'completed', 'created_at' => '2026-03-02T10:00:00+01:00', 'paid_at' => null, 'currency' => 'EUR', 'payment_method' => 'Card', 'buyer' => ['email' => 'bob@example.com', 'country' => 'DE'],
        'items' => [['name' => 'Bileto test ticket', 'quantity' => 1, 'net' => 15000, 'tax' => 0, 'tax_rate' => 0]], 'invoices' => []],
    ['id' => 'T3', 'status' => 'cancelled', 'created_at' => '2026-03-03T10:00:00+01:00', 'paid_at' => null, 'currency' => 'EUR', 'payment_method' => '', 'buyer' => ['email' => 'cy@example.com'],
        'items' => [['name' => 'Bileto test ticket', 'quantity' => 1, 'net' => 10000, 'tax' => 2300, 'tax_rate' => 23]], 'invoices' => []],
    ['id' => 'T4', 'status' => 'pending', 'created_at' => '2026-03-04T10:00:00+01:00', 'currency' => 'EUR', 'buyer' => [], 'items' => []],
    ['id' => 'T5', 'status' => 'completed', 'created_at' => '2026-03-05T10:00:00+01:00', 'currency' => 'EUR', 'buyer' => ['email' => 'dee@example.com'],
        'items' => [['name' => 'Bileto test ticket', 'quantity' => 1, 'net' => 100.5, 'tax' => 0, 'tax_rate' => 0]]],
    ['status' => 'completed', 'created_at' => '2026-03-06T10:00:00+01:00', 'currency' => 'EUR', 'items' => []],
    ['id' => 'T7', 'status' => 'completed', 'created_at' => 'yesterday-ish', 'currency' => 'EUR', 'items' => [['name' => 'x', 'quantity' => 1, 'net' => 0, 'tax' => 0]]],
    ['id' => 'T8', 'status' => 'completed', 'created_at' => '2026-03-08T23:30:00.000Z', 'paid_at' => null, 'currency' => 'EUR', 'payment_method' => '', 'buyer' => ['email' => 'eve@example.com'],
        'items' => [['name' => 'Bileto stock ticket', 'quantity' => 2, 'net' => 0, 'tax' => 0, 'tax_rate' => 0]], 'invoices' => []],
]];

$dry = $importer->import($data, $event, true);
bileto_test_assert($dry['imported'] === 4 && wc_get_orders(['meta_key' => OrderImporter::META, 'meta_compare' => 'LIKE', 'meta_value' => 'bileto-test:', 'return' => 'ids', 'status' => 'any']) === [], 'a dry run counts the orders and writes nothing');

$first = $importer->import($data, $event);
bileto_test_assert($first['imported'] === 4 && $first['tickets'] === 5 && count($first['errors']) === 4, 'completed and cancelled orders come in; a bad status, amount in major units, missing id and bad date are reported');
bileto_test_assert($mails === 0, 'no e-mail leaves during the import');
bileto_test_assert($hookedBefore && ! $hookedDuring && $handler(), 'WooCommerce e-mail handlers are off during the run (deferred mails too) and back afterwards');

$ids    = wc_get_orders(['meta_key' => OrderImporter::META, 'meta_compare' => 'LIKE', 'meta_value' => 'bileto-test:', 'return' => 'ids', 'status' => 'any', 'orderby' => 'ID', 'order' => 'ASC']);
$orders = array_map('wc_get_order', $ids);
[$o1, $o2, $o3, $o8] = $orders;
bileto_test_assert($o1->get_total() === '246.00' && (float) $o1->get_total_tax() === 46.0 && $o1->get_currency() === 'EUR', 'a 23% order keeps its booked total and VAT');
bileto_test_assert($o2->get_total() === '150.00' && (float) $o2->get_total_tax() === 0.0, 'a 0% order stays at 0%, not recalculated to the event rate');
bileto_test_assert($o1->get_date_created()->date('Y-m-d') === '2026-03-01' && $o1->get_status() === 'completed' && $o3->get_status() === 'cancelled', 'original date and status are kept');
bileto_test_assert($o1->get_meta('_polski_external_invoice') === 'TEST/1/2026, TEST-K/1/2026' && $o1->get_meta('_billing_nip') === '1234567890', 'invoice numbers and tax ID are kept for invoicing plugins');
$t1 = $repo->forOrder($o1->get_id());
bileto_test_assert(count($t1) === 2 && $t1[0]['email'] === 'ada@example.com' && $t1[1]['email'] === '', 'the buyer holds the first seat, the second waits for a name');
bileto_test_assert($repo->forOrder($o3->get_id()) === [], 'a cancelled order issues no tickets');
bileto_test_assert($o8->get_meta('_polski_external_invoice') === 'bileto-test order T8', 'an order without invoice numbers is still marked as not to be invoiced here');
bileto_test_assert(wc_get_product($stockProduct->get_id())->get_stock_quantity() === 10, 'history does not use up the stock of a live ticket product');
bileto_test_assert($o8->get_date_created()->getTimestamp() === strtotime('2026-03-08T23:30:00Z'), 'a UTC date with milliseconds keeps its exact moment');
$items   = $o1->get_items();
$product = wc_get_product(reset($items)->get_product_id());
bileto_test_assert($product && $product->get_status() === 'private' && \Bileto\Product\TicketProduct::eventId($product) === $event, 'the ticket type becomes a private ticket product of the event');

$again = $importer->import($data, $event);
bileto_test_assert($again['imported'] === 0 && $again['skipped'] === 4, 'a second run imports nothing twice');

global $wpdb;
foreach ($orders as $order) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bileto_tickets WHERE order_id = %d", $order->get_id()));
    $order->delete(true);
}
$product && $product->delete(true);
$stockProduct->delete(true);
bileto_test_cleanup();
bileto_test_done();
