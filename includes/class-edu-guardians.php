<?php
/**
 * WM Edu Privacy Portal — guardian registry
 *
 * Pairs of a pupil ID (the identifier the school gives the family) and a guardian's e-mail address. The guardian form
 * only sends a confirmation link when the pair it receives is in this registry, so knowing or guessing a pupil ID is
 * not enough to record a consent for that pupil. The address itself is not stored: only an HMAC of the lower-cased
 * address under a key derived for this purpose, which is enough to compare a typed address and not enough to read one
 * back. Changing the site's auth salt changes the key, and the registry has to be imported again.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Guardians {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a cached registry would answer for a pair that was removed.

	public const TABLE_NAME  = 'wm_edu_guardians';
	public const STUDENT_MAX = 64;
	public const EMAIL_MAX   = 190;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	public static function table_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			student_token VARCHAR(64) NOT NULL,
			email_hmac VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_student (student_token)
		) {$charset_collate};";
	}

	/**
	 * A pupil ID as the forms and the ledger use it: text without tags or line breaks, trimmed, 1 to 64 characters.
	 * null when it does not fit.
	 */
	public static function normalize_student( string $student_token ): ?string {
		$student_token = trim( sanitize_text_field( $student_token ) );
		return '' === $student_token || mb_strlen( $student_token, 'UTF-8' ) > self::STUDENT_MAX ? null : $student_token;
	}

	/**
	 * A guardian address as the forms use it, or null when it is not a valid address of at most 190 characters.
	 */
	public static function normalize_email( string $email ): ?string {
		$email = sanitize_email( trim( $email ) );
		return '' === $email || ! is_email( $email ) || mb_strlen( $email, 'UTF-8' ) > self::EMAIL_MAX ? null : $email;
	}

	/**
	 * HMAC of the lower-cased address: the registry and the form compare addresses without regard to case.
	 */
	public static function email_hmac( string $email ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), WM_Edu_Keys::derive( WM_Edu_Keys::GUARDIAN_EMAIL ) );
	}

	/**
	 * Rows for a pupil ID, compared exactly in PHP (MySQL's default collation would also match other case or accent
	 * spellings of the ID).
	 *
	 * @return object[]
	 */
	private static function rows_for( string $student_token ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, student_token, email_hmac FROM %i WHERE student_token = %s ORDER BY id ASC', self::table(), $student_token ) );
		return array_values( array_filter( is_array( $rows ) ? $rows : [], static fn( object $row ): bool => $student_token === (string) $row->student_token ) );
	}

	/**
	 * Whether the pair is registered. Unknown IDs and addresses both answer false; callers must not tell the two apart.
	 */
	public static function matches( string $student_token, string $email ): bool {
		$student = self::normalize_student( $student_token );
		$address = self::normalize_email( $email );
		if ( null === $student || null === $address ) {
			return false;
		}
		$hmac = self::email_hmac( $address );
		foreach ( self::rows_for( $student ) as $row ) {
			if ( hash_equals( (string) $row->email_hmac, $hmac ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Registers a pair. Returns 'added', 'exists' or 'invalid'.
	 */
	public static function add( string $student_token, string $email ): string {
		global $wpdb;
		$student = self::normalize_student( $student_token );
		$address = self::normalize_email( $email );
		if ( null === $student || null === $address ) {
			return 'invalid';
		}
		if ( self::matches( $student, $address ) ) {
			return 'exists';
		}
		$inserted = $wpdb->insert(
			self::table(),
			[
				'student_token' => $student,
				'email_hmac'    => self::email_hmac( $address ),
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s' ]
		);
		return false === $inserted ? 'invalid' : 'added';
	}

	/**
	 * Imports pairs, one per line: pupil ID and address separated by ";", "," or a tab. Blank lines are skipped.
	 *
	 * @return array{added: int, exists: int, invalid: int[]} invalid holds the 1-based numbers of the lines refused.
	 */
	public static function import( string $lines ): array {
		$result = [
			'added'   => 0,
			'exists'  => 0,
			'invalid' => [],
		];
		foreach ( preg_split( '/\R/', $lines ) ?: [] as $number => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$parts   = preg_split( '/[;,\t]/', $line, 2 );
			$outcome = 2 === count( (array) $parts ) ? self::add( (string) $parts[0], (string) $parts[1] ) : 'invalid';
			if ( 'invalid' === $outcome ) {
				$result['invalid'][] = $number + 1;
			} else {
				++$result[ $outcome ];
			}
		}
		return $result;
	}

	/**
	 * Removes one pair; returns the number of rows removed.
	 */
	public static function remove( string $student_token, string $email ): int {
		$student = self::normalize_student( $student_token );
		$address = self::normalize_email( $email );
		if ( null === $student || null === $address ) {
			return 0;
		}
		$hmac = self::email_hmac( $address );
		return self::delete_ids( array_map( static fn( object $row ): int => (int) $row->id, array_filter( self::rows_for( $student ), static fn( object $row ): bool => hash_equals( (string) $row->email_hmac, $hmac ) ) ) );
	}

	/**
	 * Removes every address of a pupil ID; returns the number of rows removed.
	 */
	public static function remove_student( string $student_token ): int {
		$student = self::normalize_student( $student_token );
		return null === $student ? 0 : self::delete_ids( array_map( static fn( object $row ): int => (int) $row->id, self::rows_for( $student ) ) );
	}

	/**
	 * @param int[] $ids
	 */
	private static function delete_ids( array $ids ): int {
		global $wpdb;
		$removed = 0;
		foreach ( $ids as $id ) {
			$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', self::table(), $id ) );
		}
		return $removed;
	}

	/**
	 * Registered pupil IDs with the number of addresses each, grouped exactly in PHP. A list of pairs, not an array keyed
	 * by ID: PHP turns a numeric-string key such as "10234" into an integer.
	 *
	 * @return array<int, array{student_token: string, addresses: int}>
	 */
	public static function students(): array {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT student_token FROM %i ORDER BY student_token ASC, id ASC', self::table() ) );
		$index = [];
		$list  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$token = (string) $row->student_token;
			$key   = 'id:' . $token;
			if ( ! isset( $index[ $key ] ) ) {
				$index[ $key ] = count( $list );
				$list[]        = [
					'student_token' => $token,
					'addresses'     => 0,
				];
			}
			++$list[ $index[ $key ] ]['addresses'];
		}
		return $list;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
