<?php

declare(strict_types=1);

namespace Bileto;

use Bileto\Contract\HasHooks;
use Bileto\Ticket\TicketRepository;

defined('ABSPATH') || exit;

/**
 * Attendee data in WordPress' own privacy tools (Tools > Export / Erase
 * Personal Data). Erasing removes the name, company and e-mail from tickets
 * and transfer history; the ticket row stays because it belongs to an order.
 */
final class Privacy implements HasHooks
{
    public function __construct(private TicketRepository $repo)
    {
    }

    public function registerHooks(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'erasers']);
    }

    /**
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public function exporters($exporters): array
    {
        $exporters['bileto'] = ['exporter_friendly_name' => __('Event tickets', 'bileto'), 'callback' => [$this, 'export']];

        return $exporters;
    }

    /**
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public function erasers($erasers): array
    {
        $erasers['bileto'] = ['eraser_friendly_name' => __('Event tickets', 'bileto'), 'callback' => [$this, 'erase']];

        return $erasers;
    }

    /** @return array{data: list<array<string, mixed>>, done: bool} */
    public function export(string $email): array
    {
        $data = [];
        foreach ($this->repo->forEmail($email) as $t) {
            $data[] = [
                'group_id'    => 'bileto-tickets',
                'group_label' => __('Event tickets', 'bileto'),
                'item_id'     => 'bileto-ticket-' . $t['id'],
                'data'        => [
                    ['name' => __('Event', 'bileto'), 'value' => get_the_title((int) $t['event_id'])],
                    ['name' => __('Name', 'bileto'), 'value' => trim($t['first_name'] . ' ' . $t['last_name'])],
                    ['name' => __('Company', 'bileto'), 'value' => $t['company']],
                    ['name' => __('E-mail', 'bileto'), 'value' => $t['email']],
                    ['name' => __('Status', 'bileto'), 'value' => $t['status']],
                    ['name' => __('Order', 'bileto'), 'value' => $t['order_id']],
                ],
            ];
        }
        foreach ($this->repo->transfersFor($email) as $i => $tr) {
            $data[] = [
                'group_id'    => 'bileto-transfers',
                'group_label' => __('Ticket transfers', 'bileto'),
                'item_id'     => 'bileto-transfer-' . $tr['ticket_id'] . '-' . $i,
                'data'        => [
                    ['name' => __('From', 'bileto'), 'value' => $tr['from_email']],
                    ['name' => __('To', 'bileto'), 'value' => $tr['to_email']],
                    ['name' => __('Date', 'bileto'), 'value' => $tr['created_at']],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
    public function erase(string $email): array
    {
        return ['items_removed' => $this->repo->anonymizeEmail($email) > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
