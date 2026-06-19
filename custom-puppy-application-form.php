<?php
/**
 * Plugin Name: Custom Puppy Application Form
 * Plugin URI:  https://bezugssysteme.de/
 * Description: Renders a secure puppy application form via shortcode, handles automated HTML emails (breeder notification and autoresponder), and includes a secure settings page with tabbed navigation under its own admin menu. Fully modular, highly accessible, customizable, and saves submissions to a custom database applicant table.
 * Version:     1.1.0
 * Author:      Bezugssysteme Digitalagentur
 * Author URI:  https://bezugssysteme.de/
 * License:     GPLv2 or later
 * Text Domain: custom-puppy-form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Require Core Bootstrap file.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-puppy-form-bootstrap.php';

/**
 * Initialize the Custom Puppy Application Form plugin.
 */
function run_custom_puppy_application_form() {
	Puppy_Form_Bootstrap::get_instance();
}
add_action( 'plugins_loaded', 'run_custom_puppy_application_form' );

// Register Database Schema Activation Hook.
register_activation_hook( __FILE__, array( 'Puppy_Form_Bootstrap', 'activate_plugin' ) );
