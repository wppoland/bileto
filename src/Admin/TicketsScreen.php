<?php

declare(strict_types=1);

namespace Bileto\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Bileto\Contract\HasHooks;
use Bileto\Event\EventPostType;
use Bileto\Ticket\TicketRepository;

defined('ABSPATH') || exit;

/**
 * Events > Tickets: who holds a ticket for an event, with a CSV export
 * (badges, catering lists). Shop managers only: it shows full e-mails.
 */
final class TicketsScreen implements HasHooks
{
    private const CAP    = 'manage_woocommerce';
    private const SLUG   = 'bileto-tickets';
    private const EXPORT = 'bileto_export_tickets';

    public function __construct(private TicketRepository $repo)
    {
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::EXPORT, [$this, 'export']);
    }

    public function menu(): void
    {
        add_submenu_page('edit.php?post_type=' . EventPostType::POST_TYPE, __('Tickets', 'bileto'), __('Tickets', 'bileto'), self::CAP, self::SLUG, [$this, 'render']);
    }

    public function render(): void
    {
        $eventId = EventPostType::selected();
        $tickets = $eventId > 0 ? $this->repo->forEvent($eventId) : [];
        $labels  = self::statusLabels();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Tickets', 'bileto'); ?></h1>
            <?php if ($eventId > 0) : ?>
                <a class="page-title-action" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::EXPORT . '&event=' . $eventId), self::EXPORT)); ?>"><?php esc_html_e('Export CSV', 'bileto'); ?></a>
            <?php endif; ?>
            <hr class="wp-header-end">
            <?php if ($eventId === 0) : ?>
                <p><?php esc_html_e('Create an event first.', 'bileto'); ?></p>
            <?php else : ?>
                <?php EventPostType::picker($eventId, ['post_type' => EventPostType::POST_TYPE, 'page' => self::SLUG]); ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e('Name', 'bileto'); ?></th>
                        <th scope="col"><?php esc_html_e('E-mail', 'bileto'); ?></th>
                        <th scope="col"><?php esc_html_e('Company', 'bileto'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'bileto'); ?></th>
                        <th scope="col"><?php esc_html_e('Checked In', 'bileto'); ?></th>
                        <th scope="col"><?php esc_html_e('Order', 'bileto'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if ($tickets === []) : ?>
                        <tr><td colspan="6"><?php esc_html_e('No tickets yet.', 'bileto'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($tickets as $t) : ?>
                        <tr>
                            <td><?php echo esc_html(trim($t['first_name'] . ' ' . $t['last_name'])); ?></td>
                            <td><?php echo esc_html((string) $t['email']); ?></td>
                            <td><?php echo esc_html((string) $t['company']); ?></td>
                            <td><?php echo esc_html($t['email'] === '' && $t['status'] === 'valid' ? __('Unassigned', 'bileto') : ($labels[$t['status']] ?? (string) $t['status'])); ?></td>
                            <td><?php echo esc_html(self::localTime($t['checked_in'])); ?></td>
                            <td><a href="<?php echo esc_url(OrderUtil::get_order_admin_edit_url((int) $t['order_id'])); ?>">#<?php echo esc_html((string) $t['order_id']); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function export(): void
    {
        if (! current_user_can(self::CAP)) {
            wp_die(esc_html__('You are not allowed to export tickets.', 'bileto'), 403);
        }
        check_admin_referer(self::EXPORT);
        $eventId = isset($_GET['event']) ? absint($_GET['event']) : 0;
        if (get_post_type($eventId) !== EventPostType::POST_TYPE) {
            wp_die(esc_html__('Unknown event.', 'bileto'), 404);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(get_the_title($eventId) . '-tickets.csv') . '"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, so Excel shows Polish letters.
        fputcsv($out, ['ticket_id', 'first_name', 'last_name', 'email', 'company', 'status', 'checked_in', 'order_id'], ',', '"', '');
        foreach ($this->repo->forEvent($eventId) as $t) {
            fputcsv($out, array_map([self::class, 'csvCell'], [$t['id'], $t['first_name'], $t['last_name'], $t['email'], $t['company'], $t['status'], self::localTime($t['checked_in']), $t['order_id']]), ',', '"', '');
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream.
        exit;
    }

    /** Buyers type these values: a leading = + - @ (or tab, CR) would run as a formula in a spreadsheet. */
    public static function csvCell(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    private static function localTime(?string $gmt): string
    {
        return $gmt ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) strtotime($gmt . ' UTC')) : '';
    }

    /** @return array<string, string> */
    private static function statusLabels(): array
    {
        return ['valid' => __('Valid', 'bileto'), 'cancelled' => __('Cancelled', 'bileto')];
    }
}
