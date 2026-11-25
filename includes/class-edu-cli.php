<?php
/**
 * WM Edu Privacy Portal — WP-CLI commands
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_CLI {

	/**
	 * Shows the version and the state of the consent ledger: rows, rows verified, schema-1 rows among them, the table's
	 * chain tip and the recorded tip. Ends with an error (exit 1) when the table is missing or the chain does not verify.
	 *
	 * ## EXAMPLES
	 *
	 *     wp edu status
	 */
	public function status( array $args, array $assoc_args ): void {
		$chain = WM_Edu_Consent_Vault::verify_chain();

		WP_CLI::line( 'WM Edu Privacy Portal ' . ( defined( 'WM_EDU_PORTAL_VERSION' ) ? WM_EDU_PORTAL_VERSION : '?' ) );
		WP_CLI::line( 'Zeilen im Register: ' . ( null === $chain['rows'] ? 'keine Tabelle' : (string) $chain['rows'] ) );
		WP_CLI::line( 'Geprüft:            ' . $chain['checked'] );
		WP_CLI::line( 'Davon Schema 1:     ' . $chain['legacy_rows'] );
		WP_CLI::line( 'Kettenspitze:       ' . WM_Edu_Consent_Vault::current_chain_tip() );
		$tip = WM_Edu_Consent_Vault::recorded_tip();
		WP_CLI::line( 'Aufgezeichnet:      ' . ( null === $tip ? 'keine Kettenspitze' : $tip['hash'] . ' · Zeilen: ' . $tip['count'] . ' · ' . ( $tip['signed'] ? 'signiert' : 'Signatur ungültig' ) ) );

		if ( ! $chain['valid'] ) {
			WP_CLI::error( (string) $chain['error'] );
			return;
		}
		WP_CLI::success( 'Hash-Kette gültig.' );
	}

	/**
	 * Deletes guardian request data whose retention period has passed and releases interrupted claims, as the daily
	 * event does. The consent register is not touched.
	 *
	 * ## EXAMPLES
	 *
	 *     wp edu cleanup
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$result = WM_Edu_Consent_Requests::cleanup();
		WP_CLI::line( 'Abgelaufene Anfragen gelöscht:    ' . $result['pending_deleted'] );
		WP_CLI::line( 'Abgeschlossene Anfragen gelöscht: ' . $result['closed_deleted'] );
		WP_CLI::line( 'Unterbrochene Anfragen freigegeben: ' . $result['claims_released'] );
		WP_CLI::success( 'Aufbewahrungsfrist angewendet.' );
	}

	/**
	 * Replaces German dates and school class identifiers in a text with placeholder tokens and prints the token map.
	 * Pupil names are not replaced here: they need the REST route wm-edu/v1/tokenize with known_students.
	 *
	 * ## OPTIONS
	 *
	 * <text>
	 * : Text to pseudonymise.
	 *
	 * ## EXAMPLES
	 *
	 *     wp edu tokenize "Aus der Kl. 7c, geboren am 15.03.2012."
	 */
	public function tokenize( array $args, array $assoc_args ): void {
		$text = (string) ( $args[0] ?? '' );

		if ( '' === $text ) {
			WP_CLI::error( 'Bitte Text zum Pseudonymisieren übergeben.' );
			return;
		}

		try {
			$result = WM_Edu_PII_Tokenizer::tokenize( $text );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		WP_CLI::line( 'Original:   ' . $text );
		WP_CLI::line( 'Ersetzt:    ' . $result['sanitized_text'] );
		WP_CLI::line( 'Treffer:    ' . $result['detections'] . ' (Datumsangaben und Klassenbezeichnungen)' );

		if ( ! empty( $result['token_map'] ) ) {
			WP_CLI::line( 'Token-Map:' );
			foreach ( $result['token_map'] as $token => $val ) {
				WP_CLI::line( "  {$token} => {$val}" );
			}
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'edu', 'WM_Edu_CLI' );
}
