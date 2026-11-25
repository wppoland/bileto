<?php

declare(strict_types=1);

namespace Bileto\Email;

use Bileto\Front\TicketPage;

defined('ABSPATH') || exit;

/**
 * Reminds the buyer, every few days until done, that some seats of a paid
 * order still have no attendee. Sent by the daily check in Emails; the
 * interval is a field of this e-mail (WooCommerce > Settings > Emails).
 *
 * existing-checked: Email/Emails.php - it adds the one-off "assign attendees" line to order e-mails; a repeated reminder needs its own WC_Email so shops can edit or switch it off.
 */
final class AttendeeReminderEmail extends \WC_Email
{
    private int $open = 0;

    private string $url = '';

    public function __construct()
    {
        $this->id             = 'bileto_attendee_reminder';
        $this->customer_email = true;
        $this->title          = __('Attendees missing (reminder)', 'bileto');
        $this->description    = __('Sent to the buyer every few days while seats of a paid order have no attendee, until they are filled in or the event starts.', 'bileto');
        $this->placeholders   = ['{event}' => ''];

        add_action('bileto_attendee_reminder_notification', [$this, 'trigger'], 10, 3);

        parent::__construct();
    }

    /** Days between reminders, also the wait after payment before the first one. */
    public function interval(): int
    {
        return max(1, (int) $this->get_option('interval', '3'));
    }

    public function init_form_fields(): void
    {
        parent::init_form_fields();
        $this->form_fields['interval'] = [
            'title'       => __('Every (days)', 'bileto'),
            'type'        => 'number',
            'default'     => '3',
            'description' => __('Wait after payment before the first reminder, and between reminders.', 'bileto'),
            'desc_tip'    => true,
        ];
    }

    public function get_default_subject(): string
    {
        return __('Who is coming to {event}?', 'bileto');
    }

    public function get_default_heading(): string
    {
        return __('Add your attendees', 'bileto');
    }

    /**
     * @param int    $orderId
     * @param int    $open  Seats without an attendee.
     * @param string $event Event name.
     */
    public function trigger($orderId, $open = 0, $event = ''): void
    {
        $order = wc_get_order((int) $orderId);
        if (! $order instanceof \WC_Order || ! $this->is_enabled() || $order->get_billing_email() === '') {
            return;
        }
        $this->setup_locale();
        $this->object                  = $order;
        $this->recipient               = $order->get_billing_email();
        $this->open                    = (int) $open;
        $this->url                     = TicketPage::orderUrl($order);
        $this->placeholders['{event}'] = (string) $event;
        $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        $this->restore_locale();
    }

    private function message(): string
    {
        return sprintf(
            /* translators: 1: number of tickets, 2: event name */
            _n('%1$d of your tickets for %2$s has no attendee yet. Add their name and e-mail so they get their own ticket with a QR code.', '%1$d of your tickets for %2$s have no attendees yet. Add their names and e-mails so each person gets their own ticket with a QR code.', $this->open, 'bileto'),
            $this->open,
            $this->placeholders['{event}']
        );
    }

    public function get_content_html(): string
    {
        ob_start();
        do_action('woocommerce_email_header', $this->get_heading(), $this);
        echo '<p>' . esc_html($this->message()) . '</p>';
        echo '<p><a class="button" href="' . esc_url($this->url) . '">' . esc_html__('Add attendees', 'bileto') . '</a></p>';
        echo '<p>' . esc_html__('We will remind you every few days until every ticket has an attendee.', 'bileto') . '</p>';
        do_action('woocommerce_email_footer', $this);

        return (string) ob_get_clean();
    }

    public function get_content_plain(): string
    {
        return $this->message() . "\n\n" . esc_url_raw($this->url) . "\n";
    }
}
