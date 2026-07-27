<?php
/**
 * Safe Form Submission Handling and Processing.
 *
 * @package    Custom_Puppy_Application_Form
 * @subpackage Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Puppy_Form_Handler
 *
 * Validates, sanitizes, and dispatches applicant form submissions securely.
 */
class Puppy_Form_Handler {

	/**
	 * Maximum length for multi-line free-text fields (characters).
	 *
	 * @var int
	 */
	const FREETEXT_MAX_LENGTH = 2000;

	/**
	 * Single instance storage.
	 *
	 * @var Puppy_Form_Handler|null
	 */
	private static $instance = null;

	/**
	 * Retrieve singleton instance.
	 *
	 * @return Puppy_Form_Handler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Binds form hook.
	 */
	private function __construct() {
		add_action( 'wp_loaded', array( $this, 'handle_form_submission' ) );
	}

	/**
	 * Safe redirect helper.
	 *
	 * @param string $url Destination URL.
	 */
	private function safe_redirect( $url ) {
		wp_safe_redirect( esc_url_raw( $url ) );
		exit;
	}

	/**
	 * Redirect back to referring page with query parameters.
	 *
	 * @param string $key   Query parameter key ('puppy_error' or 'puppy_success').
	 * @param string $value Query parameter value.
	 */
	private function redirect_with_query_arg( $key, $value ) {
		// Use hidden submitted page URL if present to bypass volatile referrer headers.
		$referer = ! empty( $_POST['puppy_page_url'] ) ? esc_url_raw( $_POST['puppy_page_url'] ) : wp_get_referer();
		if ( ! $referer ) {
			$referer = home_url( '/' );
		}

		// Clean parameters to prevent query arg stacking.
		$referer = remove_query_arg( array( 'puppy_error', 'puppy_success' ), $referer );
		$destination = add_query_arg( array( $key => $value ), $referer );

		$this->safe_redirect( $destination );
	}

