<?php
/**
 * Uninstall file for Custom Puppy Application Form.
 *
 * Safe-by-Default uninstallation: Database records and settings are only
 * removed if the administrator explicitly checked the wipeout option.
 *
 * @package    Custom_Puppy_Application_Form
 * @subpackage Uninstaller
 */

// If uninstall is not called by WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Retrieve consolidated settings array.
$settings = get_option( 'puppy_form_settings', array() );

// Only wipe data if the option is explicitly enabled within the settings array.
if ( is_array( $settings ) && isset( $settings['delete_data_on_uninstall'] ) && '1' === $settings['delete_data_on_uninstall'] ) {
	global $wpdb;

	// 1. Drop the custom database tables.
	$table_name = $wpdb->prefix . 'puppy_applications';
	$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

	$log_table_name = $wpdb->prefix . 'puppy_form_logs';
	$wpdb->query( "DROP TABLE IF EXISTS $log_table_name" );

	// 2. Delete the consolidated options array and internal version/state flags.
	delete_option( 'puppy_form_settings' );
	delete_option( 'puppy_form_db_version' );
	delete_option( 'puppy_form_log_table_exists' );
}
