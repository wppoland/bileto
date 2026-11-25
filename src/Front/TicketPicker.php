<?php

declare(strict_types=1);

namespace Bileto\Front;

use Bileto\Contract\HasHooks;
use Bileto\Event\EventPostType;
use Bileto\Product\TicketProduct;

defined('ABSPATH') || exit;

/**
 * [bileto_tickets event="ID"]: ticket types with quantity steppers that edit
 * the cart in place, so the Checkout block next to it becomes a one-page
 * purchase. Cart changes go through the WooCommerce cart data store (Store
 * API), so the order summary and totals follow without a reload. An empty
 * cart gets one seat of the first ticket, since the Checkout block shows no
 * form without items. Each ticket shows the lines of its short description
 * as points (what it includes, how it differs).
 *
 * [bileto_event id="ID"]: the event's days, venue and description for the
 * same page, since buyers often land on it straight from a link. Without an
 * ID it shows the next event.
 *
 * existing-checked: Front/TicketPage.php - it owns the [bileto] ticket page with secret links and no-cache headers; the picker is public, cacheable markup on another page.
 */
final class TicketPicker implements HasHooks
{
    public function registerHooks(): void
    {
        add_shortcode('bileto_tickets', [$this, 'render']);
        add_shortcode('bileto_event', [$this, 'renderEvent']);
    }

    /**
     * One registration for every caller: block themes render shortcodes before
     * wp_enqueue_scripts, so whichever runs first must carry all dependencies.
     */
    public static function enqueueScript(): void
    {
        wp_enqueue_script('bileto-picker', plugins_url('assets/picker.js', \Bileto\PLUGIN_FILE), ['wp-data', 'wc-blocks-data-store', 'wc-blocks-checkout'], \Bileto\VERSION, true);
    }

    /** @param array<string, string>|string $atts */
    public function renderEvent($atts): string
    {
        $atts  = shortcode_atts(['id' => '0'], is_array($atts) ? $atts : [], 'bileto_event');
        $event = absint($atts['id']) ?: EventPostType::selected();
        if ($event === 0 || get_post_type($event) !== EventPostType::POST_TYPE) {
            return '';
        }
        wp_enqueue_style('bileto', plugins_url('assets/bileto.css', \Bileto\PLUGIN_FILE), [], \Bileto\VERSION);

        $facts = array_filter([EventPostType::days($event), EventPostType::venue($event)]);
        $html  = $facts !== [] ? '<p class="bileto-event__facts">' . esc_html(implode(' · ', $facts)) . '</p>' : '';
        $text  = (string) get_post_field('post_content', $event);
        if (trim($text) !== '') {
            // Clamped to a few lines with a fade; the full text stays in the DOM, so find-in-page and readers get it all.
            $html .= '<div class="bileto-event__about" id="bileto-event-about-' . $event . '">' . wp_kses_post(wpautop($text)) . '</div>'
                . '<button type="button" class="bileto-event__more" aria-expanded="false" aria-controls="bileto-event-about-' . $event . '" hidden>'
                . esc_html__('Read more', 'bileto') . '</button>';
            self::enqueueScript();
        }

        return '<div class="bileto-event">' . $html . '</div>';
    }

    /**
     * Purchasable simple ticket products, cheapest first.
     *
     * @return list<\WC_Product>
     */
    public static function products(int $eventId = 0): array
    {
        $products = array_values(array_filter(
            wc_get_products(['status' => 'publish', 'type' => 'simple', 'limit' => 50]),
            static fn (\WC_Product $p): bool => TicketProduct::isTicket($p) && $p->is_purchasable() && $p->is_in_stock()
                && ($eventId === 0 || TicketProduct::eventId($p) === $eventId)
        ));
        usort($products, static fn (\WC_Product $a, \WC_Product $b): int => (float) $a->get_price() <=> (float) $b->get_price());

        return $products;
    }

    /** @param array<string, string>|string $atts */
    public function render($atts): string
    {
        $atts     = shortcode_atts(['event' => '0'], is_array($atts) ? $atts : [], 'bileto_tickets');
        $products = self::products(absint($atts['event']));
        if ($products === []) {
            return '';
        }

        wp_enqueue_style('bileto', plugins_url('assets/bileto.css', \Bileto\PLUGIN_FILE), [], \Bileto\VERSION);
        self::enqueueScript();

        $rows = '';
        foreach ($products as $product) {
            $name   = $product->get_name();
            $max    = $product->get_max_purchase_quantity();
            $points = array_filter(array_map('trim', explode("\n", wp_strip_all_tags($product->get_short_description()))));
            $list   = $points !== [] ? '<ul class="bileto-picker__points"><li>' . implode('</li><li>', array_map('esc_html', $points)) . '</li></ul>' : '';
            $rows  .= sprintf(
                '<li class="bileto-picker__row" data-product="%1$d" data-max="%2$d"><div class="bileto-picker__head"><span class="bileto-picker__name">%3$s</span><span class="bileto-picker__price">%4$s</span></div>%8$s'
                . '<div class="bileto-picker__qty"><span class="bileto-picker__qty-label" aria-hidden="true">%9$s</span><div class="bileto-picker__stepper"><button type="button" class="bileto-picker__step" data-step="-1" aria-label="%5$s">&minus;</button>'
                . '<output class="bileto-picker__count" aria-live="polite" aria-label="%6$s">0</output>'
                . '<button type="button" class="bileto-picker__step" data-step="1" aria-label="%7$s">+</button></div></div></li>',
                $product->get_id(),
                $max > 0 ? $max : 99,
                esc_html($name),
                wp_kses_post($product->get_price_html()),
                /* translators: %s: ticket name */
                esc_attr(sprintf(__('Remove one %s', 'bileto'), $name)),
                /* translators: %s: ticket name */
                esc_attr(sprintf(__('%s quantity', 'bileto'), $name)),
                /* translators: %s: ticket name */
                esc_attr(sprintf(__('Add one %s', 'bileto'), $name)),
                $list,
                esc_html__('Quantity', 'bileto')
            );
        }

        return '<div class="bileto-picker"><ul class="bileto-picker__list">' . $rows . '</ul></div>';
    }
}
