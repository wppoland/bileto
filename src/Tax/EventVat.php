<?php

declare(strict_types=1);

namespace Bileto\Tax;

use Bileto\Contract\HasHooks;
use Bileto\Event\EventPostType;
use Bileto\Product\TicketProduct;

defined('ABSPATH') || exit;

/**
 * Admission to an event is taxed where the event takes place, also for
 * business buyers with a VAT number (Directive 2006/112/EC art. 53; in Poland
 * art. 28g(1) of the VAT Act). So a ticket gets the rate of the event country,
 * whatever the billing address, and a VIES exemption does not remove it.
 *
 * Filter `bileto_force_event_vat` (bool) turns the rule off, e.g. for online
 * events, which follow different rules.
 */
final class EventVat implements HasHooks
{
    public function registerHooks(): void
    {
        add_filter('woocommerce_cart_totals_get_item_tax_rates', [$this, 'eventRates'], 20, 2);
        // WooCommerce reads the customer's exemption flag directly (no filter), and
        // VIES modules set it on these hooks, so it is reset late on each of them.
        foreach (['woocommerce_before_calculate_totals', 'woocommerce_checkout_process', 'woocommerce_store_api_checkout_update_customer_from_request'] as $hook) {
            add_action($hook, [$this, 'keepVatOnTickets'], 1000);
        }
        add_filter('woocommerce_order_is_vat_exempt', [$this, 'keepVatOnTicketOrder'], 20, 2);
        add_filter('woocommerce_order_get_tax_location', [$this, 'orderLocation'], 20, 2);
    }

    /**
     * Orders created or recalculated in admin are taxed by order address, not
     * by cart item, so a ticket order takes the event country as its location.
     *
     * @param array<string, string> $location
     * @param \WC_Abstract_Order    $order
     * @return array<string, string>
     */
    public function orderLocation($location, $order): array
    {
        $eventId = self::enabled() && $order instanceof \WC_Abstract_Order ? self::ticketEvent($order) : 0;

        return $eventId > 0
            ? ['country' => EventPostType::country($eventId), 'state' => '', 'postcode' => '', 'city' => ''] + (array) $location
            : (array) $location;
    }

    private static function ticketEvent(\WC_Abstract_Order $order): int
    {
        foreach ($order->get_items() as $item) {
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
            if ($product instanceof \WC_Product && TicketProduct::isTicket($product)) {
                return TicketProduct::eventId($product);
            }
        }

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rates
     * @param object                           $item  Cart totals item with a `product` property.
     * @return array<int, array<string, mixed>>
     */
    public function eventRates($rates, $item): array
    {
        $product = $item->product ?? null;
        if (! $product instanceof \WC_Product || ! TicketProduct::isTicket($product) || ! self::enabled()) {
            return (array) $rates;
        }

        return \WC_Tax::find_rates([
            'country'   => EventPostType::country(TicketProduct::eventId($product)),
            'state'     => '',
            'postcode'  => '',
            'city'      => '',
            'tax_class' => $product->get_tax_class(),
        ]);
    }

    public function keepVatOnTickets(): void
    {
        $customer = WC()->customer;
        if (! self::enabled() || ! $customer || ! $customer->get_is_vat_exempt() || ! WC()->cart) {
            return;
        }
        foreach (WC()->cart->get_cart() as $item) {
            if (TicketProduct::isTicket($item['data'] ?? null)) {
                $customer->set_is_vat_exempt(false);

                return;
            }
        }
    }

    /**
     * Recalculating an order in admin must not drop the VAT from tickets either.
     *
     * @param bool      $exempt
     * @param \WC_Order $order
     */
    public function keepVatOnTicketOrder($exempt, $order): bool
    {
        if (! $exempt || ! self::enabled() || ! $order instanceof \WC_Order) {
            return (bool) $exempt;
        }

        return self::ticketEvent($order) === 0;
    }

    private static function enabled(): bool
    {
        return (bool) apply_filters('bileto_force_event_vat', true);
    }
}