	/**
	 * Truncate a string to a maximum number of multibyte characters.
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Maximum character count.
	 * @return string
	 */
	private function limit_multibyte_length( $value, $max ) {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $value, 'UTF-8' ) > $max ) {
				return mb_substr( $value, 0, $max, 'UTF-8' );
			}
			return $value;
		}

		if ( strlen( $value ) > $max ) {
			return substr( $value, 0, $max );
		}

		return $value;
	}

	/**
	 * Normalize text for database storage: encode emoji safely and strip 4-byte UTF-8
	 * sequences that break legacy utf8 (non-mb4) columns in strict SQL mode.
	 *
	 * @param string $value Sanitized text value.
	 * @return string
	 */
	private function sanitize_db_text( $value ) {
		// wp_encode_emoji() is intended for HTML output (converts to <img> tags) and is
		// unsuitable for DB storage. Strip 4-byte UTF-8 instead to prevent strict-mode
		// failures on legacy utf8 (non-mb4) database charsets.
		return preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', $value );
	}

	/**
	 * Prepare a multi-line free-text field for database insert.
	 *
	 * @param string $raw_value Raw POST value.
	 * @return string
	 */
	private function prepare_freetext_for_db( $raw_value ) {
		$value = sanitize_textarea_field( $raw_value );
		$value = $this->limit_multibyte_length( $value, self::FREETEXT_MAX_LENGTH );
		return $this->sanitize_db_text( $value );
	}

	/**
	 * Prepare a single-line text field for database insert with optional maximum length.
	 *
	 * @param string   $raw_value Raw POST value.
	 * @param int|null $max       Optional maximum character count.
	 * @return string
	 */
	private function prepare_short_text_for_db( $raw_value, $max = null ) {
		$value = sanitize_text_field( $raw_value );
		if ( null !== $max ) {
			$value = $this->limit_multibyte_length( $value, $max );
		}
		return $this->sanitize_db_text( $value );
	}

	/**
	 * Core Submission handler.
	 */
	public function handle_form_submission() {
		// Detect matching action or submit key.
		$is_form_submission = ( isset( $_POST['action'] ) && 'puppy_form_submit' === $_POST['action'] ) || isset( $_POST['puppy_submit'] );
		if ( ! $is_form_submission ) {
			return;
		}

		// 1. Verify active GDPR Privacy activation lock.
		$privacy_activated = Puppy_Form_Admin::get_setting( 'privacy_activated' );
		if ( '1' !== $privacy_activated ) {
			$this->redirect_with_query_arg( 'puppy_error', 'invalid_nonce' );
			return;
		}

		// 2. Verify CSRF Nonce.
		if ( ! isset( $_POST['puppy_nonce_field'] ) || ! wp_verify_nonce( $_POST['puppy_nonce_field'], 'puppy_submit_action' ) ) {
			$this->redirect_with_query_arg( 'puppy_error', 'invalid_nonce' );
			return;
		}

		// 3. Honeypot check (Spambot detection). If filled, fail silently.
		if ( ! empty( $_POST['puppy_field_b4t'] ) ) {
			$referer = ! empty( $_POST['puppy_page_url'] ) ? esc_url_raw( $_POST['puppy_page_url'] ) : wp_get_referer();
			if ( ! $referer ) {
				$referer = home_url( '/' );
			}
			$referer = remove_query_arg( array( 'puppy_error', 'puppy_success' ), $referer );
			$this->safe_redirect( $referer );
			return;
		}

		// Zeitlimit-Check: Submissions unter 15 Sekunden sind Bots.
		$form_timestamp = isset( $_POST['puppy_form_timestamp'] )
			? (int) $_POST['puppy_form_timestamp']
			: 0;
		$elapsed = time() - $form_timestamp;
		if ( $form_timestamp === 0 || $elapsed < 15 || $elapsed > 3600 ) {
			// Zu schnell (Bot) oder zu alt (> 1 Stunde, abgelaufene Session).
			$this->redirect_with_query_arg( 'puppy_error', 'spam_detected' );
			return;
		}

		// Math-Challenge-Prüfung.
		$math_answer = isset( $_POST['puppy_math_answer'] )
			? (int) $_POST['puppy_math_answer']
			: -1;
		$math_hash   = isset( $_POST['puppy_math_hash'] )
			? sanitize_text_field( wp_unslash( $_POST['puppy_math_hash'] ) )
			: '';
		$math_valid  = hash_equals(
			$math_hash,
			wp_hash( (string) $math_answer . wp_salt() )
		);
		if ( ! $math_valid ) {
			$this->redirect_with_query_arg( 'puppy_error', 'math_failed' );
			return;
		}

		// Spam-Inhaltsfilter: URLs und bekannte Spam-Muster.
		$spam_patterns = array(
			'http://', 'https://', 'www.',
			't.me', 'telegram', '.com/', '.net/', '.org/',
			'click here', 'financial fear', 'giveaway',
			'promo', 'exclusive', 'Tesla', 'wise click',
		);

		// Auf Kyrillisch prüfen (Unicode-Block U+0400–U+04FF).
		$cyrillic_pattern = '/[\x{0400}-\x{04FF}]/u';

		// Diese Felder werden auf Spam-Inhalte geprüft.
		$spam_check_fields = array(
			'puppy_applicant_name',
			'puppy_applicant_phone',
			'puppy_applicant_address',
			'puppy_traits',
			'puppy_family_situation',
			'puppy_living_situation',
			'puppy_work_situation',
			'puppy_dog_experience',
			'puppy_selection_wish',
			'puppy_other_pets',
		);

		foreach ( $spam_check_fields as $field ) {
			$value = isset( $_POST[ $field ] )
				? strtolower( $_POST[ $field ] )
				: '';
			// URL/Phrase-Check.
			foreach ( $spam_patterns as $pattern ) {
				if ( strpos( $value, strtolower( $pattern ) )
					!== false ) {
					$this->redirect_with_query_arg(
						'puppy_error', 'spam_detected'
					);
					return;
				}
			}
			// Kyrillisch-Check (auf Original-Wert, nicht lowercase).
			$original = isset( $_POST[ $field ] )
				? $_POST[ $field ] : '';
			if ( preg_match( $cyrillic_pattern, $original ) ) {
				$this->redirect_with_query_arg(
					'puppy_error', 'spam_detected'
				);
				return;
			}
		}

		// Feld-Format-Validierung.

		// Name: nur Buchstaben, Leerzeichen, Bindestrich,
		// Apostroph, Punkt — 2 bis 60 Zeichen.
		// \p{L} matcht Buchstaben aller Sprachen (inkl. Umlaute).
		$name_raw = isset( $_POST['puppy_applicant_name'] )
			? $_POST['puppy_applicant_name'] : '';
		if ( ! preg_match(
			'/^[\p{L}\s\'\-\.]{2,60}$/u', $name_raw )
		) {
			$this->redirect_with_query_arg(
				'puppy_error', 'invalid_format'
			);
			return;
		}

		// Telefon: Ziffern, +, -, Leerzeichen, Klammern
		// — 6 bis 20 Zeichen.
		$phone_raw = isset( $_POST['puppy_applicant_phone'] )
			? $_POST['puppy_applicant_phone'] : '';
		if ( ! preg_match(
			'/^[\d\s\+\-\(\)]{6,20}$/', $phone_raw )
		) {
			$this->redirect_with_query_arg(
				'puppy_error', 'invalid_format'
			);
			return;
		}

		// Alter: Integer, strikt zwischen 18 und 99.
		$age_raw = isset( $_POST['puppy_applicant_age'] )
			? (int) $_POST['puppy_applicant_age'] : 0;
		if ( $age_raw < 18 || $age_raw > 99 ) {
			$this->redirect_with_query_arg(
				'puppy_error', 'invalid_format'
			);
			return;
		}

		// Freitextfelder: Mindestlänge 10 Zeichen.
		$min_length_fields = array(
			'puppy_traits',
			'puppy_family_situation',
			'puppy_living_situation',
			'puppy_work_situation',
			'puppy_dog_experience',
			'puppy_selection_wish',
		);
		foreach ( $min_length_fields as $field ) {
			$value = isset( $_POST[ $field ] )
				? trim( $_POST[ $field ] ) : '';
			if ( mb_strlen( $value ) < 10 ) {
				$this->redirect_with_query_arg(
					'puppy_error', 'missing_fields'
				);
				return;
			}
			// Nur-Zahlen-Check: reiner Zahleninhalt ist kein
			// sinnvoller Freitext.
			if ( preg_match( '/^[\d\s]+$/', $value ) ) {
				$this->redirect_with_query_arg(
					'puppy_error', 'invalid_format'
				);
				return;
			}
		}

		// Konsistenz-Check: Name und Telefon dürfen nicht
		// identisch sein — das ist ein eindeutiges Spam-Muster.
		$name_trimmed  = trim( strtolower(
			isset( $_POST['puppy_applicant_name'] )
			? $_POST['puppy_applicant_name'] : ''
		) );
		$phone_trimmed = trim( strtolower(
			isset( $_POST['puppy_applicant_phone'] )
			? $_POST['puppy_applicant_phone'] : ''
		) );
		if ( ! empty( $name_trimmed )
			&& $name_trimmed === $phone_trimmed ) {
			$this->redirect_with_query_arg(
				'puppy_error', 'spam_detected'
			);
			return;
		}

		// 3b. IP-basierte Ratenbegrenzung (CHANGE 5)
		/**
		 * Ermittelt die echte Client-IP, auch hinter Reverse-Proxies.
		 * Gibt einen MD5-Hash zurück (datenschutzkonform).
		 */
		$real_ip = '';
		$proxy_headers = array(
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
			'HTTP_CF_CONNECTING_IP', // Cloudflare
		);
		foreach ( $proxy_headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X-Forwarded-For can contain a chain; take the first IP.
				$real_ip = trim( explode( ',', $_SERVER[ $header ] )[0] );
				break;
			}
		}
		if ( empty( $real_ip ) ) {
			$real_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		}
		$real_ip       = sanitize_text_field( $real_ip );
		$transient_key = 'puppy_rl_' . md5( $real_ip );

		$count = (int) get_transient( $transient_key );
		if ( $count >= 3 ) {
			$this->redirect_with_query_arg( 'puppy_error', 'rate_limit' );
			return;
		}

		set_transient( $transient_key, $count + 1, DAY_IN_SECONDS );

		// 4. Basic required check.
		$required_fields = array(
			'puppy_year',
			'puppy_traits',
			'puppy_applicant_name',
			'puppy_applicant_age',
			'puppy_applicant_email',
			'puppy_applicant_phone',
			'puppy_applicant_address',
			'puppy_family_situation',
			'puppy_living_situation',
			'puppy_work_situation',
			'puppy_dog_experience',
			'puppy_selection_wish',
			'puppy_other_pets',
			'puppy_nutrition_agreement',
			'puppy_privacy_agreement'
		);
		foreach ( $required_fields as $field ) {
			if ( empty( $_POST[ $field ] ) ) {
				$this->redirect_with_query_arg( 'puppy_error', 'missing_fields' );
				return;
			}
		}

		// 5. Dropdown Whitelist Validation (CHANGE 3 part C).
		$posted_year            = sanitize_text_field( $_POST['puppy_year'] );
		$years_whitelist_option = Puppy_Form_Admin::get_setting( 'puppy_years_whitelist' );
		$allowed_years          = array_map( 'trim', explode( "\n", $years_whitelist_option ) );
		if ( ! in_array( $posted_year, $allowed_years, true ) ) {
			$this->redirect_with_query_arg( 'puppy_error', 'missing_fields' );
			return;
		}

		// Email format validation.
		$email_input = sanitize_email( $_POST['puppy_applicant_email'] );
		if ( ! is_email( $email_input ) ) {
			$this->redirect_with_query_arg( 'puppy_error', 'invalid_email' );
			return;
		}

		// 6. Full input sanitization (CHANGE 4 part B).
		$target_year          = $posted_year;
		$traits               = $this->prepare_freetext_for_db( $_POST['puppy_traits'] );
		$purpose_family       = isset( $_POST['puppy_purpose_family'] ) ? 1 : 0;
		$purpose_sport        = isset( $_POST['puppy_purpose_sport'] ) ? 1 : 0;
		$purpose_therapy      = isset( $_POST['puppy_purpose_therapy'] ) ? 1 : 0;
		$applicant_name       = $this->prepare_short_text_for_db( $_POST['puppy_applicant_name'], 255 );
		$applicant_age        = $this->prepare_short_text_for_db( $_POST['puppy_applicant_age'], 20 );
		$applicant_email      = $this->limit_multibyte_length( $email_input, 255 );
		$applicant_phone      = $this->prepare_short_text_for_db( $_POST['puppy_applicant_phone'], 100 );
		$applicant_address    = $this->prepare_freetext_for_db( $_POST['puppy_applicant_address'] );
		$family_situation     = $this->prepare_freetext_for_db( $_POST['puppy_family_situation'] );
		$living_situation     = $this->prepare_freetext_for_db( $_POST['puppy_living_situation'] );
		$work_situation       = $this->prepare_freetext_for_db( $_POST['puppy_work_situation'] );
		$dog_experience       = $this->prepare_freetext_for_db( $_POST['puppy_dog_experience'] );
		$selection_wish       = $this->prepare_freetext_for_db( $_POST['puppy_selection_wish'] );
		$other_pets           = $this->prepare_freetext_for_db( $_POST['puppy_other_pets'] );
		$nutrition_agreement  = 1;
		$privacy_agreement    = 1;

		// 7. DB Insertion using prepared structures.
		global $wpdb;
		$table_name = $wpdb->prefix . 'puppy_applications';
		
		$db_insert_success = $wpdb->insert(
			$table_name,
			array(
				'target_year'         => $target_year,
				'traits'              => $traits,
				'purpose_family'      => $purpose_family,
				'purpose_sport'       => $purpose_sport,
				'purpose_therapy'     => $purpose_therapy,
				'applicant_name'      => $applicant_name,
				'applicant_age'       => $applicant_age,
				'applicant_email'     => $applicant_email,
				'applicant_phone'     => $applicant_phone,
				'applicant_address'   => $applicant_address,
				'family_situation'    => $family_situation,
				'living_situation'    => $living_situation,
				'work_situation'      => $work_situation,
				'dog_experience'      => $dog_experience,
				'selection_wish'      => $selection_wish,
				'other_pets'          => $other_pets,
				'nutrition_agreement' => $nutrition_agreement,
				'privacy_agreement'   => $privacy_agreement,
				'created_at'          => current_time( 'mysql' ),
			),
			array(
				'%s', // target_year
				'%s', // traits
				'%d', // purpose_family
				'%d', // purpose_sport
				'%d', // purpose_therapy
				'%s', // applicant_name
				'%s', // applicant_age
				'%s', // applicant_email
				'%s', // applicant_phone
				'%s', // applicant_address
				'%s', // family_situation
				'%s', // living_situation
				'%s', // work_situation
				'%s', // dog_experience
				'%s', // selection_wish
				'%s', // other_pets
				'%d', // nutrition_agreement
				'%d', // privacy_agreement
				'%s', // created_at
			)
		);

		// Capture the DB error immediately, before any other $wpdb query can overwrite it.
		$db_insert_error = '';
		if ( ! $db_insert_success ) {
			$db_insert_error = $wpdb->last_error;
			Puppy_Form_Admin::log_event(
				'db_insert_failed',
				$db_insert_error ? $db_insert_error : 'Unbekannter Fehler beim Speichern der Bewerbung (kein last_error verfügbar).'
			);
		}

		// Fetch Settings options for Email compilation.
		$breeder_email     = Puppy_Form_Admin::get_setting( 'receiver_email' );
		$puppy_price       = Puppy_Form_Admin::get_setting( 'puppy_price' );
		$custom_email_body = Puppy_Form_Admin::get_setting( 'custom_email_body' );

		$breeder_recipient = is_email( $breeder_email ) ? $breeder_email : get_option( 'admin_email' );

		// Dynamically determine the domain-matching sender email for SPF/DMARC compatibility.
		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! empty( $site_domain ) ) {
			$site_domain = preg_replace( '/^www\./i', '', $site_domain );
		}
		$sender_email = 'no-reply@' . $site_domain;

		// Set headers to HTML format.
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: Welpen-Bewerbung <' . $sender_email . '>',
			'Reply-To: ' . $breeder_recipient,
		);

		// --- Action A: Send notification email to the breeder ---
		$breeder_subject = sprintf(
			/* translators: %s: Applicant Name */
			esc_html__( 'Neue Welpen-Bewerbung von %s', 'custom-puppy-form' ),
			$applicant_name
		);

		// Breeder email body construction.
		$admin_url     = admin_url( 'admin.php?page=puppy-application-form' );
		$breeder_body  = '<html>';
		$breeder_body .= '<body style="font-family: sans-serif; color: #333333; padding: 20px;">';
		$breeder_body .= '<h2>' . esc_html__( 'Neue Welpen-Bewerbung eingegangen', 'custom-puppy-form' ) . '</h2>';
		$breeder_body .= '<p>' . esc_html__( 'Es liegt eine neue Bewerbung für einen Welpen vor.', 'custom-puppy-form' ) . '</p>';
		$breeder_body .= '<p>';
		$breeder_body .= '<strong>' . esc_html__( 'Name:', 'custom-puppy-form' ) . '</strong> ' . esc_html( $applicant_name ) . '<br />';
		$breeder_body .= '<strong>' . esc_html__( 'E-Mail:', 'custom-puppy-form' ) . '</strong> ' . esc_html( $applicant_email );
		$breeder_body .= '</p>';

		// Fallback safety net: if the application could not be saved to the database,
		// embed the full submitted data directly in this email so no data is lost.
		if ( ! $db_insert_success ) {
			$fallback_purpose_labels = array();
			if ( $purpose_family ) {
				$fallback_purpose_labels[] = __( 'Familienhund', 'custom-puppy-form' );
			}
			if ( $purpose_sport ) {
				$fallback_purpose_labels[] = __( 'Sport', 'custom-puppy-form' );
			}
			if ( $purpose_therapy ) {
				$fallback_purpose_labels[] = __( 'Therapie', 'custom-puppy-form' );
			}
			$fallback_purposes = ! empty( $fallback_purpose_labels ) ? implode( ', ', $fallback_purpose_labels ) : __( 'Nicht angegeben', 'custom-puppy-form' );

			$breeder_body .= '<div style="margin: 16px 0; padding: 14px; background-color: #fdf2f2; border-left: 4px solid #e74c3c;">';
			$breeder_body .= '<p style="margin: 0 0 10px 0;"><strong>' . esc_html__( 'Achtung: Diese Bewerbung konnte NICHT im Backend gespeichert werden.', 'custom-puppy-form' ) . '</strong><br />';
			$breeder_body .= esc_html__( 'Bitte sichern Sie die folgenden Angaben manuell und kontaktieren Sie den Bewerber bei Bedarf erneut.', 'custom-puppy-form' ) . '</p>';
			$breeder_body .= '<p style="margin: 0;">';
			$breeder_body .= '<strong>' . esc_html__( 'Alter:', 'custom-puppy-form' ) . '</strong> ' . esc_html( $applicant_age ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Telefon:', 'custom-puppy-form' ) . '</strong> ' . esc_html( $applicant_phone ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Anschrift:', 'custom-puppy-form' ) . '</strong> ' . esc_html( $applicant_address ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Wunschjahr:', 'custom-puppy-form' ) . '</strong> ' . esc_html( $target_year ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Gewünschte Eigenschaften:', 'custom-puppy-form' ) . '</strong> ' . esc_html( $traits ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Zweck:', 'custom-puppy-form' ) . '</strong> ' . esc_html( $fallback_purposes ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Familiensituation:', 'custom-puppy-form' ) . '</strong> ' . nl2br( esc_html( $family_situation ) ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Wohnsituation:', 'custom-puppy-form' ) . '</strong> ' . nl2br( esc_html( $living_situation ) ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Arbeitssituation:', 'custom-puppy-form' ) . '</strong> ' . nl2br( esc_html( $work_situation ) ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Hundeerfahrung:', 'custom-puppy-form' ) . '</strong> ' . nl2br( esc_html( $dog_experience ) ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Welpenauswahl:', 'custom-puppy-form' ) . '</strong> ' . esc_html( $selection_wish ) . '<br />';
			$breeder_body .= '<strong>' . esc_html__( 'Tierische Mitbewohner:', 'custom-puppy-form' ) . '</strong> ' . esc_html( $other_pets );
			$breeder_body .= '</p>';
			$breeder_body .= '</div>';
		}

		$breeder_body .= '<p>' . esc_html__( 'Bitte logge dich in den Administrationsbereich ein, um alle Angaben einzusehen:', 'custom-puppy-form' ) . '</p>';
		$breeder_body .= '<p>';
		$breeder_body .= '  <a href="' . esc_url( $admin_url ) . '" style="display: inline-block; padding: 10px 20px; background-color: #2271b1; color: #ffffff; text-decoration: none; border-radius: 4px;">';
		$breeder_body .= esc_html__( 'Zur Bewerbungsliste', 'custom-puppy-form' );
		$breeder_body .= '  </a>';
		$breeder_body .= '</p>';
		$breeder_body .= '<p style="font-size: 12px; color: #888888;">';
		$breeder_body .= esc_html__( 'Direktlink:', 'custom-puppy-form' ) . ' ' . esc_url( $admin_url );
		$breeder_body .= '</p>';
		$breeder_body .= '</body>';
		$breeder_body .= '</html>';

		// Verify receiver email integrity prior to sending.
		$mail_breeder_success = wp_mail( $breeder_recipient, $breeder_subject, $breeder_body, $headers );

		// --- Action B: Send autoresponder to applicant ---
		// Placeholder replacements.
		$autoresponder_body_raw = str_replace(
			array( '{name}', '{price}' ),
			array( $applicant_name, $puppy_price ),
			$custom_email_body
		);

		$autoresponder_subject = __( 'Ihre Bewerbung für einen Welpen', 'custom-puppy-form' );

		// Wrapper layout styling for the applicant.
		$autoresponder_body  = '<html><body style="font-family: sans-serif; line-height: 1.6; color: #333333;">';
		$autoresponder_body .= nl2br( esc_html( $autoresponder_body_raw ) );
		// DSGVO-Datenschutzhinweis im Autoresponder (CHANGE 8)
		$autoresponder_body .= '<hr style="border: none; border-top: 1px solid #dddddd; margin: 24px 0;" />';
		$autoresponder_body .= '<p style="font-size: 12px; color: #888888; line-height: 1.5;">';
		$autoresponder_body .= '<strong>' . esc_html__( 'Hinweis zum Datenschutz:', 'custom-puppy-form' ) . '</strong> ';
		$autoresponder_body .= esc_html__( 'Ihre Angaben wurden ausschließlich zum Zweck der Welpen-Vermittlung erhoben und werden nur von der Züchterin verarbeitet. Sie haben das Recht auf Auskunft, Berichtigung und Löschung Ihrer gespeicherten Daten (Art. 15–17 DSGVO). Zur Ausübung Ihrer Rechte wenden Sie sich bitte direkt an die Züchterin.', 'custom-puppy-form' );
		$autoresponder_body .= '</p>';
		$autoresponder_body .= '</body></html>';

		$mail_applicant_success = wp_mail( $applicant_email, $autoresponder_subject, $autoresponder_body, $headers );

		// 8. Differentiated result handling.
		// Bewerbung gilt nur als erfasst, wenn der DB-Insert erfolgreich war.
		// Die Züchter-Benachrichtigungsmail ist sekundär — selbst wenn sie
		// ankam, ist ohne DB-Eintrag keine Verwaltung der Bewerbung möglich.
		$data_captured = (bool) $db_insert_success;

		if ( ! $data_captured ) {
			Puppy_Form_Admin::log_event(
				'total_submission_failure',
				'DB-Insert fehlgeschlagen. Bewerbung wurde nicht in der Datenbank gespeichert. Züchter-Mail-Status: ' .
				( $mail_breeder_success ? 'erfolgreich gesendet' : 'ebenfalls fehlgeschlagen' )
			);
			$this->redirect_with_query_arg( 'puppy_error', 'mail_failed' );
			return;
		}

		if ( $db_insert_success && ! $mail_breeder_success ) {
			Puppy_Form_Admin::log_event( 'mail_delivery_failed', 'Bewerbung wurde gespeichert, aber die Benachrichtigungsmail an die Züchterin ist fehlgeschlagen.' );
		}

		if ( ! $mail_applicant_success ) {
			Puppy_Form_Admin::log_event( 'mail_delivery_failed', 'Bestätigungsmail an den Bewerber ist fehlgeschlagen.' );
		}

		// 9. Success redirect.
		$this->redirect_with_query_arg( 'puppy_success', '1' );
		return;
	}
}
