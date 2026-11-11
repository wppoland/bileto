<?php

declare(strict_types=1);

namespace Bileto\Checkin;

use Bileto\Contract\HasHooks;
use Bileto\Event\EventPostType;
use Bileto\Ticket\TicketRepository;
use Bileto\Ticket\TicketService;

defined('ABSPATH') || exit;

/**
 * Door check-in for one event at a time: a phone-friendly admin screen
 * (camera scan or name search) on top of two REST routes, for users with the
 * `bileto_checkin` capability (e.g. the "Check-in" role for volunteers).
 * Search results never contain ticket secrets, and e-mails are masked.
 */
final class Checkin implements HasHooks
{
    public const CAP   = 'bileto_checkin';
    private const SLUG = 'bileto-checkin';

    public function __construct(private TicketRepository $repo, private TicketService $service)
    {
    }

    public function registerHooks(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public static function addRoles(): void
    {
        add_role('bileto_checkin', __('Check-in', 'bileto'), ['read' => true, self::CAP => true]);
        foreach (['administrator', 'shop_manager'] as $role) {
            get_role($role)?->add_cap(self::CAP);
        }
    }

    public function routes(): void
    {
        $can   = static fn (): bool => current_user_can(self::CAP);
        $event = ['type' => 'integer', 'required' => true, 'minimum' => 1];
        register_rest_route('bileto/v1', '/checkin', [
            'methods'             => 'POST',
            'permission_callback' => $can,
            'args'                => [
                'event'     => $event,
                'code'      => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
                'ticket_id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'callback'            => function (\WP_REST_Request $request): \WP_REST_Response {
                $eventId = (int) $request['event'];
                $ticket  = $request['code'] ? $this->repo->byCode((string) $request['code']) : $this->repo->find((int) $request['ticket_id']);
                $result  = $this->service->checkIn($ticket, $eventId, get_current_user_id());

                return new \WP_REST_Response([
                    'code'  => $result['code'],
                    'name'  => $result['ticket'] ? trim($result['ticket']['first_name'] . ' ' . $result['ticket']['last_name']) : '',
                    'event' => $result['ticket'] ? get_the_title((int) $result['ticket']['event_id']) : '',
                    'at'    => $result['at'] ? wp_date(get_option('time_format'), (int) strtotime($result['at'] . ' UTC')) : '',
                    'stats' => $this->repo->stats($eventId),
                ]);
            },
        ]);
        register_rest_route('bileto/v1', '/tickets', [
            'methods'             => 'GET',
            'permission_callback' => $can,
            'args'                => ['event' => $event, 'search' => ['type' => 'string', 'required' => true, 'minLength' => 3]],
            'callback'            => fn (\WP_REST_Request $request): \WP_REST_Response => new \WP_REST_Response(array_map(
                static fn (array $t): array => [
                    'id'      => (int) $t['id'],
                    'name'    => trim($t['first_name'] . ' ' . $t['last_name']),
                    'email'   => self::maskEmail($t['email']),
                    'company' => $t['company'],
                    'status'  => $t['status'],
                ],
                $this->repo->search((int) $request['event'], sanitize_text_field((string) $request['search']))
            )),
        ]);
    }

    /** "anna.kowalska@example.com" becomes "an***@example.com": enough to tell two Annas apart. */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');

        return $at === false ? '' : substr($email, 0, min(2, $at)) . '***' . substr($email, $at);
    }

    public function menu(): void
    {
        add_menu_page(__('Check-in', 'bileto'), __('Check-in', 'bileto'), self::CAP, self::SLUG, [$this, 'render'], 'dashicons-yes-alt', 56);
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_style('bileto-checkin', plugins_url('assets/checkin.css', \Bileto\PLUGIN_FILE), [], \Bileto\VERSION);
        wp_enqueue_script('bileto-checkin', plugins_url('assets/checkin.js', \Bileto\PLUGIN_FILE), ['wp-api-fetch', 'wp-i18n'], \Bileto\VERSION, true);
        wp_set_script_translations('bileto-checkin', 'bileto');
    }

    /** The selected event, or the next one to start (the latest past one if none is ahead). */
    private function currentEvent(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen filter.
        $chosen = isset($_GET['event']) ? absint($_GET['event']) : 0;
        if ($chosen > 0 && get_post_type($chosen) === EventPostType::POST_TYPE) {
            return $chosen;
        }
        $today = wp_date('Y-m-d');
        foreach ([['>=', 'ASC'], ['<', 'DESC']] as [$compare, $order]) {
            $ids = get_posts([
                'post_type'   => EventPostType::POST_TYPE,
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

    public function render(): void
    {
        $eventId = $this->currentEvent();
        $events  = get_posts(['post_type' => EventPostType::POST_TYPE, 'numberposts' => 50, 'post_status' => 'publish']);
        $stats   = $this->repo->stats($eventId);
        ?>
        <div class="wrap bileto-checkin" data-event="<?php echo esc_attr((string) $eventId); ?>" data-jsqr="<?php echo esc_url(plugins_url('assets/vendor/jsqr.js', \Bileto\PLUGIN_FILE) . '?ver=1.4.0'); ?>">
            <h1><?php esc_html_e('Check-in', 'bileto'); ?></h1>
            <?php if ($eventId === 0) : ?>
                <p><?php esc_html_e('Create an event first.', 'bileto'); ?></p>
            <?php else : ?>
                <form method="get" class="bileto-checkin__event">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                    <label for="bileto-event"><?php esc_html_e('Event', 'bileto'); ?></label>
                    <select id="bileto-event" name="event" onchange="this.form.submit()">
                        <?php foreach ($events as $event) : ?>
                            <option value="<?php echo esc_attr((string) $event->ID); ?>" <?php selected($eventId, $event->ID); ?>><?php echo esc_html($event->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <p class="bileto-checkin__count" aria-live="polite">
                    <?php echo esc_html(sprintf(/* translators: 1: checked in today, 2: valid tickets */ __('Checked in today: %1$d of %2$d', 'bileto'), $stats['in'], $stats['total'])); ?>
                </p>
                <div class="bileto-checkin__camera">
                    <video playsinline muted aria-label="<?php esc_attr_e('Camera preview', 'bileto'); ?>"></video>
                    <button type="button" class="button button-primary button-hero bileto-checkin__start"><?php esc_html_e('Start Camera', 'bileto'); ?></button>
                </div>
                <form class="bileto-checkin__search" role="search">
                    <label for="bileto-search"><?php esc_html_e('Search by name, company or e-mail', 'bileto'); ?></label>
                    <input id="bileto-search" type="search" autocomplete="off" minlength="3">
                </form>
                <ul class="bileto-checkin__results" aria-live="polite"></ul>
                <div class="bileto-checkin__result" role="alert" hidden>
                    <p class="bileto-checkin__status"></p>
                    <p class="bileto-checkin__name"></p>
                    <p class="bileto-checkin__detail"></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
