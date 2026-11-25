<?php
/**
 * Runtime check of wm-edu-privacy-portal inside a real WordPress, rolled back.
 *
 * Calls both REST routes through rest_do_request() (so core's argument validation runs), refuses and records consents,
 * inserts a row that does not verify, simulates a missing table, renders both shortcodes and runs the hub adapter.
 * Everything runs inside a transaction that is rolled back when PHP shuts down: transaction statements of other plugins
 * become savepoints, DDL is refused, a persistent object cache is refused. After the rollback the row count of the
 * consent table and the plugin options are read again with SQL and compared with the values from before; a difference
 * exits 1. Auto-increment counters are not transactional. No key or salt value is printed.
 *
 *   wp eval-file wp-content/plugins/wm-edu-privacy-portal/tests/wp-runtime-edu.php --user=1
 *   ssh <box> 'wp --path=<www> eval-file - --user=1' < tests/wp-runtime-edu.php
 *
 * `wp eval-file` runs the file inside a method: state lives in $GLOBALS.
 *
 * @package WM_Edu_Privacy_Portal
 */

defined( 'ABSPATH' ) || exit;

if ( wp_using_ext_object_cache() ) {
	WP_CLI::error( 'A persistent object cache is active; the rollback would leave cached options behind. Not run.' );
}
if ( ! class_exists( 'WM_Edu_Keys' ) || ! method_exists( 'WM_Edu_Consent_Vault', 'consent_state' ) ) {
	WP_CLI::error( 'wm-edu-privacy-portal with schema 2 (WM_Edu_Keys, consent_state) is not loaded.' );
}

global $wpdb;
$GLOBALS['edrt']       = [ 'pass' => 0, 'fail' => 0 ];
$GLOBALS['edrt_check'] = static function ( bool $ok, string $label ): void {
	$GLOBALS['edrt'][ $ok ? 'pass' : 'fail' ]++;
	echo ( $ok ? '  [PASS] ' : '  [FAIL] ' ) . $label . "\n";
};
$GLOBALS['edrt_state'] = static function (): array {
	global $wpdb;
	$table = WM_Edu_Consent_Vault::consents_table();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	$state  = [
		'rows'    => $exists ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) : null,
		'options' => $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, $wpdb->esc_like( 'wm_edu_' ) . '%' ), ARRAY_A ),
	];
	// The tables the consent flow writes: the rollback has to leave them as they were too.
	foreach ( [ 'types' => WM_Edu_Consent_Types::types_table(), 'texts' => WM_Edu_Consent_Types::texts_table(), 'guardians' => WM_Edu_Guardians::table(), 'requests' => WM_Edu_Consent_Requests::table() ] as $label => $name ) {
		$state[ $label ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) === $name
			? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $name ) )
			: null;
	}
	// phpcs:enable
	return $state;
};
$GLOBALS['edrt_before'] = $GLOBALS['edrt_state']();

// --- Transaction with savepoint rewrite ------------------------------------------------------------------------------
$GLOBALS['edrt_depth']   = 0;
$GLOBALS['edrt_rewrite'] = static function ( $query ) {
	$q = ltrim( (string) $query );
	if ( preg_match( '/^(START\s+TRANSACTION|BEGIN)\b/i', $q ) ) {
		++$GLOBALS['edrt_depth'];
		return 'SAVEPOINT edrt_' . $GLOBALS['edrt_depth'];
	}
	if ( preg_match( '/^COMMIT\b/i', $q ) ) {
		return $GLOBALS['edrt_depth'] > 0 ? 'RELEASE SAVEPOINT edrt_' . $GLOBALS['edrt_depth']-- : 'SELECT 1';
	}
	if ( preg_match( '/^ROLLBACK\b/i', $q ) ) {
		return $GLOBALS['edrt_depth'] > 0 ? 'ROLLBACK TO SAVEPOINT edrt_' . $GLOBALS['edrt_depth']-- : 'SELECT 1';
	}
	return preg_match( '/^(ALTER|CREATE|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES|SET\s+autocommit)\b/i', $q ) ? '' : $query;
};
$wpdb->query( 'START TRANSACTION' );
add_filter( 'query', $GLOBALS['edrt_rewrite'] );
register_shutdown_function(
	static function (): void {
		global $wpdb;
		remove_filter( 'query', $GLOBALS['edrt_rewrite'] );
		$wpdb->query( 'ROLLBACK' );
		$after = $GLOBALS['edrt_state']();
		$same  = $after === $GLOBALS['edrt_before'];
		echo "\n  ROLLBACK CHECK: " . ( $same ? 'consent rows and plugin options equal to before' : 'DIFFERENT ' . wp_json_encode( [ 'before' => $GLOBALS['edrt_before'], 'after' => $after ] ) ) . "\n";
		echo "  RUNTIME RESULTS: {$GLOBALS['edrt']['pass']} PASSED | {$GLOBALS['edrt']['fail']} FAILED\n";
		exit( $same && 0 === $GLOBALS['edrt']['fail'] ? 0 : 1 );
	}
);

