<?php

declare(strict_types=1);

namespace Bileto\Ticket;

use Bileto\Contract\HasHooks;
use Bileto\Product\TicketProduct;

defined('ABSPATH') || exit;

/**
 * Ticket lifecycle: issue on payment, assign, transfer, check in, cancel.
 * Orders and invoices are never touched here.
 */
final class TicketService implements HasHooks
{
    public function __construct(private TicketRepository $repo)
    {
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_order_status_processing', [$this, 'issueForOrder']);
        add_action('woocommerce_order_status_completed', [$this, 'issueForOrder']);
        foreach (['cancelled', 'refunded', 'failed'] as $status) {
            add_action('woocommerce_order_status_' . $status, [$this, 'cancelForOrder']);
        }
        add_action('woocommerce_before_delete_order', [$this, 'cancelForOrder']);
        add_action('woocommerce_trash_order', [$this, 'cancelForOrder']);
    }

    /**
     * Issues the missing tickets of a paid order. An order that was cancelled
     * and is paid again gets its tickets back.
     */
    public function issueForOrder(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            return;
        }
        $existing = $this->repo->forOrder($orderId);
        if ($existing !== [] && $existing[0]['status'] === 'cancelled') {
            $this->repo->setStatusForOrder($orderId, 'valid');
        }
        $buyerHasTicket = $existing !== [];
        $issued         = [];

        foreach ($order->get_items() as $itemId => $item) {
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
            if (! $product instanceof \WC_Product || TicketProduct::eventId($product) === 0) {
                continue;
            }
            $have = $this->repo->countForItem((int) $itemId);
            for ($seat = $have; $seat < (int) $item->get_quantity(); $seat++) {
                $holder = $buyerHasTicket ? [] : [
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                    'email'      => $order->get_billing_email(),
                    'company'    => $order->get_billing_company(),
                ];
                $id = $this->repo->create($holder + [
                    'order_id'      => $orderId,
                    'order_item_id' => (int) $itemId,
                    'seat'          => $seat,
                    'product_id'    => $product->get_id(),
                    'event_id'      => TicketProduct::eventId($product),
                ]);
                if ($id > 0) {
                    $buyerHasTicket = true;
                    $issued[]       = $id;
                }
            }
        }

        foreach ($issued as $ticketId) {
            do_action('bileto_issued', $ticketId, $orderId);
        }
    }

    public function cancelForOrder(int $orderId): void
    {
        $this->repo->setStatusForOrder($orderId, 'cancelled');
        do_action('bileto_cancelled', $orderId);
    }

    /**
     * Put a name on an unassigned seat. The secrets stay: nobody held them yet.
     *
     * @param array{first_name: string, last_name: string, email: string, company: string} $holder
     */
    public function assign(int $ticketId, array $holder): bool
    {
        if (! $this->repo->update($ticketId, $holder)) {
            return false;
        }
        do_action('bileto_assigned', $ticketId);

        return true;
    }

    /**
     * Hand a ticket to someone else: new secrets, old QR and link stop working,
     * order and invoice stay as they are. The previous holder is told.
     *
     * @param array{first_name: string, last_name: string, email: string, company: string} $holder
     */
    public function transfer(int $ticketId, array $holder, int $userId): bool
    {
        $ticket = $this->repo->find($ticketId);
        if ($ticket === null || ! $this->repo->update($ticketId, $holder + TicketRepository::newSecrets())) {
            return false;
        }
        $this->repo->logTransfer($ticketId, $ticket['email'], $holder['email'], $userId);
        do_action('bileto_transferred', $ticketId, $ticket);

        return true;
    }

    /**
     * @param array<string, string>|null $ticket
     * @return array{code: 'ok'|'already'|'cancelled'|'unknown'|'other_event', ticket: array<string, string>|null, at: string|null}
     */
    public function checkIn(?array $ticket, int $eventId, int $userId): array
    {
        if ($ticket === null) {
            return ['code' => 'unknown', 'ticket' => null, 'at' => null];
        }
        if ((int) $ticket['event_id'] !== $eventId) {
            return ['code' => 'other_event', 'ticket' => $ticket, 'at' => null];
        }
        if ($ticket['status'] !== 'valid') {
            return ['code' => 'cancelled', 'ticket' => $ticket, 'at' => null];
        }
        $at = $this->repo->checkinToday((int) $ticket['id']);
        if ($at !== null) {
            return ['code' => 'already', 'ticket' => $ticket, 'at' => $at];
        }
        $this->repo->logCheckin((int) $ticket['id'], $userId);
        do_action('bileto_checked_in', (int) $ticket['id']);

        return ['code' => 'ok', 'ticket' => $ticket, 'at' => null];
    }
}
