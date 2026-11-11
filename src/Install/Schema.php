<?php

declare(strict_types=1);

namespace Bileto\Install;

defined('ABSPATH') || exit;

/**
 * Own tables (one row per ticket, plus transfer and check-in history), the
 * Check-in role and the ticket page. Runs on first boot and whenever
 * DB_VERSION changes.
 */
final class Schema
{
    public const DB_VERSION = '2';
    private const OPTION    = 'bileto_db_version';

    public static function maybeUpgrade(): void
    {
        if (get_option(self::OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}bileto_tickets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            event_id bigint(20) unsigned NOT NULL,
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            company varchar(190) NOT NULL DEFAULT '',
            role varchar(40) NOT NULL DEFAULT 'attendee',
            status varchar(20) NOT NULL DEFAULT 'valid',
            token char(32) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY order_id (order_id),
            KEY event_id (event_id),
            KEY email (email)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}bileto_transfers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) unsigned NOT NULL,
            from_email varchar(190) NOT NULL DEFAULT '',
            to_email varchar(190) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}bileto_checkins (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) unsigned NOT NULL,
            direction varchar(3) NOT NULL DEFAULT 'in',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id)
        ) $charset;");

        \Bileto\Checkin\Checkin::addRoles();
        self::ensurePage();
        update_option(self::OPTION, self::DB_VERSION, false);
    }

    private static function ensurePage(): void
    {
        $option = \Bileto\Front\TicketPage::PAGE_OPTION;
        if (get_post_status((int) get_option($option)) === 'publish') {
            return;
        }
        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => __('My Ticket', 'bileto'),
            'post_name'    => 'ticket',
            'post_content' => '<!-- wp:shortcode -->[bileto]<!-- /wp:shortcode -->',
        ]);
        if (is_int($id) && $id > 0) {
            update_option($option, $id);
        }
    }
}
