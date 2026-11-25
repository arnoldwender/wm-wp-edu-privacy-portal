<?php
/**
 * WM Edu Privacy Portal — admin screen
 *
 * Five pages under one menu, all behind manage_edu_consents except the settings, which need manage_options because they
 * switch public access on and off:
 *
 *   Übersicht              current state per pupil ID and consent type, history of one pupil, chain verification, CSV export
 *   Erfassen               a consent recorded by hand, with the reason, and the revocation of one that is on file
 *   Einwilligungsarten     label, description, the exact text (its SHA-256 is the scope hash) and whether it is active
 *   Erziehungsberechtigte  the pupil ID / address pairs the guardian form answers to
 *   Einstellungen          public access, gate roles, link validity, retention, rate limits
 *
 * Every state-changing page posts to admin-post.php with a nonce and is answered with a redirect carrying a notice code,
 * never the submitted text: nothing a form sent is reflected into the next page. The work itself is done by the
 * process_* methods, which take the submitted array and return that code, so they can be exercised without WordPress.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Admin {

	public const MENU_SLUG     = 'wm-edu-consents';
	public const ENTRY_SLUG    = 'wm-edu-consents-entry';
	public const TYPES_SLUG    = 'wm-edu-consents-types';
	public const GUARDIAN_SLUG = 'wm-edu-consents-guardians';
	public const SETTINGS_SLUG = 'wm-edu-consents-settings';

	/** Column separator of the export: German spreadsheet settings split on the semicolon. */
	public const CSV_SEPARATOR = ';';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		foreach ( [ 'record', 'revoke', 'type', 'guardian', 'settings', 'export' ] as $action ) {
			add_action( 'admin_post_wm_edu_' . $action, [ self::class, 'handle_' . $action ] );
		}
	}

	public static function register_settings(): void {
		register_setting(
			'wm_edu_settings_group',
			'wm_edu_delete_data_on_uninstall',
			[
				'type'              => 'string',
				'sanitize_callback' => [ 'WM_Edu_Settings', 'sanitize_delete_flag' ],
				'default'           => '0',
			]
		);
	}

	public static function menu(): void {
		$cap = WM_Edu_Access::CAPABILITY;
		add_menu_page( __( 'Einwilligungen', 'wm-edu-privacy-portal' ), __( 'Einwilligungen', 'wm-edu-privacy-portal' ), $cap, self::MENU_SLUG, [ self::class, 'page_overview' ], 'dashicons-clipboard', 81 );
		add_submenu_page( self::MENU_SLUG, __( 'Übersicht', 'wm-edu-privacy-portal' ), __( 'Übersicht', 'wm-edu-privacy-portal' ), $cap, self::MENU_SLUG, [ self::class, 'page_overview' ] );
		add_submenu_page( self::MENU_SLUG, __( 'Erfassen', 'wm-edu-privacy-portal' ), __( 'Erfassen', 'wm-edu-privacy-portal' ), $cap, self::ENTRY_SLUG, [ self::class, 'page_entry' ] );
		add_submenu_page( self::MENU_SLUG, __( 'Einwilligungsarten', 'wm-edu-privacy-portal' ), __( 'Einwilligungsarten', 'wm-edu-privacy-portal' ), $cap, self::TYPES_SLUG, [ self::class, 'page_types' ] );
		add_submenu_page( self::MENU_SLUG, __( 'Erziehungsberechtigte', 'wm-edu-privacy-portal' ), __( 'Erziehungsberechtigte', 'wm-edu-privacy-portal' ), $cap, self::GUARDIAN_SLUG, [ self::class, 'page_guardians' ] );
		add_submenu_page( self::MENU_SLUG, __( 'Einstellungen', 'wm-edu-privacy-portal' ), __( 'Einstellungen', 'wm-edu-privacy-portal' ), WM_Edu_Access::SETTINGS_CAPABILITY, self::SETTINGS_SLUG, [ self::class, 'page_settings' ] );
	}

	// -----------------------------------------------------------------------------------------------------------------
	// Work: one method per action, without WordPress output
	// -----------------------------------------------------------------------------------------------------------------

	/**
	 * Records a consent by hand. The reason is required and is signed with the row; the consent type must exist, and the
	 * scope hash is the type's current one. Returns a notice code.
	 *
	 * @param array<string, mixed> $post
	 */
	public static function process_record( array $post, int $user_id, string $ip ): string {
		$type = WM_Edu_Consent_Types::get_by_slug( is_string( $post['type'] ?? null ) ? $post['type'] : '' );
		if ( null === $type ) {
			return 'type_unknown';
		}
		$status = is_string( $post['status'] ?? null ) ? $post['status'] : '';
		if ( ! in_array( $status, WM_Edu_Consent_Vault::STATUSES, true ) ) {
			return 'status_invalid';
		}
		$result = WM_Edu_Consent_Vault::append_consent(
			[
				'student_token'  => is_string( $post['student_token'] ?? null ) ? $post['student_token'] : '',
				'parent_email'   => is_string( $post['parent_email'] ?? null ) ? $post['parent_email'] : '',
				'consent_type'   => (string) $type->slug,
				'scope_hash'     => (string) $type->scope_hash,
				'granted_status' => $status,
				'ip_address'     => $ip,
				'source'         => 'admin_entry',
				'actor_id'       => $user_id,
				'note'           => is_string( $post['note'] ?? null ) ? $post['note'] : '',
			]
		);
		return $result instanceof WP_Error ? self::error_notice( $result ) : 'recorded';
	}

	/**
	 * Records a revocation for a pupil ID and type that has an entry. The pupil's address and the scope come from the
	 * newest row, so the revocation says what it revokes; the reason is required.
	 *
	 * @param array<string, mixed> $post
	 */
	public static function process_revoke( array $post, int $user_id, string $ip ): string {
		$student = is_string( $post['student_token'] ?? null ) ? trim( sanitize_text_field( $post['student_token'] ) ) : '';
		$type    = is_string( $post['type'] ?? null ) ? WM_Edu_Consent_Types::normalize_slug( $post['type'] ) : '';
		$newest  = null;
		foreach ( WM_Edu_Consent_Vault::rows_for_student( $student ) as $row ) {
			if ( $type === (string) $row->consent_type ) {
				$newest = $row;
			}
		}
		if ( null === $newest ) {
			return 'entry_unknown';
		}
		$result = WM_Edu_Consent_Vault::append_consent(
			[
				'student_token'  => (string) $newest->student_token,
				'parent_email'   => (string) $newest->parent_email,
				'consent_type'   => (string) $newest->consent_type,
				'scope_hash'     => (string) $newest->scope_hash,
				'granted_status' => 'REVOKED',
				'ip_address'     => $ip,
				'source'         => 'admin_revocation',
				'actor_id'       => $user_id,
				'note'           => is_string( $post['note'] ?? null ) ? $post['note'] : '',
			]
		);
		return $result instanceof WP_Error ? self::error_notice( $result ) : 'revoked';
	}

	/**
	 * @param array<string, mixed> $post
	 */
	public static function process_type( array $post ): string {
		$result = WM_Edu_Consent_Types::save(
			[
				'slug'         => is_string( $post['slug'] ?? null ) ? $post['slug'] : '',
				'label'        => is_string( $post['label'] ?? null ) ? $post['label'] : '',
				'description'  => is_string( $post['description'] ?? null ) ? $post['description'] : '',
				'consent_text' => is_string( $post['consent_text'] ?? null ) ? $post['consent_text'] : '',
				'active'       => '1' === ( $post['active'] ?? '' ),
			],
			isset( $post['id'] ) ? (int) $post['id'] : 0
		);
		if ( ! ( $result instanceof WP_Error ) ) {
			return isset( $post['id'] ) && (int) $post['id'] > 0 ? 'type_updated' : 'type_created';
		}
		return 'wm_edu_type_exists' === $result->get_error_code() ? 'type_exists' : 'type_invalid';
	}

	/**
	 * @param array<string, mixed> $post
	 */
	public static function process_guardian( array $post ): string {
		$what    = is_string( $post['what'] ?? null ) ? $post['what'] : '';
		$student = is_string( $post['student_token'] ?? null ) ? $post['student_token'] : '';
		$email   = is_string( $post['email'] ?? null ) ? $post['email'] : '';
		if ( 'import' === $what ) {
			$result = WM_Edu_Guardians::import( is_string( $post['pairs'] ?? null ) ? $post['pairs'] : '' );
			return 0 === $result['added'] && [] === $result['invalid'] ? 'guardians_none' : ( [] === $result['invalid'] ? 'guardians_imported' : 'guardians_partly' );
		}
		if ( 'remove' === $what ) {
			return WM_Edu_Guardians::remove( $student, $email ) > 0 ? 'guardian_removed' : 'guardian_unknown';
		}
		if ( 'remove_student' === $what ) {
			return WM_Edu_Guardians::remove_student( $student ) > 0 ? 'guardian_removed' : 'guardian_unknown';
		}
		return [
			'added'   => 'guardian_added',
			'exists'  => 'guardian_exists',
			'invalid' => 'guardian_invalid',
		][ WM_Edu_Guardians::add( $student, $email ) ];
	}

	/**
	 * @param array<string, mixed> $post
	 */
	public static function process_settings( array $post ): string {
		WM_Edu_Settings::save( $post, array_keys( wp_roles()->role_objects ) );
		update_option(
			'wm_edu_delete_data_on_uninstall',
			WM_Edu_Settings::sanitize_delete_flag( $post['wm_edu_delete_data_on_uninstall'] ?? '0' ),
			false
		);
		return 'settings_saved';
	}

	private static function error_notice( WP_Error $error ): string {
		return [
			'wm_edu_consent_invalid'       => 'entry_invalid',
			'wm_edu_chain_busy'            => 'ledger_busy',
			'wm_edu_consent_table_missing' => 'ledger_missing',
		][ $error->get_error_code() ] ?? 'ledger_error';
	}

	// -----------------------------------------------------------------------------------------------------------------
	// CSV export
	// -----------------------------------------------------------------------------------------------------------------

	/**
	 * The ledger as a semicolon-separated CSV with a UTF-8 byte order mark (spreadsheets in German settings open it
	 * without further questions). Every cell is quoted, quotes inside are doubled, and a cell that begins with a
	 * character a spreadsheet would read as a formula is prefixed with an apostrophe (CWE-1236, the characters OWASP
	 * lists: = + - @ tab CR LF).
	 *
	 * @param object[] $rows
	 */
	public static function csv( array $rows ): string {
		$columns = [ 'id', 'created_at', 'student_token', 'parent_email', 'consent_type', 'granted_status', 'scope_hash', 'source', 'actor_id', 'note', 'ip_hmac', 'key_id', 'row_version', 'prev_hash', 'signature_hash' ];
		$lines   = [ self::csv_line( $columns ) ];
		foreach ( $rows as $row ) {
			$cells = [];
			foreach ( $columns as $column ) {
				$cells[] = (string) ( ( (array) $row )[ $column ] ?? '' );
			}
			$lines[] = self::csv_line( $cells );
		}
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * @param string[] $cells
	 */
	private static function csv_line( array $cells ): string {
		$quoted = array_map(
			static fn( string $cell ): string => '"' . str_replace( '"', '""', WM_Edu_PII_Tokenizer::sanitize_formula( $cell ) ) . '"',
			$cells
		);
		return implode( self::CSV_SEPARATOR, $quoted );
	}

	// -----------------------------------------------------------------------------------------------------------------
	// Handlers (admin-post.php)
	// -----------------------------------------------------------------------------------------------------------------

	private static function guard( string $action, string $capability = WM_Edu_Access::CAPABILITY ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'Sie haben keine Berechtigung für diese Aktion.', 'wm-edu-privacy-portal' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the page that submitted, with the notice code only: no submitted text travels in the URL.
	 */
	private static function finish( string $page, string $notice ): void {
		wp_safe_redirect( add_query_arg( [ 'page' => $page, 'wm_edu_notice' => $notice ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_record(): void {
		self::guard( 'wm_edu_record' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in guard(); every value is sanitised in the process_* method and in the ledger.
		self::finish( self::ENTRY_SLUG, self::process_record( wp_unslash( $_POST ), get_current_user_id(), WM_Edu_Consent_Requests::client_ip() ) );
	}

	public static function handle_revoke(): void {
		self::guard( 'wm_edu_revoke' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in guard(); every value is sanitised in the process_* method and in the ledger.
		self::finish( self::ENTRY_SLUG, self::process_revoke( wp_unslash( $_POST ), get_current_user_id(), WM_Edu_Consent_Requests::client_ip() ) );
	}

	public static function handle_type(): void {
		self::guard( 'wm_edu_type' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in guard(); every value is sanitised in the process_* method and in the ledger.
		self::finish( self::TYPES_SLUG, self::process_type( wp_unslash( $_POST ) ) );
	}

	public static function handle_guardian(): void {
		self::guard( 'wm_edu_guardian' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in guard(); every value is sanitised in the process_* method and in the ledger.
		self::finish( self::GUARDIAN_SLUG, self::process_guardian( wp_unslash( $_POST ) ) );
	}

	public static function handle_settings(): void {
		self::guard( 'wm_edu_settings', WM_Edu_Access::SETTINGS_CAPABILITY );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in guard(); every value is sanitised in the process_* method and in the ledger.
		self::finish( self::SETTINGS_SLUG, self::process_settings( wp_unslash( $_POST ) ) );
	}

	/**
	 * Sends the ledger as a CSV download. The file is not HTML, so there is no escaping function for it; the cells are
	 * quoted and formula-neutralised in csv().
	 */
	public static function handle_export(): void {
		self::guard( 'wm_edu_export' );
		$csv = self::csv( WM_Edu_Consent_Vault::all_rows() );
		$csv = (string) apply_filters( 'wm_edu_export_csv_output', $csv );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=einwilligungen-' . gmdate( 'Y-m-d-His' ) . '.csv' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo (string) apply_filters( 'wm_edu_export_csv_stream', $csv ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV body, quoted and formula-neutralised in csv(); HTML escaping would corrupt it.
		exit;
	}

	// -----------------------------------------------------------------------------------------------------------------
	// Pages
	// -----------------------------------------------------------------------------------------------------------------

	/**
	 * The notice of the last action, by code: the page prints its own text, never what a form sent.
	 */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a code from our own redirect; only a known code prints anything.
		$code = isset( $_GET['wm_edu_notice'] ) && is_string( $_GET['wm_edu_notice'] ) ? sanitize_key( wp_unslash( $_GET['wm_edu_notice'] ) ) : '';
		$good = [
			'recorded'           => __( 'Die Einwilligung wurde erfasst.', 'wm-edu-privacy-portal' ),
			'revoked'            => __( 'Der Widerruf wurde erfasst.', 'wm-edu-privacy-portal' ),
			'type_created'       => __( 'Die Einwilligungsart wurde angelegt.', 'wm-edu-privacy-portal' ),
			'type_updated'       => __( 'Die Einwilligungsart wurde gespeichert.', 'wm-edu-privacy-portal' ),
			'guardian_added'     => __( 'Die Zuordnung wurde gespeichert.', 'wm-edu-privacy-portal' ),
			'guardian_removed'   => __( 'Die Zuordnung wurde entfernt.', 'wm-edu-privacy-portal' ),
			'guardians_imported' => __( 'Die Zuordnungen wurden übernommen.', 'wm-edu-privacy-portal' ),
			'settings_saved'     => __( 'Die Einstellungen wurden gespeichert.', 'wm-edu-privacy-portal' ),
		];
		$bad  = [
			'entry_invalid'     => __( 'Die Angaben sind unvollständig: Schüler-ID, E-Mail-Adresse, Art, Status und Begründung werden benötigt.', 'wm-edu-privacy-portal' ),
			'entry_unknown'     => __( 'Für diese Schüler-ID und Art gibt es keinen Eintrag, der widerrufen werden könnte.', 'wm-edu-privacy-portal' ),
			'type_unknown'      => __( 'Diese Einwilligungsart gibt es nicht.', 'wm-edu-privacy-portal' ),
			'status_invalid'    => __( 'Dieser Status ist nicht zulässig.', 'wm-edu-privacy-portal' ),
			'type_exists'       => __( 'Diese Kennung ist bereits vergeben.', 'wm-edu-privacy-portal' ),
			'type_invalid'      => __( 'Kennung, Bezeichnung und Wortlaut werden benötigt; die Kennung besteht aus Großbuchstaben, Ziffern, "_" und "-".', 'wm-edu-privacy-portal' ),
			'guardian_exists'   => __( 'Diese Zuordnung gibt es bereits.', 'wm-edu-privacy-portal' ),
			'guardian_invalid'  => __( 'Schüler-ID oder E-Mail-Adresse sind nicht gültig.', 'wm-edu-privacy-portal' ),
			'guardian_unknown'  => __( 'Diese Zuordnung gibt es nicht.', 'wm-edu-privacy-portal' ),
			'guardians_partly'  => __( 'Einige Zeilen konnten nicht gelesen werden; der Rest wurde übernommen.', 'wm-edu-privacy-portal' ),
			'guardians_none'    => __( 'Es wurde keine Zuordnung übernommen.', 'wm-edu-privacy-portal' ),
			'ledger_busy'       => __( 'Das Register war gesperrt. Bitte in einigen Minuten erneut versuchen.', 'wm-edu-privacy-portal' ),
			'ledger_missing'    => __( 'Die Einwilligungstabelle fehlt.', 'wm-edu-privacy-portal' ),
			'ledger_error'      => __( 'Der Eintrag konnte nicht gespeichert werden.', 'wm-edu-privacy-portal' ),
		];
		if ( isset( $good[ $code ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $good[ $code ] ) . '</p></div>';
		} elseif ( isset( $bad[ $code ] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $bad[ $code ] ) . '</p></div>';
		}
	}

	/**
	 * State of the register above every page: valid, or the reason it is not, so that no number below is read as proof.
	 */
	private static function chain_banner(): void {
		$chain = WM_Edu_Consent_Vault::verify_chain();
		if ( $chain['valid'] ) {
			echo '<div class="notice notice-success"><p>' . esc_html(
				sprintf(
					/* translators: 1: rows verified, 2: schema-1 rows among them. */
					__( 'Signaturkette geprüft: %1$d Einträge, davon %2$d aus Schema 1.', 'wm-edu-privacy-portal' ),
					(int) $chain['checked'],
					(int) $chain['legacy_rows']
				)
			) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Das Register ist nicht prüfbar:', 'wm-edu-privacy-portal' ) . '</strong> ' . esc_html( (string) $chain['error'] ) . ' '
			. esc_html__( 'Solange das so ist, zeigt die Abfrage im Portal keine Einwilligung an.', 'wm-edu-privacy-portal' ) . '</p></div>';
	}

	private static function status_label( string $status ): string {
		$labels = [
			'GRANTED' => __( 'erteilt', 'wm-edu-privacy-portal' ),
			'REVOKED' => __( 'widerrufen', 'wm-edu-privacy-portal' ),
			'DENIED'  => __( 'verweigert', 'wm-edu-privacy-portal' ),
		];
		return $labels[ $status ] ?? $status;
	}

	private static function source_label( string $source ): string {
		$labels = [
			'parent_confirmation' => __( 'Bestätigung der Erziehungsberechtigten', 'wm-edu-privacy-portal' ),
			'parent_revocation'   => __( 'Widerrufslink', 'wm-edu-privacy-portal' ),
			'admin_entry'         => __( 'Erfassung im Backend', 'wm-edu-privacy-portal' ),
			'admin_revocation'    => __( 'Widerruf im Backend', 'wm-edu-privacy-portal' ),
			'direct'              => __( 'Programmaufruf', 'wm-edu-privacy-portal' ),
		];
		return '' === $source ? __( 'unbekannt (Schema 2)', 'wm-edu-privacy-portal' ) : ( $labels[ $source ] ?? $source );
	}

	public static function page_overview(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter for a read-only list.
		$student = isset( $_GET['wm_edu_student'] ) && is_string( $_GET['wm_edu_student'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['wm_edu_student'] ) ) ) : '';
		echo '<div class="wrap"><h1>' . esc_html__( 'Einwilligungen', 'wm-edu-privacy-portal' ) . '</h1>';
		self::notice();
		self::chain_banner();

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '">'
			. '<label for="wm-edu-filter">' . esc_html__( 'Schüler-ID', 'wm-edu-privacy-portal' ) . '</label> '
			. '<input type="search" id="wm-edu-filter" name="wm_edu_student" value="' . esc_attr( $student ) . '"> '
			. '<button type="submit" class="button">' . esc_html__( 'Verlauf anzeigen', 'wm-edu-privacy-portal' ) . '</button></form>';

		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wm_edu_export' ), 'wm_edu_export' ) ) . '">' . esc_html__( 'Register als CSV exportieren', 'wm-edu-privacy-portal' ) . '</a></p>';

		$rows    = '' === $student ? WM_Edu_Consent_Vault::current_entries() : WM_Edu_Consent_Vault::rows_for_student( $student );
		$heading = '' === $student
			? __( 'Aktueller Stand je Schüler-ID und Art', 'wm-edu-privacy-portal' )
			/* translators: %s: pupil ID. */
			: sprintf( __( 'Verlauf für %s', 'wm-edu-privacy-portal' ), $student );
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		self::entries_table( $rows, '' === $student );
		echo '</div>';
	}

	/**
	 * @param object[] $rows
	 */
	private static function entries_table( array $rows, bool $with_revoke ): void {
		if ( [] === $rows ) {
			echo '<p>' . esc_html__( 'Keine Einträge.', 'wm-edu-privacy-portal' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Zeit (UTC)', 'wm-edu-privacy-portal' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Schüler-ID', 'wm-edu-privacy-portal' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Art', 'wm-edu-privacy-portal' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Status', 'wm-edu-privacy-portal' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'E-Mail', 'wm-edu-privacy-portal' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Herkunft', 'wm-edu-privacy-portal' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Begründung', 'wm-edu-privacy-portal' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Wortlaut', 'wm-edu-privacy-portal' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$type    = WM_Edu_Consent_Types::get_by_slug( (string) $row->consent_type );
			$current = null !== $type && hash_equals( (string) $type->scope_hash, (string) $row->scope_hash );
			echo '<tr>'
				. '<td>' . esc_html( (string) $row->created_at ) . '</td>'
				. '<td>' . esc_html( (string) $row->student_token ) . '</td>'
				. '<td>' . esc_html( null !== $type ? (string) $type->label : (string) $row->consent_type ) . '</td>'
				. '<td>' . esc_html( self::status_label( (string) $row->granted_status ) ) . '</td>'
				. '<td>' . esc_html( (string) $row->parent_email ) . '</td>'
				. '<td>' . esc_html( self::source_label( (string) $row->source ) ) . '</td>'
				. '<td>' . esc_html( (string) $row->note ) . '</td>'
				. '<td>' . esc_html( $current ? __( 'aktueller Wortlaut', 'wm-edu-privacy-portal' ) : __( 'seit der Erteilung geändert', 'wm-edu-privacy-portal' ) ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';
		if ( $with_revoke ) {
			echo '<p class="description">' . esc_html__( 'Widerrufen und Nacherfassen: über die Seite „Erfassen“.', 'wm-edu-privacy-portal' ) . '</p>';
		}
	}

	public static function page_entry(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Einwilligung erfassen oder widerrufen', 'wm-edu-privacy-portal' ) . '</h1>';
		self::notice();
		self::chain_banner();
		echo '<p>' . esc_html__( 'Für Fälle außerhalb des Formulars: eine auf Papier erteilte Einwilligung nachtragen oder einen telefonisch erklärten Widerruf erfassen. Jeder Eintrag wird mit Ihrem Benutzerkonto und Ihrer Begründung signiert.', 'wm-edu-privacy-portal' ) . '</p>';

		$types = WM_Edu_Consent_Types::all();
		echo '<h2>' . esc_html__( 'Erfassen', 'wm-edu-privacy-portal' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wm_edu_record">';
		wp_nonce_field( 'wm_edu_record' );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'wm-edu-entry-student', 'student_token', __( 'Schüler-ID', 'wm-edu-privacy-portal' ), 'text', true );
		self::text_row( 'wm-edu-entry-email', 'parent_email', __( 'E-Mail-Adresse der Erziehungsberechtigten', 'wm-edu-privacy-portal' ), 'email', true );
		echo '<tr><th scope="row"><label for="wm-edu-entry-type">' . esc_html__( 'Einwilligungsart', 'wm-edu-privacy-portal' ) . '</label></th><td><select id="wm-edu-entry-type" name="type" required>';
		foreach ( $types as $type ) {
			echo '<option value="' . esc_attr( (string) $type->slug ) . '">' . esc_html( (string) $type->label . ' (' . $type->slug . ')' ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wm-edu-entry-status">' . esc_html__( 'Status', 'wm-edu-privacy-portal' ) . '</label></th><td><select id="wm-edu-entry-status" name="status" required>';
		foreach ( WM_Edu_Consent_Vault::STATUSES as $status ) {
			echo '<option value="' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wm-edu-entry-note">' . esc_html__( 'Begründung', 'wm-edu-privacy-portal' ) . '</label></th><td><textarea id="wm-edu-entry-note" name="note" rows="3" class="large-text" required maxlength="' . esc_attr( (string) WM_Edu_Consent_Vault::NOTE_MAX ) . '"></textarea>'
			. '<p class="description">' . esc_html__( 'Woher der Eintrag stammt, zum Beispiel: unterschriebenes Formular vom 12.09.2026, im Sekretariat abgelegt.', 'wm-edu-privacy-portal' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Eintrag erfassen', 'wm-edu-privacy-portal' ) );
		echo '</form>';

		echo '<hr><h2>' . esc_html__( 'Widerrufen', 'wm-edu-privacy-portal' ) . '</h2>';
		echo '<p>' . esc_html__( 'Trägt einen Widerruf für den letzten Eintrag dieser Schüler-ID und Art ein; E-Mail-Adresse und Wortlaut werden aus diesem Eintrag übernommen.', 'wm-edu-privacy-portal' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wm_edu_revoke">';
		wp_nonce_field( 'wm_edu_revoke' );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'wm-edu-revoke-student', 'student_token', __( 'Schüler-ID', 'wm-edu-privacy-portal' ), 'text', true );
		echo '<tr><th scope="row"><label for="wm-edu-revoke-type">' . esc_html__( 'Einwilligungsart', 'wm-edu-privacy-portal' ) . '</label></th><td><select id="wm-edu-revoke-type" name="type" required>';
		foreach ( $types as $type ) {
			echo '<option value="' . esc_attr( (string) $type->slug ) . '">' . esc_html( (string) $type->label . ' (' . $type->slug . ')' ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wm-edu-revoke-note">' . esc_html__( 'Begründung', 'wm-edu-privacy-portal' ) . '</label></th><td><textarea id="wm-edu-revoke-note" name="note" rows="3" class="large-text" required maxlength="' . esc_attr( (string) WM_Edu_Consent_Vault::NOTE_MAX ) . '"></textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Widerruf erfassen', 'wm-edu-privacy-portal' ) );
		echo '</form></div>';
	}

	private static function text_row( string $id, string $name, string $label, string $type, bool $required ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>'
			. '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="regular-text"' . ( $required ? ' required' : '' ) . '></td></tr>';
	}

	public static function page_types(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects which type the form below edits.
		$edit_id = isset( $_GET['wm_edu_type'] ) ? (int) $_GET['wm_edu_type'] : 0;
		$edit    = $edit_id > 0 ? WM_Edu_Consent_Types::get( $edit_id ) : null;
		echo '<div class="wrap"><h1>' . esc_html__( 'Einwilligungsarten', 'wm-edu-privacy-portal' ) . '</h1>';
		self::notice();
		echo '<p>' . esc_html__( 'Der Wortlaut ist das, wozu die Erziehungsberechtigten einwilligen; sein SHA-256 wird mit jeder Einwilligung gespeichert. Ein geänderter Wortlaut gilt für neue Anfragen; frühere Einträge behalten ihren. Arten werden nicht gelöscht, sondern deaktiviert.', 'wm-edu-privacy-portal' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Bezeichnung', 'wm-edu-privacy-portal' ) . '</th><th scope="col">' . esc_html__( 'Kennung', 'wm-edu-privacy-portal' ) . '</th><th scope="col">' . esc_html__( 'Aktiv', 'wm-edu-privacy-portal' ) . '</th><th scope="col">' . esc_html__( 'Wortlaut-Hash', 'wm-edu-privacy-portal' ) . '</th><th scope="col">' . esc_html__( 'Bearbeiten', 'wm-edu-privacy-portal' ) . '</th></tr></thead><tbody>';
		foreach ( WM_Edu_Consent_Types::all() as $type ) {
			echo '<tr><td>' . esc_html( (string) $type->label ) . '</td><td><code>' . esc_html( (string) $type->slug ) . '</code></td>'
				. '<td>' . esc_html( '1' === (string) $type->active ? __( 'ja', 'wm-edu-privacy-portal' ) : __( 'nein', 'wm-edu-privacy-portal' ) ) . '</td>'
				. '<td><code>' . esc_html( substr( (string) $type->scope_hash, 0, 16 ) ) . '</code></td>'
				. '<td><a href="' . esc_url( add_query_arg( [ 'page' => self::TYPES_SLUG, 'wm_edu_type' => (int) $type->id ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Bearbeiten', 'wm-edu-privacy-portal' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html( null === $edit ? __( 'Neue Einwilligungsart', 'wm-edu-privacy-portal' ) : __( 'Einwilligungsart bearbeiten', 'wm-edu-privacy-portal' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wm_edu_type">';
		wp_nonce_field( 'wm_edu_type' );
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) ( null === $edit ? 0 : (int) $edit->id ) ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		if ( null === $edit ) {
			echo '<tr><th scope="row"><label for="wm-edu-type-slug">' . esc_html__( 'Kennung', 'wm-edu-privacy-portal' ) . '</label></th><td><input type="text" id="wm-edu-type-slug" name="slug" class="regular-text" required maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9_-]*">'
				. '<p class="description">' . esc_html__( 'Wird im Register gespeichert und kann später nicht geändert werden, zum Beispiel FAHRT_2026.', 'wm-edu-privacy-portal' ) . '</p></td></tr>';
		} else {
			echo '<tr><th scope="row">' . esc_html__( 'Kennung', 'wm-edu-privacy-portal' ) . '</th><td><code>' . esc_html( (string) $edit->slug ) . '</code></td></tr>';
		}
		echo '<tr><th scope="row"><label for="wm-edu-type-label">' . esc_html__( 'Bezeichnung', 'wm-edu-privacy-portal' ) . '</label></th><td><input type="text" id="wm-edu-type-label" name="label" class="regular-text" required maxlength="190" value="' . esc_attr( null === $edit ? '' : (string) $edit->label ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wm-edu-type-desc">' . esc_html__( 'Beschreibung', 'wm-edu-privacy-portal' ) . '</label></th><td><textarea id="wm-edu-type-desc" name="description" rows="2" class="large-text">' . esc_textarea( null === $edit ? '' : (string) $edit->description ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="wm-edu-type-text">' . esc_html__( 'Wortlaut', 'wm-edu-privacy-portal' ) . '</label></th><td><textarea id="wm-edu-type-text" name="consent_text" rows="6" class="large-text" required>' . esc_textarea( null === $edit ? '' : (string) WM_Edu_Consent_Types::text_for_scope( (string) $edit->scope_hash ) ) . '</textarea></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Aktiv', 'wm-edu-privacy-portal' ) . '</th><td><label><input type="checkbox" name="active" value="1"' . checked( null === $edit ? true : '1' === (string) $edit->active, true, false ) . '> ' . esc_html__( 'Im Formular für Erziehungsberechtigte anbieten', 'wm-edu-privacy-portal' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( null === $edit ? __( 'Anlegen', 'wm-edu-privacy-portal' ) : __( 'Speichern', 'wm-edu-privacy-portal' ) );
		echo '</form></div>';
	}

	public static function page_guardians(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Erziehungsberechtigte', 'wm-edu-privacy-portal' ) . '</h1>';
		self::notice();
		echo '<p>' . esc_html__( 'Das Formular schickt nur dann einen Bestätigungslink, wenn Schüler-ID und E-Mail-Adresse hier zusammen hinterlegt sind. Gespeichert wird die Adresse nicht im Klartext, sondern als HMAC; sie lässt sich prüfen, aber nicht auslesen. Deshalb zeigt die Liste nur die Anzahl der Adressen je Schüler-ID.', 'wm-edu-privacy-portal' ) . '</p>';

		$students = WM_Edu_Guardians::students();
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Schüler-ID', 'wm-edu-privacy-portal' ) . '</th><th scope="col">' . esc_html__( 'Hinterlegte Adressen', 'wm-edu-privacy-portal' ) . '</th></tr></thead><tbody>';
		foreach ( $students as $entry ) {
			echo '<tr><td>' . esc_html( $entry['student_token'] ) . '</td><td>' . esc_html( (string) $entry['addresses'] ) . '</td></tr>';
		}
		if ( [] === $students ) {
			echo '<tr><td colspan="2">' . esc_html__( 'Keine Zuordnungen hinterlegt.', 'wm-edu-privacy-portal' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Zuordnungen hinzufügen', 'wm-edu-privacy-portal' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wm_edu_guardian"><input type="hidden" name="what" value="import">';
		wp_nonce_field( 'wm_edu_guardian' );
		echo '<p><label for="wm-edu-guardian-pairs">' . esc_html__( 'Eine Zuordnung je Zeile: Schüler-ID und E-Mail-Adresse, getrennt durch Semikolon, Komma oder Tabulator.', 'wm-edu-privacy-portal' ) . '</label></p>';
		echo '<textarea id="wm-edu-guardian-pairs" name="pairs" rows="6" class="large-text code" required></textarea>';
		submit_button( __( 'Zuordnungen übernehmen', 'wm-edu-privacy-portal' ) );
		echo '</form>';

		echo '<hr><h2>' . esc_html__( 'Zuordnung entfernen', 'wm-edu-privacy-portal' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wm_edu_guardian"><input type="hidden" name="what" value="remove">';
		wp_nonce_field( 'wm_edu_guardian' );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'wm-edu-guardian-student', 'student_token', __( 'Schüler-ID', 'wm-edu-privacy-portal' ), 'text', true );
		self::text_row( 'wm-edu-guardian-email', 'email', __( 'E-Mail-Adresse', 'wm-edu-privacy-portal' ), 'email', true );
		echo '</tbody></table>';
		submit_button( __( 'Zuordnung entfernen', 'wm-edu-privacy-portal' ), 'secondary' );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wm_edu_guardian"><input type="hidden" name="what" value="remove_student">';
		wp_nonce_field( 'wm_edu_guardian' );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'wm-edu-guardian-student-all', 'student_token', __( 'Alle Adressen einer Schüler-ID entfernen', 'wm-edu-privacy-portal' ), 'text', true );
		echo '</tbody></table>';
		submit_button( __( 'Alle Adressen entfernen', 'wm-edu-privacy-portal' ), 'secondary' );
		echo '</form></div>';
	}

	public static function page_settings(): void {
		$settings = WM_Edu_Settings::all();
		echo '<div class="wrap"><h1>' . esc_html__( 'Einstellungen', 'wm-edu-privacy-portal' ) . '</h1>';
		self::notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wm_edu_settings">';
		wp_nonce_field( 'wm_edu_settings' );
		echo '<h2>' . esc_html__( 'Zugang', 'wm-edu-privacy-portal' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Abfrage im Portal', 'wm-edu-privacy-portal' ) . '</th><td><label><input type="checkbox" name="public_gate" value="1"' . checked( $settings['public_gate'], true, false ) . '> '
			. esc_html__( 'Öffentlich zugänglich machen (wie vor Version mit Zugriffsschutz). Dann kann jede Person mit einer Schüler-ID erfahren, ob eine Einwilligung vorliegt.', 'wm-edu-privacy-portal' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Pseudonymisierung', 'wm-edu-privacy-portal' ) . '</th><td><label><input type="checkbox" name="public_tokenizer" value="1"' . checked( $settings['public_tokenizer'], true, false ) . '> '
			. esc_html__( 'REST-Routen und Demo öffentlich zugänglich machen. Dann kann jede Person das Token eines geratenen Namens oder Datums berechnen.', 'wm-edu-privacy-portal' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Rollen für die Abfrage', 'wm-edu-privacy-portal' ) . '</th><td><fieldset>';
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			echo '<label><input type="checkbox" name="gate_roles[]" value="' . esc_attr( (string) $slug ) . '"' . checked( in_array( (string) $slug, $settings['gate_roles'], true ), true, false ) . '> ' . esc_html( (string) $slug ) . '</label><br>';
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Zusätzlich zu Benutzern mit der Berechtigung „manage_edu_consents“.', 'wm-edu-privacy-portal' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Formular für Erziehungsberechtigte', 'wm-edu-privacy-portal' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		self::number_row( 'confirm_hours', __( 'Gültigkeit des Bestätigungslinks (Stunden)', 'wm-edu-privacy-portal' ), (int) $settings['confirm_hours'] );
		self::number_row( 'retention_days', __( 'Anfragedaten löschen (Tage nach Ablauf)', 'wm-edu-privacy-portal' ), (int) $settings['retention_days'] );
		self::number_row( 'rate_ip_per_hour', __( 'Anfragen je IP und Stunde', 'wm-edu-privacy-portal' ), (int) $settings['rate_ip_per_hour'] );
		self::number_row( 'rate_email_per_hour', __( 'Anfragen je E-Mail-Adresse und Stunde', 'wm-edu-privacy-portal' ), (int) $settings['rate_email_per_hour'] );
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Erfasste Einwilligungen werden im laufenden Betrieb nie gelöscht; die Aufbewahrungsfrist gilt nur für Anfragen, die nicht bestätigt wurden, und für abgeschlossene Widerrufslinks.', 'wm-edu-privacy-portal' ) . '</p>';

		echo '<h2>' . esc_html__( 'Deinstallation', 'wm-edu-privacy-portal' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Daten bei Deinstallation löschen', 'wm-edu-privacy-portal' ) . '</th><td><label><input type="checkbox" name="wm_edu_delete_data_on_uninstall" value="1"' . checked( WM_Edu_Settings::delete_data_on_uninstall(), true, false ) . '> '
			. esc_html__( 'Alle Einstellungen, Zuordnungen, Einwilligungsarten und das Einwilligungsregister beim Löschen des Plugins endgültig entfernen (Standard: deaktiviert — alle Daten bleiben erhalten).', 'wm-edu-privacy-portal' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}

	private static function number_row( string $name, string $label, int $value ): void {
		[ $min, $max ] = WM_Edu_Settings::LIMITS[ $name ];
		echo '<tr><th scope="row"><label for="wm-edu-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>'
			. '<input type="number" id="wm-edu-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" class="small-text"></td></tr>';
	}
}
