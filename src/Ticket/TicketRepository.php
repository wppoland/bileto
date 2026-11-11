<?php

declare(strict_types=1);

namespace Bileto\Ticket;

defined('ABSPATH') || exit;

/**
 * All SQL for tickets, transfers and check-ins.
 *
 * @phpstan-type Ticket array{id: string, order_id: string, order_item_id: string, product_id: string, event_id: string, seat: string, first_name: string, last_name: string, email: string, company: string, status: string, token: string, code: string, created_at: string, updated_at: string}
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
     * Returns the new ticket id, or 0 when the seat already exists (a second
     * payment callback for the same order) or the insert failed.
     *
     * @param array<string, int|string> $data
     */
    public function create(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $ok  = $wpdb->insert($this->tickets, $data + self::newSecrets() + ['status' => 'valid', 'created_at' => $now, 'updated_at' => $now]);

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * @param array<string, string> $data
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;

        return (bool) $wpdb->update($this->tickets, $data + ['updated_at' => current_time('mysql', true)], ['id' => $id]);
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
        return $this->bySecret('token', $token);
    }

    /** @return Ticket|null */
    public function byCode(string $code): ?array
    {
        return $this->bySecret('code', $code);
    }

    /** @return Ticket|null */
    private function bySecret(string $column, string $value): ?array
    {
        global $wpdb;
        if (! preg_match('/^[a-f0-9]{32}$/', $value)) {
            return null;
        }
        $sql = $column === 'code' ? "SELECT * FROM {$this->tickets} WHERE code = %s" : "SELECT * FROM {$this->tickets} WHERE token = %s";
        $row = $wpdb->get_row($wpdb->prepare($sql, $value), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is one of two literals.

        return is_array($row) ? $row : null;
    }

    /** @return list<Ticket> */
    public function forOrder(int $orderId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tickets} WHERE order_id = %d ORDER BY id", $orderId), ARRAY_A) ?: [];
    }

    /** @return list<Ticket> */
    public function forItem(int $itemId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tickets} WHERE order_item_id = %d ORDER BY seat", $itemId), ARRAY_A) ?: [];
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
    public function search(int $eventId, string $term, int $limit = 20): array
    {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($term) . '%';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tickets} WHERE event_id = %d AND (email LIKE %s OR CONCAT(first_name, ' ', last_name) LIKE %s OR company LIKE %s) ORDER BY last_name, first_name LIMIT %d",
            $eventId,
            $like,
            $like,
            $like,
            $limit
        ), ARRAY_A) ?: [];
    }

    /**
     * Every ticket of an event with its first check-in (GMT, any day), for the admin list and export.
     *
     * @return list<array<string, string|null>>
     */
    public function forEvent(int $eventId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.order_id, t.first_name, t.last_name, t.email, t.company, t.status, (SELECT MIN(c.created_at) FROM {$this->checkins} c WHERE c.ticket_id = t.id) AS checked_in FROM {$this->tickets} t WHERE t.event_id = %d ORDER BY t.last_name, t.first_name, t.id",
            $eventId
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
        $wpdb->insert($this->checkins, ['ticket_id' => $ticketId, 'user_id' => $userId, 'created_at' => current_time('mysql', true)]);
    }

    /** Time (GMT, MySQL format) of today's first check-in, or null. "Today" follows the site time zone. */
    public function checkinToday(int $ticketId): ?string
    {
        global $wpdb;
        $at = $wpdb->get_var($wpdb->prepare("SELECT MIN(created_at) FROM {$this->checkins} WHERE ticket_id = %d AND created_at >= %s", $ticketId, self::todayStartGmt()));

        return is_string($at) ? $at : null;
    }

    /** @return array{total: int, in: int} Valid tickets of the event and those checked in today. */
    public function stats(int $eventId): array
    {
        global $wpdb;
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tickets} WHERE event_id = %d AND status = 'valid'", $eventId));
        $in    = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT c.ticket_id) FROM {$this->checkins} c JOIN {$this->tickets} t ON t.id = c.ticket_id WHERE t.event_id = %d AND t.status = 'valid' AND c.created_at >= %s",
            $eventId,
            self::todayStartGmt()
        ));

        return ['total' => $total, 'in' => $in];
    }

    /** @return list<array{ticket_id: string, from_email: string, to_email: string, created_at: string}> */
    public function transfersFor(string $email): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("SELECT ticket_id, from_email, to_email, created_at FROM {$this->transfers} WHERE from_email = %s OR to_email = %s", $email, $email), ARRAY_A) ?: [];
    }

    /** @return list<Ticket> */
    public function forEmail(string $email): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tickets} WHERE email = %s", $email), ARRAY_A) ?: [];
    }

    public function anonymizeEmail(string $email): int
    {
        global $wpdb;
        $tickets = (int) $wpdb->query($wpdb->prepare("UPDATE {$this->tickets} SET first_name = '', last_name = '', company = '', email = '' WHERE email = %s", $email));
        $wpdb->query($wpdb->prepare("UPDATE {$this->transfers} SET from_email = '' WHERE from_email = %s", $email));
        $wpdb->query($wpdb->prepare("UPDATE {$this->transfers} SET to_email = '' WHERE to_email = %s", $email));

        return $tickets;
    }

    /** @return array{token: string, code: string} */
    public static function newSecrets(): array
    {
        return ['token' => bin2hex(random_bytes(16)), 'code' => bin2hex(random_bytes(16))];
    }

    private static function todayStartGmt(): string
    {
        return get_gmt_from_date(wp_date('Y-m-d 00:00:00'));
    }
}
