<?php
/**
 * WM Edu Privacy Portal — Suite Hub spoke adapter
 *
 * Implements the WM Suite Hub contract (WM_Plugin_Module_Interface): health and telemetry from the consent
 * ledger, a component entry for the hub's software bill of materials, and a self test.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;

final class WM_Edu_Spoke_Adapter implements WM_Plugin_Module_Interface {

	public function get_module_slug(): string {
		return 'wm-edu-privacy-portal';
	}

	public function get_name(): string {
		return 'WM Edu Privacy Portal';
	}

	public function get_version(): string {
		return defined( 'WM_EDU_PORTAL_VERSION' ) ? WM_EDU_PORTAL_VERSION : '1.2.1';
	}

	public function get_description(): string {
		return 'Pseudonymisierung von Datumsangaben, Klassen und übergebenen Schülernamen & hash-verkettetes Einwilligungsregister.';
	}

	/**
	 * ERROR when the table is missing, cannot be read or its chain does not verify. Until 2026-09-15 a missing table
	 * read as a valid, empty chain and the adapter reported HEALTHY.
	 */
	public function get_health_status(): string {
		return WM_Edu_Consent_Vault::verify_chain()['valid'] ? 'HEALTHY' : 'ERROR';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-edu-privacy-portal@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', dirname( __DIR__ ) . '/wm-edu-privacy-portal.php' ),
				],
			],
			'licenses'    => [
				[
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			'properties'  => [
				[
					'name'  => 'wm:features:tokenizer',
					'value' => 'Caller-supplied names, German dates and class identifiers replaced by HMAC-SHA256 tokens under a key derived from the site auth salt (8 hex characters, 6 for classes; caller-assigned tokens as given)',
				],
				[
					'name'  => 'wm:features:consent_ledger',
					'value' => 'Hash-chained consent table written under a named lock by the guardian confirmation and revocation links and by the admin screen, read by [wm_edu_consent_gate]',
				],
			],
		];
	}

	public function get_dependencies(): array {
		return [];
	}

	/**
	 * Direct link to the plugin's admin settings screen.
	 */
	public function get_settings_url(): ?string {
		return admin_url( 'admin.php?page=wm-edu-consents-settings' );
	}

	/**
	 * Numbers from the ledger. ledger_rows counts every row (grants, revocations, refusals; null without a table);
	 * ledger_rows_verified counts the rows checked before the first break, so the two differ exactly when the chain is
	 * broken. Until 2026-09-15 a single "consents" value carried the verified count, which read 0 when the first row had
	 * been edited, next to a literal status ACTIVE.
	 */
	public function get_telemetry_metrics(): array {
		$chain = WM_Edu_Consent_Vault::verify_chain();
		return [
			'ledger_rows'          => $chain['rows'],
			'ledger_rows_verified' => $chain['checked'],
			'ledger_legacy_rows'   => $chain['legacy_rows'],
			'chain_valid'          => $chain['valid'] ? 'YES' : 'NO',
			'missing_table'        => $chain['missing_table'] ? 1 : 0,
			// What a school sees on the admin screen: how many types a guardian can answer about, and how many requests
			// are waiting for a confirmation. Null while the tables are missing, never 0.
			'consent_types_active' => $chain['missing_table'] ? null : count( WM_Edu_Consent_Types::active() ),
			'requests_pending'     => $chain['missing_table'] ? null : self::pending_requests(),
		];
	}

	/**
	 * Guardian requests that were mailed and are still waiting for their confirmation, or null when they cannot be read.
	 */
	private static function pending_requests(): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a cached count would report a state that is gone.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', WM_Edu_Consent_Requests::table(), 'pending' ) );
		return null === $count || '' !== (string) $wpdb->last_error ? null : (int) $count;
	}

	/**
	 * `self_test` runs the tokenizer on a sentence with overlapping names, a class and a date, and verifies the ledger.
	 * Every other action is unknown and says so.
	 */
	public function execute_action( string $action, array $params = [] ): array {
		if ( 'self_test' !== $action ) {
			return [
				'success' => false,
				/* translators: %s: action name. */
				'message' => sprintf( __( 'Unbekannte Spoke-Aktion "%s".', 'wm-edu-privacy-portal' ), esc_html( $action ) ),
			];
		}

		$problems = [];
		try {
			$tok = WM_Edu_PII_Tokenizer::tokenize( 'Max Müller aus Klasse 9b, geboren am 15.03.2012', [ 'Max' => '', 'Max Müller' => '' ] );
			if ( 3 !== $tok['detections'] || 1 === preg_match( '/Max|Müller|9b|15\.03\.2012/u', $tok['sanitized_text'] ) ) {
				$problems[] = __( 'Die Pseudonymisierung liefert abweichende Werte.', 'wm-edu-privacy-portal' );
			}
		} catch ( RuntimeException $e ) {
			$problems[] = __( 'Die Pseudonymisierung ist abgebrochen.', 'wm-edu-privacy-portal' );
		}

		$chain = WM_Edu_Consent_Vault::verify_chain();
		if ( ! $chain['valid'] ) {
			$problems[] = (string) $chain['error'];
		}

		if ( [] !== $problems ) {
			return [
				'success' => false,
				'message' => __( 'Selbsttest fehlgeschlagen:', 'wm-edu-privacy-portal' ) . ' ' . implode( ' ', $problems ),
			];
		}
		return [
			'success' => true,
			/* translators: 1: rows verified, 2: rows in the ledger. */
			'message' => sprintf( __( 'Pseudonymisierung ersetzt Namen, Klasse und Datum; Einwilligungskette gültig (%1$d von %2$d Einträgen geprüft).', 'wm-edu-privacy-portal' ), (int) $chain['checked'], (int) $chain['rows'] ),
		];
	}
}
