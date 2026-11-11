<?php
/**
 * Uninstall routine. Ticket data is removed only when the site owner opted
 * in with the "delete data on uninstall" setting: tickets are sales records.
 *
 * @package Bileto
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove this plugin's options from the current site, if the owner opted in.
 */
function bileto_uninstall_site(): void {
	$bileto_settings = get_option( 'bileto_settings', array() );
	if ( ! is_array( $bileto_settings ) || empty( $bileto_settings['delete_data'] ) ) {
		return;
	}

	delete_option( 'bileto_settings' );
}

// Options are per site, so a network uninstall has to visit every site.
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
