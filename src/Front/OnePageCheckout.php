<?php

declare(strict_types=1);

namespace Bileto\Front;

use Bileto\Contract\HasHooks;
use Bileto\Event\EventPostType;

defined('ABSPATH') || exit;

/**
 * One-page ticket checkout, every part a setting under WooCommerce > Settings
 * > Products > Bileto: the event and the ticket picker next to the Checkout
 * block, the home page sent to checkout, and checkout touches (order summary
 * as "2 x Regular", coupon field open, labels above fields, total on the pay
 * button). Each touch is a body class or a
 * flag read by assets/picker.js, so a shop switches them one by one.
 *
 * existing-checked: Front/TicketPicker.php - it renders the [bileto_tickets] and [bileto_event] markup; this class decides where and when the checkout shows it.
 */
final class OnePageCheckout implements HasHooks
{
    private const SECTION = 'bileto';

    /** Option name => default. A checkbox when the default is yes/no. */
    private const DEFAULTS = [
        'bileto_onepage'              => 'no',
        'bileto_onepage_layout'       => 'columns',
        'bileto_onepage_heading'      => 'Get your ticket for %s',
        'bileto_onepage_event'        => 'yes',
        'bileto_onepage_preselect'    => 'yes',
        'bileto_onepage_home'         => 'no',
        'bileto_onepage_quiet_add'    => 'yes',
        'bileto_checkout_qty_names'   => 'yes',
        'bileto_checkout_coupon_open' => 'yes',
        'bileto_checkout_labels'      => 'yes',
        'bileto_checkout_pay_total'   => 'yes',
        'bileto_line_event'           => 'yes',
        'bileto_onepage_note'         => '',
    ];

    /** @return array<string, array{0: string, 1: string}> Option name => [label, description]. */
    private static function texts(): array
    {
        return [
            'bileto_onepage'              => [__('One-page checkout', 'bileto'), __('Show the event and the tickets with quantity buttons next to the Checkout block. An empty cart stays on checkout.', 'bileto')],
            'bileto_onepage_layout'       => [__('Layout', 'bileto'), __('Two columns (event and tickets on the left, form on the right) or tickets above the form.', 'bileto')],
            /* translators: %s stays as typed: it is replaced by the event name. */
            'bileto_onepage_heading'      => [__('Heading', 'bileto'), __('%s is the event name. Leave empty for no heading.', 'bileto')],
            'bileto_onepage_event'        => [__('Event details', 'bileto'), __('Days, venue and the event description (clamped, with "Read more").', 'bileto')],
            'bileto_onepage_preselect'    => [__('Preselect a ticket', 'bileto'), __('An empty cart gets one seat of the cheapest ticket, so the form shows at once.', 'bileto')],
            'bileto_onepage_home'         => [__('Home page opens checkout', 'bileto'), __('Redirect the front page to checkout.', 'bileto')],
            'bileto_onepage_quiet_add'    => [__('No "added to cart" notice', 'bileto'), __('Adding a ticket shows no notice bar: the picker already shows the cart.', 'bileto')],
            'bileto_checkout_qty_names'   => [__('Quantity in the summary', 'bileto'), __('Order summary lines read "2 x Regular", without the quantity badge and the ticket points.', 'bileto')],
            'bileto_checkout_coupon_open' => [__('Coupon field open', 'bileto'), __('Show the coupon field with its Apply button instead of a link that opens it.', 'bileto')],
            'bileto_checkout_labels'      => [__('Labels above fields', 'bileto'), __('Field labels stay above the input instead of inside it.', 'bileto')],
            'bileto_checkout_pay_total'   => [__('Total on the pay button', 'bileto'), __('The place-order button reads "Pay" and the order total.', 'bileto')],
            'bileto_onepage_note'         => [__('Note under the pay button', 'bileto'), __('Short lines shown under the form, e.g. secure payment and the refund rules with a link. One line per row; links allowed.', 'bileto')],
            'bileto_line_event'           => [__('Event in order lines', 'bileto'), __('Order, invoice and e-mail lines read "Event - Ticket" when the ticket name lacks the event.', 'bileto')],
        ];
    }

