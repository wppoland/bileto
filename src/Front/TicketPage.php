<?php

declare(strict_types=1);

namespace Bileto\Front;

use Bileto\Contract\HasHooks;
use Bileto\Event\EventPostType;
use Bileto\Qr;
use Bileto\Ticket\TicketRepository;
use Bileto\Ticket\TicketService;

defined('ABSPATH') || exit;

/**
 * One page with the [bileto] shortcode serves two views, both reached by a
 * secret in the link (no account needed):
 * - ?ticket=TOKEN: the attendee's ticket with QR and a transfer form;
 * - ?order=ID&key=ORDER_KEY: the buyer assigns attendees to the order's seats.
 * Forms post back to the same URL and redirect (no resubmit on reload).
 */
final class TicketPage implements HasHooks
{
    public const PAGE_OPTION = 'bileto_page_id';

    public function __construct(private TicketRepository $repo, private TicketService $service)
    {
    }

    public function registerHooks(): void
    {
        add_shortcode('bileto', [$this, 'render']);
        add_action('template_redirect', [$this, 'handlePost']);
        add_action('template_redirect', [$this, 'serveQr']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('woocommerce_thankyou', [$this, 'renderThankYou'], 5);
    }

    public static function ticketUrl(string $token): string
    {
        return add_query_arg('ticket', $token, self::pageUrl());
    }

    public static function orderUrl(\WC_Order $order): string
    {
        return add_query_arg(['order' => $order->get_id(), 'key' => $order->get_order_key()], self::pageUrl());
    }

    public static function qrUrl(string $token): string
    {
        return add_query_arg('bileto_qr', $token, home_url('/'));
    }

    private static function pageUrl(): string
    {
        $url = get_permalink((int) get_option(self::PAGE_OPTION));

        return $url ?: home_url('/');
    }

    public function enqueue(): void
    {
        if (! is_page((int) get_option(self::PAGE_OPTION)) && ! is_wc_endpoint_url('order-received')) {
            return;
        }
        wp_enqueue_style('bileto', plugins_url('assets/bileto.css', \Bileto\PLUGIN_FILE), [], \Bileto\VERSION);
    }

    public function serveQr(): void
    {
        // Public by design: the token in the URL is the secret, same as on the ticket itself.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $token = isset($_GET['bileto_qr']) ? sanitize_key(wp_unslash($_GET['bileto_qr'])) : '';
        if ($token === '' || $this->repo->byToken($token) === null) {
            return;
        }
        nocache_headers();
        Qr::sendPng($token);
        exit;
    }

    /** @param array<string, string>|string $atts */
    public function render($atts = []): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $token = isset($_GET['ticket']) ? sanitize_key(wp_unslash($_GET['ticket'])) : '';
        $order = isset($_GET['order'], $_GET['key']) ? $this->orderFromLink(absint($_GET['order']), sanitize_text_field(wp_unslash($_GET['key']))) : null;
        $done  = isset($_GET['bileto_done']) ? sanitize_key(wp_unslash($_GET['bileto_done'])) : '';
        // phpcs:enable

        $notice = $done !== '' ? $this->notice($done) : '';
        if ($token !== '') {
            $ticket = $this->repo->byToken($token);

            return $notice . ($ticket ? $this->ticketView($ticket) : $this->message(__('This ticket link is no longer valid. If the ticket was transferred, the new holder received a new link.', 'bileto')));
        }
        if ($order) {
            return $notice . $this->orderView($order);
        }

        return $this->message(__('Open this page from the link in your ticket e-mail.', 'bileto'));
    }

    public function renderThankYou(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order && $this->repo->forOrder($orderId) !== []) {
            echo $this->orderView($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in orderView().
        }
    }

