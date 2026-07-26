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
		add_action( 'send_headers', array( $this, 'set_nocache_headers' ) );
	}

	/**
	 * Sendet Cache-Control-Header für Seiten mit dem Formular-Shortcode.
	 * Verhindert, dass abgelaufene Nonces aus dem Cache ausgeliefert werden.
	 */
	public function set_nocache_headers() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		// Hinweis: greift nur bei Shortcode direkt im Post-Inhalt.
		// Widget- oder Template-Part-Einbindung würde dies nicht erkennen.
		if ( $post && has_shortcode( $post->post_content, 'puppy_application_form' ) ) {
			nocache_headers();
		}
	}

	/**
	 * Maximum allowed length for free-text fields.
	 *
	 * @return int
	 */
	private function get_freetext_max_length() {
		return defined( 'PUPPY_FORM_FREETEXT_MAX_LENGTH' ) ? (int) PUPPY_FORM_FREETEXT_MAX_LENGTH : 2000;
	}

	/**
	 * Render the live character counter below a limited field.
	 *
	 * @param string $id Field id attribute.
	 */
	private function render_char_counter( $id ) {
		$max = $this->get_freetext_max_length();
		?>
		<p class="puppy-char-counter" id="<?php echo esc_attr( $id ); ?>-counter" aria-live="polite">
			<span class="puppy-char-counter-value"><?php echo (int) $max; ?></span>
			<?php esc_html_e( 'Zeichen übrig', 'custom-puppy-form' ); ?>
		</p>
		<?php
	}

	/**
	 * Render a textarea with maxlength and live character counter.
	 *
	 * @param string $name Field name attribute.
	 * @param string $id   Field id attribute.
	 */
	private function render_limited_textarea( $name, $id ) {
		$max = $this->get_freetext_max_length();
		?>
		<textarea
			name="<?php echo esc_attr( $name ); ?>"
			id="<?php echo esc_attr( $id ); ?>"
			class="puppy-textarea puppy-limited-field"
			maxlength="<?php echo (int) $max; ?>"
			data-char-max="<?php echo (int) $max; ?>"
			required
			aria-required="true"
		></textarea>
		<?php $this->render_char_counter( $id ); ?>
		<?php
	}

	/**
	 * Render a single-line input with maxlength and live character counter.
	 *
	 * @param string $name  Field name attribute.
	 * @param string $id    Field id attribute.
	 * @param string $class CSS class for the input element.
	 * @param string $type  Input type attribute.
	 */
	private function render_limited_input( $name, $id, $class = 'puppy-input-text', $type = 'text' ) {
		$max = $this->get_freetext_max_length();
		?>
		<input
			type="<?php echo esc_attr( $type ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			id="<?php echo esc_attr( $id ); ?>"
			class="<?php echo esc_attr( $class ); ?> puppy-limited-field"
			maxlength="<?php echo (int) $max; ?>"
			data-char-max="<?php echo (int) $max; ?>"
			required
			aria-required="true"
		/>
		<?php $this->render_char_counter( $id ); ?>
		<?php
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
				case 'spam_detected':
					$message = __( 'Ihre Anfrage konnte nicht verarbeitet werden. Bitte laden Sie die Seite neu und versuchen Sie es erneut.', 'custom-puppy-form' );
					break;
				case 'math_failed':
					$message = __( 'Die Antwort auf die Rechenaufgabe war leider nicht korrekt. Bitte versuchen Sie es erneut.', 'custom-puppy-form' );
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
			<input type="hidden" name="puppy_form_timestamp" value="<?php echo esc_attr( time() ); ?>" />

			<!-- Bulletproof Redirection hidden parameter -->
			<input type="hidden" name="puppy_page_url" value="<?php echo esc_url( $current_url ); ?>" />

			<!-- Spambot Honeypot (Fully hidden from screen readers & keyboard inputs) -->
			<div class="puppy-hp-wrapper" style="position:absolute;left:-9999px;top:-9999px;width:0;height:0;overflow:hidden;opacity:0;" tabindex="-1" aria-hidden="true">
				<label for="puppy_field_b4t"><?php esc_html_e( 'Bitte dieses Feld freilassen:', 'custom-puppy-form' ); ?></label>
				<input type="text" name="puppy_field_b4t" id="puppy_field_b4t" autocomplete="off" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:0;height:0;overflow:hidden;opacity:0;" value="" />
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
					<?php $this->render_limited_textarea( 'puppy_traits', 'puppy_traits' ); ?>
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
					<?php $this->render_limited_input( 'puppy_applicant_address', 'puppy_applicant_address' ); ?>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_family_situation" class="puppy-label">
						<?php esc_html_e( 'Familiensituation (Anzahl Personen im Haushalt, ggf. Kinder mit Altersangabe)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<?php $this->render_limited_textarea( 'puppy_family_situation', 'puppy_family_situation' ); ?>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_living_situation" class="puppy-label">
						<?php esc_html_e( 'Wohnsituation / Größe (Wohnung, Haus, Garten, Hof)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<?php $this->render_limited_textarea( 'puppy_living_situation', 'puppy_living_situation' ); ?>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_work_situation" class="puppy-label">
						<?php esc_html_e( 'Arbeitssituation (Berufstätigkeitsumfang, Std. pro Tag, ggf. Home-Office usw.)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<?php $this->render_limited_textarea( 'puppy_work_situation', 'puppy_work_situation' ); ?>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_dog_experience" class="puppy-label">
						<?php esc_html_e( 'Vorerfahrungen in der Hundehaltung (Wenn ja, welche Rasse?)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<?php $this->render_limited_textarea( 'puppy_dog_experience', 'puppy_dog_experience' ); ?>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_selection_wish" class="puppy-label">
						<?php esc_html_e( 'Welpenauswahl (Geschlechterwunsch / Farbwunsch / Egal)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<?php $this->render_limited_textarea( 'puppy_selection_wish', 'puppy_selection_wish' ); ?>
				</div>

				<div class="puppy-form-group">
					<label for="puppy_other_pets" class="puppy-label">
						<?php esc_html_e( 'Tierische Mitbewohner (Habt ihr bereits Tiere, mit denen der Welpe aufwachsen wird?)', 'custom-puppy-form' ); ?> <span class="required" aria-hidden="true">*</span>
					</label>
					<?php $this->render_limited_textarea( 'puppy_other_pets', 'puppy_other_pets' ); ?>
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

				<?php
				$num1      = wp_rand( 1, 9 );
				$num2      = wp_rand( 1, 9 );
				$expected  = $num1 + $num2;
				$math_hash = wp_hash( (string) $expected . wp_salt() );
				?>
				<div class="puppy-form-field">
					<label for="puppy_math_answer">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %1$d: first number, %2$d: second number */
								__( 'Sicherheitsfrage: Was ergibt %1$d + %2$d?', 'custom-puppy-form' ),
								$num1,
								$num2
							)
						);
						?> *
					</label>
					<input type="number"
						id="puppy_math_answer"
						name="puppy_math_answer"
						min="1"
						max="18"
						required
						aria-required="true" />
					<input type="hidden"
						name="puppy_math_hash"
						value="<?php echo esc_attr( $math_hash ); ?>" />
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
		<script>
		(function () {
			var fields = document.querySelectorAll('.puppy-application-form-container .puppy-limited-field');
			fields.forEach(function (field) {
				var max = parseInt(field.getAttribute('data-char-max'), 10) || parseInt(field.getAttribute('maxlength'), 10) || 2000;
				var counter = document.getElementById(field.id + '-counter');
				var valueNode = counter ? counter.querySelector('.puppy-char-counter-value') : null;

				function enforceLimit() {
					if (field.value.length > max) {
						field.value = field.value.slice(0, max);
					}
				}

				function updateCounter() {
					if (!valueNode) {
						return;
					}
					valueNode.textContent = Math.max(0, max - field.value.length);
				}

				function syncField() {
					enforceLimit();
					updateCounter();
				}

				field.addEventListener('input', syncField);
				field.addEventListener('paste', function () {
					window.setTimeout(syncField, 0);
				});
				syncField();
			});
		}());
		</script>
		<?php

		return ob_get_clean();
	}
}
