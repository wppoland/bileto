<?php

/**
 * A German business with a VIES-confirmed VAT number buys a ticket for an
 * event in Poland: the ticket carries 23% Polish VAT, not 0% and not 19% DE.
 *
 * Needs a WordPress site with WooCommerce, Bileto, and a 23% PL and 19% DE
 * standard tax rate. Run: wp eval-file tests/integration/event-vat.php
 */

require_once __DIR__ . '/bootstrap.php';

$event   = bileto_test_event('PL');
$product = bileto_test_ticket_product($event, '100');

WC()->customer->set_billing_country('DE');
WC()->customer->set_shipping_country('DE');
WC()->customer->set_is_vat_exempt(true); // What a VIES module sets for a valid EU VAT ID.

WC()->cart->empty_cart();
WC()->cart->add_to_cart($product->get_id(), 2);
WC()->cart->calculate_totals();

$tax = (float) WC()->cart->get_total_tax();
bileto_test_assert(abs($tax - 46.0) < 0.01, 'DE VAT ID buyer pays 23% PL VAT on 2 x 100 (expected 46.00, got ' . $tax . ')');

// The rule does not leak to ordinary products: exemption still works there.
WC()->cart->empty_cart();
$plain = new WC_Product_Simple();
$plain->set_name('Bileto test mug');
$plain->set_regular_price('100');
$plain->save();
WC()->customer->set_is_vat_exempt(true); // The VIES module sets it again on the next checkout update.
WC()->cart->add_to_cart($plain->get_id(), 1);
WC()->cart->calculate_totals();
bileto_test_assert((float) WC()->cart->get_total_tax() === 0.0, 'non-ticket product stays VAT exempt for the same buyer');

// Mixed cart is refused.
// The form handler and the Store API run this filter before adding to the cart.
$added = apply_filters('woocommerce_add_to_cart_validation', true, $product->get_id(), 1);
bileto_test_assert($added === false, 'a ticket cannot join a cart with other products');
wc_clear_notices();

WC()->cart->empty_cart();
WC()->customer->set_is_vat_exempt(false);
$plain->delete(true);
bileto_test_cleanup();
bileto_test_done();