$check = $GLOBALS['edrt_check'];
$call  = static function ( string $route, array $params ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', $route );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $params ) );
	return rest_ensure_response( rest_do_request( $request ) );
};
$table = WM_Edu_Consent_Vault::consents_table();
$count = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

echo "\n=== WM EDU PRIVACY PORTAL · RUNTIME (rolled back) ===\n";
echo '  [INFO] before: ' . wp_json_encode( $GLOBALS['edrt_before'] ) . "\n";

// --- Routes and schema -----------------------------------------------------------------------------------------------
$routes = rest_get_server()->get_routes();
$targs  = $routes['/wm-edu/v1/tokenize'][0]['args'] ?? [];
$check( isset( $routes['/wm-edu/v1/tokenize'], $routes['/wm-edu/v1/detokenize'] ) && true === ( $targs['text']['required'] ?? false ) && isset( $targs['known_students']['validate_callback'] ), 'both routes registered with argument schemas' );

$columns = array_column( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A ), null, 'Field' );
$check( WM_Edu_Consent_Vault::SCHEMA_VERSION === (string) get_option( WM_Edu_Consent_Vault::SCHEMA_OPTION ) && isset( $columns['key_id'], $columns['granted_status'], $columns['source'], $columns['actor_id'], $columns['note'], $columns['row_version'] ) && null === $columns['granted_status']['Default'], 'schema ' . WM_Edu_Consent_Vault::SCHEMA_VERSION . ' on this install: key_id, source, actor_id, note and row_version columns, granted_status without default (was: DEFAULT GRANTED)' );
$tip_now = WM_Edu_Consent_Vault::recorded_tip();
$check( null !== $tip_now && true === $tip_now['signed'] && $tip_now['count'] === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'the recorded chain tip exists, is signed and counts the rows of the table' );
$lock = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', WM_Edu_Consent_Vault::lock_name(), 1 ) );
$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', WM_Edu_Consent_Vault::lock_name() ) );
$check( '1' === $lock, "this database answers GET_LOCK with 1 (a site that answers otherwise refuses every consent write): {$lock}" );

// --- REST ------------------------------------------------------------------------------------------------------------
$statuses = [
	$call( '/wm-edu/v1/tokenize', [ 'text' => 'Max Müller aus Klasse 9b', 'known_students' => [ 'Max Müller' ] ] )->get_status(),
	$call( '/wm-edu/v1/tokenize', [ 'text' => 'Max aus Klasse 9b', 'known_students' => [ 'Max' => 7 ] ] )->get_status(),
	$call( '/wm-edu/v1/detokenize', [ 'text' => 'Hallo [[X]]', 'token_map' => [ '[[X]]' => 5 ] ] )->get_status(),
	$call( '/wm-edu/v1/detokenize', [ 'text' => 'Hallo a', 'token_map' => [ 'a' ] ] )->get_status(),
	$call( '/wm-edu/v1/tokenize', [] )->get_status(),
];
$check( [ 400, 400, 400, 400, 400 ] === $statuses, 'a list of names, a number as token, a number or a list as token_map, and a missing text -> 400 (was: fatal TypeError, 200 without text): ' . implode( ',', $statuses ) );

$text = "Schülerin geboren am 15.03.2012, Klasse 9b.\nAufgabe 1: Punkte <3 von 10\nAufgabe 2: Quote 80%ad erreicht";
$r    = $call( '/wm-edu/v1/tokenize', [ 'text' => $text ] );
$out  = (string) ( $r->get_data()['data']['sanitized_text'] ?? '' );
$check( 200 === $r->get_status() && 2 === substr_count( $out, "\n" ) && str_contains( $out, 'Punkte <3 von 10' ) && str_contains( $out, '80%ad' ) && ! str_contains( $out, '15.03.2012' ) && ! str_contains( $out, 'Klasse 9b' ), '/tokenize keeps line breaks, "<3" and "80%ad" and replaces date and class (was: one line, "&lt;3", "80 erreicht")' );

