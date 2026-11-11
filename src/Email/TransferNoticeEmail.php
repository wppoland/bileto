<?php

declare(strict_types=1);

namespace Bileto\Email;

defined('ABSPATH') || exit;

/**
 * Tells the previous holder that their ticket went to someone else, so a
 * transfer they did not make is noticed. The new holder's details are not
 * included.
 */
final class TransferNoticeEmail extends \WC_Email
{
    private string $event = '';

    public function __construct()
    {
        $this->id             = 'bileto_transferred';
        $this->customer_email = true;
        $this->title          = __('Ticket transferred (previous holder)', 'bileto');
        $this->description    = __('Sent to the previous holder when a ticket is transferred to someone else.', 'bileto');
        $this->placeholders   = ['{event}' => ''];

        add_action('bileto_transferred_notification', [$this, 'trigger'], 10, 2);

        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('Your ticket for {event} was transferred', 'bileto');
    }

    public function get_default_heading(): string
    {
        return __('Ticket transferred', 'bileto');
    }

    /**
     * @param int                   $ticketId
     * @param array<string, string> $previous Ticket row before the transfer.
     */
    public function trigger($ticketId, $previous = []): void
    {
        if (! is_array($previous) || empty($previous['email']) || ! $this->is_enabled()) {
            return;
        }
        $this->setup_locale();
        $this->recipient               = $previous['email'];
        $this->event                   = get_the_title((int) $previous['event_id']);
        $this->placeholders['{event}'] = $this->event;
        $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        $this->restore_locale();
    }

    public function get_content_html(): string
    {
        ob_start();
        do_action('woocommerce_email_header', $this->get_heading(), $this);
        echo '<p>' . esc_html(sprintf(/* translators: %s: event name */ __('Your ticket for %s was transferred to another person. Your QR code no longer works.', 'bileto'), $this->event)) . '</p>';
        echo '<p>' . esc_html__('If you did not do this, reply to this e-mail right away.', 'bileto') . '</p>';
        do_action('woocommerce_email_footer', $this);

        return (string) ob_get_clean();
    }

    public function get_content_plain(): string
    {
        return sprintf(/* translators: %s: event name */ __('Your ticket for %s was transferred to another person. Your QR code no longer works. If you did not do this, reply to this e-mail right away.', 'bileto'), $this->event) . "\n";
    }
}
