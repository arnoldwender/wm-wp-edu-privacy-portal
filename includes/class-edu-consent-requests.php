<?php
/**
 * WM Edu Privacy Portal — guardian requests: confirmation link, receipt, revocation link, retention
 *
 * A guardian's form submission becomes a pending request only when the pupil ID and the address are a registered pair
 * (WM_Edu_Guardians); the caller answers the same either way. The request is mailed as a one-time confirmation link
 * that expires after the configured hours. Nothing is written to the ledger until the link's page is confirmed with a
 * POST; a GET only shows what would be confirmed. After confirmation the receipt mail carries a revocation link for the
 * granted consents, which records REVOKED rows, again only on POST.
 *
 * Tokens are 64 hex characters from random_bytes(); the table stores only their SHA-256. Each state change claims the
 * request with an UPDATE … WHERE status = …, so a link clicked twice at once records once. Once confirmed, the request
 * keeps no pupil ID and no address: the ledger rows hold them, and the request holds only their ids.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Consent_Requests {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a cached request would let a used link be used again.

	public const TABLE_NAME = 'wm_edu_consent_requests';

	/** Daily WP-Cron event that deletes request data after the retention period. */
	public const CRON_HOOK = 'wm_edu_retention_cleanup';

	/** Single immediate event that delivers the mail queue on a server that cannot close the response early. */
	public const MAIL_HOOK = 'wm_edu_deliver_mail';

	/** Transient that carries such a queue; the event gets the key, never the message. */
	private const MAIL_TRANSIENT_PREFIX = 'wm_edu_mail_';

	/**
	 * Mails waiting for the end of the request.
	 *
	 * @var array<int, array{to: string, subject: string, message: string, request: int}>
	 */
	private static array $mail_queue = [];

	/**
	 * Hooks the two ends of the mail queue. Called from the main file on every load, so a mail queued by any request
	 * leaves at the end of it.
	 */
	public static function init(): void {
		add_action( 'shutdown', [ self::class, 'flush_mail_queue' ], 100 );
		add_action( self::MAIL_HOOK, [ self::class, 'deliver_scheduled_mail' ] );
	}

	/** Query arguments of the links in the mails. */
	public const CONFIRM_PARAM = 'wm_edu_confirm';
	public const REVOKE_PARAM  = 'wm_edu_revoke';

	/** A claim (confirming / revoking) older than this is taken as an interrupted request and released by cleanup(). */
	public const STALE_CLAIM_SECONDS = 3600;

	private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	public static function table_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			confirm_hash VARCHAR(64) NOT NULL,
			revoke_hash VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL,
			student_token VARCHAR(64) NOT NULL DEFAULT '',
			parent_email VARCHAR(190) NOT NULL DEFAULT '',
			decisions LONGTEXT NOT NULL,
			consent_ids TEXT NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_confirm (confirm_hash),
			KEY idx_revoke (revoke_hash),
			KEY idx_status (status)
		) {$charset_collate};";
	}

	// -----------------------------------------------------------------------------------------------------------------
	// Rate limit
	// -----------------------------------------------------------------------------------------------------------------

	/**
	 * Counts one submission for the client address and for the typed e-mail address and says whether either is over its
	 * hourly limit. Every submission counts, valid or not, registered or not, so the limit says nothing about the
	 * registry. The window is fixed from the first submission (a transient holds the count and the window's start); the
	 * keys are HMACs, so neither address is stored in the options table.
	 */
	public static function rate_limited( string $ip, string $email, ?int $now = null ): bool {
		$now      = $now ?? time();
		$settings = WM_Edu_Settings::all();
		$limited  = false;
		$counters = [
			'ip'    => [ $ip, $settings['rate_ip_per_hour'] ],
			'email' => [ strtolower( trim( $email ) ), $settings['rate_email_per_hour'] ],
		];
		foreach ( $counters as $kind => [ $value, $limit ] ) {
			if ( '' === $value ) {
				continue;
			}
			$key    = 'wm_edu_rl_' . $kind . '_' . substr( hash_hmac( 'sha256', $value, WM_Edu_Keys::derive( WM_Edu_Keys::RATE_LIMIT ) ), 0, 32 );
			$window = get_transient( $key );
			$window = is_array( $window ) && isset( $window['n'], $window['start'] ) && $now - (int) $window['start'] < HOUR_IN_SECONDS ? $window : [
				'n'     => 0,
				'start' => $now,
			];
			if ( (int) $window['n'] >= $limit ) {
				$limited = true;
				continue;
			}
			$window['n'] = (int) $window['n'] + 1;
			set_transient( $key, $window, max( 1, HOUR_IN_SECONDS - ( $now - (int) $window['start'] ) ) );
		}
		return $limited;
	}

	/**
	 * The client address WordPress sees (REMOTE_ADDR), or '' when it is not a valid IP. Forwarded-for headers are not
	 * read: any client can set them.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP address, nothing else passes.
		return is_string( $ip ) ? $ip : '';
	}

	// -----------------------------------------------------------------------------------------------------------------
	// Request
	// -----------------------------------------------------------------------------------------------------------------

	/**
	 * A validated submission becomes a pending request and a confirmation mail, when the pair is registered. The return
	 * value is for tests and logs only; the form answers the same for every outcome ('queued', 'unregistered',
	 * 'not_written'). The mail is queued, not sent: see queue_mail().
	 *
	 * @param array<int, array{slug: string, status: string, scope_hash: string, label: string}> $decisions
	 */
	public static function submit( string $student_token, string $email, array $decisions, string $page_url, ?int $now = null ): string {
		global $wpdb;
		if ( ! WM_Edu_Guardians::matches( $student_token, $email ) ) {
			return 'unregistered';
		}
		$now      = $now ?? time();
		$hours    = WM_Edu_Settings::get( 'confirm_hours' );
		$token    = bin2hex( random_bytes( 32 ) );
		$inserted = $wpdb->insert(
			self::table(),
			[
				'confirm_hash'  => hash( 'sha256', $token ),
				'revoke_hash'   => '',
				'status'        => 'pending',
				'student_token' => $student_token,
				'parent_email'  => $email,
				'decisions'     => (string) wp_json_encode( array_values( $decisions ) ),
				'consent_ids'   => '[]',
				'created_at'    => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', $now + $hours * HOUR_IN_SECONDS ),
				'updated_at'    => gmdate( 'Y-m-d H:i:s', $now ),
			],
			array_fill( 0, 10, '%s' )
		);
		if ( false === $inserted ) {
			return 'not_written';
		}
		$id = (int) $wpdb->insert_id;

		$lines = [];
		foreach ( $decisions as $decision ) {
			$lines[] = '- ' . $decision['label'] . ': ' . ( 'GRANTED' === $decision['status'] ? __( 'erteilen', 'wm-edu-privacy-portal' ) : __( 'verweigern', 'wm-edu-privacy-portal' ) );
		}
		$body = sprintf(
			/* translators: 1: site name, 2: pupil ID, 3: list of decisions, 4: validity in hours, 5: confirmation link. */
			__( "Guten Tag,\n\nüber das Einwilligungsformular von %1\$s wurden für die Schüler-ID %2\$s folgende Angaben gemacht:\n\n%3\$s\n\nErfasst werden diese Angaben erst, wenn Sie sie über den folgenden Link bestätigen. Der Link ist %4\$d Stunden gültig und kann einmal verwendet werden:\n\n%5\$s\n\nWenn Sie diese Angaben nicht gemacht haben, müssen Sie nichts tun: Ohne Bestätigung wird nichts erfasst.", 'wm-edu-privacy-portal' ),
			self::site_name(),
			$student_token,
			implode( "\n", $lines ),
			$hours,
			add_query_arg( self::CONFIRM_PARAM, $token, $page_url )
		);
		/* translators: %s: site name. */
		self::queue_mail( $email, sprintf( __( '[%s] Bitte bestätigen Sie Ihre Angaben zur Einwilligung', 'wm-edu-privacy-portal' ), self::site_name() ), $body, $id );
		return 'queued';
	}

	/**
	 * The request a token belongs to, or null for a malformed or unknown token. $column is confirm_hash or revoke_hash.
	 */
	private static function find( string $column, string $token ): ?object {
		global $wpdb;
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}
		$hash = hash( 'sha256', $token );
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s LIMIT 1', self::table(), $column, $hash ) );
		return is_object( $row ) && hash_equals( (string) $row->{$column}, $hash ) ? $row : null;
	}

	/**
	 * A pending request whose confirmation link has not expired, or null.
	 */
	public static function confirmable( string $token, ?int $now = null ): ?object {
		$request = self::find( 'confirm_hash', $token );
		if ( null === $request || 'pending' !== (string) $request->status || (string) $request->expires_at <= gmdate( 'Y-m-d H:i:s', $now ?? time() ) ) {
			return null;
		}
		return $request;
	}

	/**
	 * A confirmed request whose revocation link has not been used, or null.
	 */
	public static function revocable( string $token ): ?object {
		$request = self::find( 'revoke_hash', $token );
		return null !== $request && 'confirmed' === (string) $request->status ? $request : null;
	}

	/**
	 * @return array<int, array{slug: string, status: string, scope_hash: string, label: string, consent_id?: int}>
	 */
	public static function decisions( object $request ): array {
		$decisions = json_decode( (string) $request->decisions, true );
		return is_array( $decisions ) ? array_values( array_filter( $decisions, 'is_array' ) ) : [];
	}

	/**
	 * Takes a request from one status to another only when it is still in the first one. True when this call made the
	 * change, false when another request was faster or the row changed.
	 */
	private static function claim( int $id, string $from, string $to ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status = %s', self::table(), $to, gmdate( 'Y-m-d H:i:s' ), $id, $from ) );
	}

	/**
	 * Records a confirmed request in the ledger. Outcomes: 'confirmed'; 'invalid' (unknown, expired or used link);
	 * 'busy' (the request is being confirmed right now, or the ledger was locked: nothing lost, the link stays valid, and
	 * decisions already recorded are not recorded again); 'error' (the ledger refused a row, for example without a valid
	 * client IP).
	 */
	public static function confirm( string $token, string $ip, string $page_url, ?int $now = null ): string {
		global $wpdb;
		$request = self::confirmable( $token, $now );
		if ( null === $request ) {
			$any = self::find( 'confirm_hash', $token );
			return null !== $any && 'confirming' === (string) $any->status ? 'busy' : 'invalid';
		}
		$id = (int) $request->id;
		if ( ! self::claim( $id, 'pending', 'confirming' ) ) {
			return 'busy';
		}

		$decisions = self::decisions( $request );
		foreach ( $decisions as $index => $decision ) {
			if ( isset( $decision['consent_id'] ) ) {
				continue; // Recorded by an earlier attempt that was interrupted.
			}
			$result = WM_Edu_Consent_Vault::append_consent(
				[
					'student_token'  => (string) $request->student_token,
					'parent_email'   => (string) $request->parent_email,
					'consent_type'   => (string) $decision['slug'],
					'scope_hash'     => (string) $decision['scope_hash'],
					'granted_status' => (string) $decision['status'],
					'ip_address'     => $ip,
					'source'         => 'parent_confirmation',
				]
			);
			if ( $result instanceof WP_Error ) {
				// Progress is kept, so a retry records only what is missing; the link is released for that retry.
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET decisions = %s, status = %s WHERE id = %d', self::table(), (string) wp_json_encode( $decisions ), 'pending', $id ) );
				return 400 === ( $result->get_error_data()['status'] ?? 0 ) ? 'error' : 'busy';
			}
			$decisions[ $index ]['consent_id'] = $result;
		}

		$granted = array_values( array_map( static fn( array $d ): int => (int) $d['consent_id'], array_filter( $decisions, static fn( array $d ): bool => 'GRANTED' === $d['status'] ) ) );
		$revoke  = [] === $granted ? '' : bin2hex( random_bytes( 32 ) );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, revoke_hash = %s, student_token = %s, parent_email = %s, decisions = %s, consent_ids = %s, updated_at = %s WHERE id = %d',
				self::table(),
				'confirmed',
				'' === $revoke ? '' : hash( 'sha256', $revoke ),
				'',
				'',
				(string) wp_json_encode( $decisions ),
				(string) wp_json_encode( $granted ),
				gmdate( 'Y-m-d H:i:s', $now ?? time() ),
				$id
			)
		);

		$lines = [];
		foreach ( $decisions as $decision ) {
			$lines[] = '- ' . $decision['label'] . ': ' . ( 'GRANTED' === $decision['status'] ? __( 'erteilt', 'wm-edu-privacy-portal' ) : __( 'verweigert', 'wm-edu-privacy-portal' ) );
		}
		$body = sprintf(
			/* translators: 1: pupil ID, 2: date and time, 3: site name, 4: list of decisions. */
			__( "Guten Tag,\n\nfür die Schüler-ID %1\$s wurden am %2\$s folgende Entscheidungen im Einwilligungsregister von %3\$s erfasst:\n\n%4\$s", 'wm-edu-privacy-portal' ),
			(string) $request->student_token,
			wp_date( 'd.m.Y H:i', $now ),
			self::site_name(),
			implode( "\n", $lines )
		);
		if ( '' !== $revoke ) {
			$body .= "\n\n" . sprintf(
				/* translators: %s: revocation link. */
				__( "Erteilte Einwilligungen können Sie jederzeit über diesen Link widerrufen:\n\n%s", 'wm-edu-privacy-portal' ),
				add_query_arg( self::REVOKE_PARAM, $revoke, $page_url )
			);
		}
		/* translators: %s: site name. */
		self::queue_mail( (string) $request->parent_email, sprintf( __( '[%s] Ihre Angaben zur Einwilligung wurden erfasst', 'wm-edu-privacy-portal' ), self::site_name() ), $body );
		return 'confirmed';
	}

	/**
	 * Ledger rows a confirmed request granted, in the order recorded. Rows that no longer exist are left out.
	 *
	 * @return object[]
	 */
	public static function granted_rows( object $request ): array {
		global $wpdb;
		$ids  = json_decode( (string) $request->consent_ids, true );
		$rows = [];
		foreach ( is_array( $ids ) ? $ids : [] as $consent_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', WM_Edu_Consent_Vault::consents_table(), (int) $consent_id ) );
			if ( is_object( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Records REVOKED for every consent a confirmed request granted. Outcomes: 'revoked', 'invalid' (unknown or used
	 * link), 'busy' (being revoked right now, or the ledger was locked: the link stays valid and consents already revoked
	 * are not revoked again), 'error' (a granted row is gone from the ledger, or the ledger refused a row).
	 */
	public static function revoke( string $token, string $ip, ?int $now = null ): string {
		global $wpdb;
		$request = self::revocable( $token );
		if ( null === $request ) {
			$any = self::find( 'revoke_hash', $token );
			return null !== $any && 'revoking' === (string) $any->status ? 'busy' : 'invalid';
		}
		$id  = (int) $request->id;
		$ids = json_decode( (string) $request->consent_ids, true );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : [];
		if ( ! self::claim( $id, 'confirmed', 'revoking' ) ) {
			return 'busy';
		}
		$rows = self::granted_rows( $request );
		if ( count( $rows ) !== count( $ids ) ) {
			self::claim( $id, 'revoking', 'confirmed' );
			return 'error';
		}

		$remaining = $ids;
		$labels    = [];
		foreach ( $rows as $row ) {
			$result = WM_Edu_Consent_Vault::append_consent(
				[
					'student_token'  => (string) $row->student_token,
					'parent_email'   => (string) $row->parent_email,
					'consent_type'   => (string) $row->consent_type,
					'scope_hash'     => (string) $row->scope_hash,
					'granted_status' => 'REVOKED',
					'ip_address'     => $ip,
					'source'         => 'parent_revocation',
				]
			);
			if ( $result instanceof WP_Error ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET consent_ids = %s, status = %s WHERE id = %d', self::table(), (string) wp_json_encode( array_values( $remaining ) ), 'confirmed', $id ) );
				return 400 === ( $result->get_error_data()['status'] ?? 0 ) ? 'error' : 'busy';
			}
			$remaining = array_values( array_diff( $remaining, [ (int) $row->id ] ) );
			$type      = WM_Edu_Consent_Types::get_by_slug( (string) $row->consent_type );
			$labels[]  = '- ' . ( null !== $type ? (string) $type->label : (string) $row->consent_type );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, consent_ids = %s, updated_at = %s WHERE id = %d', self::table(), 'revoked', '[]', gmdate( 'Y-m-d H:i:s', $now ?? time() ), $id ) );

		if ( [] !== $rows ) {
			$body = sprintf(
				/* translators: 1: pupil ID, 2: date and time, 3: site name, 4: list of consent types. */
				__( "Guten Tag,\n\nfür die Schüler-ID %1\$s wurde am %2\$s der Widerruf folgender Einwilligungen im Einwilligungsregister von %3\$s erfasst:\n\n%4\$s", 'wm-edu-privacy-portal' ),
				(string) $rows[0]->student_token,
				wp_date( 'd.m.Y H:i', $now ),
				self::site_name(),
				implode( "\n", $labels )
			);
			/* translators: %s: site name. */
			self::queue_mail( (string) $rows[0]->parent_email, sprintf( __( '[%s] Ihr Widerruf wurde erfasst', 'wm-edu-privacy-portal' ), self::site_name() ), $body );
		}
		return 'revoked';
	}

	// -----------------------------------------------------------------------------------------------------------------
	// Retention
	// -----------------------------------------------------------------------------------------------------------------

	/**
	 * Deletes request data after the retention period: pending requests whose link expired more than retention_days ago,
	 * used revocation requests and confirmed requests without a revocation link whose last change is that old. Confirmed
	 * requests with an unused revocation link stay (the link must keep working; they hold no pupil ID and no address).
	 * Claims older than STALE_CLAIM_SECONDS are released. The ledger is never touched.
	 *
	 * @return array{pending_deleted: int, closed_deleted: int, claims_released: int}
	 */
	public static function cleanup( ?int $now = null ): array {
		global $wpdb;
		$now    = $now ?? time();
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - WM_Edu_Settings::get( 'retention_days' ) * DAY_IN_SECONDS );
		$stale  = gmdate( 'Y-m-d H:i:s', $now - self::STALE_CLAIM_SECONDS );
		$table  = self::table();
		return [
			'pending_deleted' => (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status = %s AND expires_at < %s', $table, 'pending', $cutoff ) ),
			'closed_deleted'  => (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %s AND ( status = %s OR ( status = %s AND revoke_hash = %s ) )', $table, $cutoff, 'revoked', 'confirmed', '' ) ),
			'claims_released' => (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s WHERE status = %s AND updated_at < %s', $table, 'pending', 'confirming', $stale ) )
				+ (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s WHERE status = %s AND updated_at < %s', $table, 'confirmed', 'revoking', $stale ) ),
		];
	}

	/**
	 * Schedules the daily cleanup when it is not scheduled. Hooked to plugins_loaded, not only to activation: an update
	 * does not run the activation hook again, and a cron wired to it alone never runs on a site that was already active.
	 */
	public static function sync_schedule(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// -----------------------------------------------------------------------------------------------------------------
	// Mail
	// -----------------------------------------------------------------------------------------------------------------

	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * A mail never goes out inside the request that asked for it. A registered pair would otherwise cost one mail-server
	 * round trip more than an unknown one, and the response TIME would answer what the response BODY refuses to answer:
	 * whoever tries pairs could tell the registered ones apart by measuring latency. Equalising by waiting is no answer
	 * either — it slows every visitor down and is just as measurable. So the queue is flushed on `shutdown`, after the
	 * response has been closed, or handed to a single immediate event when this server cannot close it.
	 *
	 * @param int $request_id The pending request this mail belongs to, 0 for a mail that stands on its own.
	 */
	private static function queue_mail( string $to, string $subject, string $body, int $request_id = 0 ): void {
		self::$mail_queue[] = [
			'to'      => $to,
			'subject' => $subject,
			'message' => $body,
			'request' => $request_id,
		];
	}

	/**
	 * Whether this server can send the response before PHP ends. PHP-FPM and LiteSpeed can; mod_php cannot, and a host
	 * with another SAPI can say so through the filter.
	 */
	public static function can_close_response(): bool {
		return (bool) apply_filters( 'wm_edu_can_close_response', function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ) );
	}

	private static function close_response(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			return;
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Hooked to `shutdown`: closes the response and then hands the queue to the mail server, so the time the visitor
	 * measured is already over. When the response cannot be closed, the queue goes into a transient and a single
	 * immediate event delivers it, which again leaves the mail out of the measured request. The queue is emptied before
	 * anything is delivered, so a second flush in the same request cannot send a mail twice.
	 */
	public static function flush_mail_queue(): void {
		if ( [] === self::$mail_queue ) {
			return;
		}
		$queue            = self::$mail_queue;
		self::$mail_queue = [];

		if ( self::can_close_response() ) {
			self::close_response();
			foreach ( $queue as $mail ) {
				self::deliver( $mail );
			}
			return;
		}

		$key = self::MAIL_TRANSIENT_PREFIX . bin2hex( random_bytes( 16 ) );
		set_transient( $key, $queue, 15 * MINUTE_IN_SECONDS );
		wp_schedule_single_event( time(), self::MAIL_HOOK, [ $key ] );
	}

	/**
	 * Hooked to MAIL_HOOK: delivers a queue the flush could not deliver itself.
	 */
	public static function deliver_scheduled_mail( string $key ): void {
		if ( ! str_starts_with( $key, self::MAIL_TRANSIENT_PREFIX ) ) {
			return;
		}
		$queue = get_transient( $key );
		delete_transient( $key );
		foreach ( is_array( $queue ) ? $queue : [] as $mail ) {
			if ( is_array( $mail ) ) {
				self::deliver( $mail );
			}
		}
	}

	/**
	 * One mail. A confirmation mail the site cannot send leaves a pending request nobody can confirm, which would keep
	 * the pupil ID and the address for nothing: that request is deleted here, where the outcome is known.
	 *
	 * @param array{to: string, subject: string, message: string, request: int} $mail
	 */
	private static function deliver( array $mail ): void {
		global $wpdb;
		$sent = (bool) wp_mail( $mail['to'], $mail['subject'], $mail['message'], [ 'Content-Type: text/plain; charset=UTF-8' ] );
		if ( ! $sent && (int) $mail['request'] > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND status = %s', self::table(), (int) $mail['request'], 'pending' ) );
		}
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