$overlap = (string) ( $call( '/wm-edu/v1/tokenize', [ 'text' => 'Max Müller und Max Meier', 'known_students' => [ 'Max' => '', 'Max Müller' => '' ] ] )->get_data()['data']['sanitized_text'] ?? '' );
$dollar  = (string) ( $call( '/wm-edu/v1/tokenize', [ 'text' => 'Max Müller fehlt', 'known_students' => [ 'Max Müller' => 'PUPIL_$0' ] ] )->get_data()['data']['sanitized_text'] ?? '' );
$prefix  = (string) ( $call( '/wm-edu/v1/detokenize', [ 'text' => 'Antwort für S10 und S1', 'token_map' => [ 'S1' => 'Anna', 'S10' => 'Ben' ] ] )->get_data()['data']['restored_text'] ?? '' );
$check( '' !== $overlap && ! str_contains( $overlap, 'Müller' ) && 'PUPIL_$0 fehlt' === $dollar && 'Antwort für Ben und Anna' === $prefix, 'overlapping names, a $0 token and the S1/S10 map come out right (was: surname in clear, name put back, "Anna0")' );

$tok = WM_Edu_PII_Tokenizer::tokenize( 'geboren am 15.03.2012' )['sanitized_text'];
$raw = defined( 'AUTH_KEY' ) && '' !== (string) AUTH_KEY ? 'geboren am [[DOB_' . strtoupper( substr( hash_hmac( 'sha256', '15.03.2012', (string) AUTH_KEY ), 0, 8 ) ) . ']]' : null;
$check( null === $raw || ! hash_equals( $raw, $tok ), 'the tokenizer key is not the raw AUTH_KEY (compared, not printed)' );

// --- Ledger ----------------------------------------------------------------------------------------------------------
$rows_before = $count();
$base        = [ 'student_token' => 'RUNTIME-EDU-A', 'parent_email' => 'runtime@example.test', 'consent_type' => 'RUNTIME', 'scope_hash' => hash( 'sha256', 'runtime' ), 'ip_address' => '203.0.113.9' ];
$refused     = [
	WM_Edu_Consent_Vault::record_consent( $base ),
	WM_Edu_Consent_Vault::record_consent( [ 'granted_status' => 'revoked' ] + $base ),
	WM_Edu_Consent_Vault::record_consent( [ 'granted_status' => 'GRANTED', 'ip_address' => '' ] + $base ),
];
$check( [ false, false, false ] === $refused && $rows_before === $count(), 'record_consent() without a status, with "revoked" or without an IP stores nothing (was: GRANTED, 127.0.0.1)' );

