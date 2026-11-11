<?php

declare(strict_types=1);

namespace Bileto\Product;

use Bileto\Contract\HasHooks;
use Bileto\Event\EventPostType;

defined('ABSPATH') || exit;

/**
 * Any simple or variable product becomes a ticket with one checkbox and an
 * event. No custom product type, so coupons, Block Checkout and other plugins
 * keep working.
 */
final class TicketProduct implements HasHooks
{
    private const IS_TICKET = '_bileto_is_ticket';
    private const EVENT     = '_bileto_event_id';

    public function registerHooks(): void
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'renderFields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'preventMixedCart'], 10, 2);
    }

    public static function isTicket(?\WC_Product $product): bool
    {
        return $product !== null && self::eventId($product) > 0;
    }

    /** The event a ticket product admits to, or 0 when the product is not a ticket. */
    public static function eventId(\WC_Product $product): int
    {
        $parent = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;

        return $parent instanceof \WC_Product && $parent->get_meta(self::IS_TICKET) === 'yes' ? (int) $parent->get_meta(self::EVENT) : 0;
    }

    public function renderFields(): void
    {
        $events = get_posts(['post_type' => EventPostType::POST_TYPE, 'numberposts' => 100, 'post_status' => 'publish']);
        $options = ['' => __('Select an event', 'bileto')];
        foreach ($events as $event) {
            $options[(string) $event->ID] = $event->post_title;
        }

        echo '<div class="options_group show_if_simple show_if_variable">';
        woocommerce_wp_checkbox([
            'id'          => self::IS_TICKET,
            'label'       => __('Event ticket', 'bileto'),
            'description' => __('Each unit sold becomes one ticket with its own QR code.', 'bileto'),
        ]);
        woocommerce_wp_select([
            'id'      => self::EVENT,
            'label'   => __('Event', 'bileto'),
            'options' => $options,
        ]);
        echo '</div>';
    }

    public function save(\WC_Product $product): void
    {
        // Nonce verified by WooCommerce before this action runs.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $product->update_meta_data(self::IS_TICKET, isset($_POST[self::IS_TICKET]) ? 'yes' : 'no');
        $product->update_meta_data(self::EVENT, isset($_POST[self::EVENT]) ? (string) absint($_POST[self::EVENT]) : '0');
        // phpcs:enable
    }

    /**
     * Tickets carry the VAT of the event country even for VAT-exempt buyers,
     * and WooCommerce exempts a whole customer, not a line. A cart therefore
     * holds either tickets or other products, never both.
     *
     * @param bool $passed
     * @param int  $productId
     */
    public function preventMixedCart($passed, $productId): bool
    {
        if (! $passed || ! WC()->cart || WC()->cart->is_empty()) {
            return (bool) $passed;
        }
        $adding = self::isTicket(wc_get_product($productId));
        foreach (WC()->cart->get_cart() as $item) {
            if (self::isTicket($item['data']) !== $adding) {
                wc_add_notice(__('Tickets are checked out on their own. Finish this order first, then add the other products.', 'bileto'), 'error');

                return false;
            }
        }

        return true;
    }
}
