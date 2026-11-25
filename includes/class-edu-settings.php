<?php
/**
 * WM Edu Privacy Portal — settings
 *
 * One option, wm_edu_settings, written from the settings page of the admin screen. Every read goes through all(),
 * which fills in the defaults and re-applies the limits, so a value edited in the database cannot switch access open or
 * set a link validity of a year.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Settings {

	public const OPTION                     = 'wm_edu_settings';
	public const DELETE_ON_UNINSTALL_OPTION = 'wm_edu_delete_data_on_uninstall';

	/**
	 * public_gate / public_tokenizer: false keeps the gate and the tokenizer (routes and demo) for authorised users; true
	 * restores the public behaviour of the releases before 2026-09-17. gate_roles: roles that may use the gate in addition
	 * to users with manage_edu_consents. confirm_hours: validity of a guardian's confirmation link. retention_days: days
	 * after a confirmation link expired (or a revocation link was used) before the request's data is deleted. The rate
	 * limits count guardian form submissions per hour.
	 */
	public const DEFAULTS = [
		'public_gate'         => false,
		'public_tokenizer'    => false,
		'gate_roles'          => [],
		'confirm_hours'       => 48,
		'retention_days'      => 7,
		'rate_ip_per_hour'    => 10,
		'rate_email_per_hour' => 3,
	];

	/** Inclusive [min, max] per integer setting. */
	public const LIMITS = [
		'confirm_hours'       => [ 1, 720 ],
		'retention_days'      => [ 0, 365 ],
		'rate_ip_per_hour'    => [ 1, 1000 ],
		'rate_email_per_hour' => [ 1, 100 ],
	];

	/**
	 * Stored settings with defaults filled in and limits applied.
	 *
	 * @return array{public_gate: bool, public_tokenizer: bool, gate_roles: string[], confirm_hours: int, retention_days: int, rate_ip_per_hour: int, rate_email_per_hour: int}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		// Stored booleans are true or false; anything else in the database reads as the default (off).
		$settings = self::DEFAULTS;
		foreach ( [ 'public_gate', 'public_tokenizer' ] as $flag ) {
			$settings[ $flag ] = true === ( $stored[ $flag ] ?? false );
		}
		$settings['gate_roles'] = array_values( array_filter( (array) ( $stored['gate_roles'] ?? [] ), 'is_string' ) );
		foreach ( self::LIMITS as $key => [ $min, $max ] ) {
			$settings[ $key ] = self::clamp( $stored[ $key ] ?? self::DEFAULTS[ $key ], $key, $min, $max );
		}
		return $settings;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Settings from a submitted form. Checkboxes count only as "1"; roles not in $known_roles are dropped; numbers are
	 * clamped to LIMITS (a non-numeric value keeps the default).
	 *
	 * @param array<string, mixed> $input
	 * @param string[]             $known_roles Role slugs that exist on the site.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, array $known_roles ): array {
		$clean = [
			'public_gate'      => '1' === ( $input['public_gate'] ?? '' ),
			'public_tokenizer' => '1' === ( $input['public_tokenizer'] ?? '' ),
			'gate_roles'       => array_values( array_intersect( array_filter( (array) ( $input['gate_roles'] ?? [] ), 'is_string' ), $known_roles ) ),
		];
		foreach ( self::LIMITS as $key => [ $min, $max ] ) {
			$clean[ $key ] = self::clamp( $input[ $key ] ?? self::DEFAULTS[ $key ], $key, $min, $max );
		}
		return $clean;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param string[]             $known_roles
	 */
	public static function save( array $input, array $known_roles ): void {
		update_option( self::OPTION, self::sanitize( $input, $known_roles ) );
	}

	/**
	 * Whether administrator opt-in to delete options and ledger data on plugin uninstall is enabled (default false).
	 */
	public static function delete_data_on_uninstall(): bool {
		return in_array( get_option( 'wm_edu_delete_data_on_uninstall', '0' ), [ '1', 1, true ], true );
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_delete_flag( $value ): string {
		return ( '1' === (string) $value || true === $value ) ? '1' : '0';
	}

	/**
	 * @param mixed $value
	 */
	private static function clamp( $value, string $key, int $min, int $max ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) ) {
			return self::DEFAULTS[ $key ];
		}
		return max( $min, min( $max, (int) $value ) );
	}
}
