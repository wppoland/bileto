<?php

/**
 * A paid order with empty seats gets the attendee reminder once the interval
 * has passed since payment, then again only after another interval, and never
 * once every seat has an attendee or the event has started.
 *
 * Run: wp eval-file tests/integration/attendee-reminders.php
 */

use Bileto\Email\Emails;
use Bileto\Ticket\TicketRepository;
use Bileto\Ticket\TicketService;

require_once __DIR__ . '/bootstrap.php';

$repo    = new TicketRepository();
$service = new TicketService($repo);
$event   = bileto_test_event('PL');
update_post_meta($event, '_bileto_start', gmdate('Y-m-d\TH:i', time() + 30 * DAY_IN_SECONDS));
$product = bileto_test_ticket_product($event, '100');

$order = wc_create_order();
$order->add_product($product, 3);
$order->set_billing_email('ada@example.com');
$order->calculate_totals();
$order->save();
$order->update_status('processing');
$order = wc_get_order($order->get_id());

$sent = [];
add_action('bileto_attendee_reminder', static function ($orderId, $open) use (&$sent): void {
    $sent[] = [(int) $orderId, (int) $open];
}, 1, 2);
$emails = new Emails($repo);
$mine   = static function () use (&$sent, $order): array {
    return array_values(array_filter($sent, static fn (array $s): bool => $s[0] === $order->get_id()));
};

$emails->remindAttendees();
bileto_test_assert($mine() === [], 'no reminder right after payment');

$order->set_date_paid(time() - 4 * DAY_IN_SECONDS);
$order->save();
$emails->remindAttendees();
bileto_test_assert($mine() === [[$order->get_id(), 2]], 'reminder after the interval, counting the 2 empty seats');

$emails->remindAttendees();
bileto_test_assert(count($mine()) === 1, 'no second reminder within the interval');

$order = wc_get_order($order->get_id());
$order->update_meta_data('_bileto_attendees_reminded', (string) (time() - 4 * DAY_IN_SECONDS));
$order->save();
$emails->remindAttendees();
bileto_test_assert(count($mine()) === 2, 'reminded again after another interval');

update_post_meta($event, '_bileto_start', gmdate('Y-m-d\TH:i', time() - HOUR_IN_SECONDS));
$order = wc_get_order($order->get_id());
$order->update_meta_data('_bileto_attendees_reminded', (string) (time() - 4 * DAY_IN_SECONDS));
$order->save();
$emails->remindAttendees();
bileto_test_assert(count($mine()) === 2, 'no reminder once the event has started');
update_post_meta($event, '_bileto_start', gmdate('Y-m-d\TH:i', time() + 30 * DAY_IN_SECONDS));

foreach ($repo->forOrder($order->get_id()) as $i => $ticket) {
    if ($ticket['email'] === '') {
        $service->assign((int) $ticket['id'], ['first_name' => 'Guest', 'last_name' => (string) $i, 'email' => "guest{$i}@example.com", 'company' => '']);
    }
}
$order = wc_get_order($order->get_id());
$order->update_meta_data('_bileto_attendees_reminded', (string) (time() - 4 * DAY_IN_SECONDS));
$order->save();
$emails->remindAttendees();
bileto_test_assert(count($mine()) === 2, 'no reminder once every seat has an attendee');

$order->delete(true);
bileto_test_cleanup();
bileto_test_done();
