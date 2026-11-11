<?php

declare(strict_types=1);

namespace Bileto\Email;

use Bileto\Contract\HasHooks;
use Bileto\Front\TicketPage;
use Bileto\Ticket\TicketRepository;

defined('ABSPATH') || exit;

/**
 * Registers the ticket e-mail and adds the "assign attendees" link to the
 * buyer's order e-mails when the order holds more than one ticket.
 */
final class Emails implements HasHooks
{
    public function __construct(private TicketRepository $repo)
    {
    }

    public function registerHooks(): void
    {
        add_filter('woocommerce_email_classes', [$this, 'register']);
        add_action('woocommerce_email_after_order_table', [$this, 'assignLink'], 10, 4);
        // WooCommerce loads e-mail classes lazily; ticket hooks must exist before the first ticket is issued.
        foreach (['bileto_issued', 'bileto_assigned', 'bileto_transferred'] as $hook) {
            add_action($hook, [$this, 'loadMailer'], 1);
        }
    }

    /**
     * @param array<string, \WC_Email> $emails
     * @return array<string, \WC_Email>
     */
    public function register($emails): array
    {
        $emails['bileto_ticket'] = new TicketEmail($this->repo);

        return $emails;
    }

    public function loadMailer(): void
    {
        WC()->mailer();
    }

    /**
     * @param \WC_Order      $order
     * @param bool           $sentToAdmin
     * @param bool           $plain
     * @param \WC_Email|null $email
     */
    public function assignLink($order, $sentToAdmin, $plain = false, $email = null): void
    {
        if ($sentToAdmin || ! $order instanceof \WC_Order) {
            return;
        }
        $open = array_filter($this->repo->forOrder($order->get_id()), static fn (array $t): bool => $t['email'] === '' && $t['status'] === 'valid');
        if ($open === []) {
            return;
        }
        $url  = TicketPage::orderUrl($order);
        $text = sprintf(/* translators: %d: number of tickets */ _n('%d ticket still needs an attendee.', '%d tickets still need attendees.', count($open), 'bileto'), count($open));
        if ($plain) {
            echo esc_html($text) . "\n" . esc_url_raw($url) . "\n\n";

            return;
        }
        echo '<p><strong>' . esc_html($text) . '</strong> <a href="' . esc_url($url) . '">' . esc_html__('Assign attendees', 'bileto') . '</a></p>';
    }
}