$initial = WM_Edu_Consent_Vault::verify_chain();
if ( ! $initial['valid'] ) {
	echo '  [INFO] the existing register does not verify (' . $initial['error'] . '); checks that need a valid chain are skipped' . "\n";
} else {
	$id          = WM_Edu_Consent_Vault::record_consent( [ 'granted_status' => 'GRANTED' ] + $base );
	$chain       = WM_Edu_Consent_Vault::verify_chain();
	$sql_matches = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE student_token = %s', $table, 'runtime-edu-a' ) );
	$check( is_int( $id ) && $chain['valid'] && 'granted' === WM_Edu_Consent_Vault::consent_state( 'RUNTIME-EDU-A' ) && 'none' === WM_Edu_Consent_Vault::consent_state( 'runtime-edu-a' ), "a recorded consent verifies and is granted; the lower-case token is not, although the SQL comparison alone matches {$sql_matches} row(s)" );

	if ( class_exists( 'WM_Edu_Spoke_Adapter' ) ) {
		$adapter = new WM_Edu_Spoke_Adapter();
		$tele    = $adapter->get_telemetry_metrics();
		$self    = $adapter->execute_action( 'self_test' );
		$check( 'HEALTHY' === $adapter->get_health_status() && true === $self['success'] && $count() === $tele['ledger_rows'] && $tele['ledger_rows'] === $tele['ledger_rows_verified'], 'hub adapter on a valid ledger: HEALTHY, self_test passes, ledger_rows = COUNT(*) = ' . $tele['ledger_rows'] );
	} else {
		echo "  [INFO] hub not active: adapter not checked\n";
	}

	$wpdb->insert( $table, [ 'student_token' => 'RUNTIME-EDU-UNSIGNED', 'parent_email' => 'runtime@example.test', 'consent_type' => 'RUNTIME', 'scope_hash' => str_repeat( '0', 64 ), 'granted_status' => 'GRANTED', 'ip_hmac' => str_repeat( '0', 64 ), 'key_id' => WM_Edu_Keys::signature_key_id(), 'prev_hash' => WM_Edu_Consent_Vault::current_chain_tip(), 'signature_hash' => 'not-a-signature', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );
	$unsigned_id = (int) $wpdb->insert_id;
	// The lookup verifies a nonce for logged-in users; without one it answers the security message, not the state.
	$_POST  = [ 'edu_consent_check' => '1', 'edu_student_id' => 'RUNTIME-EDU-A', 'wm_edu_gate_nonce' => wp_create_nonce( 'wm_edu_gate' ) ];
	$gate   = WM_Edu_Shortcodes::render_consent_gate();
	$_POST  = [];
	$health = class_exists( 'WM_Edu_Spoke_Adapter' ) ? ( new WM_Edu_Spoke_Adapter() )->get_health_status() : 'ERROR';
	$check( 'unverifiable' === WM_Edu_Consent_Vault::consent_state( 'RUNTIME-EDU-UNSIGNED' ) && str_contains( $gate, 'REGISTER NICHT PRÜFBAR' ) && ! str_contains( $gate, 'GÜLTIGE' ) && 'ERROR' === $health, 'a row that does not verify: the gate shows no consent and says REGISTER NICHT PRÜFBAR, health ERROR (was: GÜLTIGE EINWILLIGUNG, HEALTHY)' );

	// The row above was inserted past the plugin, so the recorded tip never counted it: removing it again by its id puts
	// the register back where the tip says it ends. The checks that follow need a chain that verifies.
	// wm-runtime: removes only the rows it inserts
	$wpdb->delete( $table, [ 'id' => $unsigned_id ], [ '%d' ] );
	$check( WM_Edu_Consent_Vault::verify_chain()['valid'], 'the register verifies again once the row that was written past the plugin is gone' );
}

// The consent table disappears: SHOW TABLES for it finds nothing (prepare() doubles the backslashes esc_like() adds, so
// the name is compared without them) and every other query is pointed at a table that does not exist.
$missing_name = $table . '_runtime_missing';
$missing      = static function ( $q ) use ( $table, $missing_name ) {
	$q = (string) $q;
	if ( preg_match( '/^\s*SHOW\s+TABLES\s+LIKE\b/i', $q ) && str_contains( str_replace( '\\', '', $q ), $table ) ) {
		return "SHOW TABLES LIKE 'wm_edu_runtime_no_such_table'";
	}
	return str_replace( $table, $missing_name, $q );
};
add_filter( 'query', $missing, 20 );
$suppressed = $wpdb->suppress_errors( true );
$mchain     = WM_Edu_Consent_Vault::verify_chain();
$mhealth    = class_exists( 'WM_Edu_Spoke_Adapter' ) ? ( new WM_Edu_Spoke_Adapter() )->get_health_status() : 'ERROR';
$wpdb->suppress_errors( $suppressed );
remove_filter( 'query', $missing, 20 );
$check( false === $mchain['valid'] && true === $mchain['missing_table'] && 'ERROR' === $mhealth, 'a missing table is not a valid chain and health is ERROR (was: valid with 0 entries, HEALTHY)' );

// --- Shortcodes and text domain -------------------------------------------------------------------------------------
$GLOBALS['edrt_warnings'] = [];
set_error_handler(
	static function ( $no, $msg, $file, $line ) {
		$GLOBALS['edrt_warnings'][] = $msg . ' @ ' . basename( (string) $file ) . ':' . $line;
		return true;
	}
);
$typed = [];
foreach ( [ 'render_pii_sanitizer', 'render_consent_gate' ] as $method ) {
	try {
		$typed[] = strlen( WM_Edu_Shortcodes::$method( '' ) ) > 0;
	} catch ( \Throwable $t ) {
		$typed[] = get_class( $t );
	}
}
$html = do_shortcode( '[wm_edu_pii_sanitizer][wm_edu_consent_gate]' );
restore_error_handler();
$check( [ true, true ] === $typed && [] === $GLOBALS['edrt_warnings'], "both shortcodes render with '' as attributes, as WordPress before 6.5 passes them, without notices (was: TypeError)" . ( $GLOBALS['edrt_warnings'] ? ': ' . $GLOBALS['edrt_warnings'][0] : '' ) );
$check( '' !== $html && ! str_contains( $html, 'style=' ) && wp_style_is( WM_Edu_Shortcodes::STYLE_HANDLE, 'enqueued' ) && ! str_contains( $html, 'edu_service' ) && ! str_contains( $html, 'nonymisier' ), 'shortcode markup without inline styles, stylesheet enqueued, no service field, no "Anonymisieren" (was: 18 style attributes)' );

// --- Guardian flow, admin screen, access (2026-09-17) -----------------------------------------------------------------
// Mails are not handed to the MTA: pre_wp_mail short-circuits wp_mail() and keeps what would have been sent.
$GLOBALS['edrt_mails'] = [];
$keep_mail             = static function ( $short, $atts ) {
	$GLOBALS['edrt_mails'][] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $keep_mail, 10, 2 );

$type_id = WM_Edu_Consent_Types::save(
	[
		'slug'         => 'RUNTIME_' . wp_rand( 1000, 9999 ),
		'label'        => 'Runtime-Prüfung',
		'description'  => 'Nur für die Laufzeitprüfung.',
		'consent_text' => "Ich willige in die Laufzeitprüfung ein.\nZweite Zeile.",
		'active'       => true,
	]
);
$type      = is_int( $type_id ) ? WM_Edu_Consent_Types::get( $type_id ) : null;
$archived  = null === $type ? null : WM_Edu_Consent_Types::text_for_scope( (string) $type->scope_hash );
$check( null !== $type && hash( 'sha256', (string) $archived ) === (string) $type->scope_hash && str_contains( (string) $archived, "\n" ), 'a consent type stores its wording under the SHA-256 that every entry carries' );

$student  = 'RUNTIME-EDU-' . wp_rand( 1000, 9999 );
$address  = 'runtime-guardian@example.test';
$added    = WM_Edu_Guardians::add( $student, $address );
$stored   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE student_token = %s', WM_Edu_Guardians::table(), $student ), ARRAY_A );
$check( 'added' === $added && WM_Edu_Guardians::matches( $student, strtoupper( $address ) ) && ! WM_Edu_Guardians::matches( $student, 'fremd@example.test' ) && ! str_contains( wp_json_encode( $stored ), '@' ), 'the guardian registry matches an address in any case and stores no address in clear' );

$decisions = [
	[
		'slug'       => (string) $type->slug,
		'status'     => 'GRANTED',
		'scope_hash' => (string) $type->scope_hash,
		'label'      => (string) $type->label,
	],
];
$page          = home_url( '/' );
$unregistered  = WM_Edu_Consent_Requests::submit( $student . '-X', $address, $decisions, $page );
$mails_after_x = count( $GLOBALS['edrt_mails'] );
$sent          = WM_Edu_Consent_Requests::submit( $student, $address, $decisions, $page );
$body          = (string) ( end( $GLOBALS['edrt_mails'] )['message'] ?? '' );
$token         = preg_match( '/' . WM_Edu_Consent_Requests::CONFIRM_PARAM . '=([0-9a-f]{64})/', $body, $m ) ? $m[1] : '';
$check( 'unregistered' === $unregistered && 0 === $mails_after_x && 'sent' === $sent && 64 === strlen( $token ), 'the form mails a one-time link for a registered pair only (unregistered: no request, no mail)' );

$rows_before_confirm = $count();
$confirmed           = WM_Edu_Consent_Requests::confirm( $token, '203.0.113.9', $page );
$state_after         = WM_Edu_Consent_Vault::consent_state( $student, (string) $type->slug );
$recorded            = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE student_token = %s ORDER BY id DESC LIMIT 1', $table, $student ), ARRAY_A );
$used_again          = WM_Edu_Consent_Requests::confirm( $token, '203.0.113.9', $page );
$revoke_token        = preg_match( '/' . WM_Edu_Consent_Requests::REVOKE_PARAM . '=([0-9a-f]{64})/', (string) ( end( $GLOBALS['edrt_mails'] )['message'] ?? '' ), $m2 ) ? $m2[1] : '';
$check(
	'confirmed' === $confirmed && $rows_before_confirm + 1 === $count() && 'granted' === $state_after && 'parent_confirmation' === ( $recorded['source'] ?? '' )
	&& 'invalid' === $used_again && 64 === strlen( $revoke_token ) && WM_Edu_Consent_Vault::verify_chain()['valid'],
	'the confirmation link records the decision once, the chain stays valid, and the link cannot be used twice'
);

$revoked      = WM_Edu_Consent_Requests::revoke( $revoke_token, '203.0.113.9' );
$state_revoked = WM_Edu_Consent_Vault::consent_state( $student, (string) $type->slug );
$check( 'revoked' === $revoked && 'none' === $state_revoked && 'invalid' === WM_Edu_Consent_Requests::revoke( $revoke_token, '203.0.113.9' ) && WM_Edu_Consent_Vault::verify_chain()['valid'], 'the revocation link records REVOKED once and the lookup stops showing the consent' );

require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-admin.php';
$admin_code = WM_Edu_Admin::process_record(
	[
		'student_token' => $student,
		'parent_email'  => $address,
		'type'          => (string) $type->slug,
		'status'        => 'GRANTED',
		'note'          => 'Laufzeitprüfung: Papierformular vom 17.09.2026.',
	],
	get_current_user_id(),
	'203.0.113.9'
);
$admin_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE student_token = %s ORDER BY id DESC LIMIT 1', $table, $student ), ARRAY_A );
$csv       = WM_Edu_Admin::csv( [ (object) [ 'note' => '=1+1', 'student_token' => 'a"b' ] ] );
$check(
	'recorded' === $admin_code && 'admin_entry' === ( $admin_row['source'] ?? '' ) && (string) get_current_user_id() === ( $admin_row['actor_id'] ?? '' ) && str_contains( (string) ( $admin_row['note'] ?? '' ), 'Papierformular' )
	&& 'entry_invalid' === WM_Edu_Admin::process_record( [ 'student_token' => $student, 'parent_email' => $address, 'type' => (string) $type->slug, 'status' => 'GRANTED', 'note' => '' ], get_current_user_id(), '203.0.113.9' )
	&& str_contains( $csv, '"\'=1+1"' ) && str_contains( $csv, '"a""b"' ),
	'a manual entry is signed with the acting user and its reason, an entry without a reason is refused, and the export neutralises formulas'
);

$administrator = get_role( 'administrator' );
$check( $administrator instanceof WP_Role && $administrator->has_cap( WM_Edu_Access::CAPABILITY ), 'administrators have the capability manage_edu_consents' );

$previous_user = get_current_user_id();
wp_set_current_user( 0 );
$anonymous_permission = WM_Edu_Access::rest_tokenizer_permission();
$anonymous_route      = $call( '/wm-edu/v1/tokenize', [ 'text' => 'Klasse 9b' ] )->get_status();
$anonymous_gate       = WM_Edu_Shortcodes::render_consent_gate();
wp_set_current_user( $previous_user );
$check(
	is_wp_error( $anonymous_permission ) && 401 === ( $anonymous_permission->get_error_data()['status'] ?? 0 ) && 401 === $anonymous_route
	&& ! str_contains( $anonymous_gate, '<form' ) && 200 === $call( '/wm-edu/v1/tokenize', [ 'text' => 'Klasse 9b' ] )->get_status(),
	"without a login /tokenize answers 401 and the lookup shows no form; as user {$previous_user} the route answers 200 (was: public)"
);

$cleanup = WM_Edu_Consent_Requests::cleanup();
$check( wp_next_scheduled( WM_Edu_Consent_Requests::CRON_HOOK ) > 0 && isset( $cleanup['pending_deleted'], $cleanup['closed_deleted'], $cleanup['claims_released'] ), 'the daily retention event is scheduled on this install and cleanup() runs' );

remove_filter( 'pre_wp_mail', $keep_mail, 10 );
echo '  [INFO] mails kept instead of sent: ' . count( $GLOBALS['edrt_mails'] ) . "\n";

$locale = determine_locale();
if ( file_exists( WM_EDU_PORTAL_PATH . 'languages/wm-edu-privacy-portal-' . $locale . '.mo' ) ) {
	$check( is_textdomain_loaded( 'wm-edu-privacy-portal' ), "the plugin's catalog for {$locale} is loaded once its strings render (was: never loaded)" );
} else {
	echo "  [INFO] no catalog for {$locale}: text domain not checked\n";
}