    public function registerHooks(): void
    {
        add_filter('woocommerce_get_sections_products', [$this, 'section']);
        add_filter('woocommerce_get_settings_products', [$this, 'settings'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(\Bileto\PLUGIN_FILE), [$this, 'settingsLink']);

        if (self::on('bileto_onepage')) {
            add_filter('render_block_woocommerce/checkout', [$this, 'wrapCheckout']);
            add_filter('woocommerce_checkout_redirect_empty_cart', '__return_false');
            add_filter('body_class', [$this, 'bodyClass']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        }
        if (self::on('bileto_onepage_home')) {
            add_action('template_redirect', [$this, 'redirectHome']);
        }
        if (self::on('bileto_onepage') && self::on('bileto_onepage_quiet_add')) {
            add_filter('wc_add_to_cart_message_html', '__return_empty_string');
        }
    }

    public static function get(string $option): string
    {
        return (string) get_option($option, self::DEFAULTS[$option]);
    }

    public static function on(string $option): bool
    {
        return self::get($option) === 'yes';
    }

    /**
     * "Settings" next to Deactivate on the Plugins screen.
     *
     * @param array<int|string, string> $links
     * @return array<int|string, string>
     */
    public function settingsLink(array $links): array
    {
        $url = admin_url('admin.php?page=wc-settings&tab=products&section=' . self::SECTION);
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'bileto') . '</a>');

        return $links;
    }

    /**
     * @param array<string, string> $sections
     * @return array<string, string>
     */
    public function section(array $sections): array
    {
        $sections[self::SECTION] = __('Bileto', 'bileto');

        return $sections;
    }

    /**
     * @param array<int, array<string, mixed>> $settings
     * @return array<int, array<string, mixed>>
     */
    public function settings(array $settings, string $section): array
    {
        if ($section !== self::SECTION) {
            return $settings;
        }
        $fields = [['title' => __('Ticket checkout', 'bileto'), 'type' => 'title', 'id' => 'bileto_checkout']];
        foreach (self::texts() as $id => [$label, $desc]) {
            $default = self::DEFAULTS[$id];
            $field   = ['id' => $id, 'title' => $label, 'desc' => $desc, 'default' => $default];
            if ($id === 'bileto_onepage_note') {
                $field['type'] = 'textarea';
                $field['css']  = 'min-height: 6em;';
            } elseif ($id === 'bileto_onepage_layout') {
                $field += ['type' => 'select', 'options' => ['columns' => __('Two columns', 'bileto'), 'stacked' => __('Tickets above the form', 'bileto')]];
            } elseif ($default === 'yes' || $default === 'no') {
                $field['type'] = 'checkbox';
            } else {
                $field['type'] = 'text';
                $field['desc_tip'] = false;
            }
            $fields[] = $field;
        }
        $fields[] = ['type' => 'sectionend', 'id' => 'bileto_checkout'];

        return $fields;
    }

    public function wrapCheckout(string $html): string
    {
        if (is_admin() || ! is_checkout() || is_wc_endpoint_url()) {
            return $html;
        }
        $event   = EventPostType::selected();
        $heading = self::get('bileto_onepage_heading');
        $offer   = $heading !== '' ? '<h1 class="bileto-onepage__title">' . esc_html(sprintf($heading, $event ? get_the_title($event) : '')) . '</h1>' : '';
        if (self::on('bileto_onepage_event') && $event) {
            $offer .= do_shortcode('[bileto_event id="' . $event . '"]');
        }
        $offer .= do_shortcode('[bileto_tickets]');
        $lines  = array_filter(array_map('trim', explode("\n", self::get('bileto_onepage_note'))));
        $note   = $lines !== [] ? '<ul class="bileto-onepage__note"><li>' . implode('</li><li>', array_map('wp_kses_post', $lines)) . '</li></ul>' : '';

        return '<div class="bileto-onepage bileto-onepage--' . esc_attr(self::get('bileto_onepage_layout')) . '"><aside class="bileto-onepage__offer">' . $offer . '</aside><div class="bileto-onepage__form">' . $html . $note . '</div></div>';
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public function bodyClass(array $classes): array
    {
        if (is_checkout() && ! is_wc_endpoint_url()) {
            foreach (['bileto_checkout_qty_names' => 'bileto-qty-names', 'bileto_checkout_coupon_open' => 'bileto-coupon-open', 'bileto_checkout_labels' => 'bileto-labels-above'] as $option => $class) {
                if (self::on($option)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    public function enqueue(): void
    {
        if (! is_checkout() || is_wc_endpoint_url()) {
            return;
        }
        TicketPicker::enqueueScript();
        wp_localize_script('bileto-picker', 'biletoCheckout', [
            'preselect' => self::on('bileto_onepage_preselect'),
            'qtyNames'  => self::on('bileto_checkout_qty_names'),
            'coupon'    => self::on('bileto_checkout_coupon_open'),
            /* translators: %s: order total */
            'payLabel'  => self::on('bileto_checkout_pay_total') ? __('Pay %s', 'bileto') : '',
        ]);
    }

    public function redirectHome(): void
    {
        if (is_front_page() && ! is_checkout()) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }
}
