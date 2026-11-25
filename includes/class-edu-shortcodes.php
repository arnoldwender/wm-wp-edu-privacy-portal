<?php
/**
 * WM Edu Privacy Portal — shortcodes
 *
 * - [wm_edu_pii_sanitizer]: demo of the tokenizer on a text typed into the page (dates and class identifiers).
 * - [wm_edu_consent_gate]: looks up the newest consent entry for a pupil token in the ledger, for one type or any.
 *
 * Both are behind WM_Edu_Access since 2026-09-17: the lookup would otherwise tell anyone who guesses a pupil ID whether
 * a consent is on file, and the demo is the third door to the tokenizer, which turns a guessed date or class into the
 * token a pseudonymised text carries. The settings public_gate and public_tokenizer restore the earlier behaviour.
 *
 * Colours and dimensions live in assets/css/wm-edu-portal.css, enqueued by the shortcodes themselves; until 2026-09-15
 * the forms carried inline styles whose button text and field borders fell below the contrast minimums.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Shortcodes {

	public const STYLE_HANDLE = 'wm-edu-portal';

	public static function init(): void {
		add_shortcode( 'wm_edu_pii_sanitizer', [ self::class, 'render_pii_sanitizer' ] );
		add_shortcode( 'wm_edu_consent_gate', [ self::class, 'render_consent_gate' ] );
	}

	/**
	 * The sheet is enqueued only on pages that render a form; WordPress prints a late style in the footer. Public for the
	 * guardian form (WM_Edu_Consent_Form), which uses the same sheet.
	 */
	public static function enqueue_style(): void {
		wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'assets/css/wm-edu-portal.css', WM_EDU_PORTAL_FILE ), [], WM_EDU_PORTAL_VERSION );
	}

	/**
	 * Decorative inline SVG (monochrome, currentColor, hidden from assistive technology). The result messages keep their
	 * words, which carry the meaning.
	 */
	public static function icon( string $name ): string {
		$paths = [
			'lock'  => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
			'form'  => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
			'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
			'block' => '<circle cx="12" cy="12" r="9"/><path d="M5.7 5.7l12.6 12.6"/>',
			'alert' => '<path d="M12 3 2 21h20L12 3Z"/><path d="M12 10v5M12 18h.01"/>',
		];
		return '<svg class="wm-edu-icon" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	/**
	 * Tokenizer demo. $atts is not used: it is typed array|string because WordPress before 6.5 passes '' for a shortcode
	 * without attributes, which the former array type turned into a fatal TypeError.
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function render_pii_sanitizer( array|string $atts = [] ): string {
		self::enqueue_style();

		if ( ! WM_Edu_Access::can_use_tokenizer() ) {
			return self::denied_box(
				'wm-edu-sanitizer-box',
				__( 'Pseudonymisierung', 'wm-edu-privacy-portal' ),
				'lock',
				__( 'Diese Demo steht angemeldeten Benutzern mit der nötigen Berechtigung offen. Wer sie benutzen kann, kann auch das Token eines geratenen Datums berechnen.', 'wm-edu-privacy-portal' )
			);
		}

		$output  = '<section class="wm-edu-box wm-edu-sanitizer-box" aria-label="' . esc_attr__( 'Pseudonymisierung', 'wm-edu-privacy-portal' ) . '">';
		$output .= '<h3 class="wm-edu-title">' . self::icon( 'lock' ) . ' ' . esc_html__( 'Pseudonymisierung vor der Übergabe an KI-Dienste', 'wm-edu-privacy-portal' ) . '</h3>';
		$output .= '<p class="wm-edu-intro">' . esc_html__( 'Ersetzt Datumsangaben wie Geburtsdaten und Klassenbezeichnungen durch Platzhalter, bevor ein Text an ein KI-Modell geht. Schülernamen werden nur ersetzt, wenn die Namensliste der Schule als known_students an die REST-Route wm-edu/v1/tokenize übergeben wird.', 'wm-edu-privacy-portal' ) . '</p>';
		$output .= '<form method="post" class="wm-edu-form">';
		$output .= '<label class="wm-edu-field">' . esc_html__( 'Text mit Schülerdaten', 'wm-edu-privacy-portal' ) . ' <textarea name="wm_edu_raw" rows="4" required class="wm-edu-input" placeholder="' . esc_attr__( 'Max Müller (geb. 15.03.2012) aus Klasse 9b hat im Aufsatz eine hervorragende Leistung erbracht.', 'wm-edu-privacy-portal' ) . '"></textarea></label>';
		$output .= '<button type="submit" name="wm_edu_sanitize_submit" value="1" class="wm-edu-button">' . esc_html__( 'Text pseudonymisieren', 'wm-edu-privacy-portal' ) . '</button>';
		$output .= '</form>';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only demo, nothing is stored.
		if ( isset( $_POST['wm_edu_sanitize_submit'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- plain text for the tokenizer, checked as UTF-8 below and escaped on output; sanitize_text_field() would delete line breaks and "%ad" and turn "<3" into an entity.
			$raw    = isset( $_POST['wm_edu_raw'] ) && is_string( $_POST['wm_edu_raw'] ) ? wp_unslash( $_POST['wm_edu_raw'] ) : '';
			$result = null;
			if ( 1 === preg_match( '//u', $raw ) ) {
				try {
					$result = WM_Edu_PII_Tokenizer::tokenize( $raw );
				} catch ( RuntimeException $e ) {
					$result = null;
				}
			}

			$output .= '<div class="wm-edu-result" role="status">';
			if ( null === $result ) {
				$output .= '<p class="wm-edu-notice">' . esc_html__( 'Der Text konnte nicht verarbeitet werden: er ist kein gültiges UTF-8.', 'wm-edu-privacy-portal' ) . '</p>';
			} else {
				$output .= '<h4 class="wm-edu-result-title">' . esc_html(
					sprintf(
						/* translators: %d: number of replaced dates and class identifiers. */
						_n( 'Pseudonymisierter Text (%d Datum oder Klassenbezeichnung ersetzt)', 'Pseudonymisierter Text (%d Datumsangaben oder Klassenbezeichnungen ersetzt)', (int) $result['detections'], 'wm-edu-privacy-portal' ),
						(int) $result['detections']
					)
				) . '</h4>';
				$output .= '<pre class="wm-edu-pre">' . esc_html( $result['sanitized_text'] ) . '</pre>';
				$output .= '<p class="wm-edu-notice">' . esc_html__( 'Hinweis: Schülernamen werden nur pseudonymisiert, wenn die Schülerliste der Schule als known_students an die REST-Route wm-edu/v1/tokenize übergeben wird. Diese Demo ersetzt Datumsangaben und Klassenbezeichnungen.', 'wm-edu-privacy-portal' ) . '</p>';
			}
			$output .= '</div>';
		}

		$output .= '</section>';
		return $output;
	}

	/**
	 * A box that says why the form is not shown, with a link to the login for visitors who are not logged in.
	 */
	private static function denied_box( string $box_class, string $label, string $icon, string $message ): string {
		$output  = '<section class="wm-edu-box ' . $box_class . '" aria-label="' . esc_attr( $label ) . '">';
		$output .= '<h3 class="wm-edu-title">' . self::icon( $icon ) . ' ' . esc_html( $label ) . '</h3>';
		$output .= '<div class="wm-edu-alert is-blocked" role="alert">' . self::icon( 'block' ) . ' ' . esc_html( $message ) . '</div>';
		if ( ! is_user_logged_in() ) {
			$here    = get_permalink();
			$output .= '<p class="wm-edu-notice"><a href="' . esc_url( wp_login_url( is_string( $here ) && '' !== $here ? $here : home_url( '/' ) ) ) . '">' . esc_html__( 'Zur Anmeldung', 'wm-edu-privacy-portal' ) . '</a></p>';
		}
		return $output . '</section>';
	}

	/**
	 * Consent lookup. Without the attribute `type` the answer covers the newest entry of any consent type; with it, the
	 * newest entry of that type. The form had a "service" field that nothing read until 2026-09-15. While the register
	 * does not verify, no consent is shown, and the form is only shown to those who may use it (class comment).
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function render_consent_gate( array|string $atts = [] ): string {
		self::enqueue_style();

		if ( ! WM_Edu_Access::can_use_gate() ) {
			return self::denied_box(
				'wm-edu-consent-box',
				__( 'Eltern-Einwilligung prüfen', 'wm-edu-privacy-portal' ),
				'form',
				__( 'Diese Abfrage steht angemeldeten Benutzern mit der nötigen Berechtigung offen. Sie gibt preis, ob für eine Schüler-ID eine Einwilligung vorliegt.', 'wm-edu-privacy-portal' )
			);
		}

		$settings = shortcode_atts( [ 'type' => '' ], is_array( $atts ) ? $atts : [], 'wm_edu_consent_gate' );
		$type     = WM_Edu_Consent_Types::normalize_slug( (string) $settings['type'] );
		$type_row = '' === $type ? null : WM_Edu_Consent_Types::get_by_slug( $type );

		$output  = '<section class="wm-edu-box wm-edu-consent-box" aria-label="' . esc_attr__( 'Eltern-Einwilligung prüfen', 'wm-edu-privacy-portal' ) . '">';
		$output .= '<h3 class="wm-edu-title wm-edu-title-gate">' . self::icon( 'form' ) . ' ' . esc_html__( 'Eltern-Einwilligung prüfen', 'wm-edu-privacy-portal' ) . '</h3>';
		$output .= '<p class="wm-edu-intro">' . esc_html__( 'Zeigt den letzten Eintrag im Einwilligungsregister dieser Website für eine Schüler-ID, gleich welcher Einwilligungsart. Solange die Signaturkette des Registers nicht vollständig geprüft werden kann, wird keine Einwilligung angezeigt.', 'wm-edu-privacy-portal' ) . '</p>';
		if ( '' !== $type ) {
			$output .= '<p class="wm-edu-notice">' . esc_html(
				sprintf(
					/* translators: %s: label of the consent type the shortcode asks about. */
					__( 'Geprüft wird nur die Einwilligungsart: %s', 'wm-edu-privacy-portal' ),
					null !== $type_row ? (string) $type_row->label : $type
				)
			) . '</p>';
		}
		$output .= '<form method="post" class="wm-edu-form">';
		$output .= wp_nonce_field( 'wm_edu_gate', 'wm_edu_gate_nonce', true, false );
		$output .= '<label class="wm-edu-field">' . esc_html__( 'Schüler-ID / Token', 'wm-edu-privacy-portal' ) . ' <input type="text" name="edu_student_id" class="wm-edu-input" placeholder="EDU-2026-9B-042" required></label>';
		$output .= '<button type="submit" name="edu_consent_check" value="1" class="wm-edu-button wm-edu-button-gate">' . esc_html__( 'Einwilligung prüfen', 'wm-edu-privacy-portal' ) . '</button>';
		$output .= '</form>';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified below for logged-in users; see the comment there.
		if ( isset( $_POST['edu_consent_check'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$nonce = isset( $_POST['wm_edu_gate_nonce'] ) && is_string( $_POST['wm_edu_gate_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wm_edu_gate_nonce'] ) ) : '';
			// A visitor who is not logged in shares one nonce with every other visitor and loses it to the page cache long
			// before it expires; it is required from logged-in users, who have a session, and the lookup stores nothing.
			if ( is_user_logged_in() && ! wp_verify_nonce( $nonce, 'wm_edu_gate' ) ) {
				return $output . '<div class="wm-edu-alert is-unverifiable" role="alert">' . self::icon( 'alert' ) . ' ' . esc_html__( 'Die Sicherheitsprüfung ist fehlgeschlagen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.', 'wm-edu-privacy-portal' ) . '</div></section>';
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$student_id = isset( $_POST['edu_student_id'] ) && is_string( $_POST['edu_student_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edu_student_id'] ) ) : '';
			$state      = WM_Edu_Consent_Vault::consent_state( $student_id, $type );

			if ( 'granted' === $state ) {
				/* translators: %s: pupil token. */
				$output .= '<div class="wm-edu-alert is-valid" role="alert">' . self::icon( 'check' ) . ' ' . esc_html( sprintf( __( 'GÜLTIGE EINWILLIGUNG: Für %s ist als letzter Eintrag im Register eine erteilte Einwilligung erfasst.', 'wm-edu-privacy-portal' ), $student_id ) ) . '</div>';
			} elseif ( 'unverifiable' === $state ) {
				$output .= '<div class="wm-edu-alert is-unverifiable" role="alert">' . self::icon( 'alert' ) . ' ' . esc_html__( 'REGISTER NICHT PRÜFBAR: Die Einwilligungstabelle fehlt oder ihre Signaturkette ist unterbrochen; es wird keine Einwilligung angezeigt.', 'wm-edu-privacy-portal' ) . '</div>';
			} else {
				/* translators: %s: pupil token. */
				$output .= '<div class="wm-edu-alert is-blocked" role="alert">' . self::icon( 'block' ) . ' ' . esc_html( sprintf( __( 'KEIN NACHWEIS: Für %s liegt kein aktiver Einwilligungsnachweis vor oder er wurde widerrufen.', 'wm-edu-privacy-portal' ), $student_id ) ) . '</div>';
			}
		}

		$output .= '</section>';
		return $output;
	}
}
