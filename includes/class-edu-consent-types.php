<?php
/**
 * WM Edu Privacy Portal — consent types and the texts consented to
 *
 * A consent type is what a guardian can grant or deny: a slug (stored in the ledger as consent_type), a label, a
 * description, the exact text and whether new requests may name it. The ledger's scope_hash is the SHA-256 of that
 * text. Every text a type ever carried is kept in the text archive under its hash, so the wording behind any ledger
 * row can be shown later although the type's text was edited since. Types are never deleted, only deactivated: rows in
 * the ledger refer to them.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Consent_Types {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own tables, read on the admin screen and when a guardian's request is built.

	public const TYPES_TABLE = 'wm_edu_consent_types';
	public const TEXTS_TABLE = 'wm_edu_consent_texts';

	/**
	 * Slug: ASCII capitals, digits, "_" and "-", starting with a letter or digit, up to the 50 characters of the ledger's
	 * consent_type column. Lower-case input is upper-cased first.
	 */
	public const SLUG_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{0,49}$/';
	public const LABEL_MAX    = 190;
	public const DESC_MAX     = 2000;
	public const TEXT_MAX     = 20000;

	public static function types_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TYPES_TABLE;
	}

	public static function texts_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TEXTS_TABLE;
	}

	public static function types_table_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(50) NOT NULL,
			label VARCHAR(190) NOT NULL,
			description TEXT NOT NULL,
			scope_hash VARCHAR(64) NOT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";
	}

	public static function texts_table_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			scope_hash VARCHAR(64) NOT NULL,
			consent_text LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (scope_hash)
		) {$charset_collate};";
	}

	public static function normalize_slug( string $slug ): string {
		return strtoupper( trim( $slug ) );
	}

	/**
	 * The text as stored and hashed: valid UTF-8, tags removed, line breaks kept and normalised to "\n", surrounding
	 * whitespace trimmed. null for text that is not valid UTF-8.
	 */
	public static function normalize_text( string $text ): ?string {
		if ( 1 !== preg_match( '//u', $text ) ) {
			return null;
		}
		return trim( str_replace( [ "\r\n", "\r" ], "\n", wp_strip_all_tags( $text ) ) );
	}

	public static function scope_hash( string $normalized_text ): string {
		return hash( 'sha256', $normalized_text );
	}

	/**
	 * Creates a type ($id 0) or updates one. The slug is taken on creation only; an update keeps the stored slug, because
	 * ledger rows name it. Codes: wm_edu_type_invalid (with the field in the data), wm_edu_type_exists,
	 * wm_edu_type_not_found, wm_edu_type_not_written.
	 *
	 * @param array<string, mixed> $data slug, label, description, consent_text, active (bool)
	 */
	public static function save( array $data, int $id = 0 ): int|WP_Error {
		global $wpdb;
		$label       = sanitize_text_field( is_string( $data['label'] ?? null ) ? $data['label'] : '' );
		$description = sanitize_textarea_field( is_string( $data['description'] ?? null ) ? $data['description'] : '' );
		$text        = self::normalize_text( is_string( $data['consent_text'] ?? null ) ? $data['consent_text'] : '' );
		$active      = true === ( $data['active'] ?? false );

		foreach ( [
			'label'        => '' !== $label && mb_strlen( $label, 'UTF-8' ) <= self::LABEL_MAX,
			'description'  => mb_strlen( $description, 'UTF-8' ) <= self::DESC_MAX,
			'consent_text' => null !== $text && '' !== $text && mb_strlen( $text, 'UTF-8' ) <= self::TEXT_MAX,
		] as $field => $ok ) {
			if ( ! $ok ) {
				return new WP_Error( 'wm_edu_type_invalid', $field, [ 'field' => $field ] );
			}
		}

		$now   = gmdate( 'Y-m-d H:i:s' );
		$scope = self::scope_hash( (string) $text );
		if ( ! self::archive_text( (string) $text, $scope ) ) {
			return new WP_Error( 'wm_edu_type_not_written', 'text archive' );
		}

		if ( 0 === $id ) {
			$slug = self::normalize_slug( is_string( $data['slug'] ?? null ) ? $data['slug'] : '' );
			if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
				return new WP_Error( 'wm_edu_type_invalid', 'slug', [ 'field' => 'slug' ] );
			}
			if ( null !== self::get_by_slug( $slug ) ) {
				return new WP_Error( 'wm_edu_type_exists', $slug );
			}
			$inserted = $wpdb->insert(
				self::types_table(),
				[
					'slug'        => $slug,
					'label'       => $label,
					'description' => $description,
					'scope_hash'  => $scope,
					'active'      => $active ? 1 : 0,
					'created_at'  => $now,
					'updated_at'  => $now,
				],
				[ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);
			return false === $inserted ? new WP_Error( 'wm_edu_type_not_written', $slug ) : (int) $wpdb->insert_id;
		}

		if ( null === self::get( $id ) ) {
			return new WP_Error( 'wm_edu_type_not_found', (string) $id );
		}
		$updated = $wpdb->update(
			self::types_table(),
			[
				'label'       => $label,
				'description' => $description,
				'scope_hash'  => $scope,
				'active'      => $active ? 1 : 0,
				'updated_at'  => $now,
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%d', '%s' ],
			[ '%d' ]
		);
		return false === $updated ? new WP_Error( 'wm_edu_type_not_written', (string) $id ) : $id;
	}

	/**
	 * Keeps a text under its hash; a text already archived is left as it is.
	 */
	private static function archive_text( string $text, string $scope ): bool {
		global $wpdb;
		if ( null !== self::text_for_scope( $scope ) ) {
			return true;
		}
		return false !== $wpdb->insert(
			self::texts_table(),
			[
				'scope_hash'   => $scope,
				'consent_text' => $text,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s' ]
		);
	}

	/**
	 * The archived text for a scope hash, compared exactly (the hash is hex; the column collation must not decide).
	 */
	public static function text_for_scope( string $scope_hash ): ?string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT scope_hash, consent_text FROM %i WHERE scope_hash = %s LIMIT 1', self::texts_table(), $scope_hash ) );
		return is_object( $row ) && hash_equals( (string) $row->scope_hash, $scope_hash ) ? (string) $row->consent_text : null;
	}

	/**
	 * @return object[] Every type, ordered by label.
	 */
	public static function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY label ASC, id ASC', self::types_table() ) );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @return object[] Types new requests may name.
	 */
	public static function active(): array {
		return array_values( array_filter( self::all(), static fn( object $type ): bool => '1' === (string) $type->active ) );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::types_table(), $id ) );
		return is_object( $row ) ? $row : null;
	}

	/**
	 * A type by slug, compared exactly after normalisation.
	 */
	public static function get_by_slug( string $slug ): ?object {
		global $wpdb;
		$slug = self::normalize_slug( $slug );
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s LIMIT 1', self::types_table(), $slug ) );
		return is_object( $row ) && $slug === (string) $row->slug ? $row : null;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