    public function handlePost(): void
    {
        if (! isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset($_POST['bileto_action'], $_POST['bileto_nonce'])) {
            return;
        }
        $action   = sanitize_key(wp_unslash($_POST['bileto_action']));
        $ticketId = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bileto_nonce'])), 'bileto_' . $action . '_' . $ticketId)) {
            wp_die(esc_html__('This form has expired. Reload the page and try again.', 'bileto'), '', ['response' => 403]);
        }
        $ticket = $this->repo->find($ticketId);
        $holder = [
            'first_name' => isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '',
            'last_name'  => isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '',
            'email'      => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
            'company'    => isset($_POST['company']) ? sanitize_text_field(wp_unslash($_POST['company'])) : '',
        ];
        if ($ticket === null || $ticket['status'] !== 'valid' || $holder['first_name'] === '' || $holder['last_name'] === '' || ! is_email($holder['email'])) {
            wp_die(esc_html__('Please fill in first name, last name and a valid e-mail.', 'bileto'), '', ['response' => 400, 'back_link' => true]);
        }

        $redirect = wp_get_referer() ?: self::pageUrl();
        if ($action === 'assign') {
            $order = $this->orderFromLink((int) $ticket['order_id'], isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '');
            if (! $order) {
                wp_die(esc_html__('This link is not valid for that order.', 'bileto'), '', ['response' => 403]);
            }
            if ($ticket['email'] === '') {
                $this->service->assign($ticketId, $holder);
            } else {
                $this->service->transfer($ticketId, $holder, get_current_user_id());
            }
            $redirect = add_query_arg('bileto_done', 'assigned', $redirect);
        } elseif ($action === 'transfer') {
            $token = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : '';
            if (! hash_equals($ticket['token'], $token)) {
                wp_die(esc_html__('This ticket link is no longer valid.', 'bileto'), '', ['response' => 403]);
            }
            $this->service->transfer($ticketId, $holder, get_current_user_id());
            $redirect = add_query_arg('bileto_done', 'transferred', remove_query_arg('ticket', $redirect));
        }

