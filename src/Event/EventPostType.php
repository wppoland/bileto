<?php

declare(strict_types=1);

namespace Bileto\Event;

use Bileto\Contract\HasHooks;

defined('ABSPATH') || exit;

/**
 * The event a ticket admits to. Its country decides the VAT on the ticket.
 */
final class EventPostType implements HasHooks
{
    public const POST_TYPE = 'bileto_event';

    /** @var array<string, string> meta key => field type */
    private const FIELDS = [
        '_bileto_start'   => 'datetime-local',
        '_bileto_end'     => 'datetime-local',
        '_bileto_venue'   => 'text',
        '_bileto_address' => 'text',
        '_bileto_country' => 'country',
    ];

    public function registerHooks(): void
    {
        $this->register();
        add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'addMetaBox']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save']);
    }

    private function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels'       => [
                'name'          => __('Events', 'bileto'),
                'singular_name' => __('Event', 'bileto'),
                'add_new_item'  => __('Add New Event', 'bileto'),
                'edit_item'     => __('Edit Event', 'bileto'),
                'menu_name'     => __('Events', 'bileto'),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-tickets-alt',
            'supports'     => ['title'],
            'capability_type' => 'product',
            'map_meta_cap' => true,
        ]);
    }

    public function addMetaBox(): void
    {
        add_meta_box('bileto-event', __('Event details', 'bileto'), [$this, 'render'], self::POST_TYPE, 'normal', 'high');
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field('bileto_event_save', 'bileto_event_nonce');
        $labels = [
            '_bileto_start'   => __('Starts', 'bileto'),
            '_bileto_end'     => __('Ends', 'bileto'),
            '_bileto_venue'   => __('Venue', 'bileto'),
            '_bileto_address' => __('Address', 'bileto'),
            '_bileto_country' => __('Country (decides the VAT on tickets)', 'bileto'),
        ];
        echo '<table class="form-table" role="presentation">';
        foreach (self::FIELDS as $key => $type) {
            $value = (string) get_post_meta($post->ID, $key, true);
            echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($labels[$key]) . '</label></th><td>';
            if ($type === 'country') {
                $value = $value !== '' ? $value : WC()->countries->get_base_country();
                echo '<select id="' . esc_attr($key) . '" name="' . esc_attr($key) . '">';
                foreach (WC()->countries->get_countries() as $code => $name) {
                    echo '<option value="' . esc_attr($code) . '"' . selected($value, $code, false) . '>' . esc_html($name) . '</option>';
                }
                echo '</select>';
            } else {
                echo '<input class="regular-text" type="' . esc_attr($type) . '" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
            }
            echo '</td></tr>';
        }
        echo '</table>';
    }

    public function save(int $postId): void
    {
        $nonce = isset($_POST['bileto_event_nonce']) ? sanitize_text_field(wp_unslash($_POST['bileto_event_nonce'])) : '';
        if (! wp_verify_nonce($nonce, 'bileto_event_save') || ! current_user_can('edit_post', $postId) || wp_is_post_autosave($postId)) {
            return;
        }
        foreach (array_keys(self::FIELDS) as $key) {
            if (isset($_POST[$key])) {
                update_post_meta($postId, $key, sanitize_text_field(wp_unslash($_POST[$key])));
            }
        }
    }

    public static function country(int $eventId): string
    {
        $country = (string) get_post_meta($eventId, '_bileto_country', true);

        return $country !== '' ? $country : WC()->countries->get_base_country();
    }

    /** Event date and time in the site time zone, e.g. "12.11.2027 09:00 - 14.11.2027 18:00". */
    public static function when(int $eventId): string
    {
        $format = get_option('date_format') . ' ' . get_option('time_format');
        $parts  = [];
        foreach (['_bileto_start', '_bileto_end'] as $key) {
            $value = (string) get_post_meta($eventId, $key, true);
            if ($value === '') {
                continue;
            }
            try {
                $parts[] = wp_date($format, (new \DateTimeImmutable($value, wp_timezone()))->getTimestamp());
            } catch (\Exception $e) {
                continue;
            }
        }

        return implode(' - ', $parts);
    }

    public static function venue(int $eventId): string
    {
        return trim((string) get_post_meta($eventId, '_bileto_venue', true) . ', ' . (string) get_post_meta($eventId, '_bileto_address', true), ', ');
    }

    /** The event chosen in `?event=`, or the next one to start (the latest past one if none is ahead). */
    public static function selected(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen filter.
        $chosen = isset($_GET['event']) ? absint($_GET['event']) : 0;
        if ($chosen > 0 && get_post_type($chosen) === self::POST_TYPE) {
            return $chosen;
        }
        $today = wp_date('Y-m-d');
        foreach ([['>=', 'ASC'], ['<', 'DESC']] as [$compare, $order]) {
            $ids = get_posts([
                'post_type'   => self::POST_TYPE,
                'numberposts' => 1,
                'fields'      => 'ids',
                'meta_key'    => '_bileto_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- a handful of events.
                'orderby'     => 'meta_value',
                'order'       => $order,
                'meta_query'  => [['key' => '_bileto_start', 'value' => $today, 'compare' => $compare]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
            ]);
            if ($ids !== []) {
                return (int) $ids[0];
            }
        }

        return 0;
    }

    /**
     * Event switcher for an admin screen: reloads it with `?event=`.
     *
     * @param array<string, string> $query The screen's own query args (page, post_type).
     */
    public static function picker(int $eventId, array $query): void
    {
        $events = get_posts(['post_type' => self::POST_TYPE, 'numberposts' => 50, 'post_status' => 'publish']);
        ?>
        <form method="get" class="bileto-event-picker">
            <?php foreach ($query as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endforeach; ?>
            <label for="bileto-event"><?php esc_html_e('Event', 'bileto'); ?></label>
            <select id="bileto-event" name="event" onchange="this.form.submit()">
                <?php foreach ($events as $event) : ?>
                    <option value="<?php echo esc_attr((string) $event->ID); ?>" <?php selected($eventId, $event->ID); ?>><?php echo esc_html($event->post_title); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php
    }
}
