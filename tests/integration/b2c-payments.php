<?php

/**
 * The B2C payments report lists paid orders of private buyers in the period
 * with their gateway references and refunds; business buyers (NIP, EU VAT ID,
 * filter), unpaid orders and orders paid outside the period stay out.
 *
 * Run: wp eval-file tests/integration/b2c-payments.php
 */

use Bileto\Admin\PaymentsReport;

require_once __DIR__ . '/bootstrap.php';

$event   = bileto_test_event('PL');
$product = bileto_test_ticket_product($event, '100');
$orders  = [];

$make = static function (string $paid, array $meta = [], string $status = 'processing', string $currency = 'PLN') use ($product, &$orders): WC_Order {
    $order = wc_create_order();
    $order->add_product($product, 1);
    $order->set_currency($currency);
    $order->set_payment_method_title('Card');
    $order->set_transaction_id('ch_test_' . wp_generate_password(6, false));
    foreach ($meta as $key => $value) {
        $order->update_meta_data($key, $value);
    }
    $order->calculate_totals();
    $order->set_status($status);
    if ($paid !== '') {
        $order->set_date_paid((new DateTimeImmutable(strlen($paid) > 10 ? $paid : $paid . ' 12:00', wp_timezone()))->getTimestamp());
    }
    $order->save();
    $orders[] = $order;

    return $order;
};

$b2c      = $make('2031-04-10', ['_stripe_intent_id' => 'pi_test', '_stripe_fee' => '3.00', '_stripe_net' => '120.00', '_stripe_currency' => 'PLN', '_stripe_customer_id' => 'cus_secret']);
$eur      = $make('2031-04-11', ['_stripe_fee' => '10.00', '_stripe_net' => '400.00', '_stripe_currency' => 'pln'], 'completed', 'EUR');
$noRate   = $make('2031-04-12', [], 'completed', 'EUR');
$nip      = $make('2031-04-10', ['_billing_nip' => '5260250274']);
$vat      = $make('2031-04-10', ['_billing_vat_id' => 'DE123456789']);
$filtered = $make('2031-04-10');
$unpaid   = $make('', [], 'pending');
$outside  = $make('2031-05-01');
$first    = $make('2031-04-01 00:30');
$last     = $make('2031-04-30 23:30');
$before   = $make('2031-03-31 23:30');
$after    = $make('2031-05-01 00:30');
$free     = $make('2031-04-10');
$free->set_total('0');
$free->save();

wc_create_refund(['order_id' => $b2c->get_id(), 'amount' => '23']);
add_filter('bileto_buyer_is_business', static fn ($is, $order) => $is || $order->get_id() === $filtered->get_id(), 10, 2);

$rows = PaymentsReport::rows('2031-04-01', '2031-04-30');
$ids  = array_column($rows, 'order_id');
bileto_test_assert($ids === [$first->get_id(), $b2c->get_id(), $eur->get_id(), $noRate->get_id(), $last->get_id()], 'only paid private orders of the period, oldest first, first and last day whole in site time');
bileto_test_assert(! array_intersect($ids, [$before->get_id(), $after->get_id(), $free->get_id()]), 'the minutes around the period and free orders stay out');
bileto_test_assert(! array_intersect($ids, [$nip->get_id(), $vat->get_id(), $filtered->get_id()]), 'NIP, EU VAT ID and the filter make a business buyer');
bileto_test_assert(! array_intersect($ids, [$unpaid->get_id(), $outside->get_id()]), 'unpaid orders and orders paid outside the period stay out');

$row = $rows[1];
bileto_test_assert($row['gross'] === 123.0 && $row['refunded'] === 23.0 && $row['net'] === 100.0 && $row['pln'] === 100.0, 'a partial refund is subtracted, PLN orders give their PLN amount');
bileto_test_assert($row['transaction'] === $b2c->get_transaction_id() && $row['intent'] === 'pi_test' && $row['method'] === 'Card', 'the row carries the gateway transaction, payment intent and method');
bileto_test_assert($rows[2]['pln'] === 410.0 && $rows[3]['pln'] === null, 'a foreign order settled by Stripe in PLN gets fee plus net, without a stored rate it stays empty');
bileto_test_assert(! in_array('cus_secret', array_merge(...array_map('array_values', $rows)), true), 'the report carries no Stripe customer ID');

$totals = PaymentsReport::totals($rows);
bileto_test_assert($totals['currencies']['PLN']['net'] === 346.0 && $totals['currencies']['EUR']['orders'] === 2 && $totals['pln'] === 756.0 && $totals['pln_missing'] === 1, 'totals per currency and in PLN');

foreach ($orders as $order) {
    $order->delete(true);
}
bileto_test_cleanup();
bileto_test_done();