        wp_safe_redirect($redirect);
        exit;
    }

    private function orderFromLink(int $orderId, string $key): ?\WC_Order
    {
        $order = wc_get_order($orderId);

        return $order instanceof \WC_Order && $key !== '' && hash_equals($order->get_order_key(), $key) ? $order : null;
    }

    /** @param array<string, string> $ticket */
    private function ticketView(array $ticket): string
    {
        $eventId = (int) $ticket['event_id'];
        $status  = $ticket['status'] === 'valid' ? '' : '<p class="bileto-badge bileto-badge--error">' . esc_html__('Cancelled', 'bileto') . '</p>';
        $start   = EventPostType::startsAt($eventId);

        ob_start();
        ?>
        <article class="bileto-ticket" aria-labelledby="bileto-ticket-title">
            <div class="bileto-ticket__qr"><?php echo Qr::svg($ticket['token']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built by the QR library from a hex token. ?></div>
            <div class="bileto-ticket__body">
                <?php echo $status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
                <h2 id="bileto-ticket-title" class="bileto-ticket__event"><?php echo esc_html(get_the_title($eventId)); ?></h2>
                <?php if ($start !== '') : ?>
                    <p class="bileto-ticket__meta"><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) strtotime($start))); ?></p>
                <?php endif; ?>
                <p class="bileto-ticket__meta"><?php echo esc_html(EventPostType::venue($eventId)); ?></p>
                <p class="bileto-ticket__name"><?php echo esc_html(trim($ticket['first_name'] . ' ' . $ticket['last_name'])); ?></p>
                <?php if ($ticket['company'] !== '') : ?>
                    <p class="bileto-ticket__meta"><?php echo esc_html($ticket['company']); ?></p>
                <?php endif; ?>
                <p class="bileto-ticket__hint"><?php esc_html_e('Show this QR code at the entrance, on your phone or printed.', 'bileto'); ?></p>
                <button type="button" class="wp-element-button bileto-button bileto-button--ghost" onclick="window.print()"><?php esc_html_e('Print Ticket', 'bileto'); ?></button>
            </div>
        </article>
        <?php if ($ticket['status'] === 'valid') : ?>
            <details class="bileto-panel">
                <summary><?php esc_html_e('Transfer This Ticket', 'bileto'); ?></summary>
                <p><?php esc_html_e('The new holder gets a new QR code by e-mail. This one stops working. The order and the invoice do not change.', 'bileto'); ?></p>
                <?php echo $this->holderForm('transfer', $ticket, ['token' => $ticket['token']], __('Transfer Ticket', 'bileto')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in holderForm(). ?>
            </details>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    private function orderView(\WC_Order $order): string
    {
        $tickets = $this->repo->forOrder($order->get_id());
        if ($tickets === []) {
            return $this->message(__('Tickets appear here as soon as the payment is confirmed.', 'bileto'));
        }

        ob_start();
        ?>
        <section class="bileto-assign" aria-labelledby="bileto-assign-title">
            <h2 id="bileto-assign-title"><?php esc_html_e('Your Tickets', 'bileto'); ?></h2>
            <p><?php esc_html_e('Add the name and e-mail of each attendee. Everyone gets their own ticket with a QR code.', 'bileto'); ?></p>
            <ol class="bileto-assign__list">
                <?php foreach ($tickets as $index => $ticket) : ?>
                    <li class="bileto-card" style="--bileto-i: <?php echo (int) $index; ?>">
                        <?php if ($ticket['status'] !== 'valid') : ?>
                            <p class="bileto-badge bileto-badge--error"><?php esc_html_e('Cancelled', 'bileto'); ?></p>
                        <?php elseif ($ticket['email'] !== '') : ?>
                            <p class="bileto-card__holder"><span class="bileto-check" aria-hidden="true"></span><?php echo esc_html(trim($ticket['first_name'] . ' ' . $ticket['last_name']) . ' (' . $ticket['email'] . ')'); ?></p>
                            <details class="bileto-panel">
                                <summary><?php esc_html_e('Change Attendee', 'bileto'); ?></summary>
                                <?php echo $this->holderForm('assign', $ticket, ['key' => $order->get_order_key()], __('Save Attendee', 'bileto')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in holderForm(). ?>
                            </details>
                        <?php else : ?>
                            <?php echo $this->holderForm('assign', $ticket, ['key' => $order->get_order_key()], __('Send Ticket', 'bileto')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in holderForm(). ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, string> $ticket
     * @param array<string, string> $hidden
     */
    private function holderForm(string $action, array $ticket, array $hidden, string $label): string
    {
        $id = 'bileto-' . $action . '-' . $ticket['id'];
        ob_start();
        ?>
        <form class="bileto-form" method="post">
            <input type="hidden" name="bileto_action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="ticket_id" value="<?php echo esc_attr($ticket['id']); ?>">
            <?php foreach ($hidden as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endforeach; ?>
            <?php wp_nonce_field('bileto_' . $action . '_' . $ticket['id'], 'bileto_nonce', false); ?>
            <div class="bileto-form__row">
                <label for="<?php echo esc_attr($id); ?>-first"><?php esc_html_e('First name', 'bileto'); ?></label>
                <input id="<?php echo esc_attr($id); ?>-first" name="first_name" autocomplete="given-name" required>
            </div>
            <div class="bileto-form__row">
                <label for="<?php echo esc_attr($id); ?>-last"><?php esc_html_e('Last name', 'bileto'); ?></label>
                <input id="<?php echo esc_attr($id); ?>-last" name="last_name" autocomplete="family-name" required>
            </div>
            <div class="bileto-form__row">
                <label for="<?php echo esc_attr($id); ?>-email"><?php esc_html_e('E-mail', 'bileto'); ?></label>
                <input id="<?php echo esc_attr($id); ?>-email" name="email" type="email" autocomplete="email" required>
            </div>
            <div class="bileto-form__row">
                <label for="<?php echo esc_attr($id); ?>-company"><?php esc_html_e('Company (optional)', 'bileto'); ?></label>
                <input id="<?php echo esc_attr($id); ?>-company" name="company" autocomplete="organization">
            </div>
            <button type="submit" class="wp-element-button bileto-button"><?php echo esc_html($label); ?></button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    private function notice(string $done): string
    {
        $text = $done === 'transferred'
            ? __('Ticket transferred. The new holder has received it by e-mail.', 'bileto')
            : __('Saved. The attendee has received the ticket by e-mail.', 'bileto');

        return '<p class="bileto-notice" role="status">' . esc_html($text) . '</p>';
    }

    private function message(string $text): string
    {
        return '<p class="bileto-message">' . esc_html($text) . '</p>';
    }
}
