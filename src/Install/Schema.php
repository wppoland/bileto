<?php

declare(strict_types=1);

namespace Bileto\Install;

defined('ABSPATH') || exit;

/**
 * Own tables: one row per ticket, plus transfer and check-in history.
 * Created on activation and upgraded when DB_VERSION changes.
 */
final class Schema
{
    public const DB_VERSION = '1';
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

        update_option(self::OPTION, self::DB_VERSION, false);
    }
}
