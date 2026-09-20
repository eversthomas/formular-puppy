<?php
/**
 * Admin Panel and Settings Manager.
 *
 * @package    Custom_Puppy_Application_Form
 * @subpackage Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Puppy_Form_Admin
 *
 * Registers the Settings page, menus, options, and handles settings output.
 */
class Puppy_Form_Admin {

	/**
	 * Single instance storage.
	 *
	 * @var Puppy_Form_Admin|null
	 */
	private static $instance = null;

	/**
	 * Retrieve singleton instance.
	 *
	 * @return Puppy_Form_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers admin hooks and settings page processes.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_admin_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_delete_application' ) );
		add_action( 'admin_init', array( $this, 'handle_privacy_activation' ) );
		add_action( 'admin_notices', array( $this, 'render_privacy_admin_notice' ) );
		add_action( 'admin_init', array( $this, 'handle_privacy_revocation' ) );
		// Runs on every request so schema upgrades apply before frontend form submissions.
		add_action( 'init', array( $this, 'maybe_upgrade_database' ), 5 );
		add_action( 'admin_init', array( $this, 'handle_csv_export' ) );

		// Runs on every request (frontend included), so the log table exists before a
		// site visitor can submit the form - independent of when an admin next logs in.
		add_action( 'init', array( $this, 'maybe_create_log_table_early' ) );
	}

	/**
	 * Ensures the diagnostics log table exists as early as possible, on any request
	 * (not just admin_init), so form submissions right after an update can always log.
	 * Uses its own lightweight flag instead of puppy_form_db_version, so it never
	 * interferes with - or skips ahead of - the ordered migration chain in
	 * maybe_upgrade_database().
	 */
	public function maybe_create_log_table_early() {
		if ( '1' === get_option( 'puppy_form_log_table_exists', '0' ) ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-puppy-form-bootstrap.php';
		Puppy_Form_Bootstrap::create_log_table();

		// Only mark as "exists" if dbDelta actually succeeded - otherwise retry on the
		// next request instead of silently staying blind to future log_event() calls.
		global $wpdb;
		$log_table_name = $wpdb->prefix . 'puppy_form_logs';
		$table_found    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table_name ) );
		if ( $table_found === $log_table_name ) {
			update_option( 'puppy_form_log_table_exists', '1' );
		}
	}

	/**
	 * Retrieve standard default values for all plugin options.
	 *
	 * @param string $option_name Option key.
	 * @return string Default value or empty string.
	 */
	public static function get_default( $option_name ) {
		$defaults = array(
			'receiver_email'            => get_option( 'admin_email' ),
			'puppy_years_whitelist'     => "2026\n2027\n2028",
			'puppy_litters_whitelist'   => '',
			'puppy_litters_intro'       => '',
			'puppy_price'                => '2.200 €',
			'delete_data_on_uninstall'  => '0',
			'privacy_activated'         => '0',
			'field_label_name'          => 'Vollständiger Name',
			'field_label_email'         => 'E-Mail-Adresse',
			'field_label_phone'         => 'Telefonnummer',
			'field_label_living'        => 'Wohnsituation',
			'field_options_living'      => 'Mietwohnung, Eigentumswohnung, Haus mit Garten',
			'field_label_working'       => 'Arbeitszeiten / Zeit alleine',
			'field_options_working'      => 'Weniger als 2 Stunden, 2 bis 5 Stunden, Mehr als 5 Stunden',
			'field_label_experience'    => 'Hundeerfahrung',
			'field_label_privacy'       => 'Ich stimme der Datenschutzerklärung zu und willige in die Verarbeitung meiner Daten ein.',
			'custom_email_body'         => "Hallo {name},\n\nvielen Dank für Ihre Bewerbung für einen Welpen!\n\nWir freuen uns über Ihr Interesse. Der aktuelle Preis für einen Welpen beträgt {price}.\n\nWir werden Ihre Angaben in den nächsten Tagen sorgfältig prüfen und uns bezüglich der weiteren Schritte bei Ihnen melden.\n\nHerzliche Grüße,\nBezugssysteme Digitalagentur",
			
			// Fields Active status
			'field_active_year'              => '1',
			'field_active_litter'            => '1',
			'field_active_traits'            => '1',
			'field_active_purpose'           => '1',
			'field_active_applicant_name'    => '1',
			'field_active_applicant_age'     => '1',
			'field_active_applicant_email'    => '1',
			'field_active_applicant_phone'    => '1',
			'field_active_applicant_address'  => '1',
			'field_active_family_situation'   => '1',
			'field_active_living_situation'   => '1',
			'field_active_work_situation'     => '1',
			'field_active_dog_experience'     => '1',
			'field_active_selection_wish'     => '1',
			'field_active_other_pets'         => '1',

			// Fields Required status
			'field_required_year'             => '1',
			'field_required_litter'           => '1',
			'field_required_traits'           => '1',
			'field_required_purpose'          => '0',
			'field_required_applicant_name'   => '1',
			'field_required_applicant_age'    => '1',
			'field_required_applicant_email'   => '1',
			'field_required_applicant_phone'   => '1',
			'field_required_applicant_address' => '1',
			'field_required_family_situation'  => '1',
			'field_required_living_situation'  => '1',
			'field_required_work_situation'    => '1',
			'field_required_dog_experience'    => '1',
			'field_required_selection_wish'    => '1',
			'field_required_other_pets'        => '1',
		);

		return isset( $defaults[ $option_name ] ) ? $defaults[ $option_name ] : '';
	}

	/**
	 * Retrieve a single plugin setting from the consolidated settings array with its default fallback.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get_setting( $key ) {
		$settings = get_option( 'puppy_form_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( isset( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}

		// Fallback to default values.
		return self::get_default( $key );
	}

	/**
	 * Parse the configured litter labels (one per line) into a clean list.
	 *
	 * @return string[]
	 */
	public static function get_litter_options() {
		$raw = self::get_setting( 'puppy_litters_whitelist' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$lines   = preg_split( '/\r\n|\r|\n/', $raw );
		$options = array();

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( '' !== $trimmed ) {
				$options[] = $trimmed;
			}
		}

		return $options;
	}

	/**
	 * Format a stored multi-select litter value for admin, CSV and e-mail output.
	 *
	 * @param string $value Newline-separated litter labels.
	 * @return string
	 */
	public static function format_litter_choice_for_display( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$parts = preg_split( '/\r\n|\r|\n/', $value );
		$parts = array_filter( array_map( 'trim', $parts ) );

		return implode( ', ', $parts );
	}

	/**
	 * Register top-level admin menu page.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Welpen-Formular Bewerbungen', 'custom-puppy-form' ),
			__( 'Welpen-Formular', 'custom-puppy-form' ),
			'manage_options',
			'puppy-application-form',
			array( $this, 'render_admin_page' ),
			'dashicons-pets',
			30
		);
	}

	/**
	 * Register settings using the WordPress Settings API.
	 */
	public function register_admin_settings() {
		// Register a single consolidated options array.
		register_setting(
			'puppy_app_settings_group',
			'puppy_form_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_all_settings' ),
				'default'           => array(),
			)
		);

		// ==========================================
		// TAB 2 SECTIONS & FIELDS: FORM CONFIGURATION
		// ==========================================

		// Section A: General Settings.
		add_settings_section(
			'puppy_app_general_section',
			__( 'Allgemeine Formular-Einstellungen', 'custom-puppy-form' ),
			array( $this, 'render_general_section_desc' ),
			'puppy-form-settings-page'
		);

		add_settings_field(
			'puppy_app_receiver_email',
			__( 'E-Mail-Empfänger für Bewerbungen', 'custom-puppy-form' ),
			array( $this, 'render_receiver_email_field' ),
			'puppy-form-settings-page',
			'puppy_app_general_section'
		);

		add_settings_field(
			'puppy_years_whitelist',
			__( 'Verfügbare Jahre (ein Jahr pro Zeile)', 'custom-puppy-form' ),
			array( $this, 'render_years_whitelist_field' ),
			'puppy-form-settings-page',
			'puppy_app_general_section'
		);

		add_settings_field(
			'puppy_litters_whitelist',
			__( 'Verfügbare Würfe (ein Eintrag pro Zeile)', 'custom-puppy-form' ),
			array( $this, 'render_litters_whitelist_field' ),
			'puppy-form-settings-page',
			'puppy_app_general_section'
		);

		add_settings_field(
			'puppy_litters_intro',
			__( 'Erklärungstext zur Wurf-Auswahl', 'custom-puppy-form' ),
			array( $this, 'render_litters_intro_field' ),
			'puppy-form-settings-page',
			'puppy_app_general_section'
		);

		add_settings_field(
			'puppy_app_puppy_price',
			__( 'Welpenpreis', 'custom-puppy-form' ),
			array( $this, 'render_puppy_price_field' ),
			'puppy-form-settings-page',
			'puppy_app_general_section'
		);

		add_settings_field(
			'puppy_app_privacy_status',
			__( 'Status & Freischaltung', 'custom-puppy-form' ),
			array( $this, 'render_privacy_status_field' ),
			'puppy-form-settings-page',
			'puppy_app_general_section'
		);

		add_settings_field(
			'puppy_app_delete_data_on_uninstall',
			__( 'Daten bei Deinstallation löschen', 'custom-puppy-form' ),
			array( $this, 'render_delete_data_on_uninstall_field' ),
			'puppy-form-settings-page',
			'puppy_app_general_section'
		);

		// Section B: Field Customization (Beschriftungen & Optionen).
		add_settings_section(
			'puppy_app_fields_section',
			__( 'Bewerbungsfelder konfigurieren', 'custom-puppy-form' ),
			array( $this, 'render_fields_section_desc' ),
			'puppy-form-settings-page'
		);

		add_settings_field(
			'puppy_app_fields_status_table',
			__( 'Formularfelder aktivieren / Pflichtfelder festlegen', 'custom-puppy-form' ),
			array( $this, 'render_fields_status_table' ),
			'puppy-form-settings-page',
			'puppy_app_fields_section'
		);

		add_settings_field(
			'puppy_field_label_name',
			__( 'Feld: Name Label', 'custom-puppy-form' ),
			array( $this, 'render_label_name_field' ),
			'puppy-form-settings-page',
			'puppy_app_fields_section'
		);

		add_settings_field(
			'puppy_field_label_email',
			__( 'Feld: E-Mail Label', 'custom-puppy-form' ),
			array( $this, 'render_label_email_field' ),
			'puppy-form-settings-page',
			'puppy_app_fields_section'
		);

		add_settings_field(
			'puppy_field_label_phone',
			__( 'Feld: Telefon Label', 'custom-puppy-form' ),
			array( $this, 'render_label_phone_field' ),
			'puppy-form-settings-page',
			'puppy_app_fields_section'
		);

		add_settings_field(
			'puppy_field_label_living',
			__( 'Feld: Wohnsituation Label', 'custom-puppy-form' ),
			array( $this, 'render_label_living_field' ),
			'puppy-form-settings-page',
			'puppy_app_fields_section'
		);

		add_settings_field(
			'puppy_field_options_living',
			__( 'Feld: Wohnsituation Dropdown-Optionen', 'custom-puppy-form' ),
			array( $this, 'render_options_living_field' ),
			'puppy-form-settings-page',
			'puppy_app_fields_section'
		);

		add_settings_field(
			'puppy_field_label_working',
			__( 'Feld: Arbeitszeiten Label', 'custom-puppy-form' ),
			array( $this, 'render_label_working_field' ),
			'puppy-form-settings-page',
			'puppy_app_fields_section'
		);

		add_settings_field(
			'puppy_field_options_working',
			__( 'Feld: Arbeitszeiten Dropdown-Optionen', 'custom-puppy-form' ),
			array( $this, 'render_options_working_field' ),
			'puppy-form-settings-page',
			'puppy_app_fields_section'
		);

		add_settings_field(
			'puppy_field_label_experience',
			__( 'Feld: Hundeerfahrung Label', 'custom-puppy-form' ),
			array( $this, 'render_label_experience_field' ),
			'puppy-form-settings-page',
			'puppy_app_fields_section'
		);

		add_settings_field(
			'puppy_field_label_privacy',
			__( 'Feld: Datenschutzerklärung Einwilligungstext', 'custom-puppy-form' ),
			array( $this, 'render_label_privacy_field' ),
			'puppy-form-settings-page',
			'puppy_app_fields_section'
		);

		// ==========================================
		// TAB 3 SECTIONS & FIELDS: AUTO-EMAIL
		// ==========================================

		add_settings_section(
			'puppy_app_email_section',
			__( 'Automatische E-Mail-Antwort', 'custom-puppy-form' ),
			array( $this, 'render_email_section_desc' ),
			'puppy-email-settings-page'
		);

		add_settings_field(
			'puppy_app_custom_email_body',
			__( 'E-Mail-Vorlage für Bewerber', 'custom-puppy-form' ),
			array( $this, 'render_custom_email_body_field' ),
			'puppy-email-settings-page',
			'puppy_app_email_section'
		);
	}

	/**
	 * Sanitizes all settings inside the consolidated settings array.
	 *
	 * @param array $input Unsanitized options from POST.
	 * @return array Sanitized merged options.
	 */
	public function sanitize_all_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		// CHANGE 1: Basis-Optionen laden, um Tab-Wechsel-Datenverluste zu vermeiden
		$existing  = get_option( 'puppy_form_settings', array() );
		$sanitized = is_array( $existing ) ? $existing : array();

		// Sanitize Email.
		if ( isset( $input['receiver_email'] ) ) {
			$email = sanitize_email( $input['receiver_email'] );
			if ( is_email( $email ) ) {
				$sanitized['receiver_email'] = $email;
			} else {
				add_settings_error(
					'puppy_form_settings',
					'invalid_receiver_email',
					__( 'Bitte geben Sie eine gültige E-Mail-Adresse als Empfänger an.', 'custom-puppy-form' ),
					'error'
				);
				$sanitized['receiver_email'] = self::get_setting( 'receiver_email' );
			}
		}

		// Sanitize years whitelist (CHANGE 3).
		if ( isset( $input['puppy_years_whitelist'] ) ) {
			$lines            = explode( "\n", $input['puppy_years_whitelist'] );
			$sanitized_years  = array();
			foreach ( $lines as $line ) {
				$trimmed = trim( $line );
				if ( preg_match( '/^\d{4}$/', $trimmed ) ) {
					$sanitized_years[] = sanitize_text_field( $trimmed );
				}
			}
			$sanitized['puppy_years_whitelist'] = implode( "\n", $sanitized_years );
		}

		if ( isset( $input['puppy_litters_whitelist'] ) ) {
			$lines             = preg_split( '/\r\n|\r|\n/', $input['puppy_litters_whitelist'] );
			$sanitized_litters = array();
			$seen              = array();

			foreach ( $lines as $line ) {
				$trimmed = sanitize_text_field( trim( $line ) );
				if ( '' === $trimmed ) {
					continue;
				}

				if ( function_exists( 'mb_substr' ) ) {
					$trimmed = mb_substr( $trimmed, 0, 120, 'UTF-8' );
				} else {
					$trimmed = substr( $trimmed, 0, 120 );
				}

				$lookup_key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $trimmed, 'UTF-8' ) : strtolower( $trimmed );
				if ( isset( $seen[ $lookup_key ] ) ) {
					continue;
				}

				$seen[ $lookup_key ]   = true;
				$sanitized_litters[] = $trimmed;
			}

			$sanitized['puppy_litters_whitelist'] = implode( "\n", $sanitized_litters );
		}

		if ( isset( $input['puppy_litters_intro'] ) ) {
			$intro = sanitize_textarea_field( $input['puppy_litters_intro'] );
			if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
				if ( mb_strlen( $intro, 'UTF-8' ) > PUPPY_FORM_FREETEXT_MAX_LENGTH ) {
					$intro = mb_substr( $intro, 0, PUPPY_FORM_FREETEXT_MAX_LENGTH, 'UTF-8' );
				}
			} elseif ( strlen( $intro ) > PUPPY_FORM_FREETEXT_MAX_LENGTH ) {
				$intro = substr( $intro, 0, PUPPY_FORM_FREETEXT_MAX_LENGTH );
			}
			$sanitized['puppy_litters_intro'] = $intro;
		}

		// Sanitize field active / required checkboxes.
		if ( isset( $input['puppy_price'] ) ) {
			$sanitized['delete_data_on_uninstall'] = isset( $input['delete_data_on_uninstall'] ) && '1' === $input['delete_data_on_uninstall'] ? '1' : '0';
			
			$fields_keys = array(
				'year', 'litter', 'traits', 'purpose', 'applicant_name', 'applicant_age',
				'applicant_email', 'applicant_phone', 'applicant_address',
				'family_situation', 'living_situation', 'work_situation',
				'dog_experience', 'selection_wish', 'other_pets'
			);
			foreach ( $fields_keys as $key ) {
				$sanitized['field_active_' . $key] = isset( $input['field_active_' . $key] ) && '1' === $input['field_active_' . $key] ? '1' : '0';
				$sanitized['field_required_' . $key] = isset( $input['field_required_' . $key] ) && '1' === $input['field_required_' . $key] ? '1' : '0';
			}
		}

		// Sanitize privacy activation key if present in input (to preserve direct database updates).
		if ( isset( $input['privacy_activated'] ) ) {
			$sanitized['privacy_activated'] = '1' === $input['privacy_activated'] || 1 === $input['privacy_activated'] ? '1' : '0';
		}

		// Sanitize standard text fields.
		$text_fields = array(
			'puppy_price',
			'field_label_name',
			'field_label_email',
			'field_label_phone',
			'field_label_living',
			'field_options_living',
			'field_label_working',
			'field_options_working',
			'field_label_experience',
			'field_label_privacy',
		);

		foreach ( $text_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( $input[ $field ] );
			}
		}

		// Sanitize Textarea template.
		if ( isset( $input['custom_email_body'] ) ) {
			$sanitized['custom_email_body'] = sanitize_textarea_field( $input['custom_email_body'] );
		}

		return $sanitized;
	}

	// ==========================================
	// SECTION & FIELD RENDERERS
	// ==========================================

	public function render_general_section_desc() {
		echo '<p>' . esc_html__( 'Allgemeine Konfigurationsdaten für das Bewerbungssystem.', 'custom-puppy-form' ) . '</p>';
	}

	public function render_fields_section_desc() {
		echo '<p>' . esc_html__( 'Bestimmen Sie hier die Beschriftungen und Auswahlwerte, die im Bewerbungsformular ausgegeben werden.', 'custom-puppy-form' ) . '</p>';
	}

	public function render_email_section_desc() {
		echo '<p>' . esc_html__( 'Verfassen Sie hier den Text der Bestätigungs-E-Mail, die der Bewerber unmittelbar nach Absenden des Formulars erhält.', 'custom-puppy-form' ) . '</p>';
	}

	public function render_receiver_email_field() {
		$val = self::get_setting( 'receiver_email' );
		echo '<input type="email" name="puppy_form_settings[receiver_email]" id="puppy_app_receiver_email" value="' . esc_attr( $val ) . '" class="regular-text" required />';
	}

	public function render_years_whitelist_field() {
		$val = self::get_setting( 'puppy_years_whitelist' );
		echo '<textarea name="puppy_form_settings[puppy_years_whitelist]" id="puppy_years_whitelist" class="large-text" rows="4">' . esc_textarea( $val ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Tragen Sie ein Jahr pro Zeile ein (z. B. 2026). Nur 4-stellige Zahlen sind gültig.', 'custom-puppy-form' ) . '</p>';
	}

	public function render_litters_whitelist_field() {
		$val = self::get_setting( 'puppy_litters_whitelist' );
		echo '<textarea name="puppy_form_settings[puppy_litters_whitelist]" id="puppy_litters_whitelist" class="large-text" rows="5">' . esc_textarea( $val ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Ein Eintrag pro Zeile, z. B. der Name der Hündin. Diese Einträge erscheinen im Formular als Mehrfachauswahl. Ohne Einträge wird das Feld im Frontend nicht angezeigt.', 'custom-puppy-form' ) . '</p>';
	}

	public function render_litters_intro_field() {
		$val = self::get_setting( 'puppy_litters_intro' );
		echo '<textarea name="puppy_form_settings[puppy_litters_intro]" id="puppy_litters_intro" class="large-text" rows="4">' . esc_textarea( $val ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Optionaler Text, der oberhalb der Wurf-Auswahl angezeigt wird. Leer lassen, wenn kein Hinweis erscheinen soll.', 'custom-puppy-form' ) . '</p>';
	}

	public function render_puppy_price_field() {
		$val = self::get_setting( 'puppy_price' );
		echo '<input type="text" name="puppy_form_settings[puppy_price]" id="puppy_app_puppy_price" value="' . esc_attr( $val ) . '" class="regular-text" required />';
	}

	public function render_delete_data_on_uninstall_field() {
		$val = self::get_setting( 'delete_data_on_uninstall' );
		echo '<input type="checkbox" name="puppy_form_settings[delete_data_on_uninstall]" id="puppy_app_delete_data_on_uninstall" value="1" ' . checked( '1', $val, false ) . ' />';
		echo '<p class="description" style="color: #b32d2e;"><strong>' . esc_html__( 'Achtung:', 'custom-puppy-form' ) . '</strong> ' . esc_html__( 'Wenn Sie diese Option aktivieren, werden bei der Deinstallation alle Daten des Formulars (Bewerbungen und Konfigurationen) unwiderruflich aus der Datenbank gelöscht. Ohne Aktivierung bleiben die Daten erhalten.', 'custom-puppy-form' ) . '</p>';
	}

	/**
	 * Renders privacy activation status and deactivation buttons inside settings page.
	 */
	public function render_privacy_status_field() {
		$is_activated = self::get_setting( 'privacy_activated' );
		
		if ( '1' === $is_activated ) {
			echo '<div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">';
			echo '<span class="puppy-privacy-badge">✓ ' . esc_html__( 'Plugin ist aktiv und freigeschaltet', 'custom-puppy-form' ) . '</span>';
			
			$revoke_nonce = wp_create_nonce( 'puppy_revoke_privacy_action' );
			$revoke_url   = admin_url( 'admin.php?page=puppy-application-form&action=revoke_privacy&_wpnonce=' . $revoke_nonce );
			
			echo '<a href="' . esc_url( $revoke_url ) . '" class="puppy-delete-action" style="visibility: visible !important;" onclick="return confirm(\'' . esc_js( __( 'Möchten Sie die Freischaltung wirklich widerrufen? Das Frontend-Formular wird dadurch sofort gesperrt. Bitte denken Sie daran, erhobene Bewerberdaten manuell aus der Datenbank oder dem Bewerbungen-Tab zu löschen, um die DSGVO-Konformität zu wahren!', 'custom-puppy-form' ) ) . '\');">' . esc_html__( 'Freischaltung widerrufen', 'custom-puppy-form' ) . '</a>';
			echo '</div>';
		} else {
			echo '<span class="puppy-privacy-badge" style="background: #fdf2f2; color: #8c2424; border-left: 3px solid #e74c3c;">✗ ' . esc_html__( 'Wartemodus: Freischaltung steht aus', 'custom-puppy-form' ) . '</span>';
		}
	}

	public function render_fields_status_table() {
		$fields = array(
			'year'              => __( 'Wunschjahr', 'custom-puppy-form' ),
			'litter'            => __( 'Interessierter Wurf (Mehrfachauswahl)', 'custom-puppy-form' ),
			'traits'            => __( 'Gewünschte Eigenschaften', 'custom-puppy-form' ),
			'purpose'           => __( 'Verwendungszweck (Checkboxen)', 'custom-puppy-form' ),
			'applicant_name'    => __( 'Name (Bewerber)', 'custom-puppy-form' ),
			'applicant_age'     => __( 'Alter (Ansprechpartner)', 'custom-puppy-form' ),
			'applicant_email'   => __( 'E-Mail-Adresse', 'custom-puppy-form' ),
			'applicant_phone'   => __( 'Handy / Telefonnummer', 'custom-puppy-form' ),
			'applicant_address' => __( 'Anschrift (Straße, PLZ, Ort)', 'custom-puppy-form' ),
			'family_situation'  => __( 'Familiensituation', 'custom-puppy-form' ),
			'living_situation'  => __( 'Wohnsituation / Größe', 'custom-puppy-form' ),
			'work_situation'    => __( 'Arbeitssituation', 'custom-puppy-form' ),
			'dog_experience'    => __( 'Vorerfahrungen', 'custom-puppy-form' ),
			'selection_wish'    => __( 'Welpenauswahl (Geschlecht)', 'custom-puppy-form' ),
			'other_pets'        => __( 'Tierische Mitbewohner', 'custom-puppy-form' ),
		);
		?>
		<table class="wp-list-table widefat fixed striped puppy-fields-status-table" style="max-width: 600px; margin-top: 10px;">
			<thead>
				<tr>
					<th scope="col" style="padding: 10px; font-weight: 600;"><?php esc_html_e( 'Feldname', 'custom-puppy-form' ); ?></th>
					<th scope="col" style="padding: 10px; font-weight: 600; text-align: center; width: 120px;"><?php esc_html_e( 'Aktivieren', 'custom-puppy-form' ); ?></th>
					<th scope="col" style="padding: 10px; font-weight: 600; text-align: center; width: 120px;"><?php esc_html_e( 'Pflichtfeld', 'custom-puppy-form' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fields as $key => $label ) : 
					$is_locked = ( 'applicant_name' === $key || 'applicant_email' === $key );
					
					$active = self::get_setting( 'field_active_' . $key );
					$required = self::get_setting( 'field_required_' . $key );
					?>
					<tr>
						<td style="padding: 10px; font-weight: 500; vertical-align: middle;"><?php echo esc_html( $label ); ?></td>
						<td style="padding: 10px; text-align: center; vertical-align: middle;">
							<?php if ( $is_locked ) : ?>
								<input type="checkbox" checked disabled />
								<input type="hidden" name="puppy_form_settings[field_active_<?php echo esc_attr( $key ); ?>]" value="1" />
							<?php else : ?>
								<input type="checkbox" name="puppy_form_settings[field_active_<?php echo esc_attr( $key ); ?>]" id="field_active_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( '1', $active ); ?> />
							<?php endif; ?>
						</td>
						<td style="padding: 10px; text-align: center; vertical-align: middle;">
							<?php if ( $is_locked ) : ?>
								<input type="checkbox" checked disabled />
								<input type="hidden" name="puppy_form_settings[field_required_<?php echo esc_attr( $key ); ?>]" value="1" />
							<?php elseif ( 'purpose' === $key ) : ?>
								<span style="color: #888888; font-size: 12px;">—</span>
							<?php else : ?>
								<input type="checkbox" name="puppy_form_settings[field_required_<?php echo esc_attr( $key ); ?>]" id="field_required_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( '1', $required ); ?> />
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description" style="margin-top: 8px;">
			<?php esc_html_e( 'Hinweis: Name und E-Mail-Adresse sind zwingend erforderlich und können nicht deaktiviert werden.', 'custom-puppy-form' ); ?>
		</p>
		<?php
	}

	public function render_label_name_field() {
		$val = self::get_setting( 'field_label_name' );
		echo '<input type="text" name="puppy_form_settings[field_label_name]" id="puppy_field_label_name" value="' . esc_attr( $val ) . '" class="regular-text" required />';
	}

	public function render_label_email_field() {
		$val = self::get_setting( 'field_label_email' );
		echo '<input type="text" name="puppy_form_settings[field_label_email]" id="puppy_field_label_email" value="' . esc_attr( $val ) . '" class="regular-text" required />';
	}

	public function render_label_phone_field() {
		$val = self::get_setting( 'field_label_phone' );
		echo '<input type="text" name="puppy_form_settings[field_label_phone]" id="puppy_field_label_phone" value="' . esc_attr( $val ) . '" class="regular-text" required />';
	}

	public function render_label_living_field() {
		$val = self::get_setting( 'field_label_living' );
		echo '<input type="text" name="puppy_form_settings[field_label_living]" id="puppy_field_label_living" value="' . esc_attr( $val ) . '" class="regular-text" required />';
	}

	public function render_options_living_field() {
		$val = self::get_setting( 'field_options_living' );
		echo '<input type="text" name="puppy_form_settings[field_options_living]" id="puppy_field_options_living" value="' . esc_attr( $val ) . '" class="large-text" required />';
		echo '<p class="description">' . esc_html__( 'Trennen Sie die einzelnen Optionen mit einem Komma (z.B. "Mietwohnung, Eigentumswohnung, Haus mit Garten").', 'custom-puppy-form' ) . '</p>';
	}

	public function render_label_working_field() {
		$val = self::get_setting( 'field_label_working' );
		echo '<input type="text" name="puppy_form_settings[field_label_working]" id="puppy_field_label_working" value="' . esc_attr( $val ) . '" class="regular-text" required />';
	}

	public function render_options_working_field() {
		$val = self::get_setting( 'field_options_working' );
		echo '<input type="text" name="puppy_form_settings[field_options_working]" id="puppy_field_options_working" value="' . esc_attr( $val ) . '" class="large-text" required />';
		echo '<p class="description">' . esc_html__( 'Trennen Sie die einzelnen Optionen mit einem Komma (z.B. "Weniger als 2 Stunden, 2 bis 5 Stunden, Mehr als 5 Stunden").', 'custom-puppy-form' ) . '</p>';
	}

	public function render_label_experience_field() {
		$val = self::get_setting( 'field_label_experience' );
		echo '<input type="text" name="puppy_form_settings[field_label_experience]" id="puppy_field_label_experience" value="' . esc_attr( $val ) . '" class="regular-text" required />';
	}

	public function render_label_privacy_field() {
		$val = self::get_setting( 'field_label_privacy' );
		echo '<textarea name="puppy_form_settings[field_label_privacy]" id="puppy_field_label_privacy" rows="2" class="large-text" required>' . esc_textarea( $val ) . '</textarea>';
	}

	public function render_custom_email_body_field() {
		$val = self::get_setting( 'custom_email_body' );
		echo '<textarea name="puppy_form_settings[custom_email_body]" id="puppy_app_custom_email_body" rows="12" class="large-text" required>' . esc_textarea( $val ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Verfügbare Platzhalter: {name} (Vollständiger Name des Bewerbers) und {price} (Welpenpreis).', 'custom-puppy-form' ) . '</p>';
	}

	/**
	 * Shortcode Instruction and GDPR privacy policy template panel.
	 */
	private function render_shortcode_info_box() {
		?>
		<div class="puppy-shortcode-info-box">
			<h3><?php esc_html_e( 'Einbindung im Frontend', 'custom-puppy-form' ); ?></h3>
			<p>
				<?php esc_html_e( 'Das Bewerbungsformular kann über den folgenden Shortcode auf jeder Seite, in jedem Beitrag oder Text-Widget eingebunden werden:', 'custom-puppy-form' ); ?>
			</p>
			<code>[puppy_application_form]</code>
			<p class="note">
				<?php esc_html_e( 'Hinweis: Das Formular passt sich automatisch der Breite des übergeordneten Elements an und ist mobil-optimiert.', 'custom-puppy-form' ); ?>
			</p>

			<hr style="margin: 25px 0; border: 0; border-top: 1px solid #ccd0d4;" />

			<div class="puppy-privacy-template-container">
				<h3><?php esc_html_e( 'Mustertext für Ihre Datenschutzerklärung (DSGVO / TDDDG)', 'custom-puppy-form' ); ?></h3>
				<p>
					<?php esc_html_e( 'Nach deutschem und europäischem Recht sind Sie verpflichtet, Ihre Besucher über den Umfang und die Rechtsgrundlagen der erhobenen Bewerbungsdaten aufzuklären. Sie können den folgenden Textbaustein kopieren und in die Datenschutzerklärung Ihrer Website integrieren:', 'custom-puppy-form' ); ?>
				</p>
				
				<div class="puppy-privacy-template-box">
					<h4><?php esc_html_e( '1. Verarbeitung personenbezogener Daten über das Welpen-Bewerbungsformular', 'custom-puppy-form' ); ?></h4>
					<p><?php esc_html_e( 'Auf unserer Webseite bieten wir Ihnen die Möglichkeit, sich über ein Online-Formular für einen Welpen zu bewerben. Dabei werden personenbezogene Daten erfasst, die Sie in die Eingabemaske eintragen. Dies umfasst folgende Daten:', 'custom-puppy-form' ); ?></p>
					<ul>
						<li><?php esc_html_e( 'Name des Bewerbers', 'custom-puppy-form' ); ?></li>
						<li><?php esc_html_e( 'E-Mail-Adresse', 'custom-puppy-form' ); ?></li>
						<li><?php esc_html_e( 'Telefonnummer', 'custom-puppy-form' ); ?></li>
						<li><?php esc_html_e( 'Angaben zur Wohnsituation (Mietwohnung, Eigentumswohnung, Haus mit Garten etc.)', 'custom-puppy-form' ); ?></li>
						<li><?php esc_html_e( 'Angaben zu Arbeitszeiten / Zeit, in der das Tier alleine wäre', 'custom-puppy-form' ); ?></li>
						<li><?php esc_html_e( 'Angaben zur bisherigen Hundeerfahrung', 'custom-puppy-form' ); ?></li>
					</ul>

					<h4><?php esc_html_e( '2. Zweck der Datenverarbeitung', 'custom-puppy-form' ); ?></h4>
					<p><?php esc_html_e( 'Die Verarbeitung dieser Daten dient dem Zweck der Kontaktaufnahme, der Prüfung der Eignung als künftiger Hundehalter sowie der Anbahnung und Vorbereitung eines potenziellen Kauf- bzw. Vermittlungsvertrags für einen Welpen.', 'custom-puppy-form' ); ?></p>

					<h4><?php esc_html_e( '3. Rechtsgrundlage der Verarbeitung', 'custom-puppy-form' ); ?></h4>
					<p><?php esc_html_e( 'Die Datenverarbeitung erfolgt auf Basis von:', 'custom-puppy-form' ); ?></p>
					<ul>
						<li><strong><?php esc_html_e( 'Art. 6 Abs. 1 lit. b DSGVO', 'custom-puppy-form' ); ?></strong><?php esc_html_e( ': Die Verarbeitung ist für die Durchführung vorvertraglicher Maßnahmen erforderlich, die auf Anfrage der betroffenen Person (Einreichung der Bewerbung) erfolgen.', 'custom-puppy-form' ); ?></li>
						<li><strong><?php esc_html_e( 'Art. 6 Abs. 1 lit. a DSGVO', 'custom-puppy-form' ); ?></strong><?php esc_html_e( ': Sofern Sie das Haken-Kontrollkästchen im Formular aktiv ankreuzen, willigen Sie hiermit explizit in die Verarbeitung Ihrer Daten ein. Diese Einwilligung ist jederzeit mit Wirkung für die Zukunft frei widerrufbar.', 'custom-puppy-form' ); ?></li>
					</ul>

					<h4><?php esc_html_e( '4. Speicherdauer', 'custom-puppy-form' ); ?></h4>
					<p><?php esc_html_e( 'Die Daten werden gelöscht, sobald sie für die Erreichung des Zweckes ihrer Erhebung nicht mehr erforderlich sind. Dies ist nach Abschluss des Vermittlungsverfahrens (Kauf oder Absage der Bewerbung) oder bei Widerruf Ihrer Einwilligung der Fall, sofern keine gesetzlichen Aufbewahrungsfristen (z. B. steuer- oder handelsrechtliche Fristen bei Vertragsabschluss) eine längere Speicherung vorschreiben.', 'custom-puppy-form' ); ?></p>
				</div>

				<div class="puppy-legal-disclaimer-box">
					<strong><?php esc_html_e( 'Wichtiger Rechtlicher Hinweis (Disclaimer):', 'custom-puppy-form' ); ?></strong>
					<p style="margin: 5px 0 0 0; font-style: italic;">
						<?php esc_html_e( 'Dieser Mustertext stellt lediglich eine unverbindliche Vorlage dar. Er bildet keine rechtliche Beratung ab und ersetzt diese nicht. Es wird keine Gewähr oder Haftung für die Richtigkeit, Vollständigkeit oder Aktualität des Textes übernommen. Es wird dringend empfohlen, die gesamte Datenschutzerklärung der Webseite (z. B. mithilfe eines anerkannten Datenschutz-Generators) zu überprüfen oder bei Unklarheiten einen Fachanwalt für IT-Recht zu konsultieren.', 'custom-puppy-form' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Securely handles GDPR application entries deletion.
	 */
	public function handle_delete_application() {
		if ( ! isset( $_GET['page'] ) || 'puppy-application-form' !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['action'] ) || 'delete_application' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sie haben keine Berechtigung, diese Aktion auszuführen.', 'custom-puppy-form' ) );
		}

		$app_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
		if ( ! $app_id ) {
			return;
		}

		// Verify deletion nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'puppy_delete_application_' . $app_id ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'custom-puppy-form' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'puppy_applications';
		$deleted    = $wpdb->delete( $table_name, array( 'id' => $app_id ), array( '%d' ) );

		if ( false !== $deleted ) {
			// Redirect cleanly back to the applicants tab.
			wp_safe_redirect( admin_url( 'admin.php?page=puppy-application-form&tab=applications&deleted=1' ) );
			exit;
		}
	}

	/**
	 * Securely handles active GDPR Privacy Activations via Dashboard Notices.
	 */
	public function handle_privacy_activation() {
		if ( ! isset( $_POST['puppy_privacy_nonce_field'] ) || ! wp_verify_nonce( $_POST['puppy_privacy_nonce_field'], 'puppy_activate_privacy' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sie haben keine Berechtigung, diese Aktion auszuführen.', 'custom-puppy-form' ) );
		}

		if ( isset( $_POST['puppy_activate_privacy_confirm'] ) && '1' === $_POST['puppy_activate_privacy_confirm'] ) {
			$settings = get_option( 'puppy_form_settings', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			$settings['privacy_activated'] = '1';
			update_option( 'puppy_form_settings', $settings );

			// Redirect back cleanly to the settings page.
			$referer = wp_get_referer();
			if ( ! $referer ) {
				$referer = admin_url( 'admin.php?page=puppy-application-form' );
			}
			wp_safe_redirect( add_query_arg( 'puppy_privacy_activated', '1', $referer ) );
			exit;
		}
	}

	/**
	 * Securely handles revoking GDPR Privacy Activations.
	 */
	public function handle_privacy_revocation() {
		if ( ! isset( $_GET['page'] ) || 'puppy-application-form' !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['action'] ) || 'revoke_privacy' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sie haben keine Berechtigung, diese Aktion auszuführen.', 'custom-puppy-form' ) );
		}

		// Verify revocation nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'puppy_revoke_privacy_action' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'custom-puppy-form' ) );
		}

		$settings = get_option( 'puppy_form_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['privacy_activated'] = '0';
		update_option( 'puppy_form_settings', $settings );

		// Redirect cleanly back to settings page.
		wp_safe_redirect( admin_url( 'admin.php?page=puppy-application-form&tab=form_settings&puppy_privacy_revoked=1' ) );
		exit;
	}

	/**
	 * Run DB migration check on admin init to update the database table.
	 */
	public function maybe_upgrade_database() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'puppy_applications';
		$db_version = get_option( 'puppy_form_db_version', '1.0.0' );

		// Erste Migrationsstufe (1.1.0)
		if ( version_compare( $db_version, '1.1.0', '<' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-puppy-form-bootstrap.php';
			Puppy_Form_Bootstrap::activate_plugin();
			update_option( 'puppy_form_db_version', '1.1.0' );
			$db_version = '1.1.0';
		}

		// Zweite Migrationsstufe (1.2.0) - Aufsplittung von applicant_name_age
		// Die Datenbank-Spalten werden durch das option-Guard-Upgrade nur ein einziges Mal angepasst.
		if ( version_compare( $db_version, '1.2.0', '<' ) ) {
			// Überprüfen, ob die neuen Spalten bereits existieren
			$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'applicant_name'" );
			if ( empty( $column_exists ) ) {
				// Neue Spalten hinzufügen
				$wpdb->query( "ALTER TABLE $table_name ADD COLUMN applicant_name varchar(255) NOT NULL AFTER purpose_therapy, ADD COLUMN applicant_age varchar(20) NOT NULL AFTER applicant_name" );

				// Vorhandene applicant_name_age Daten aufteilen und in die neuen Spalten kopieren
				// Wir trennen am ersten Komma. Falls kein Komma existiert, bleibt das Alter leer.
				$wpdb->query( "UPDATE $table_name SET 
					applicant_name = TRIM(SUBSTRING_INDEX(applicant_name_age, ',', 1)), 
					applicant_age = IF(LOCATE(',', applicant_name_age) > 0, TRIM(SUBSTRING_INDEX(applicant_name_age, ',', -1)), '') 
					WHERE applicant_name_age IS NOT NULL AND applicant_name_age != ''" );
			}
			update_option( 'puppy_form_db_version', '1.2.0' );
		}

		// Dritte Migrationsstufe (1.3.0) - Anlegen der internen Diagnose-Log-Tabelle.
		if ( version_compare( $db_version, '1.3.0', '<' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-puppy-form-bootstrap.php';
			Puppy_Form_Bootstrap::create_log_table();
			update_option( 'puppy_form_db_version', '1.3.0' );
			$db_version = '1.3.0';
		}

		// Vierte Migrationsstufe (1.4.0) - varchar-Felder für lange Freitexte auf TEXT erweitern.
		if ( version_compare( $db_version, '1.4.0', '<' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-puppy-form-bootstrap.php';

			// dbDelta alone often fails to change column types; explicit ALTER is required.
			$text_columns = array( 'traits', 'applicant_address', 'selection_wish', 'other_pets' );
			foreach ( $text_columns as $column ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column names are hardcoded.
				$wpdb->query( "ALTER TABLE $table_name MODIFY COLUMN `$column` TEXT NOT NULL" );
			}

			Puppy_Form_Bootstrap::upgrade_applications_table();
			update_option( 'puppy_form_db_version', '1.4.0' );
			$db_version = '1.4.0';
		}

		// Fünfte Migrationsstufe (1.4.1) - Reparatur, falls 1.4.0 dbDelta die Spaltentypen nicht geändert hat.
		if ( version_compare( $db_version, '1.4.1', '<' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-puppy-form-bootstrap.php';

			$text_columns = array( 'traits', 'applicant_address', 'selection_wish', 'other_pets' );
			foreach ( $text_columns as $column ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column names are hardcoded.
				$column_info = $wpdb->get_row( "SHOW COLUMNS FROM $table_name LIKE '$column'" );
				if ( $column_info && false !== stripos( $column_info->Type, 'varchar' ) ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column names are hardcoded.
					$wpdb->query( "ALTER TABLE $table_name MODIFY COLUMN `$column` TEXT NOT NULL" );
				}
			}

			Puppy_Form_Bootstrap::upgrade_applications_table();
			update_option( 'puppy_form_db_version', '1.4.1' );
		}

		// Sechste Migrationsstufe (1.5.0) - Spalte für die Wurf-Mehrfachauswahl.
		if ( version_compare( $db_version, '1.5.0', '<' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-puppy-form-bootstrap.php';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefixed and hardcoded.
			$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'litter_choice'" );
			if ( empty( $column_exists ) ) {
				// NULL first so existing rows do not fail under strict SQL mode.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefixed and hardcoded.
				$wpdb->query( "ALTER TABLE $table_name ADD COLUMN litter_choice TEXT NULL AFTER target_year" );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefixed and hardcoded.
				$wpdb->query( "UPDATE $table_name SET litter_choice = '' WHERE litter_choice IS NULL" );
			}

			Puppy_Form_Bootstrap::upgrade_applications_table();
			update_option( 'puppy_form_db_version', '1.5.0' );
		}
	}

	/**
	 * Writes a diagnostic event to the internal log table.
	 *
	 * No applicant personal data is stored here by design (GDPR data
	 * minimization) - only technical details needed for troubleshooting.
	 *
	 * @param string $event_type Short machine-readable event identifier.
	 * @param string $message    Human-readable diagnostic message (e.g. DB error).
	 */
	public static function log_event( $event_type, $message ) {
		global $wpdb;
		$log_table_name = $wpdb->prefix . 'puppy_form_logs';

		$client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$wpdb->insert(
			$log_table_name,
			array(
				'created_at' => current_time( 'mysql' ),
				'event_type' => sanitize_key( $event_type ),
				'message'    => sanitize_text_field( $message ),
				'ip_hash'    => $client_ip ? md5( $client_ip ) : '',
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Retrieves the most recent diagnostic log entries.
	 *
	 * @param int $limit Maximum number of entries to retrieve.
	 * @return array
	 */
	public static function get_recent_log_entries( $limit = 50 ) {
		global $wpdb;
		$log_table_name = $wpdb->prefix . 'puppy_form_logs';

		// Auto-prune entries older than 30 days to keep the table small (GDPR storage limitation).
		$wpdb->query( $wpdb->prepare( "DELETE FROM $log_table_name WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ) ) );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $log_table_name ORDER BY created_at DESC LIMIT %d", $limit ) );
	}

	/**
	 * Handles secure CSV export of all applications.
	 */
	public function handle_csv_export() {
		if ( isset( $_GET['page'] ) && 'puppy-application-form' === $_GET['page'] && isset( $_GET['action'] ) && 'export_csv' === $_GET['action'] ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sie haben keine Berechtigung, diese Aktion auszuführen.', 'custom-puppy-form' ) );
			}

			// Nonce validieren
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'puppy_export_csv_action' ) ) {
				wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'custom-puppy-form' ) );
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'puppy_applications';
			$applications = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY created_at DESC" );

			// CSV-Header setzen
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="welpen-bewerbungen-' . date( 'Y-m-d' ) . '.csv"' );

			// UTF-8 BOM für Excel hinzufügen
			echo "\xEF\xBB\xBF";

			$output = fopen( 'php://output', 'w' );

			// Spaltenüberschriften schreiben
			fputcsv( $output, array(
				'ID',
				'Name',
				'Alter',
				'E-Mail',
				'Telefon',
				'Anschrift',
				'Jahr',
				'Interessierter Wurf',
				'Gewünschte Eigenschaften',
				'Zweck',
				'Familiensituation',
				'Wohnsituation',
				'Arbeitssituation',
				'Hundeerfahrung',
				'Welpenauswahl',
				'Tierische Mitbewohner',
				'Futter-Zustimmung',
				'Datenschutz-Zustimmung',
				'Eingegangen am',
			), ',', '"', '\\' );

			if ( ! empty( $applications ) ) {
				foreach ( $applications as $app ) {
					// Zweck-Zusammenfassung als kommagetrennte Liste
					$purposes = array();
					if ( ! empty( $app->purpose_family ) ) {
						$purposes[] = 'Familienhund';
					}
					if ( ! empty( $app->purpose_sport ) ) {
						$purposes[] = 'Sport';
					}
					if ( ! empty( $app->purpose_therapy ) ) {
						$purposes[] = 'Therapie';
					}
					$zweck = implode( ', ', $purposes );

					// Spaltendaten aufbereiten mit Fallbacks für Altdaten
					$name = ! empty( $app->applicant_name ) ? $app->applicant_name : ( ! empty( $app->name ) ? $app->name : '' );
					if ( empty( $name ) && ! empty( $app->applicant_name_age ) ) {
						$name = trim( explode( ',', $app->applicant_name_age )[0] );
					}

					$age = ! empty( $app->applicant_age ) ? $app->applicant_age : '';
					if ( empty( $age ) && ! empty( $app->applicant_name_age ) && strpos( $app->applicant_name_age, ',' ) !== false ) {
						$age = trim( explode( ',', $app->applicant_name_age )[1] );
					}

					$email = ! empty( $app->applicant_email ) ? $app->applicant_email : ( ! empty( $app->email ) ? $app->email : '' );
					$phone = ! empty( $app->applicant_phone ) ? $app->applicant_phone : ( ! empty( $app->phone ) ? $app->phone : '' );

					fputcsv( $output, array(
						$app->id,
						$name,
						$age,
						$email,
						$phone,
						$app->applicant_address,
						$app->target_year,
						self::format_litter_choice_for_display( isset( $app->litter_choice ) ? $app->litter_choice : '' ),
						$app->traits,
						$zweck,
						$app->family_situation,
						! empty( $app->living_situation ) ? $app->living_situation : ( ! empty( $app->living ) ? $app->living : '' ),
						! empty( $app->work_situation ) ? $app->work_situation : ( ! empty( $app->working ) ? $app->working : '' ),
						! empty( $app->dog_experience ) ? $app->dog_experience : ( ! empty( $app->experience ) ? $app->experience : '' ),
						$app->selection_wish,
						$app->other_pets,
						! empty( $app->nutrition_agreement ) ? 'Ja' : 'Nein',
						! empty( $app->privacy_agreement ) ? 'Ja' : 'Nein',
						$app->created_at,
					), ',', '"', '\\' );
				}
			}

			fclose( $output );
			exit;
		}
	}

	/**
	 * Renders native dashboard notice warning until administrator confirms GDPR processing.
	 */
	public function render_privacy_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only show notice if not already activated.
		$is_activated = self::get_setting( 'privacy_activated' );
		if ( '1' === $is_activated ) {
			return;
		}

		?>
		<div class="notice notice-warning is-dismissible puppy-privacy-notice">
			<form method="post" action="">
				<?php wp_nonce_field( 'puppy_activate_privacy', 'puppy_privacy_nonce_field' ); ?>
				<p>
					<strong><?php esc_html_e( 'Welpen-Formular: Datenschutz-Freischaltung erforderlich', 'custom-puppy-form' ); ?></strong><br />
					<?php esc_html_e( 'Das Welpen-Bewerbungsformular verarbeitet sensible personenbezogene Daten (Name, E-Mail-Adresse, Telefonnummer, Wohnverhältnisse und Erfahrungen). Nach der Datenschutz-Grundverordnung (DSGVO) sind Sie als Betreiber verpflichtet, diese Daten rechtssicher und geschützt zu verarbeiten. Das Frontend-Formular bleibt deaktiviert, bis Sie diese Verantwortung aktiv bestätigt haben.', 'custom-puppy-form' ); ?>
				</p>
				<p>
					<label for="puppy_activate_privacy_confirm">
						<input type="checkbox" name="puppy_activate_privacy_confirm" id="puppy_activate_privacy_confirm" value="1" required />
						<?php esc_html_e( 'Ich bestätige, dass ich die datenschutzrechtliche Verantwortung für die Verarbeitung der Bewerberdaten übernehme und das Formular freischalten möchte.', 'custom-puppy-form' ); ?>
					</label>
				</p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Plugin freischalten', 'custom-puppy-form' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render applicants database listing table.
	 */
	private function render_applications_list() {
		global $wpdb;
		$table_name   = $wpdb->prefix . 'puppy_applications';

		// A) Sortierparameter auslesen und validieren
		$orderby_whitelist = array( 'created_at', 'applicant_name', 'applicant_email', 'target_year' );
		$orderby           = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'created_at';
		if ( ! in_array( $orderby, $orderby_whitelist, true ) ) {
			$orderby = 'created_at';
		}

		$order_whitelist = array( 'ASC', 'DESC' );
		$order           = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( $_GET['order'] ) ) : 'DESC';
		if ( ! in_array( $order, $order_whitelist, true ) ) {
			$order = 'DESC';
		}

		// Toggle-Helfer für die Generierung von Links und Indikatoren
		$get_sort_link = function( $col ) use ( $orderby, $order ) {
			$next_ord = 'DESC';
			if ( $orderby === $col ) {
				$next_ord = 'ASC' === $order ? 'DESC' : 'ASC';
			}
			return esc_url( add_query_arg( array(
				'orderby' => $col,
				'order'   => $next_ord,
			) ) );
		};

		$get_indicator = function( $col ) use ( $orderby, $order ) {
			if ( $orderby === $col ) {
				return 'ASC' === $order ? ' ▲' : ' ▼';
			}
			return '';
		};

		// Bewerbungen sortiert abfragen
		$applications = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY {$orderby} {$order}" );
		
		?>
		<div class="puppy-applications-list-container">
			<p><?php esc_html_e( 'Hier sehen Sie alle eingegangenen Welpen-Bewerbungen und Kontaktdaten.', 'custom-puppy-form' ); ?></p>
			
			<?php if ( ! empty( $applications ) ) : 
				$export_nonce = wp_create_nonce( 'puppy_export_csv_action' );
				$export_url   = admin_url( 'admin.php?page=puppy-application-form&action=export_csv&_wpnonce=' . $export_nonce );
				?>
				<div style="margin-bottom: 15px;">
					<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
						<?php esc_html_e( 'CSV exportieren', 'custom-puppy-form' ); ?>
					</a>
				</div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped posts" style="margin-top: 15px;">
				<thead>
					<tr>
						<th scope="col" class="manage-column puppy-col-header puppy-col-name">
							<a href="<?php echo $get_sort_link( 'applicant_name' ); ?>">
								<?php esc_html_e( 'Name / Alter', 'custom-puppy-form' ); ?><?php echo esc_html( $get_indicator( 'applicant_name' ) ); ?>
							</a>
						</th>
						<th scope="col" class="manage-column puppy-col-header puppy-col-email">
							<a href="<?php echo $get_sort_link( 'applicant_email' ); ?>">
								<?php esc_html_e( 'E-Mail', 'custom-puppy-form' ); ?><?php echo esc_html( $get_indicator( 'applicant_email' ) ); ?>
							</a>
						</th>
						<th scope="col" class="manage-column puppy-col-header puppy-col-phone"><?php esc_html_e( 'Telefon', 'custom-puppy-form' ); ?></th>
						<th scope="col" class="manage-column puppy-col-header puppy-col-year">
							<a href="<?php echo $get_sort_link( 'target_year' ); ?>">
								<?php esc_html_e( 'Jahr', 'custom-puppy-form' ); ?><?php echo esc_html( $get_indicator( 'target_year' ) ); ?>
							</a>
						</th>
						<th scope="col" class="manage-column puppy-col-header puppy-col-details"><?php esc_html_e( 'Bewerbungs-Details', 'custom-puppy-form' ); ?></th>
						<th scope="col" class="manage-column puppy-col-header puppy-col-privacy"><?php esc_html_e( 'Zustimmungen', 'custom-puppy-form' ); ?></th>
						<th scope="col" class="manage-column puppy-col-header puppy-col-date">
							<a href="<?php echo $get_sort_link( 'created_at' ); ?>">
								<?php esc_html_e( 'Datum', 'custom-puppy-form' ); ?><?php echo esc_html( $get_indicator( 'created_at' ) ); ?>
							</a>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( ! empty( $applications ) ) {
						foreach ( $applications as $app ) {
							$delete_nonce = wp_create_nonce( 'puppy_delete_application_' . $app->id );
							$delete_url   = admin_url( 'admin.php?page=puppy-application-form&action=delete_application&id=' . $app->id . '&_wpnonce=' . $delete_nonce );

							// Backward compatibility fallbacks for existing database entries
							$name       = ! empty( $app->applicant_name ) ? $app->applicant_name : ( ! empty( $app->applicant_name_age ) ? trim( explode( ',', $app->applicant_name_age )[0] ) : ( ! empty( $app->name ) ? $app->name : '' ) );
							$age        = ! empty( $app->applicant_age ) ? $app->applicant_age : ( ! empty( $app->applicant_name_age ) && strpos( $app->applicant_name_age, ',' ) !== false ? trim( explode( ',', $app->applicant_name_age )[1] ) : '' );
							$email      = ! empty( $app->applicant_email ) ? $app->applicant_email : ( ! empty( $app->email ) ? $app->email : '' );
							$phone      = ! empty( $app->applicant_phone ) ? $app->applicant_phone : ( ! empty( $app->phone ) ? $app->phone : '' );
							$year       = ! empty( $app->target_year ) ? $app->target_year : '';
							$litter     = ! empty( $app->litter_choice ) ? self::format_litter_choice_for_display( $app->litter_choice ) : '';
							$traits     = ! empty( $app->traits ) ? $app->traits : '';
							$address    = ! empty( $app->applicant_address ) ? $app->applicant_address : '';
							$family     = ! empty( $app->family_situation ) ? $app->family_situation : '';
							$living     = ! empty( $app->living_situation ) ? $app->living_situation : ( ! empty( $app->living ) ? $app->living : '' );
							$working    = ! empty( $app->work_situation ) ? $app->work_situation : ( ! empty( $app->working ) ? $app->working : '' );
							$experience = ! empty( $app->dog_experience ) ? $app->dog_experience : ( ! empty( $app->experience ) ? $app->experience : '' );
							$wish       = ! empty( $app->selection_wish ) ? $app->selection_wish : '';
							$pets       = ! empty( $app->other_pets ) ? $app->other_pets : '';
							?>
							<tr>
								<td data-col-name="<?php esc_attr_e( 'Name / Alter', 'custom-puppy-form' ); ?>" class="has-row-actions">
									<strong><?php echo esc_html( $name ); ?></strong>
									<div class="puppy-row-actions">
										<span class="delete">
											<a href="<?php echo esc_url( $delete_url ); ?>" class="puppy-delete-action" onclick="return confirm('<?php echo esc_js( __( 'Möchten Sie diese Bewerbung wirklich unwiderruflich und DSGVO-konform löschen?', 'custom-puppy-form' ) ); ?>');">
												<?php esc_html_e( 'Löschen', 'custom-puppy-form' ); ?>
											</a>
										</span>
									</div>
								</td>
								<td data-col-name="<?php esc_attr_e( 'E-Mail', 'custom-puppy-form' ); ?>"><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></td>
								<td data-col-name="<?php esc_attr_e( 'Telefon', 'custom-puppy-form' ); ?>"><?php echo esc_html( $phone ); ?></td>
								<td data-col-name="<?php esc_attr_e( 'Jahr', 'custom-puppy-form' ); ?>"><?php echo esc_html( $year ); ?></td>
								<td data-col-name="<?php esc_attr_e( 'Details', 'custom-puppy-form' ); ?>">
									<details class="puppy-details-expand">
										<summary style="cursor: pointer; color: #0073aa; font-weight: 600; outline: none; user-select: none; font-size: 13px;">
											<?php esc_html_e( 'Details einblenden', 'custom-puppy-form' ); ?>
										</summary>
										<div class="puppy-details-expanded-box" style="margin-top: 10px; padding: 12px; background: #f6f7f7; border: 1px solid #ccd0d4; border-radius: 6px; font-size: 13px; line-height: 1.5; color: #2c3e50; max-height: 300px; overflow-y: auto;">
											<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Name:', 'custom-puppy-form' ); ?></strong> <?php echo esc_html( $name ); ?></p>
											<?php if ( ! empty( $age ) ) : ?>
												<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Alter:', 'custom-puppy-form' ); ?></strong> <?php echo esc_html( $age ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $traits ) ) : ?>
												<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Eigenschaften:', 'custom-puppy-form' ); ?></strong> <?php echo esc_html( $traits ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $litter ) ) : ?>
												<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Interessierter Wurf:', 'custom-puppy-form' ); ?></strong> <?php echo esc_html( $litter ); ?></p>
											<?php endif; ?>
											<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Zweck:', 'custom-puppy-form' ); ?></strong> 
												<?php
												$purposes = array();
												if ( ! empty( $app->purpose_family ) ) $purposes[] = __( 'Familienhund', 'custom-puppy-form' );
												if ( ! empty( $app->purpose_sport ) ) $purposes[] = __( 'Sport', 'custom-puppy-form' );
												if ( ! empty( $app->purpose_therapy ) ) $purposes[] = __( 'Therapie', 'custom-puppy-form' );
												if ( empty( $purposes ) ) {
													echo esc_html__( 'Nicht angegeben (Bestandseintrag)', 'custom-puppy-form' );
												} else {
													echo esc_html( implode( ', ', $purposes ) );
												}
												?>
											</p>
											<?php if ( ! empty( $address ) ) : ?>
												<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Anschrift:', 'custom-puppy-form' ); ?></strong> <?php echo esc_html( $address ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $family ) ) : ?>
												<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Familiensituation:', 'custom-puppy-form' ); ?></strong><br /><?php echo nl2br( esc_html( $family ) ); ?></p>
											<?php endif; ?>
											<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Wohnsituation:', 'custom-puppy-form' ); ?></strong><br /><?php echo nl2br( esc_html( $living ) ); ?></p>
											<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Arbeitssituation:', 'custom-puppy-form' ); ?></strong><br /><?php echo nl2br( esc_html( $working ) ); ?></p>
											<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Hundeerfahrung:', 'custom-puppy-form' ); ?></strong><br /><?php echo nl2br( esc_html( $experience ) ); ?></p>
											<?php if ( ! empty( $wish ) ) : ?>
												<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Welpenauswahl:', 'custom-puppy-form' ); ?></strong> <?php echo esc_html( $wish ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $pets ) ) : ?>
												<p style="margin: 0 0 0 0;"><strong><?php esc_html_e( 'Tierische Mitbewohner:', 'custom-puppy-form' ); ?></strong> <?php echo esc_html( $pets ); ?></p>
											<?php endif; ?>
										</div>
									</details>
								</td>
								<td data-col-name="<?php esc_attr_e( 'Zustimmungen', 'custom-puppy-form' ); ?>">
									<div style="display: flex; flex-direction: column; gap: 4px;">
										<span class="puppy-privacy-badge" style="background: #eafaf1; color: #1a5c37; border-left: 3px solid #2ecc71;">
											✓ <?php esc_html_e( 'Datenschutz', 'custom-puppy-form' ); ?>
										</span>
										<span class="puppy-privacy-badge" style="background: #eef2ff; color: #3730a3; border-left: 3px solid #6366f1;">
											✓ <?php esc_html_e( 'Futter-Info', 'custom-puppy-form' ); ?>
										</span>
									</div>
								</td>
								<td data-col-name="<?php esc_attr_e( 'Datum', 'custom-puppy-form' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $app->created_at ) ); ?></td>
							</tr>
							<?php
						}
					} else {
						?>
						<tr>
							<td colspan="7" style="text-align: center; padding: 20px; color: #646970;">
								<?php esc_html_e( 'Keine Bewerbungen gefunden.', 'custom-puppy-form' ); ?>
							</td>
						</tr>
						<?php
					}
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render internal diagnostics log (e.g. failed DB inserts on form submission).
	 */
	private function render_diagnostics_tab() {
		$entries = self::get_recent_log_entries( 50 );
		?>
		<div class="puppy-diagnostics-container">
			<p>
				<?php esc_html_e( 'Hier werden technische Fehler protokolliert, die beim Verarbeiten von Bewerbungen auftreten (z. B. wenn eine Bewerbung nicht gespeichert werden konnte). Einträge werden automatisch nach 30 Tagen gelöscht. Es werden keine Bewerberdaten in diesem Protokoll gespeichert.', 'custom-puppy-form' ); ?>
			</p>
			<table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Datum', 'custom-puppy-form' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Ereignis', 'custom-puppy-form' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Details', 'custom-puppy-form' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $entries ) ) : ?>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->created_at ) ); ?></td>
								<td><?php echo esc_html( $entry->event_type ); ?></td>
								<td><?php echo esc_html( $entry->message ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="3" style="text-align: center; padding: 20px; color: #646970;">
								<?php esc_html_e( 'Keine Einträge vorhanden.', 'custom-puppy-form' ); ?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render settings page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'applications';
		
		// Display standard deletion alerts.
		if ( isset( $_GET['deleted'] ) && '1' === $_GET['deleted'] ) {
			echo '<div class="notice notice-success is-dismissible" style="margin-left: 0; margin-right: 0;"><p>' . esc_html__( 'Bewerbung erfolgreich und DSGVO-konform gelöscht.', 'custom-puppy-form' ) . '</p></div>';
		}

		if ( isset( $_GET['puppy_privacy_activated'] ) && '1' === $_GET['puppy_privacy_activated'] ) {
			echo '<div class="notice notice-success is-dismissible" style="margin-left: 0; margin-right: 0;"><p>' . esc_html__( 'Plugin erfolgreich freigeschaltet! Das Frontend-Formular ist nun aktiv.', 'custom-puppy-form' ) . '</p></div>';
		}

		if ( isset( $_GET['puppy_privacy_revoked'] ) && '1' === $_GET['puppy_privacy_revoked'] ) {
			echo '<div class="notice notice-info is-dismissible" style="margin-left: 0; margin-right: 0;"><p>' . esc_html__( 'Freischaltung erfolgreich widerrufen. Das Frontend-Formular wurde gesperrt. Bitte denken Sie daran, erhobene Bewerberdaten manuell aus der Datenbank oder dem Bewerbungen-Tab zu löschen, falls erforderlich.', 'custom-puppy-form' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
				<a href="?page=puppy-application-form&tab=applications" class="nav-tab <?php echo 'applications' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Bewerbungen', 'custom-puppy-form' ); ?>
				</a>
				<a href="?page=puppy-application-form&tab=form_settings" class="nav-tab <?php echo 'form_settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Formular-Konfiguration', 'custom-puppy-form' ); ?>
				</a>
				<a href="?page=puppy-application-form&tab=email_settings" class="nav-tab <?php echo 'email_settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Automatische Antwort', 'custom-puppy-form' ); ?>
				</a>
				<a href="?page=puppy-application-form&tab=shortcode_info" class="nav-tab <?php echo 'shortcode_info' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Shortcode & Anleitung', 'custom-puppy-form' ); ?>
				</a>
				<a href="?page=puppy-application-form&tab=diagnostics" class="nav-tab <?php echo 'diagnostics' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Diagnose', 'custom-puppy-form' ); ?>
				</a>
			</h2>

			<?php
			if ( 'applications' === $active_tab ) {
				$this->render_applications_list();
			} elseif ( 'shortcode_info' === $active_tab ) {
				$this->render_shortcode_info_box();
			} elseif ( 'diagnostics' === $active_tab ) {
				$this->render_diagnostics_tab();
			} else {
				?>
				<form action="options.php" method="post">
					<?php
					settings_fields( 'puppy_app_settings_group' );
					
					if ( 'form_settings' === $active_tab ) {
						do_settings_sections( 'puppy-form-settings-page' );
					} else {
						do_settings_sections( 'puppy-email-settings-page' );
					}
					
					submit_button( __( 'Einstellungen speichern', 'custom-puppy-form' ) );
					?>
				</form>
				<?php
			}
			?>
		</div>
		<?php
	}
}
