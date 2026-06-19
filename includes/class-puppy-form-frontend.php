<?php
/**
 * Shortcode Rendering and Frontend UI.
 *
 * @package    Custom_Puppy_Application_Form
 * @subpackage Frontend
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Puppy_Form_Frontend
 *
 * Renders the shortcode and displays validation messages securely.
 */
class Puppy_Form_Frontend {

	/**
	 * Single instance storage.
	 *
	 * @var Puppy_Form_Frontend|null
	 */
	private static $instance = null;

	/**
	 * Retrieve singleton instance.
	 *
	 * @return Puppy_Form_Frontend
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers the shortcode.
	 */
	private function __construct() {
		add_shortcode( 'puppy_application_form', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render frontend puppy form shortcode.
	 *
	 * @return string Form HTML.
	 */
	public function render_shortcode() {
		// 1. Check active GDPR Privacy activation lock.
		$privacy_activated = Puppy_Form_Admin::get_setting( 'privacy_activated' );
		if ( '1' !== $privacy_activated ) {
			if ( current_user_can( 'manage_options' ) ) {
				// Show a descriptive notice only to administrators.
				return '<div class="puppy-form-message puppy-form-error" role="alert" style="max-width: 650px; margin: 20px auto; border-left: 4px solid #e74c3c;">' . esc_html__( 'Welpen-Formular im Wartemodus: Bitte bestätigen Sie zuerst die Datenschutz-Richtlinien im Admin-Bereich.', 'custom-puppy-form' ) . '</div>';
			}
			// Empty for guests.
			return '';
		}

		ob_start();

		// Handle error / success notifications.
		if ( isset( $_GET['puppy_success'] ) && '1' === $_GET['puppy_success'] ) {
			echo '<div class="puppy-form-message puppy-form-success" role="alert">';
			echo esc_html__( 'Vielen Dank! Ihre Bewerbung wurde erfolgreich übermittelt. Wir haben Ihnen eine Bestätigungs-E-Mail gesendet.', 'custom-puppy-form' );
			echo '</div>';
		} elseif ( isset( $_GET['puppy_error'] ) ) {
			$error_code = sanitize_text_field( $_GET['puppy_error'] );
			$message    = '';

			switch ( $error_code ) {
				case 'invalid_nonce':
					$message = __( 'Sicherheits-Check fehlgeschlagen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.', 'custom-puppy-form' );
					break;
				case 'missing_fields':
					$message = __( 'Fehler: Alle Felder sind Pflichtfelder! Bitte füllen Sie das gesamte Formular aus und stimmen Sie der Datenschutzerklärung zu.', 'custom-puppy-form' );
					break;
				case 'invalid_email':
					$message = __( 'Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'custom-puppy-form' );
					break;
				case 'mail_failed':
					$message = __( 'Der Versand Ihrer Bewerbung ist fehlgeschlagen. Bitte kontaktieren Sie uns direkt.', 'custom-puppy-form' );
					break;
				case 'rate_limit':
					$message = __( 'Zu viele Einsendungen. Bitte versuchen Sie es morgen erneut.', 'custom-puppy-form' );
					break;
				default:
					$message = __( 'Ein unerwarteter Fehler ist aufgetreten. Bitte versuchen Sie es erneut.', 'custom-puppy-form' );
					break;
			}

			echo '<div class="puppy-form-message puppy-form-error" role="alert">';
			echo esc_html( $message );
			echo '</div>';
		}

		// Retrieve database privacy label text with standard fallback.
		$label_privacy = Puppy_Form_Admin::get_setting( 'field_label_privacy' );
		if ( empty( $label_privacy ) ) {
			$label_privacy = __( 'Ich stimme der Datenschutzerklärung zu und willige in die Verarbeitung meiner Daten ein.', 'custom-puppy-form' );
		}

		// Current Request URL without query args.
		$current_url = remove_query_arg( array( 'puppy_error', 'puppy_success' ) );
		?>
		<form action="<?php echo esc_url( $current_url ); ?>" method="post" class="puppy-application-form-container">
			
			<!-- Actions and CSRF nonces -->
			<input type="hidden" name="action" value="puppy_form_submit">
			<?php wp_nonce_field( 'puppy_submit_action', 'puppy_nonce_field' ); ?>

			<!-- Bulletproof Redirection hidden parameter -->
			<input type="hidden" name="puppy_page_url" value="<?php echo esc_url( $current_url ); ?>" />

			<!-- Spambot Honeypot (Fully hidden from screen readers & keyboard inputs) -->
			<div class="puppy-hp-wrapper" style="display:none !important;" tabindex="-1" aria-hidden="true">
				<label for="puppy_website_hp"><?php esc_html_e( 'Bitte dieses Feld freilassen:', 'custom-puppy-form' ); ?></label>
				<input type="text" name="puppy_website_hp" id="puppy_website_hp" autocomplete="off" tabindex="-1" value="" />
			</div>

			<!-- Bereich A: Interessenbekundung -->
			<fieldset class="puppy-fieldset">
				<legend class="puppy-legend"><?php esc_html_e( 'A) Interessenbekundung', 'custom-puppy-form' ); ?></legend>
				
				<div class="puppy-form-group">
					<label for="puppy_year" class="puppy-label">
						<?php esc_html_e( 'Für welches Jahr / zu wann suchst Du einen Welpen?', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<select name="puppy_year" id="puppy_year" class="puppy-select" required aria-required="true">
						<option value=""><?php esc_html_e( '-- Bitte auswählen --', 'custom-puppy-form' ); ?></option>
						<?php
						$years_whitelist_option = Puppy_Form_Admin::get_setting( 'puppy_years_whitelist' );
						$allowed_years          = array_map( 'trim', explode( "\n", $years_whitelist_option ) );
						foreach ( $allowed_years as $year_val ) {
							if ( empty( $year_val ) ) {
								continue;
							}
							echo '<option value="' . esc_attr( $year_val ) . '">' . esc_html( $year_val ) . '</option>';
						}
						?>
					</select>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_traits" class="puppy-label">
						<?php esc_html_e( 'Welche Eigenschaften soll Dein Vierbeiner mitbringen?', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<input type="text" name="puppy_traits" id="puppy_traits" class="puppy-input-text" required aria-required="true" />
				</div>

				<div class="puppy-form-group">
					<label class="puppy-label"><?php esc_html_e( 'Unser neues Familienmitglied soll...', 'custom-puppy-form' ); ?></label>
					
					<div class="puppy-form-checkbox-group" style="margin-bottom: 8px;">
						<label for="puppy_purpose_family" class="puppy-label-checkbox">
							<input type="checkbox" name="puppy_purpose_family" id="puppy_purpose_family" class="puppy-input-checkbox" value="1" />
							<span><?php esc_html_e( 'als Familienhund leben', 'custom-puppy-form' ); ?></span>
						</label>
					</div>
					
					<div class="puppy-form-checkbox-group" style="margin-bottom: 8px;">
						<label for="puppy_purpose_sport" class="puppy-label-checkbox">
							<input type="checkbox" name="puppy_purpose_sport" id="puppy_purpose_sport" class="puppy-input-checkbox" value="1" />
							<span><?php esc_html_e( 'sportlich geführt werden', 'custom-puppy-form' ); ?></span>
						</label>
					</div>
					
					<div class="puppy-form-checkbox-group">
						<label for="puppy_purpose_therapy" class="puppy-label-checkbox">
							<input type="checkbox" name="puppy_purpose_therapy" id="puppy_purpose_therapy" class="puppy-input-checkbox" value="1" />
							<span><?php esc_html_e( 'im Therapiebereich ausgebildet werden', 'custom-puppy-form' ); ?></span>
						</label>
					</div>
				</div>
			</fieldset>

			<!-- Bereich B: Angaben zur anfragenden Familie -->
			<fieldset class="puppy-fieldset">
				<legend class="puppy-legend"><?php esc_html_e( 'B) Angaben zur anfragenden Familie', 'custom-puppy-form' ); ?></legend>

				<div class="puppy-form-group">
					<label for="puppy_applicant_name" class="puppy-label">
						<?php
						$label_name = Puppy_Form_Admin::get_setting( 'field_label_name' );
						if ( empty( $label_name ) ) {
							$label_name = __( 'Vollständiger Name', 'custom-puppy-form' );
						}
						echo esc_html( $label_name );
						?> <span class="required" aria-hidden="true">*</span>
					</label>
					<input type="text" name="puppy_applicant_name" id="puppy_applicant_name" class="puppy-input-text" required aria-required="true" />
				</div>

				<div class="puppy-form-group">
					<label for="puppy_applicant_age" class="puppy-label">
						<?php esc_html_e( 'Alter (Ansprechpartner)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<input type="number" name="puppy_applicant_age" id="puppy_applicant_age" class="puppy-input-text" min="18" max="99" required aria-required="true" />
				</div>

				<div class="puppy-form-group">
					<label for="puppy_applicant_email" class="puppy-label">
						<?php esc_html_e( 'E-Mail-Adresse', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<input type="email" name="puppy_applicant_email" id="puppy_applicant_email" class="puppy-input-email" required aria-required="true" />
				</div>

				<div class="puppy-form-group">
					<label for="puppy_applicant_phone" class="puppy-label">
						<?php esc_html_e( 'Handy / Telefonnummer', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<input type="tel" name="puppy_applicant_phone" id="puppy_applicant_phone" class="puppy-input-tel" required aria-required="true" />
				</div>

				<div class="puppy-form-group">
					<label for="puppy_applicant_address" class="puppy-label">
						<?php esc_html_e( 'Anschrift (Straße, PLZ, Ort)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<input type="text" name="puppy_applicant_address" id="puppy_applicant_address" class="puppy-input-text" required aria-required="true" />
				</div>

				<div class="puppy-form-group">
					<label for="puppy_family_situation" class="puppy-label">
						<?php esc_html_e( 'Familiensituation (Anzahl Personen im Haushalt, ggf. Kinder mit Altersangabe)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<textarea name="puppy_family_situation" id="puppy_family_situation" class="puppy-textarea" required aria-required="true"></textarea>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_living_situation" class="puppy-label">
						<?php esc_html_e( 'Wohnsituation / Größe (Wohnung, Haus, Garten, Hof)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<textarea name="puppy_living_situation" id="puppy_living_situation" class="puppy-textarea" required aria-required="true"></textarea>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_work_situation" class="puppy-label">
						<?php esc_html_e( 'Arbeitssituation (Berufstätigkeitsumfang, Std. pro Tag, ggf. Home-Office usw.)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<textarea name="puppy_work_situation" id="puppy_work_situation" class="puppy-textarea" required aria-required="true"></textarea>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_dog_experience" class="puppy-label">
						<?php esc_html_e( 'Vorerfahrungen in der Hundehaltung (Wenn ja, welche Rasse?)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<textarea name="puppy_dog_experience" id="puppy_dog_experience" class="puppy-textarea" required aria-required="true"></textarea>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_selection_wish" class="puppy-label">
						<?php esc_html_e( 'Welpenauswahl (Geschlechterwunsch / Farbwunsch / Egal)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<input type="text" name="puppy_selection_wish" id="puppy_selection_wish" class="puppy-input-text" required aria-required="true" />
				</div>

				<div class="puppy-form-group">
					<label for="puppy_other_pets" class="puppy-label">
						<?php esc_html_e( 'Tierische Mitbewohner (Habt ihr bereits Tiere, mit denen der Welpe aufwachsen wird?)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<input type="text" name="puppy_other_pets" id="puppy_other_pets" class="puppy-input-text" required aria-required="true" />
				</div>
			</fieldset>

			<!-- Bereich C: Wichtige Ernährungsinfo & Datenschutz -->
			<fieldset class="puppy-fieldset">
				<legend class="puppy-legend"><?php esc_html_e( 'C) Wichtige Ernährungsinfo & Datenschutz', 'custom-puppy-form' ); ?></legend>

				<!-- Futter-Bereitschaft Checkbox -->
				<div class="puppy-form-group puppy-form-checkbox">
					<label for="puppy_nutrition_agreement" class="puppy-label-checkbox">
						<input type="checkbox" name="puppy_nutrition_agreement" id="puppy_nutrition_agreement" class="puppy-input-checkbox" value="1" required aria-required="true" />
						<span>
							<?php esc_html_e( 'Ich habe die Ernährungsinfo gelesen und bestätige die Bereitschaft, das gewohnte Futter im Wachstum des Welpen weiterzufüttern (wichtig zur Vermeidung von Problemen durch Futterumstellungen).', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
						</span>
					</label>
				</div>

				<!-- Datenschutz-Zustimmungs-Checkbox -->
				<div class="puppy-form-group puppy-form-checkbox">
					<label for="puppy_privacy_agreement" class="puppy-label-checkbox">
						<input type="checkbox" name="puppy_privacy_agreement" id="puppy_privacy_agreement" class="puppy-input-checkbox" value="1" required aria-required="true" />
						<span>
							<?php echo esc_html( $label_privacy ); ?> <span class="required" aria-hidden="true">*</span>
						</span>
					</label>
				</div>
			</fieldset>

			<!-- Submit Button -->
			<div class="puppy-form-group" style="margin-bottom: 0;">
				<button type="submit" name="puppy_submit" class="puppy-submit-btn">
					<?php esc_html_e( 'Bewerbung absenden', 'custom-puppy-form' ); ?>
				</button>
			</div>
		</form>
		<?php

		return ob_get_clean();
	}
}
