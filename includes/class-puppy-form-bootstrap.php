<?php
/**
 * Bootstrap class for the Custom Puppy Application Form.
 *
 * @package    Custom_Puppy_Application_Form
 * @subpackage Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Puppy_Form_Bootstrap
 *
 * Orchestrates class files loading, asset registration, and lifecycle processes.
 */
class Puppy_Form_Bootstrap {

	/**
	 * Single instance storage.
	 *
	 * @var Puppy_Form_Bootstrap|null
	 */
	private static $instance = null;

	/**
	 * Retrieve singleton instance.
	 *
	 * @return Puppy_Form_Bootstrap
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers styles enqueuing and bootstraps sub-systems.
	 */
	private function __construct() {
		// Include dependent files.
		$this->include_dependencies();

		// Init classes.
		$this->init_components();

		// Hooks.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
	}

	/**
	 * Include all required core files.
	 */
	private function include_dependencies() {
		$plugin_path = plugin_dir_path( dirname( __FILE__ ) );
		
		require_once $plugin_path . 'includes/class-puppy-form-admin.php';
		require_once $plugin_path . 'includes/class-puppy-form-frontend.php';
		require_once $plugin_path . 'includes/class-puppy-form-handler.php';
	}

	/**
	 * Instantiate active core components.
	 */
	private function init_components() {
		Puppy_Form_Admin::get_instance();
		Puppy_Form_Frontend::get_instance();
		Puppy_Form_Handler::get_instance();
	}

	/**
	 * Enqueue frontend CSS styling.
	 */
	public function enqueue_frontend_styles() {
		// Check if we are not in admin, then register and enqueue.
		if ( ! is_admin() ) {
			$plugin_url = plugin_dir_url( dirname( __FILE__ ) );
			
			wp_enqueue_style(
				'puppy-form-frontend-css',
				$plugin_url . 'assets/css/frontend.css',
				array(),
				'1.0.0',
				'all'
			);
		}
	}

	/**
	 * Enqueue admin stylesheet only on our plugin settings page.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_styles( $hook ) {
		if ( 'toplevel_page_puppy-application-form' === $hook ) {
			$plugin_url = plugin_dir_url( dirname( __FILE__ ) );
			
			wp_enqueue_style(
				'puppy-form-admin-css',
				$plugin_url . 'assets/css/admin.css',
				array(),
				'1.0.0',
				'all'
			);
		}
	}

	/**
	 * Set up custom database table upon plugin activation.
	 */
	public static function activate_plugin() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'puppy_applications';
		$charset_collate = $wpdb->get_charset_collate();

		// Strict SQL syntax for dbDelta compatibility.
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			target_year varchar(10) NOT NULL,
			traits varchar(255) NOT NULL,
			purpose_family tinyint(1) NOT NULL DEFAULT 0,
			purpose_sport tinyint(1) NOT NULL DEFAULT 0,
			purpose_therapy tinyint(1) NOT NULL DEFAULT 0,
			applicant_name varchar(255) NOT NULL,
			applicant_age varchar(20) NOT NULL,
			applicant_email varchar(255) NOT NULL,
			applicant_phone varchar(100) NOT NULL,
			applicant_address varchar(255) NOT NULL,
			family_situation text NOT NULL,
			living_situation text NOT NULL,
			work_situation text NOT NULL,
			dog_experience text NOT NULL,
			selection_wish varchar(255) NOT NULL,
			other_pets varchar(255) NOT NULL,
			nutrition_agreement tinyint(1) NOT NULL DEFAULT 0,
			privacy_agreement tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::create_log_table();
	}

	/**
	 * Creates (or upgrades, via dbDelta) the internal diagnostics log table.
	 *
	 * Used to record failures (e.g. failed DB inserts on form submission) that
	 * would otherwise be invisible to site owners without server log access.
	 */
	public static function create_log_table() {
		global $wpdb;

		$log_table_name  = $wpdb->prefix . 'puppy_form_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $log_table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			event_type varchar(50) NOT NULL,
			message text NOT NULL,
			ip_hash varchar(32) NOT NULL DEFAULT '',
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
