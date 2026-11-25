<?php
/**
 * WM Edu Privacy Portal — parent consent ledger
 *
 * Hash-chained table of parental consent entries. A row carries an HMAC-SHA256 signature over its fields, the blinded
 * IP, the fingerprint of the signing key, where the entry came from (source, acting user, note) and the previous row's
 * signature. Every append holds a named database lock, links to the recorded chain tip (an option signed with its own
 * key) and writes the row and the new tip in one transaction. The consent gate shows a consent only while the whole
 * chain verifies, including the recorded tip.
 *
 * Writers since 2026-09-17: the guardian confirmation and revocation links (WM_Edu_Consent_Requests) and the admin
 * screen (WM_Edu_Admin). Until then nothing in the plugin wrote a consent.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Consent_Vault {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own consent table: a cached read would verify a stale chain, and install() owns its schema.

	public const TABLE_NAME   = 'wm_edu_parent_consents';
	public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';
	public const STATUSES     = [ 'GRANTED', 'REVOKED', 'DENIED' ];

	/**
	 * Schema 3 (2026-09-17): source, actor_id, note and row_version columns, signed in the v3 payload; the new tables of
	 * the consent flow; the recorded tip. Schema 2 (2026-09-15): key_id column, no default for granted_status, signature
	 * over every stored field. Rows up to the id kept in LEGACY_BOUNDARY_OPTION were written under schema 1 and verify
	 * with its payload and key; rows with a key_id and an empty row_version were written under schema 2.
	 */
	public const SCHEMA_VERSION         = '3';
	public const SCHEMA_OPTION          = 'wm_edu_schema_version';
	public const LEGACY_BOUNDARY_OPTION = 'wm_edu_legacy_row_boundary';

	/** Stored schema versions under which LEGACY_BOUNDARY_OPTION was recorded (schema 2 introduced it). */
	private const BOUNDARY_SCHEMAS = [ '2', '3' ];

	/** row_version of rows signed with the v3 payload; schema-2 rows keep the column default ''. */
	public const ROW_VERSION = '3';

	/**
	 * Where an entry came from. `direct` is a call of record_consent() by other code that names no source. The admin
	 * sources require the acting user and a note (the reason the school gives for the entry).
	 */
	public const SOURCES       = [ 'direct', 'parent_confirmation', 'parent_revocation', 'admin_entry', 'admin_revocation' ];
	public const ADMIN_SOURCES = [ 'admin_entry', 'admin_revocation' ];
	public const NOTE_MAX      = 500;

	/**
	 * Recorded chain tip: JSON {hash, count, sig} in wp_options (autoload off). It lives outside the table on purpose: a
	 * table whose newest rows were deleted verifies perfectly on its own. The signature uses its own derived key, so
	 * rewriting the option without the key is reported as well.
	 */
	public const TIP_OPTION = 'wm_edu_chain_tip';

	/** Seconds GET_LOCK waits for another append before the write is refused (HTTP 503 for the callers). */
	public const LOCK_TIMEOUT = 5;

	public static function consents_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * CREATE TABLE for dbDelta(). granted_status has no default: an insert that leaves the status out fails instead of
	 * storing a grant (schema 1 defaulted it to GRANTED). The schema-3 columns default to '' / 0, which is what the
	 * schema-2 rows read after the upgrade and how verify_chain() tells them from v3 rows.
	 */
	public static function table_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			student_token VARCHAR(64) NOT NULL,
			parent_email VARCHAR(190) NOT NULL,
			consent_type VARCHAR(50) NOT NULL,
			scope_hash VARCHAR(64) NOT NULL,
			granted_status VARCHAR(20) NOT NULL,
			ip_hmac VARCHAR(64) NOT NULL,
			key_id VARCHAR(16) NOT NULL DEFAULT '',
			prev_hash VARCHAR(64) NOT NULL,
			signature_hash VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			source VARCHAR(20) NOT NULL DEFAULT '',
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note VARCHAR(500) NOT NULL DEFAULT '',
			row_version VARCHAR(4) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY idx_student (student_token),
			KEY idx_type (consent_type),
			KEY idx_signature (signature_hash)
		) {$charset_collate};";
	}

	/**
	 * Every CREATE TABLE of the plugin, in one dbDelta() call.
	 *
	 * @return string[]
	 */
	public static function all_table_sql(): array {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		return [
			self::table_sql( self::consents_table(), $collate ),
			WM_Edu_Consent_Types::types_table_sql( WM_Edu_Consent_Types::types_table(), $collate ),
			WM_Edu_Consent_Types::texts_table_sql( WM_Edu_Consent_Types::texts_table(), $collate ),
			WM_Edu_Guardians::table_sql( WM_Edu_Guardians::table(), $collate ),
			WM_Edu_Consent_Requests::table_sql( WM_Edu_Consent_Requests::table(), $collate ),
		];
	}

	public static function create_tables(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( file_exists( $upgrade_file ) ) {
				require_once $upgrade_file;
			}
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}
		dbDelta( self::all_table_sql() );
	}

	public static function table_exists(): bool {
		global $wpdb;
		$table = self::consents_table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Creates or upgrades the tables and records the schema version. Runs on activation (including all network sites
	 * when network-activated) and, through maybe_upgrade() or wp_initialize_site, on every site whose stored version
	 * differs: a multisite subsite or a copy deployed without activation gets its tables instead of reporting an empty,
	 * valid chain. The recorded tip is written from the table as it stands when no tip exists yet (a site upgrading
	 * from schema 2, or a fresh install with the genesis tip); an existing tip is kept, whatever it says.
	 */
	public static function install( bool $network_wide = false ): void {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
			$site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					self::install( false );
				} finally {
					restore_current_blog();
				}
			}
			return;
		}

		global $wpdb;
		$table     = self::consents_table();
		$had_table = self::table_exists();
		$boundary  = $had_table ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $table ) ) : 0;

		self::create_tables();
		if ( ! self::table_exists() ) {
			return; // dbDelta() failed: the version stays unset and the next load tries again.
		}
		if ( $had_table ) {
			// dbDelta() sets column defaults but never drops one.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ALTER COLUMN granted_status DROP DEFAULT', $table ) );
		}
		// add_option() keeps a boundary an earlier upgrade recorded.
		add_option( self::LEGACY_BOUNDARY_OPTION, (string) $boundary, '', false );

		if ( null === self::recorded_tip() ) {
			[ $tip_hash, $tip_count ] = self::table_tip();
			if ( null === $tip_count || ! self::store_tip( $tip_hash, $tip_count ) ) {
				return; // Without a tip the chain cannot verify under schema 3: the next load tries again.
			}
		}

		WM_Edu_Access::grant_capability();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			self::install();
		}
	}

	/**
	 * Signature of the newest row in the table (by id), or the genesis hash. This is the table's own end, not the recorded
	 * tip; appends link to the recorded tip (recorded_tip()).
	 */
	public static function current_chain_tip(): string {
		global $wpdb;
		$tip = $wpdb->get_var( $wpdb->prepare( 'SELECT signature_hash FROM %i WHERE prev_hash <> %s ORDER BY id DESC LIMIT 1', self::consents_table(), '' ) );
		return is_string( $tip ) && '' !== $tip ? $tip : self::GENESIS_HASH;
	}

	/**
	 * The table's end as a tip: newest signature and row count, or [GENESIS, 0] for an empty table; count null when the
	 * table cannot be read.
	 *
	 * @return array{0: string, 1: int|null}
	 */
	private static function table_tip(): array {
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::consents_table() ) );
		if ( null === $count || '' !== (string) $wpdb->last_error ) {
			return [ self::GENESIS_HASH, null ];
		}
		return [ self::current_chain_tip(), (int) $count ];
	}

	/**
	 * HMAC of a recorded tip under the chain-tip key. Hash and count are JSON-encoded, so neither can borrow digits from
	 * the other.
	 */
	public static function tip_signature( string $hash, int $count ): string {
		return hash_hmac( 'sha256', (string) wp_json_encode( [ 'tip', $hash, $count ] ), WM_Edu_Keys::derive( WM_Edu_Keys::CHAIN_TIP ) );
	}

	/**
	 * The recorded tip, read from the options table directly: get_option() may answer from this request's cache, and an
	 * append that links to a tip another request has already moved forks the chain. null when no tip is recorded.
	 * `signed` is false for a malformed value or a signature that does not verify under the current key.
	 *
	 * @return array{hash: string, count: int, signed: bool}|null
	 */
	public static function recorded_tip(): ?array {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::TIP_OPTION ) );
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$tip = json_decode( $raw, true );
		if ( ! is_array( $tip ) || ! is_string( $tip['hash'] ?? null ) || ! is_int( $tip['count'] ?? null ) || ! is_string( $tip['sig'] ?? null ) ) {
			return [ 'hash' => '', 'count' => -1, 'signed' => false ];
		}
		return [
			'hash'   => $tip['hash'],
			'count'  => $tip['count'],
			'signed' => hash_equals( self::tip_signature( $tip['hash'], $tip['count'] ), $tip['sig'] ),
		];
	}

	private static function store_tip( string $hash, int $count ): bool {
		$value = (string) wp_json_encode(
			[
				'hash'  => $hash,
				'count' => $count,
				'sig'   => self::tip_signature( $hash, $count ),
			]
		);
		return (bool) update_option( self::TIP_OPTION, $value, false );
	}

	/**
	 * Name of the database lock for this site's chain. GET_LOCK names are server-wide: two WordPress sites on the same
	 * MySQL server, or two subsites of a network, must not wait for each other. MySQL limits names to 64 characters.
	 */
	public static function lock_name(): string {
		$database = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		return 'wm_edu_chain_' . substr( hash( 'sha256', $database . '|' . self::consents_table() ), 0, 32 );
	}

	/**
	 * `1` is the lock. `0` is a timeout and NULL an error; neither may write. The SQLite Database Integration plugin
	 * answers GET_LOCK() with the string '1=1' (a stub, class-wp-sqlite-pdo-user-defined-functions.php, read
	 * 2026-09-17), which is not '1': on such a site every consent write is refused.
	 */
	private static function lock(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::lock_name(), self::LOCK_TIMEOUT ) );
	}

	private static function unlock(): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::lock_name() ) );
	}

	/**
	 * Schema-1 signature: seven fields joined with "|" under the legacy key. Kept to verify rows written before schema 2;
	 * record_consent() no longer signs with it.
	 */
	public static function generate_signature(
		string $student_token,
		string $parent_email,
		string $consent_type,
		string $scope_hash,
		string $granted_status,
		string $timestamp,
		string $prev_hash
	): string {
		$payload = implode( '|', [ $student_token, $parent_email, $consent_type, $scope_hash, $granted_status, $timestamp, $prev_hash ] );
		return hash_hmac( 'sha256', $payload, WM_Edu_Keys::legacy_signature_key() );
	}

	/**
	 * Signature of a schema-2 or schema-3 row, chosen by the row's own row_version: '3' signs the v3 payload, anything
	 * else the v2 payload. The payloads are JSON lists (a "|" inside a field cannot move a boundary) that open with their
	 * version label, so a v3 row whose row_version is blanked computes a different signature and fails. v2 covers the
	 * fields of 2026-09-15 including the blinded IP and the key fingerprint; v3 adds source, actor_id and note.
	 *
	 * @param array<string, mixed>|object $row
	 */
	public static function row_signature( array|object $row ): string {
		$r      = (array) $row;
		$v3     = self::ROW_VERSION === (string) ( $r['row_version'] ?? '' );
		$fields = [
			$v3 ? 'v3' : 'v2',
			(string) ( $r['key_id'] ?? '' ),
			(string) ( $r['student_token'] ?? '' ),
			(string) ( $r['parent_email'] ?? '' ),
			(string) ( $r['consent_type'] ?? '' ),
			(string) ( $r['scope_hash'] ?? '' ),
			(string) ( $r['granted_status'] ?? '' ),
			(string) ( $r['ip_hmac'] ?? '' ),
			(string) ( $r['created_at'] ?? '' ),
			(string) ( $r['prev_hash'] ?? '' ),
		];
		if ( $v3 ) {
			$fields[] = (string) ( $r['source'] ?? '' );
			$fields[] = (string) ( $r['actor_id'] ?? '0' );
			$fields[] = (string) ( $r['note'] ?? '' );
		}
		$payload = (string) wp_json_encode( $fields );
		return hash_hmac( 'sha256', $payload, WM_Edu_Keys::derive( WM_Edu_Keys::CONSENT_SIGNATURE ) );
	}

	/**
	 * Appends a consent row and returns its id, or false when it was not written. It refuses (false) instead of guessing:
	 * the status must be exactly GRANTED, REVOKED or DENIED, and the pupil token, the parent e-mail, the consent type, a
	 * 64-character hex scope hash and a valid IP address must be given, within the column lengths (a value MySQL truncates
	 * would no longer match its signature). Until 2026-09-15 a missing or misspelled status was stored as GRANTED, with
	 * 127.0.0.1, EXCURSION and a fixed scope filled in. append_consent() tells the reasons apart.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function record_consent( array $data ): int|false {
		$result = self::append_consent( $data );
		return is_int( $result ) ? $result : false;
	}

	/**
	 * record_consent() with the reason for a refusal: `wm_edu_consent_invalid` (400), `wm_edu_consent_table_missing`
	 * (503), `wm_edu_chain_busy` (503: the lock was not obtained within LOCK_TIMEOUT seconds) and
	 * `wm_edu_consent_not_written` (500: the insert or the tip update failed and the transaction was rolled back).
	 *
	 * Optional fields: `source` (one of SOURCES, default `direct`), `actor_id` (user id, default 0), `note` (up to
	 * NOTE_MAX characters). The admin sources need an actor and a note.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function append_consent( array $data ): int|WP_Error {
		$row = self::normalize_consent( $data );
		if ( false === $row ) {
			return new WP_Error( 'wm_edu_consent_invalid', 'Die Einwilligung ist unvollständig oder ungültig.', [ 'status' => 400 ] );
		}
		if ( ! self::table_exists() ) {
			return new WP_Error( 'wm_edu_consent_table_missing', 'Die Einwilligungstabelle fehlt.', [ 'status' => 503 ] );
		}
		if ( ! self::lock() ) {
			return new WP_Error( 'wm_edu_chain_busy', 'Das Einwilligungsregister ist gerade gesperrt.', [ 'status' => 503 ] );
		}
		try {
			return self::append_locked( $row );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Validated row without prev_hash, created_at and signature, or false.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, string>|false
	 */
	private static function normalize_consent( array $data ): array|false {
		$status = $data['granted_status'] ?? null;
		if ( ! is_string( $status ) || ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$student_token = is_string( $data['student_token'] ?? null ) ? sanitize_text_field( $data['student_token'] ) : '';
		$parent_email  = is_string( $data['parent_email'] ?? null ) ? sanitize_email( $data['parent_email'] ) : '';
		$consent_type  = is_string( $data['consent_type'] ?? null ) ? sanitize_text_field( $data['consent_type'] ) : '';
		$scope_hash    = is_string( $data['scope_hash'] ?? null ) ? strtolower( $data['scope_hash'] ) : '';
		$ip            = is_string( $data['ip_address'] ?? null ) ? filter_var( $data['ip_address'], FILTER_VALIDATE_IP ) : false;
		if (
			'' === $student_token || mb_strlen( $student_token, 'UTF-8' ) > 64
			|| '' === $parent_email || mb_strlen( $parent_email, 'UTF-8' ) > 190
			|| '' === $consent_type || mb_strlen( $consent_type, 'UTF-8' ) > 50
			|| 1 !== preg_match( '/^[0-9a-f]{64}$/', $scope_hash )
			|| false === $ip
		) {
			return false;
		}

		$source = $data['source'] ?? 'direct';
		$actor  = $data['actor_id'] ?? 0;
		$note   = $data['note'] ?? '';
		if ( ! is_string( $source ) || ! in_array( $source, self::SOURCES, true ) || ! is_int( $actor ) || $actor < 0 || ! is_string( $note ) ) {
			return false;
		}
		$note = sanitize_textarea_field( $note );
		if ( mb_strlen( $note, 'UTF-8' ) > self::NOTE_MAX ) {
			return false;
		}
		// An entry made on the admin screen names who made it and why.
		if ( in_array( $source, self::ADMIN_SOURCES, true ) && ( 0 === $actor || '' === trim( $note ) ) ) {
			return false;
		}

		return [
			'student_token'  => $student_token,
			'parent_email'   => $parent_email,
			'consent_type'   => $consent_type,
			'scope_hash'     => $scope_hash,
			'granted_status' => $status,
			// Blinded with the site's own salt: with a literal key in the source anyone could brute-force the 2^32 IPv4
			// space against a stored hash (fleet audit 2026-09-13 §6.2 #24).
			'ip_hmac'        => hash_hmac( 'sha256', (string) $ip, wp_salt( 'auth' ) ),
			'key_id'         => WM_Edu_Keys::signature_key_id(),
			'source'         => $source,
			'actor_id'       => (string) $actor,
			'note'           => $note,
			'row_version'    => self::ROW_VERSION,
		];
	}

	/**
	 * The append itself, under the lock. The row links to the recorded tip, not to the newest row in the table: linking
	 * to the table's end would let the next consent launder a deleted row (the new row and a recounted tip would verify
	 * again). The row and the new tip are written in one transaction, so a worker that dies between the two leaves
	 * neither. When the recorded tip is missing or its signature does not verify, the row links to the table's end and
	 * no new tip is written: the register stays reported as not verifiable instead of being blessed by the next write,
	 * and a revocation can still be recorded. Before the schema-3 upgrade has run no tip is expected, and one is written.
	 *
	 * @param array<string, string> $row
	 */
	private static function append_locked( array $row ): int|WP_Error {
		global $wpdb;
		$tip     = self::recorded_tip();
		$trusted = null !== $tip && $tip['signed'];

		$row['prev_hash']      = $trusted ? $tip['hash'] : self::current_chain_tip();
		$row['created_at']     = current_time( 'mysql', true );
		$row['signature_hash'] = self::row_signature( $row );

		$wpdb->query( 'START TRANSACTION' );
		$inserted = $wpdb->insert( self::consents_table(), $row, array_fill( 0, count( $row ), '%s' ) );
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'wm_edu_consent_not_written', 'Die Einwilligung konnte nicht gespeichert werden.', [ 'status' => 500 ] );
		}
		$id = (int) $wpdb->insert_id;

		$new_count = null;
		if ( $trusted ) {
			$new_count = $tip['count'] + 1;
		} elseif ( null === $tip && self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			$new_count = self::table_tip()[1];
		}
		if ( null !== $new_count && ! self::store_tip( $row['signature_hash'], $new_count ) ) {
			$wpdb->query( 'ROLLBACK' );
			// update_option() already put the new value into the object cache; the database no longer has it.
			wp_cache_delete( self::TIP_OPTION, 'options' );
			return new WP_Error( 'wm_edu_consent_not_written', 'Die Einwilligung konnte nicht gespeichert werden.', [ 'status' => 500 ] );
		}
		$wpdb->query( 'COMMIT' );
		return $id;
	}

	/**
	 * State of the newest entry for a pupil token: 'granted', 'none' (no entry, or the newest one is REVOKED or DENIED)
	 * or 'unverifiable' (the table is missing or the chain does not verify, so no row is trusted). The token and the
	 * type must match exactly: MySQL's default collation also matches other case or accent spellings, so the candidates
	 * are compared again in PHP. An empty $consent_type means any type.
	 */
	public static function consent_state( string $student_token, string $consent_type = '' ): string {
		global $wpdb;
		$student_token = trim( $student_token );
		if ( '' === $student_token ) {
			return 'none';
		}
		if ( ! self::verify_chain()['valid'] ) {
			return 'unverifiable';
		}

		$table = self::consents_table();
		$rows  = '' === $consent_type
			? $wpdb->get_results( $wpdb->prepare( 'SELECT student_token, consent_type, granted_status FROM %i WHERE student_token = %s ORDER BY id DESC', $table, $student_token ) )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT student_token, consent_type, granted_status FROM %i WHERE student_token = %s AND consent_type = %s ORDER BY id DESC', $table, $student_token, $consent_type ) );

		foreach ( (array) $rows as $row ) {
			if ( $student_token === (string) $row->student_token && ( '' === $consent_type || $consent_type === (string) $row->consent_type ) ) {
				return 'GRANTED' === (string) $row->granted_status ? 'granted' : 'none';
			}
		}
		return 'none';
	}

	/**
	 * Whether the newest entry for a pupil token is a GRANTED consent in a register whose chain verifies.
	 * A later REVOKED or DENIED entry wins over an earlier GRANTED one; no entry means no proof.
	 */
	public static function has_valid_consent( string $student_token, string $consent_type = '' ): bool {
		return 'granted' === self::consent_state( $student_token, $consent_type );
	}

	/**
	 * One ledger row by id, or null.
	 */
	public static function row( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::consents_table(), $id ) );
		return is_object( $row ) ? $row : null;
	}

	/**
	 * Every row of the ledger, oldest first. For the admin screen and the CSV export; the register of a school is
	 * counted in thousands of rows, which is what verify_chain() reads anyway.
	 *
	 * @return object[]
	 */
	public static function all_rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', self::consents_table() ) );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * The history of one pupil ID, oldest first. The candidates are compared again in PHP: MySQL's default collation
	 * would also return other case or accent spellings of the ID.
	 *
	 * @return object[]
	 */
	public static function rows_for_student( string $student_token ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE student_token = %s ORDER BY id ASC', self::consents_table(), $student_token ) );
		return array_values( array_filter( is_array( $rows ) ? $rows : [], static fn( object $row ): bool => $student_token === (string) $row->student_token ) );
	}

	/**
	 * The newest row per pupil ID and consent type — the state that counts — ordered by ID and type. Grouped in PHP for
	 * the same reason: GROUP BY would put two spellings of one ID into one group.
	 *
	 * @return object[]
	 */
	public static function current_entries(): array {
		$newest = [];
		foreach ( self::all_rows() as $row ) {
			// JSON as the key: a "|" inside a pupil ID would let two different pairs share a joined key.
			$newest[ (string) wp_json_encode( [ (string) $row->student_token, (string) $row->consent_type ] ) ] = $row;
		}
		uasort(
			$newest,
			static fn( object $a, object $b ): int => [ (string) $a->student_token, (string) $a->consent_type ] <=> [ (string) $b->student_token, (string) $b->consent_type ]
		);
		return array_values( $newest );
	}

	/**
	 * Walks the table by id and checks every link and signature, then the recorded tip. `rows` is the number of rows read
	 * (null without a table), `checked` the number verified before the first break, `legacy_rows` the schema-1 rows
	 * among them. Without a table, or when the read fails, the chain is not valid: until 2026-09-15 both cases reported
	 * a valid, empty chain. `tip_mismatch` is set when the rows verify but the recorded tip is missing, unsigned or does
	 * not end where the table ends (rows deleted, or appended without the plugin); the tip is checked once the schema-3
	 * upgrade has run on the site.
	 *
	 * @return array{valid: bool, rows: int|null, checked: int, legacy_rows: int, broken_at_id: int|null, error: string|null, missing_table: bool, key_changed: bool, tip_mismatch: bool}
	 */
	public static function verify_chain(): array {
		global $wpdb;
		$result = [
			'valid'         => false,
			'rows'          => null,
			'checked'       => 0,
			'legacy_rows'   => 0,
			'broken_at_id'  => null,
			'error'         => null,
			'missing_table' => false,
			'key_changed'   => false,
			'tip_mismatch'  => false,
		];

		if ( ! self::table_exists() ) {
			$result['missing_table'] = true;
			$result['error']         = 'Die Einwilligungstabelle fehlt.';
			return $result;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', self::consents_table() ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			$result['error'] = 'Das Einwilligungsregister konnte nicht gelesen werden.';
			return $result;
		}
		$result['rows'] = count( $rows );

		// Before the schema-2 upgrade has run on this site every row is a schema-1 row.
		$stored_schema = (string) get_option( self::SCHEMA_OPTION, '' );
		$boundary      = in_array( $stored_schema, self::BOUNDARY_SCHEMAS, true ) ? (int) get_option( self::LEGACY_BOUNDARY_OPTION, 0 ) : PHP_INT_MAX;
		$key_id        = WM_Edu_Keys::signature_key_id();
		$expected_prev = self::GENESIS_HASH;

		foreach ( $rows as $row ) {
			$id                     = (int) $row->id;
			$result['broken_at_id'] = $id;

			if ( (string) $row->prev_hash !== $expected_prev ) {
				$result['error'] = sprintf( 'Kettenbruch bei Einwilligung-ID %d.', $id );
				return $result;
			}

			$row_key_id  = (string) ( $row->key_id ?? '' );
			$row_version = (string) ( $row->row_version ?? '' );
			if ( '' === $row_key_id ) {
				if ( $id > $boundary ) {
					$result['error'] = sprintf( 'Einwilligung-ID %d hat keine Schlüsselkennung, wurde aber nach der Umstellung auf Schema 2 geschrieben.', $id );
					return $result;
				}
				$expected = self::generate_signature(
					(string) $row->student_token,
					(string) $row->parent_email,
					(string) $row->consent_type,
					(string) $row->scope_hash,
					(string) $row->granted_status,
					(string) $row->created_at,
					(string) $row->prev_hash
				);
				++$result['legacy_rows'];
			} elseif ( ! hash_equals( $key_id, $row_key_id ) ) {
				$result['key_changed'] = true;
				$result['error']       = sprintf( 'Einwilligung-ID %d trägt eine andere Schlüsselkennung: der Auth-Salt der Website wurde geändert oder die Zeile bearbeitet.', $id );
				return $result;
			} elseif ( '' !== $row_version && self::ROW_VERSION !== $row_version ) {
				$result['error'] = sprintf( 'Einwilligung-ID %d nennt eine unbekannte Zeilenversion.', $id );
				return $result;
			} else {
				$expected = self::row_signature( $row );
			}

			if ( ! hash_equals( $expected, (string) $row->signature_hash ) ) {
				$result['error'] = sprintf( 'Signatur-Ungültigkeit bei Einwilligung-ID %d.', $id );
				return $result;
			}

			$expected_prev = (string) $row->signature_hash;
			++$result['checked'];
		}
		$result['broken_at_id'] = null;

		if ( self::SCHEMA_VERSION === $stored_schema ) {
			$tip = self::recorded_tip();
			if ( null === $tip ) {
				$result['tip_mismatch'] = true;
				$result['error']        = 'Die aufgezeichnete Kettenspitze fehlt.';
				return $result;
			}
			if ( ! $tip['signed'] ) {
				$result['tip_mismatch'] = true;
				$result['error']        = 'Die Signatur der aufgezeichneten Kettenspitze ist ungültig.';
				return $result;
			}
			if ( $tip['count'] !== $result['rows'] || ! hash_equals( $tip['hash'], $expected_prev ) ) {
				$result['tip_mismatch'] = true;
				$result['error']        = sprintf( 'Das Register endet nicht an der aufgezeichneten Kettenspitze (%1$d Zeilen aufgezeichnet, %2$d vorhanden): Zeilen fehlen oder wurden ohne das Plugin angehängt.', $tip['count'], $result['rows'] );
				return $result;
			}
		}

		$result['valid'] = true;
		return $result;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}
