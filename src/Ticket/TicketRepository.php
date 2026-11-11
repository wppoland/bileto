<?php

declare(strict_types=1);

namespace Bileto\Ticket;

defined('ABSPATH') || exit;

/**
 * All SQL for tickets, transfers and check-ins.
 *
 * @phpstan-type Ticket array{id: string, order_id: string, order_item_id: string, product_id: string, event_id: string, first_name: string, last_name: string, email: string, company: string, role: string, status: string, token: string, created_at: string, updated_at: string}
 */
final class TicketRepository
{
    private string $tickets;
    private string $transfers;
    private string $checkins;

    public function __construct()
    {
        global $wpdb;
        $this->tickets   = $wpdb->prefix . 'bileto_tickets';
        $this->transfers = $wpdb->prefix . 'bileto_transfers';
        $this->checkins  = $wpdb->prefix . 'bileto_checkins';
    }

    /**
     * @param array<string, int|string> $data
     */
    public function create(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert($this->tickets, $data + ['token' => self::newToken(), 'status' => 'valid', 'created_at' => $now, 'updated_at' => $now]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, string> $data
     */
    public function update(int $id, array $data): void
    {
        global $wpdb;
        $wpdb->update($this->tickets, $data + ['updated_at' => current_time('mysql', true)], ['id' => $id]);
    }

    /** @return Ticket|null */
    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tickets} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @return Ticket|null */
    public function byToken(string $token): ?array
    {
        global $wpdb;
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tickets} WHERE token = %s", $token), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @return list<Ticket> */
    public function forOrder(int $orderId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tickets} WHERE order_id = %d ORDER BY id", $orderId), ARRAY_A) ?: [];
    }

    public function countForItem(int $itemId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tickets} WHERE order_item_id = %d", $itemId));
    }

    public function setStatusForOrder(int $orderId, string $status): void
    {
        global $wpdb;
        $wpdb->update($this->tickets, ['status' => $status, 'updated_at' => current_time('mysql', true)], ['order_id' => $orderId]);
    }

    /** @return list<Ticket> */
    public function search(string $term, int $limit = 20): array
    {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($term) . '%';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tickets} WHERE email LIKE %s OR last_name LIKE %s OR first_name LIKE %s OR CONCAT(first_name, ' ', last_name) LIKE %s ORDER BY last_name, first_name LIMIT %d",
            $like,
            $like,
            $like,
            $like,
            $limit
        ), ARRAY_A) ?: [];
    }

    public function logTransfer(int $ticketId, string $from, string $to, int $userId): void
    {
        global $wpdb;
        $wpdb->insert($this->transfers, ['ticket_id' => $ticketId, 'from_email' => $from, 'to_email' => $to, 'user_id' => $userId, 'created_at' => current_time('mysql', true)]);
    }

    public function logCheckin(int $ticketId, int $userId): void
    {
        global $wpdb;
        $wpdb->insert($this->checkins, ['ticket_id' => $ticketId, 'direction' => 'in', 'user_id' => $userId, 'created_at' => current_time('mysql', true)]);
    }

    /** Time (GMT, MySQL format) of today's first check-in, or null. "Today" follows the site time zone. */
    public function checkinToday(int $ticketId): ?string
    {
        global $wpdb;
        $start = get_gmt_from_date(wp_date('Y-m-d 00:00:00'));
        $at    = $wpdb->get_var($wpdb->prepare("SELECT MIN(created_at) FROM {$this->checkins} WHERE ticket_id = %d AND created_at >= %s", $ticketId, $start));

        return is_string($at) ? $at : null;
    }

    /** @return array{total: int, in: int} Valid tickets and those checked in today. */
    public function stats(): array
    {
        global $wpdb;
        $start = get_gmt_from_date(wp_date('Y-m-d 00:00:00'));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tickets} WHERE status = 'valid'");
        $in    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ticket_id) FROM {$this->checkins} WHERE created_at >= %s", $start));

        return ['total' => $total, 'in' => $in];
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
