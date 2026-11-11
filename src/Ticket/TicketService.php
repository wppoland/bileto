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
    }

    public function issueForOrder(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            return;
        }
        $buyerHasTicket = $this->repo->forOrder($orderId) !== [];
        $issued         = [];

        foreach ($order->get_items() as $itemId => $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            if (! $product instanceof \WC_Product || ! TicketProduct::isTicket($product)) {
                continue;
            }
            $missing = (int) $item->get_quantity() - $this->repo->countForItem((int) $itemId);
            for ($i = 0; $i < $missing; $i++) {
                $holder = $buyerHasTicket ? [] : [
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                    'email'      => $order->get_billing_email(),
                    'company'    => $order->get_billing_company(),
                ];
                $buyerHasTicket = true;
                $issued[]       = $this->repo->create($holder + [
                    'order_id'      => $orderId,
                    'order_item_id' => (int) $itemId,
                    'product_id'    => $product->get_id(),
                    'event_id'      => TicketProduct::eventId($product),
                ]);
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
     * Put a name on an unassigned seat. The token stays: nobody held it yet.
     *
     * @param array{first_name: string, last_name: string, email: string, company: string} $holder
     */
    public function assign(int $ticketId, array $holder): void
    {
        $this->repo->update($ticketId, $holder);
        do_action('bileto_assigned', $ticketId);
    }

    /**
     * Hand a ticket to someone else: new token, old QR stops working,
     * order and invoice stay as they are.
     *
     * @param array{first_name: string, last_name: string, email: string, company: string} $holder
     */
    public function transfer(int $ticketId, array $holder, int $userId): void
    {
        $ticket = $this->repo->find($ticketId);
        if ($ticket === null) {
            return;
        }
        $this->repo->update($ticketId, $holder + ['token' => TicketRepository::newToken()]);
        $this->repo->logTransfer($ticketId, $ticket['email'], $holder['email'], $userId);
        do_action('bileto_transferred', $ticketId, $ticket['email']);
    }

    /**
     * @return array{code: 'ok'|'already'|'cancelled'|'unknown', ticket: array<string, string>|null, at: string|null}
     */
    public function checkIn(string $token, int $userId): array
    {
        $ticket = $this->repo->byToken($token);
        if ($ticket === null) {
            return ['code' => 'unknown', 'ticket' => null, 'at' => null];
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
