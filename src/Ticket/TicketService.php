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
        add_action('woocommerce_order_status_changed', [$this, 'sync']);
        add_action('woocommerce_order_partially_refunded', [$this, 'sync']);
        add_action('woocommerce_order_fully_refunded', [$this, 'sync']);
        add_action('woocommerce_saved_order_items', [$this, 'sync']);
        add_action('woocommerce_refund_deleted', [$this, 'syncAfterRefundDeleted'], 10, 2);
        add_action('woocommerce_before_delete_order', [$this, 'cancelForOrder']);
        add_action('woocommerce_trash_order', [$this, 'cancelForOrder']);
    }

    /**
     * Makes the tickets of an order match it: one valid seat per paid unit
     * that was not refunded. Missing seats are issued, refunded or removed
     * seats are cancelled (unassigned ones first), restored seats come back.
     *
     * Gateways that refund asynchronously keep their own statuses while the
     * refund is pending or after it failed; refunds do not count then.
     * PlanetPay's statuses are the default (filter `bileto_refund_pending_statuses`).
     *
     * @param int|string $orderId
     */
    public function sync($orderId): void
    {
        $order = wc_get_order((int) $orderId);
        if (! $order instanceof \WC_Order) {
            return;
        }
        $status  = $order->get_status();
        $pending = (array) apply_filters('bileto_refund_pending_statuses', ['requested-status', 'rejected-status'], $order);
        if (in_array($status, ['cancelled', 'failed', 'refunded', 'trash'], true)) {
            $this->cancelForOrder($order->get_id());

            return;
        }
        // Any other status after payment counts as paid, including gateways' own refund statuses.
        if (in_array($status, (array) apply_filters('bileto_unpaid_statuses', ['pending', 'on-hold', 'checkout-draft'], $order), true)) {
            return;
        }
        $countRefunds = ! in_array($status, $pending, true);
        $issued       = [];
        $buyerHas     = $this->repo->forOrder($order->get_id()) !== [];

        foreach ($order->get_items() as $itemId => $item) {
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
            if (! $product instanceof \WC_Product || TicketProduct::eventId($product) === 0) {
                continue;
            }
            $qty   = (int) $item->get_quantity();
            $seats = $qty - ($countRefunds ? abs((int) $order->get_qty_refunded_for_item((int) $itemId)) : 0);

            for ($seat = $this->repo->countForItem((int) $itemId); $seat < $qty; $seat++) {
                $holder = $buyerHas ? [] : [
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                    'email'      => $order->get_billing_email(),
                    'company'    => $order->get_billing_company(),
                ];
                $id = $this->repo->create($holder + [
                    'order_id'      => $order->get_id(),
                    'order_item_id' => (int) $itemId,
                    'seat'          => $seat,
                    'product_id'    => $product->get_id(),
                    'event_id'      => TicketProduct::eventId($product),
                ]);
                if ($id > 0) {
                    $buyerHas = true;
                    $issued[] = $id;
                }
            }
            $this->applySeatCount($this->repo->forItem((int) $itemId), max(0, $seats));
        }

        foreach ($issued as $ticketId) {
            if (($this->repo->find($ticketId)['status'] ?? '') === 'valid') {
                do_action('bileto_issued', $ticketId, $order->get_id());
            }
        }
    }

    /**
     * @param int|string $refundId
     * @param int|string $orderId
     */
    public function syncAfterRefundDeleted($refundId, $orderId): void
    {
        $this->sync($orderId);
    }

    /**
     * Keeps $valid seats valid: assigned seats are kept before empty ones,
     * lower seat numbers before higher.
     *
     * @param list<array<string, string>> $tickets
     */
    private function applySeatCount(array $tickets, int $valid): void
    {
        usort($tickets, static fn (array $a, array $b): int => [$a['email'] === '', (int) $a['seat']] <=> [$b['email'] === '', (int) $b['seat']]);
        foreach ($tickets as $i => $ticket) {
            $status = $i < $valid ? 'valid' : 'cancelled';
            if ($ticket['status'] !== $status) {
                $this->repo->update((int) $ticket['id'], ['status' => $status]);
            }
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
