<?php
/**
 * Uninstall routine. Tickets are sales records, so they are removed only when
 * the site owner opted in by setting the `bileto_delete_data` option to "yes".
 * Options, the Check-in role and its capability are always removed.
 *
 * @package Bileto
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean up the current site.
 */
function bileto_uninstall_site(): void {
	global $wpdb;

	if ( get_option( 'bileto_delete_data' ) === 'yes' ) {
		foreach ( array( 'bileto_tickets', 'bileto_transfers', 'bileto_checkins' ) as $bileto_table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $bileto_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- fixed table names.
		}
		wp_delete_post( (int) get_option( 'bileto_page_id' ), true );
	}

	foreach ( array( 'bileto_db_version', 'bileto_page_id', 'bileto_delete_data' ) as $bileto_option ) {
		delete_option( $bileto_option );
	}
	remove_role( 'bileto_checkin' );
	foreach ( array( 'administrator', 'shop_manager' ) as $bileto_role ) {
		$bileto_role_object = get_role( $bileto_role );
		if ( $bileto_role_object ) {
			$bileto_role_object->remove_cap( 'bileto_checkin' );
		}
	}
}

// Data is per site, so a network uninstall has to visit every site.
if ( is_multisite() ) {
	$bileto_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $bileto_site_ids as $bileto_site_id ) {
		switch_to_blog( (int) $bileto_site_id );
		bileto_uninstall_site();
		restore_current_blog();
	}
	unset( $bileto_site_ids, $bileto_site_id );
} else {
	bileto_uninstall_site();
}
