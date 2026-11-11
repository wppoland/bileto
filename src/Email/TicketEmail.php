<?php

declare(strict_types=1);

namespace Bileto\Email;

use Bileto\Event\EventPostType;
use Bileto\Front\TicketPage;
use Bileto\Ticket\TicketRepository;

defined('ABSPATH') || exit;

/**
 * "Your ticket" e-mail to one attendee, styled and editable like any
 * WooCommerce e-mail (WooCommerce > Settings > Emails).
 */
final class TicketEmail extends \WC_Email
{
    /** @var array<string, string>|null */
    private ?array $ticket = null;

    public function __construct(private TicketRepository $repo)
    {
        $this->id             = 'bileto_ticket';
        $this->customer_email = true;
        $this->title          = __('Event ticket', 'bileto');
        $this->description    = __('Sent to each attendee when a ticket is issued, assigned or transferred to them.', 'bileto');
        $this->placeholders   = ['{event}' => ''];

        foreach (['bileto_issued', 'bileto_assigned', 'bileto_transferred'] as $action) {
            add_action($action . '_notification', [$this, 'trigger']);
        }

        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('Your ticket: {event}', 'bileto');
    }

    public function get_default_heading(): string
    {
        return __('Your ticket', 'bileto');
    }

    /** @param int $ticketId */
    public function trigger($ticketId): void
    {
        $this->setup_locale();
        $this->ticket = $this->repo->find((int) $ticketId);
        if ($this->ticket !== null && $this->ticket['email'] !== '' && $this->ticket['status'] === 'valid' && $this->is_enabled()) {
            $this->recipient               = $this->ticket['email'];
            $this->placeholders['{event}'] = get_the_title((int) $this->ticket['event_id']);
            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }
        $this->restore_locale();
    }

    public function get_content_html(): string
    {
        if ($this->ticket === null) {
            return '';
        }
        $t       = $this->ticket;
        $eventId = (int) $t['event_id'];
        $when    = EventPostType::when($eventId);

        ob_start();
        do_action('woocommerce_email_header', $this->get_heading(), $this);
        ?>
        <p><?php echo esc_html(sprintf(/* translators: %s: first name */ __('Hi %s,', 'bileto'), $t['first_name'])); ?></p>
        <p><?php echo esc_html(sprintf(/* translators: %s: event name */ __('here is your ticket for %s.', 'bileto'), get_the_title($eventId))); ?></p>
        <p>
            <strong><?php echo esc_html(get_the_title($eventId)); ?></strong><br>
            <?php if ($when !== '') : ?>
                <?php echo esc_html($when); ?><br>
            <?php endif; ?>
            <?php echo esc_html(EventPostType::venue($eventId)); ?><br>
            <?php echo esc_html(trim($t['first_name'] . ' ' . $t['last_name'])); ?>
        </p>
        <?php if (\Bileto\Qr::canPng()) : ?>
            <p style="text-align:center"><img src="<?php echo esc_url(TicketPage::qrUrl($t['code'])); ?>" width="220" height="220" alt="<?php esc_attr_e('Ticket QR code', 'bileto'); ?>"></p>
        <?php endif; ?>
        <p style="text-align:center"><a href="<?php echo esc_url(TicketPage::ticketUrl($t['token'])); ?>"><?php esc_html_e('Open your ticket', 'bileto'); ?></a></p>
        <p><?php esc_html_e('Show the QR code at the entrance. Can\'t come? Open your ticket and transfer it to a colleague.', 'bileto'); ?></p>
        <?php
        do_action('woocommerce_email_footer', $this);

        return (string) ob_get_clean();
    }

    public function get_content_plain(): string
    {
        if ($this->ticket === null) {
            return '';
        }

        return sprintf(
            "%s\n\n%s\n%s\n\n%s\n%s\n",
            get_the_title((int) $this->ticket['event_id']),
            EventPostType::venue((int) $this->ticket['event_id']),
            trim($this->ticket['first_name'] . ' ' . $this->ticket['last_name']),
            __('Open your ticket:', 'bileto'),
            TicketPage::ticketUrl($this->ticket['token'])
        );
    }
}
