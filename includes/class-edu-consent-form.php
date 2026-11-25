<?php
/**
 * WM Edu Privacy Portal — [wm_edu_consent_form], the guardians' consent form
 *
 * Three views on one page: the form (pupil ID, guardian address, grant or deny per active consent type, confirmation
 * of having read the texts), the confirmation page of the mailed link, and the revocation page of the receipt's link.
 * Submissions are processed once on template_redirect, before any output, and answered with a redirect (303) to the
 * same page: rendering the shortcode twice (an SEO plugin building a description, an excerpt) cannot send a mail or
 * record a consent twice, and reloading the result does not submit again.
 *
 * No WordPress nonce on these forms, on purpose. The request form is public and a nonce for visitors who are not
 * logged in is the same for all of them and expires while a page cache keeps serving the form, so it would stop genuine
 * submissions and not a single scripted one; the form is rate-limited and only mails a registered address instead. The
 * confirmation and revocation forms carry the link's 64-character token, which is the secret a forged POST lacks.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Consent_Form {

	public const SHORTCODE    = 'wm_edu_consent_form';
	public const STATUS_PARAM = 'wm_edu_status';

	/**
	 * Result of the POST processed on template_redirect, read by render(): notice code, field errors and the submitted
	 * values to show again.
	 *
	 * @var array{notice: string, errors: array<string, string>, values: array<string, mixed>}
	 */
	private static array $state = [
		'notice' => '',
		'errors' => [],
		'values' => [],
	];

	/** Suffix for element ids, so that two forms on one page do not share an id. */
	private static int $instance = 0;

	public static function init(): void {
		add_shortcode( 'wm_edu_consent_form', [ self::class, 'render' ] );
		add_action( 'template_redirect', [ self::class, 'handle_request' ] );
	}

	/**
	 * Resets the processed state; for the standalone test suite, which renders many requests in one process.
	 */
	public static function reset(): void {
		self::$state    = [
			'notice' => '',
			'errors' => [],
			'values' => [],
		];
		self::$instance = 0;
	}

	// -----------------------------------------------------------------------------------------------------------------
	// Processing (template_redirect)
	// -----------------------------------------------------------------------------------------------------------------

	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- see the class comment: a public, rate-limited form, and token-bearing links whose token is the secret.

	public static function handle_request(): void {
		if ( isset( $_GET[ WM_Edu_Consent_Requests::CONFIRM_PARAM ] ) || isset( $_GET[ WM_Edu_Consent_Requests::REVOKE_PARAM ] ) ) {
			// A page that carries a one-time token: never cached, and the token never leaves in a Referer header.
			add_filter( 'nocache_headers', [ self::class, 'private_link_headers' ] );
			nocache_headers();
		}
		$action = isset( $_POST['wm_edu_action'] ) && is_string( $_POST['wm_edu_action'] ) ? sanitize_key( wp_unslash( $_POST['wm_edu_action'] ) ) : '';
		if ( 'request' === $action ) {
			self::handle_submission( self::page_url() );
		} elseif ( 'confirm' === $action || 'revoke' === $action ) {
			self::handle_link_action( $action, self::page_url() );
		}
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	public static function private_link_headers( array $headers ): array {
		$headers['Referrer-Policy'] = 'no-referrer';
		return $headers;
	}

	private static function handle_submission( string $page ): void {
		$values = [
			'student'   => self::posted_text( 'wm_edu_student_id' ),
			'email'     => self::posted_text( 'wm_edu_email' ),
			'decisions' => isset( $_POST['wm_edu_decision'] ) && is_array( $_POST['wm_edu_decision'] ) ? array_map( static fn( $v ): string => is_string( $v ) ? sanitize_key( $v ) : '', wp_unslash( $_POST['wm_edu_decision'] ) ) : [], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value passes sanitize_key(); only "grant" and "deny" are read.
			'read'      => isset( $_POST['wm_edu_read'] ) && '1' === $_POST['wm_edu_read'],
		];
		// Counted before anything else, so a refused or unregistered submission uses up the limit like any other.
		if ( WM_Edu_Consent_Requests::rate_limited( WM_Edu_Consent_Requests::client_ip(), $values['email'] ) ) {
			self::$state['notice'] = 'limited';
			self::$state['values'] = $values;
			status_header( 429 );
			return;
		}
		// Filled only by programs that fill every field; answered like a successful submission and otherwise ignored.
		if ( '' !== self::posted_text( 'wm_edu_website' ) ) {
			self::redirect( $page, 'requested' );
			return;
		}
		$checked = self::validate( $values, WM_Edu_Consent_Types::active() );
		if ( [] !== $checked['errors'] ) {
			self::$state['errors'] = $checked['errors'];
			self::$state['values'] = $values;
			return;
		}
		WM_Edu_Consent_Requests::submit( $checked['student'], $checked['email'], $checked['decisions'], $page );
		self::redirect( $page, 'requested' );
	}

	private static function handle_link_action( string $action, string $page ): void {
		$token = self::posted_text( 'wm_edu_token' );
		if ( 'confirm' === $action && ! ( isset( $_POST['wm_edu_read_confirm'] ) && '1' === $_POST['wm_edu_read_confirm'] ) ) {
			self::$state['notice'] = 'read_required';
			return;
		}
		$outcome = 'confirm' === $action
			? WM_Edu_Consent_Requests::confirm( $token, WM_Edu_Consent_Requests::client_ip(), $page )
			: WM_Edu_Consent_Requests::revoke( $token, WM_Edu_Consent_Requests::client_ip() );
		if ( 'confirmed' === $outcome || 'revoked' === $outcome ) {
			self::redirect( $page, $outcome );
			return;
		}
		if ( 'busy' === $outcome ) {
			status_header( 503 );
			if ( ! headers_sent() ) {
				header( 'Retry-After: 30' );
			}
		}
		self::$state['notice'] = 'invalid' === $outcome ? 'invalid_link' : $outcome;
	}

	/**
	 * Checks a submission against the active types. Returns the field errors and, when there are none, the pupil ID, the
	 * address and the decisions (slug, GRANTED or DENIED, the type's current scope hash, its label). A type without a
	 * choice is left out; at least one choice is required.
	 *
	 * @param array<string, mixed> $values  student, email, decisions (slug => "grant"|"deny"), read
	 * @param object[]             $types   Active consent types.
	 * @return array{errors: array<string, string>, student: string, email: string, decisions: array<int, array{slug: string, status: string, scope_hash: string, label: string}>}
	 */
	public static function validate( array $values, array $types ): array {
		$errors  = [];
		$student = WM_Edu_Guardians::normalize_student( (string) ( $values['student'] ?? '' ) );
		$email   = WM_Edu_Guardians::normalize_email( (string) ( $values['email'] ?? '' ) );
		if ( null === $student ) {
			$errors['student'] = __( 'Bitte geben Sie die Schüler-ID an, die Sie von der Schule erhalten haben (höchstens 64 Zeichen).', 'wm-edu-privacy-portal' );
		}
		if ( null === $email ) {
			$errors['email'] = __( 'Bitte geben Sie eine gültige E-Mail-Adresse an.', 'wm-edu-privacy-portal' );
		}
		$choices   = is_array( $values['decisions'] ?? null ) ? $values['decisions'] : [];
		$decisions = [];
		foreach ( $types as $type ) {
			$choice = $choices[ (string) $type->slug ] ?? '';
			if ( 'grant' === $choice || 'deny' === $choice ) {
				$decisions[] = [
					'slug'       => (string) $type->slug,
					'status'     => 'grant' === $choice ? 'GRANTED' : 'DENIED',
					'scope_hash' => (string) $type->scope_hash,
					'label'      => (string) $type->label,
				];
			}
		}
		if ( [] === $decisions ) {
			$errors['decisions'] = __( 'Bitte wählen Sie für mindestens eine Einwilligung „erteilen“ oder „verweigern“.', 'wm-edu-privacy-portal' );
		}
		if ( true !== ( $values['read'] ?? false ) ) {
			$errors['read'] = __( 'Bitte bestätigen Sie, dass Sie den Wortlaut der gewählten Einwilligungen gelesen haben.', 'wm-edu-privacy-portal' );
		}
		return [
			'errors'    => $errors,
			'student'   => (string) $student,
			'email'     => (string) $email,
			'decisions' => $decisions,
		];
	}

	private static function posted_text( string $field ): string {
		return isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ) : '';
	}

	private static function query_text( string $param ): string {
		return isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) ) : '';
	}

	/**
	 * The page the form was used on, without the plugin's own query arguments; the mailed links lead back to it.
	 */
	private static function page_url(): string {
		$id  = (int) get_queried_object_id();
		$url = $id > 0 ? get_permalink( $id ) : false;
		$url = is_string( $url ) && '' !== $url ? $url : home_url( '/' );
		return remove_query_arg( [ self::STATUS_PARAM, WM_Edu_Consent_Requests::CONFIRM_PARAM, WM_Edu_Consent_Requests::REVOKE_PARAM ], $url );
	}

	private static function redirect( string $page, string $status ): void {
		wp_safe_redirect( add_query_arg( self::STATUS_PARAM, $status, $page ), 303 );
		exit;
	}

	// -----------------------------------------------------------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------------------------------------------------------

	/**
	 * @param array<string, string>|string $atts Not used; typed array|string because WordPress before 6.5 passes ''.
	 */
	public static function render( array|string $atts = [] ): string {
		WM_Edu_Shortcodes::enqueue_style();
		$suffix = '-' . ( ++self::$instance );

		$output = '<section class="wm-edu-box wm-edu-consent-form-box" aria-labelledby="wm-edu-form-title' . $suffix . '">';
		$output .= self::notice();

		$confirm = self::query_text( WM_Edu_Consent_Requests::CONFIRM_PARAM );
		$revoke  = self::query_text( WM_Edu_Consent_Requests::REVOKE_PARAM );
		$status  = self::query_text( self::STATUS_PARAM );
		if ( '' !== $confirm ) {
			$output .= self::view_confirm( $confirm, $suffix );
		} elseif ( '' !== $revoke ) {
			$output .= self::view_revoke( $revoke, $suffix );
		} elseif ( in_array( $status, [ 'requested', 'confirmed', 'revoked' ], true ) && '' === self::$state['notice'] && [] === self::$state['errors'] ) {
			$output .= self::view_status( $status, $suffix );
		} else {
			$output .= self::view_request( $suffix );
		}
		return $output . '</section>';
	}

	private static function title( string $text, string $icon, string $suffix ): string {
		return '<h3 class="wm-edu-title" id="wm-edu-form-title' . esc_attr( $suffix ) . '">' . WM_Edu_Shortcodes::icon( $icon ) . ' ' . esc_html( $text ) . '</h3>';
	}

	private static function notice(): string {
		$messages = [
			'limited'       => __( 'Es wurden in kurzer Zeit zu viele Anfragen gesendet. Bitte versuchen Sie es in einer Stunde erneut.', 'wm-edu-privacy-portal' ),
			'busy'          => __( 'Ihre Angaben konnten gerade nicht gespeichert werden. Bitte versuchen Sie es in einigen Minuten erneut; der Link bleibt gültig.', 'wm-edu-privacy-portal' ),
			'invalid_link'  => __( 'Dieser Link ist ungültig, abgelaufen oder wurde bereits verwendet.', 'wm-edu-privacy-portal' ),
			'error'         => __( 'Ihre Angaben konnten nicht gespeichert werden. Bitte wenden Sie sich an die Schule.', 'wm-edu-privacy-portal' ),
			'read_required' => __( 'Bitte bestätigen Sie, dass Sie den Wortlaut gelesen haben.', 'wm-edu-privacy-portal' ),
		];
		$code = self::$state['notice'];
		if ( ! isset( $messages[ $code ] ) ) {
			return '';
		}
		return '<div class="wm-edu-alert is-blocked" role="alert">' . WM_Edu_Shortcodes::icon( 'alert' ) . ' ' . esc_html( $messages[ $code ] ) . '</div>';
	}

	private static function view_status( string $status, string $suffix ): string {
		$messages = [
			'requested' => __( 'Vielen Dank. Wenn die Schüler-ID und die E-Mail-Adresse zu den Angaben der Schule passen, erhalten Sie in Kürze eine E-Mail mit einem Bestätigungslink. Erst mit der Bestätigung wird Ihre Entscheidung erfasst.', 'wm-edu-privacy-portal' ),
			'confirmed' => __( 'Ihre Angaben wurden erfasst. Sie erhalten eine Bestätigung per E-Mail; erteilte Einwilligungen können Sie über den Link darin jederzeit widerrufen.', 'wm-edu-privacy-portal' ),
			'revoked'   => __( 'Ihr Widerruf wurde erfasst. Sie erhalten eine Bestätigung per E-Mail.', 'wm-edu-privacy-portal' ),
		];
		return self::title( __( 'Einwilligungen der Erziehungsberechtigten', 'wm-edu-privacy-portal' ), 'form', $suffix )
			. '<div class="wm-edu-alert is-valid" role="status">' . WM_Edu_Shortcodes::icon( 'check' ) . ' ' . esc_html( $messages[ $status ] ) . '</div>';
	}

	/**
	 * The wording a scope hash stands for, from the text archive, as a disclosure; empty when the archive lacks it.
	 */
	private static function wording( string $scope_hash ): string {
		$text = WM_Edu_Consent_Types::text_for_scope( $scope_hash );
		if ( null === $text ) {
			return '';
		}
		return '<details class="wm-edu-details"><summary class="wm-edu-summary">' . esc_html__( 'Wortlaut der Einwilligung', 'wm-edu-privacy-portal' ) . '</summary><div class="wm-edu-consent-text">' . esc_html( $text ) . '</div></details>';
	}

	private static function field_error( string $key, string $id ): string {
		return isset( self::$state['errors'][ $key ] ) ? '<p class="wm-edu-error" id="' . esc_attr( $id ) . '-error">' . esc_html( self::$state['errors'][ $key ] ) . '</p>' : '';
	}

	private static function invalid_attrs( string $key, string $id ): string {
		return isset( self::$state['errors'][ $key ] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : '';
	}

	private static function view_request( string $suffix ): string {
		$types  = WM_Edu_Consent_Types::active();
		$output = self::title( __( 'Einwilligungen erteilen oder verweigern', 'wm-edu-privacy-portal' ), 'form', $suffix );
		if ( [] === $types ) {
			return $output . '<p class="wm-edu-notice">' . esc_html__( 'Derzeit können über dieses Formular keine Einwilligungen erteilt werden.', 'wm-edu-privacy-portal' ) . '</p>';
		}
		$output .= '<p class="wm-edu-intro">' . esc_html__( 'Geben Sie die Schüler-ID an, die Sie von der Schule erhalten haben, und die E-Mail-Adresse, die die Schule von Ihnen kennt. Sie erhalten eine E-Mail mit einem Bestätigungslink; erst mit der Bestätigung wird Ihre Entscheidung erfasst.', 'wm-edu-privacy-portal' ) . '</p>';

		$values = self::$state['values'];
		$errors = self::$state['errors'];
		$ids    = [
			'student'   => 'wm-edu-student' . $suffix,
			'email'     => 'wm-edu-email' . $suffix,
			'decisions' => 'wm-edu-decisions' . $suffix,
			'read'      => 'wm-edu-read' . $suffix,
		];
		if ( [] !== $errors ) {
			$output .= '<div class="wm-edu-alert is-blocked" role="alert"><p class="wm-edu-alert-title">' . esc_html__( 'Bitte prüfen Sie Ihre Angaben:', 'wm-edu-privacy-portal' ) . '</p><ul class="wm-edu-error-list">';
			foreach ( $errors as $key => $message ) {
				$output .= '<li><a href="#' . esc_attr( $ids[ $key ] ?? '' ) . '">' . esc_html( $message ) . '</a></li>';
			}
			$output .= '</ul></div>';
		}

		$output .= '<form method="post" class="wm-edu-form">';
		$output .= '<input type="hidden" name="wm_edu_action" value="request">';
		$output .= '<label class="wm-edu-field" for="' . esc_attr( $ids['student'] ) . '">' . esc_html__( 'Schüler-ID', 'wm-edu-privacy-portal' )
			. ' <input type="text" id="' . esc_attr( $ids['student'] ) . '" name="wm_edu_student_id" class="wm-edu-input" maxlength="64" autocomplete="off" required value="' . esc_attr( (string) ( $values['student'] ?? '' ) ) . '"' . self::invalid_attrs( 'student', $ids['student'] ) . '></label>'
			. self::field_error( 'student', $ids['student'] );
		$output .= '<label class="wm-edu-field" for="' . esc_attr( $ids['email'] ) . '">' . esc_html__( 'E-Mail-Adresse', 'wm-edu-privacy-portal' )
			. ' <input type="email" id="' . esc_attr( $ids['email'] ) . '" name="wm_edu_email" class="wm-edu-input" maxlength="190" autocomplete="email" required value="' . esc_attr( (string) ( $values['email'] ?? '' ) ) . '"' . self::invalid_attrs( 'email', $ids['email'] ) . '></label>'
			. self::field_error( 'email', $ids['email'] );

		$output .= '<fieldset class="wm-edu-fieldset" id="' . esc_attr( $ids['decisions'] ) . '"' . ( isset( $errors['decisions'] ) ? ' aria-describedby="' . esc_attr( $ids['decisions'] ) . '-error"' : '' ) . '><legend class="wm-edu-legend">' . esc_html__( 'Ihre Entscheidungen', 'wm-edu-privacy-portal' ) . '</legend>';
		$output .= self::field_error( 'decisions', $ids['decisions'] );
		$chosen  = is_array( $values['decisions'] ?? null ) ? $values['decisions'] : [];
		foreach ( $types as $type ) {
			$slug    = (string) $type->slug;
			$name    = 'wm_edu_decision[' . $slug . ']';
			$choice  = $chosen[ $slug ] ?? '';
			$output .= '<fieldset class="wm-edu-type"><legend class="wm-edu-legend">' . esc_html( (string) $type->label ) . '</legend>';
			if ( '' !== (string) $type->description ) {
				$output .= '<p class="wm-edu-type-desc">' . esc_html( (string) $type->description ) . '</p>';
			}
			$output .= self::wording( (string) $type->scope_hash );
			$output .= '<label class="wm-edu-choice"><input type="radio" name="' . esc_attr( $name ) . '" value="grant"' . ( 'grant' === $choice ? ' checked' : '' ) . '> ' . esc_html__( 'Einwilligung erteilen', 'wm-edu-privacy-portal' ) . '</label>';
			$output .= '<label class="wm-edu-choice"><input type="radio" name="' . esc_attr( $name ) . '" value="deny"' . ( 'deny' === $choice ? ' checked' : '' ) . '> ' . esc_html__( 'Einwilligung verweigern', 'wm-edu-privacy-portal' ) . '</label>';
			$output .= '</fieldset>';
		}
		$output .= '</fieldset>';

		$output .= '<label class="wm-edu-choice" for="' . esc_attr( $ids['read'] ) . '"><input type="checkbox" id="' . esc_attr( $ids['read'] ) . '" name="wm_edu_read" value="1" required' . ( true === ( $values['read'] ?? false ) ? ' checked' : '' ) . self::invalid_attrs( 'read', $ids['read'] ) . '> ' . esc_html__( 'Ich habe den Wortlaut der gewählten Einwilligungen gelesen.', 'wm-edu-privacy-portal' ) . '</label>'
			. self::field_error( 'read', $ids['read'] );

		// Honeypot: visually hidden and out of the tab order; people leave it empty.
		$output .= '<p class="wm-edu-hp" aria-hidden="true"><label for="wm-edu-website' . esc_attr( $suffix ) . '">' . esc_html__( 'Website', 'wm-edu-privacy-portal' ) . ' <input type="text" id="wm-edu-website' . esc_attr( $suffix ) . '" name="wm_edu_website" value="" tabindex="-1" autocomplete="off"></label></p>';
		$output .= '<button type="submit" class="wm-edu-button">' . esc_html__( 'Angaben absenden', 'wm-edu-privacy-portal' ) . '</button>';
		return $output . '</form>';
	}

	private static function view_confirm( string $token, string $suffix ): string {
		$request = WM_Edu_Consent_Requests::confirmable( $token );
		$output  = self::title( __( 'Angaben bestätigen', 'wm-edu-privacy-portal' ), 'form', $suffix );
		if ( null === $request ) {
			return $output . ( '' === self::$state['notice'] ? '<div class="wm-edu-alert is-blocked" role="alert">' . WM_Edu_Shortcodes::icon( 'alert' ) . ' ' . esc_html__( 'Dieser Link ist ungültig, abgelaufen oder wurde bereits verwendet.', 'wm-edu-privacy-portal' ) . '</div>' : '' );
		}
		$output .= '<p class="wm-edu-intro">' . esc_html(
			sprintf(
				/* translators: %s: pupil ID. */
				__( 'Für die Schüler-ID %s wurden folgende Angaben gemacht. Mit Ihrer Bestätigung werden sie im Einwilligungsregister der Schule erfasst.', 'wm-edu-privacy-portal' ),
				(string) $request->student_token
			)
		) . '</p><ul class="wm-edu-decisions">';
		foreach ( WM_Edu_Consent_Requests::decisions( $request ) as $decision ) {
			$output .= '<li class="wm-edu-decision"><strong>' . esc_html( (string) $decision['label'] ) . '</strong>: '
				. ( 'GRANTED' === $decision['status'] ? esc_html__( 'Einwilligung erteilen', 'wm-edu-privacy-portal' ) : esc_html__( 'Einwilligung verweigern', 'wm-edu-privacy-portal' ) )
				. self::wording( (string) $decision['scope_hash'] ) . '</li>';
		}
		$output .= '</ul><form method="post" class="wm-edu-form">';
		$output .= '<input type="hidden" name="wm_edu_action" value="confirm"><input type="hidden" name="wm_edu_token" value="' . esc_attr( $token ) . '">';
		$output .= '<label class="wm-edu-choice" for="wm-edu-read-confirm' . esc_attr( $suffix ) . '"><input type="checkbox" id="wm-edu-read-confirm' . esc_attr( $suffix ) . '" name="wm_edu_read_confirm" value="1" required> ' . esc_html__( 'Ich habe den Wortlaut gelesen und bestätige diese Angaben.', 'wm-edu-privacy-portal' ) . '</label>';
		$output .= '<button type="submit" class="wm-edu-button">' . esc_html__( 'Angaben bestätigen', 'wm-edu-privacy-portal' ) . '</button>';
		return $output . '</form>';
	}

	private static function view_revoke( string $token, string $suffix ): string {
		$request = WM_Edu_Consent_Requests::revocable( $token );
		$output  = self::title( __( 'Einwilligungen widerrufen', 'wm-edu-privacy-portal' ), 'block', $suffix );
		$rows    = null === $request ? [] : WM_Edu_Consent_Requests::granted_rows( $request );
		if ( null === $request || [] === $rows ) {
			return $output . ( '' === self::$state['notice'] ? '<div class="wm-edu-alert is-blocked" role="alert">' . WM_Edu_Shortcodes::icon( 'alert' ) . ' ' . esc_html__( 'Dieser Link ist ungültig, abgelaufen oder wurde bereits verwendet.', 'wm-edu-privacy-portal' ) . '</div>' : '' );
		}
		$output .= '<p class="wm-edu-intro">' . esc_html(
			sprintf(
				/* translators: %s: pupil ID. */
				__( 'Für die Schüler-ID %s können Sie folgende erteilte Einwilligungen widerrufen. Der Widerruf wird im Einwilligungsregister der Schule erfasst.', 'wm-edu-privacy-portal' ),
				(string) $rows[0]->student_token
			)
		) . '</p><ul class="wm-edu-decisions">';
		foreach ( $rows as $row ) {
			$type    = WM_Edu_Consent_Types::get_by_slug( (string) $row->consent_type );
			$output .= '<li class="wm-edu-decision">' . esc_html( null !== $type ? (string) $type->label : (string) $row->consent_type ) . '</li>';
		}
		$output .= '</ul><form method="post" class="wm-edu-form">';
		$output .= '<input type="hidden" name="wm_edu_action" value="revoke"><input type="hidden" name="wm_edu_token" value="' . esc_attr( $token ) . '">';
		$output .= '<button type="submit" class="wm-edu-button">' . esc_html__( 'Einwilligungen widerrufen', 'wm-edu-privacy-portal' ) . '</button>';
		return $output . '</form>';
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
}
