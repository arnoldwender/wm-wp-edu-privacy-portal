<?php
/**
 * WM Edu Privacy Portal — uninstaller
 *
 * Preserves all options, consent types, guardian registry entries and the hash-chained consent ledger by default
 * (`wm_edu_delete_data_on_uninstall` defaults to `0`). Only when an administrator explicitly enables
 * `wm_edu_delete_data_on_uninstall` on the settings screen does uninstalling delete the plugin's options, capabilities,
 * transients and custom tables. Scheduled cron events are always cleared so no orphaned hook fires after removal.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'wm_edu_retention_cleanup' );
wp_clear_scheduled_hook( 'wm_edu_deliver_mail' );

$wm_edu_delete_all = in_array( get_option( 'wm_edu_delete_data_on_uninstall', '0' ), [ '1', 1, true ], true );
if ( ! $wm_edu_delete_all ) {
	return;
}

delete_option( 'wm_edu_schema_version' );
delete_option( 'wm_edu_legacy_row_boundary' );
delete_option( 'wm_edu_settings' );
delete_option( 'wm_edu_chain_tip' );
delete_option( 'wm_edu_delete_data_on_uninstall' );

foreach ( wp_roles()->role_objects as $wm_edu_role ) {
	if ( $wm_edu_role->has_cap( 'manage_edu_consents' ) ) {
		$wm_edu_role->remove_cap( 'manage_edu_consents' );
	}
}

global $wpdb;
if ( isset( $wpdb ) && is_object( $wpdb ) ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_wm_edu_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wm_edu_' ) . '%'
		)
	);
	foreach ( [ 'wm_edu_parent_consents', 'wm_edu_consent_types', 'wm_edu_consent_texts', 'wm_edu_guardians', 'wm_edu_consent_requests' ] as $wm_edu_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $wm_edu_table ) );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}
