<?php

declare(strict_types=1);

namespace Bileto\Checkin;

use Bileto\Contract\HasHooks;
use Bileto\Ticket\TicketRepository;
use Bileto\Ticket\TicketService;

defined('ABSPATH') || exit;

/**
 * Door check-in: a phone-friendly admin screen (camera scan or name search)
 * on top of two REST routes. Only users with the `bileto_checkin` capability,
 * e.g. the "Check-in" role for volunteers, can use either.
 */
final class Checkin implements HasHooks
{
    public const CAP  = 'bileto_checkin';
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
        $can = static fn (): bool => current_user_can(self::CAP);
        register_rest_route('bileto/v1', '/checkin', [
            'methods'             => 'POST',
            'permission_callback' => $can,
            'args'                => ['token' => ['type' => 'string', 'required' => true, 'pattern' => '^[a-f0-9]{32}$']],
            'callback'            => function (\WP_REST_Request $request): \WP_REST_Response {
                $result = $this->service->checkIn((string) $request['token'], get_current_user_id());

                return new \WP_REST_Response([
                    'code'  => $result['code'],
                    'name'  => $result['ticket'] ? trim($result['ticket']['first_name'] . ' ' . $result['ticket']['last_name']) : '',
                    'event' => $result['ticket'] ? get_the_title((int) $result['ticket']['event_id']) : '',
                    'at'    => $result['at'] ? wp_date(get_option('time_format'), (int) strtotime($result['at'] . ' UTC')) : '',
                    'stats' => $this->repo->stats(),
                ]);
            },
        ]);
        register_rest_route('bileto/v1', '/tickets', [
            'methods'             => 'GET',
            'permission_callback' => $can,
            'args'                => ['search' => ['type' => 'string', 'required' => true, 'minLength' => 2]],
            'callback'            => fn (\WP_REST_Request $request): \WP_REST_Response => new \WP_REST_Response(array_map(
                static fn (array $t): array => [
                    'token'   => $t['token'],
                    'name'    => trim($t['first_name'] . ' ' . $t['last_name']),
                    'email'   => $t['email'],
                    'company' => $t['company'],
                    'status'  => $t['status'],
                ],
                $this->repo->search(sanitize_text_field((string) $request['search']))
            )),
        ]);
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

    public function render(): void
    {
        $stats = $this->repo->stats();
        ?>
        <div class="wrap bileto-checkin">
            <h1><?php esc_html_e('Check-in', 'bileto'); ?></h1>
            <p class="bileto-checkin__count" aria-live="polite">
                <?php echo esc_html(sprintf(/* translators: 1: checked in today, 2: valid tickets */ __('Checked in today: %1$d of %2$d', 'bileto'), $stats['in'], $stats['total'])); ?>
            </p>
            <div class="bileto-checkin__camera">
                <video playsinline muted aria-label="<?php esc_attr_e('Camera preview', 'bileto'); ?>"></video>
                <button type="button" class="button button-primary button-hero bileto-checkin__start"><?php esc_html_e('Start Camera', 'bileto'); ?></button>
            </div>
            <form class="bileto-checkin__search" role="search">
                <label for="bileto-search"><?php esc_html_e('Search by name or e-mail', 'bileto'); ?></label>
                <input id="bileto-search" type="search" autocomplete="off" minlength="2">
            </form>
            <ul class="bileto-checkin__results" aria-live="polite"></ul>
            <div class="bileto-checkin__result" role="alert" hidden>
                <p class="bileto-checkin__status"></p>
                <p class="bileto-checkin__name"></p>
                <p class="bileto-checkin__detail"></p>
            </div>
        </div>
        <?php
    }
}
