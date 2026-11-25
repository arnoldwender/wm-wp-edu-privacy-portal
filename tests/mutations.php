<?php
/**
 * Literal mutations for tests/run-mutations.sh: each entry undoes one correction of 2026-09-15; the
 * standalone suite must report a failure for every one. Search strings must occur exactly once.
 *
 * @package WM_Edu_Privacy_Portal
 */

return [
	[
		'Names replaced in caller order, not longest first',
		[
			[ 'includes/class-edu-pii-tokenizer.php', "\t\tusort( \$names, static fn( array \$a, array \$b ): int => strlen( \$b[0] ) <=> strlen( \$a[0] ) );\n", '' ],
		],
	],
	[
		'Tokens inserted through preg_replace (a $0 token puts the match back)',
		[
			[ 'includes/class-edu-pii-tokenizer.php', '$text = strtr( $text, $placeholders );', 'foreach ( $placeholders as $p => $t ) { $text = (string) preg_replace( \'/\' . preg_quote( $p, \'/\' ) . \'/u\', $t, $text ); }' ],
		],
	],
	[
		'Names replaced directly by their tokens (a shorter name matches inside a token)',
		[
			[ 'includes/class-edu-pii-tokenizer.php', 'static fn(): string => $placeholder, $text, -1, $count', 'static fn(): string => $token, $text, -1, $count' ],
		],
	],
	[
		'detokenize() in map order again (S10 becomes Anna0)',
		[
			[ 'includes/class-edu-pii-tokenizer.php', 'return [] === $pairs ? $tokenized_text : strtr( $tokenized_text, $pairs );', "foreach ( \$pairs as \$t => \$o ) { \$tokenized_text = str_replace( (string) \$t, \$o, \$tokenized_text ); }\n\t\treturn \$tokenized_text;" ],
		],
	],
	[
		'A number given as token is used as the token',
		[
			[ 'includes/class-edu-pii-tokenizer.php', '$token   = is_string( $assigned ) && \'\' !== trim( $assigned ) ? $assigned :', '$token   = \'\' !== trim( (string) $assigned ) ? (string) $assigned :' ],
		],
	],
	[
		'A failed pattern returns an empty text instead of throwing',
		[
			[ 'includes/class-edu-pii-tokenizer.php', 'throw new RuntimeException( \'Pseudonymisierung abgebrochen: \' . esc_html( preg_last_error_msg() ) );', 'return \'\';' ],
		],
	],
	[
		'Tokenizer keyed with the raw AUTH_KEY again',
		[
			[ 'includes/class-edu-pii-tokenizer.php', '$key        = WM_Edu_Keys::derive( WM_Edu_Keys::TOKENIZER );', '$key        = WM_Edu_Keys::legacy_signature_key();' ],
		],
	],
	[
		'One key for every purpose',
		[
			[ 'includes/class-edu-keys.php', 'return hash_hmac( \'sha256\', \'wm-edu-privacy-portal|\' . $purpose, wp_salt( \'auth\' ) );', 'return hash_hmac( \'sha256\', \'wm-edu-privacy-portal\', wp_salt( \'auth\' ) );' ],
		],
	],
	[
		'Unknown or missing status stored as GRANTED',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( ! is_string( \$status ) || ! in_array( \$status, self::STATUSES, true ) ) {\n\t\t\treturn false;\n\t\t}", "if ( ! is_string( \$status ) || ! in_array( \$status, self::STATUSES, true ) ) {\n\t\t\t\$status = 'GRANTED';\n\t\t}" ],
		],
	],
	[
		'A missing or invalid IP accepted',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\t|| false === \$ip\n", '' ],
		],
	],
	[
		'Any scope hash accepted',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\t|| 1 !== preg_match( '/^[0-9a-f]{64}\$/', \$scope_hash )\n", '' ],
		],
	],
	[
		'Token length unchecked (MySQL would truncate a signed value)',
		[
			[ 'includes/class-edu-consent-vault.php', '\'\' === $student_token || mb_strlen( $student_token, \'UTF-8\' ) > 64', '\'\' === $student_token' ],
		],
	],
	[
		'A refused insert reported as id 0',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( false === \$inserted ) {\n\t\t\t\$wpdb->query( 'ROLLBACK' );", "if ( false ) {\n\t\t\t\$wpdb->query( 'ROLLBACK' );" ],
		],
	],
	[
		'granted_status defaults to GRANTED again',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\tgranted_status VARCHAR(20) NOT NULL,\n", "\t\t\tgranted_status VARCHAR(20) NOT NULL DEFAULT 'GRANTED',\n" ],
		],
	],
	[
		'key_id column missing from the schema',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\tkey_id VARCHAR(16) NOT NULL DEFAULT '',\n", '' ],
		],
	],
	[
		'Signature does not cover ip_hmac',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\t(string) ( \$r['ip_hmac'] ?? '' ),\n", '' ],
		],
	],
	[
		'Signed fields joined with | again',
		[
			[ 'includes/class-edu-consent-vault.php', '$payload = (string) wp_json_encode(', '$payload = implode( \'|\',' ],
		],
	],
	[
		'The gate ignores the chain again',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( ! self::verify_chain()['valid'] ) {\n\t\t\treturn 'unverifiable';", "if ( false ) {\n\t\t\treturn 'unverifiable';" ],
		],
	],
	[
		'Token matched by the SQL collation alone',
		[
			[ 'includes/class-edu-consent-vault.php', 'if ( $student_token === (string) $row->student_token && ( \'\' === $consent_type || $consent_type === (string) $row->consent_type ) ) {', 'if ( \'\' === $consent_type || $consent_type === (string) $row->consent_type ) {' ],
		],
	],
	[
		'Type matched by the SQL collation alone',
		[
			[ 'includes/class-edu-consent-vault.php', 'if ( $student_token === (string) $row->student_token && ( \'\' === $consent_type || $consent_type === (string) $row->consent_type ) ) {', 'if ( $student_token === (string) $row->student_token ) {' ],
		],
	],
	[
		'A missing table not detected',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( ! self::table_exists() ) {\n\t\t\t\$result['missing_table'] = true;", "if ( false ) {\n\t\t\t\$result['missing_table'] = true;" ],
		],
	],
	[
		'A failed read counted as a valid chain',
		[
			[ 'includes/class-edu-consent-vault.php', 'if ( \'\' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {', 'if ( ! is_array( $rows ) ) {' ],
		],
	],
	[
		'A changed key reported as a bad signature',
		[
			[ 'includes/class-edu-consent-vault.php', '} elseif ( ! hash_equals( $key_id, $row_key_id ) ) {', '} elseif ( false ) {' ],
		],
	],
	[
		'Schema-1 rows accepted after the boundary',
		[
			[ 'includes/class-edu-consent-vault.php', 'if ( $id > $boundary ) {', 'if ( false ) {' ],
		],
	],
	[
		'Rows of a site not yet upgraded checked against boundary 0',
		[
			[ 'includes/class-edu-consent-vault.php', '$boundary      = in_array( $stored_schema, self::BOUNDARY_SCHEMAS, true ) ? (int) get_option( self::LEGACY_BOUNDARY_OPTION, 0 ) : PHP_INT_MAX;', '$boundary      = (int) get_option( self::LEGACY_BOUNDARY_OPTION, 0 );' ],
		],
	],
	// --- 2026-09-17: named lock, recorded tip, one transaction, schema 3 (test group 14) ---
	[
		'Append without the named lock',
		[
			[ 'includes/class-edu-consent-vault.php', 'if ( ! self::lock() ) {', 'if ( false ) {' ],
		],
	],
	[
		'Lock never released',
		[
			[ 'includes/class-edu-consent-vault.php', "} finally {\n\t\t\tself::unlock();\n\t\t}", "} finally {\n\t\t}" ],
		],
	],
	[
		'A GET_LOCK answer of 0 taken as the lock',
		[
			[ 'includes/class-edu-consent-vault.php', "return '1' === (string) \$wpdb->get_var( \$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )'", "return null !== \$wpdb->get_var( \$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )'" ],
		],
	],
	[
		'One lock name for every site on the database server',
		[
			[ 'includes/class-edu-consent-vault.php', "substr( hash( 'sha256', \$database . '|' . self::consents_table() ), 0, 32 )", "substr( hash( 'sha256', 'wm-edu-privacy-portal' ), 0, 32 )" ],
		],
	],
	[
		'Append links to the table end instead of the recorded tip',
		[
			[ 'includes/class-edu-consent-vault.php', "\$row['prev_hash']      = \$trusted ? \$tip['hash'] : self::current_chain_tip();", "\$row['prev_hash']      = self::current_chain_tip();" ],
		],
	],
	[
		'A tip whose signature does not verify is carried forward and re-signed',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( \$trusted ) {\n\t\t\t\$new_count = \$tip['count'] + 1;", "if ( null !== \$tip ) {\n\t\t\t\$new_count = \$tip['count'] + 1;" ],
		],
	],
	[
		'Tip signature not checked by verify_chain()',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( ! \$tip['signed'] ) {", 'if ( false ) {' ],
		],
	],
	[
		'Any tip signature accepted',
		[
			[ 'includes/class-edu-consent-vault.php', "'signed' => hash_equals( self::tip_signature( \$tip['hash'], \$tip['count'] ), \$tip['sig'] ),", "'signed' => true," ],
		],
	],
	[
		'Recorded tip not compared with the table',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( \$tip['count'] !== \$result['rows'] || ! hash_equals( \$tip['hash'], \$expected_prev ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'A missing recorded tip read as a valid chain',
		[
			[ 'includes/class-edu-consent-vault.php', "\$result['tip_mismatch'] = true;\n\t\t\t\t\$result['error']        = 'Die aufgezeichnete Kettenspitze fehlt.';", "\$result['valid'] = true;" ],
		],
	],
	[
		'The upgrade records no tip',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( null === self::recorded_tip() ) {\n\t\t\t[ \$tip_hash, \$tip_count ] = self::table_tip();", "if ( false ) {\n\t\t\t[ \$tip_hash, \$tip_count ] = self::table_tip();" ],
		],
	],
	[
		'A refused tip update commits the row anyway',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( null !== \$new_count && ! self::store_tip( \$row['signature_hash'], \$new_count ) ) {\n\t\t\t\$wpdb->query( 'ROLLBACK' );", "if ( null !== \$new_count && ! self::store_tip( \$row['signature_hash'], \$new_count ) ) {\n\t\t\t\$wpdb->query( 'COMMIT' );" ],
		],
	],
	[
		'The append transaction is never committed',
		[
			[ 'includes/class-edu-consent-vault.php', "\$wpdb->query( 'COMMIT' );\n\t\treturn \$id;", 'return $id;' ],
		],
	],
	[
		'Object cache keeps a rolled-back tip',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\twp_cache_delete( self::TIP_OPTION, 'options' );\n", '' ],
		],
	],
	[
		'v3 signature does not cover the note',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\t\$fields[] = (string) ( \$r['note'] ?? '' );\n", '' ],
		],
	],
	[
		'v3 signature does not cover the actor',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\t\$fields[] = (string) ( \$r['actor_id'] ?? '0' );\n", '' ],
		],
	],
	[
		'v3 signature does not cover the source',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\t\$fields[] = (string) ( \$r['source'] ?? '' );\n", '' ],
		],
	],
	[
		'row_version ignored: every row signed and verified with the v2 payload',
		[
			[ 'includes/class-edu-consent-vault.php', "\$v3     = self::ROW_VERSION === (string) ( \$r['row_version'] ?? '' );", '$v3     = false;' ],
		],
	],
	[
		'An unknown row_version verified as a schema-2 row',
		[
			[ 'includes/class-edu-consent-vault.php', "} elseif ( '' !== \$row_version && self::ROW_VERSION !== \$row_version ) {", '} elseif ( false ) {' ],
		],
	],
	[
		'Admin entry without actor or note accepted',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( in_array( \$source, self::ADMIN_SOURCES, true ) && ( 0 === \$actor || '' === trim( \$note ) ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'Unknown source accepted',
		[
			[ 'includes/class-edu-consent-vault.php', '! in_array( $source, self::SOURCES, true ) || ', '' ],
		],
	],
	[
		'Note length unchecked',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( mb_strlen( \$note, 'UTF-8' ) > self::NOTE_MAX ) {", 'if ( false ) {' ],
		],
	],
	[
		'Actor accepted as a string',
		[
			[ 'includes/class-edu-consent-vault.php', '! is_int( $actor ) || $actor < 0', '$actor < 0' ],
		],
	],
	[
		'Boundary of a site still at schema 2 ignored',
		[
			[ 'includes/class-edu-consent-vault.php', '$boundary      = in_array( $stored_schema, self::BOUNDARY_SCHEMAS, true ) ?', '$boundary      = self::SCHEMA_VERSION === $stored_schema ?' ],
		],
	],
	// --- 2026-09-17: settings, capability, consent types, guardian registry (test group 15) ---
	[
		'Capability not granted on install',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\tWM_Edu_Access::grant_capability();\n", '' ],
		],
	],
	[
		'Capability rewritten on every install',
		[
			[ 'includes/class-edu-access.php', 'if ( $role instanceof WP_Role && ! $role->has_cap( self::CAPABILITY ) ) {', 'if ( $role instanceof WP_Role ) {' ],
		],
	],
	[
		'Guardian table not created',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\tWM_Edu_Guardians::table_sql( WM_Edu_Guardians::table(), \$collate ),\n", '' ],
		],
	],
	[
		'Any submitted value opens the public gate',
		[
			[ 'includes/class-edu-settings.php', "'public_gate'      => '1' === ( \$input['public_gate'] ?? '' ),", "'public_gate'      => ! empty( \$input['public_gate'] )," ],
		],
	],
	[
		'A truthy stored value opens public access',
		[
			[ 'includes/class-edu-settings.php', '$settings[ $flag ] = true === ( $stored[ $flag ] ?? false );', '$settings[ $flag ] = ! empty( $stored[ $flag ] );' ],
		],
	],
	[
		'Unknown roles kept in the gate roles',
		[
			[ 'includes/class-edu-settings.php', "array_values( array_intersect( array_filter( (array) ( \$input['gate_roles'] ?? [] ), 'is_string' ), \$known_roles ) )", "array_values( array_filter( (array) ( \$input['gate_roles'] ?? [] ), 'is_string' ) )" ],
		],
	],
	[
		'Settings numbers not clamped',
		[
			[ 'includes/class-edu-settings.php', 'return max( $min, min( $max, (int) $value ) );', 'return (int) $value;' ],
		],
	],
	[
		'Scope hash not the SHA-256 of the stored text',
		[
			[ 'includes/class-edu-consent-types.php', "return hash( 'sha256', \$normalized_text );", "return hash( 'sha256', \$normalized_text . ' ' );" ],
		],
	],
	[
		'Consent text not archived',
		[
			[ 'includes/class-edu-consent-types.php', 'if ( ! self::archive_text( (string) $text, $scope ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'Archived text written again on every save',
		[
			[ 'includes/class-edu-consent-types.php', "if ( null !== self::text_for_scope( \$scope ) ) {\n\t\t\treturn true;", "if ( false ) {\n\t\t\treturn true;" ],
		],
	],
	[
		'Slug changed on update',
		[
			[ 'includes/class-edu-consent-types.php', "'updated_at'  => \$now,\n\t\t\t],\n\t\t\t[ 'id' => \$id ],\n\t\t\t[ '%s', '%s', '%s', '%d', '%s' ],", "'updated_at'  => \$now,\n\t\t\t\t'slug'        => self::normalize_slug( (string) ( \$data['slug'] ?? '' ) ),\n\t\t\t],\n\t\t\t[ 'id' => \$id ],\n\t\t\t[ '%s', '%s', '%s', '%d', '%s', '%s' ]," ],
		],
	],
	[
		'Duplicate slug accepted',
		[
			[ 'includes/class-edu-consent-types.php', 'if ( null !== self::get_by_slug( $slug ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'Slug pattern unchecked',
		[
			[ 'includes/class-edu-consent-types.php', 'if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {', "if ( '' === \$slug ) {" ],
		],
	],
	[
		'Tags kept in the consent text',
		[
			[ 'includes/class-edu-consent-types.php', 'wp_strip_all_tags( $text )', '$text' ],
		],
	],
	[
		'Consent text not checked for UTF-8',
		[
			[ 'includes/class-edu-consent-types.php', "if ( 1 !== preg_match( '//u', \$text ) ) {\n\t\t\treturn null;", "if ( false ) {\n\t\t\treturn null;" ],
		],
	],
	[
		'Line breaks of the consent text not normalised',
		[
			[ 'includes/class-edu-consent-types.php', 'str_replace( [ "\r\n", "\r" ], "\n", ', 'str_replace( [], "\n", ' ],
		],
	],
	[
		'Registry stores the address itself',
		[
			[ 'includes/class-edu-guardians.php', "'email_hmac'    => self::email_hmac( \$address ),", "'email_hmac'    => \$address," ],
		],
	],
	[
		'Registry compares addresses with their case',
		[
			[ 'includes/class-edu-guardians.php', 'strtolower( trim( $email ) )', 'trim( $email )' ],
		],
	],
	[
		'Registry matches the pupil ID through the collation alone',
		[
			[ 'includes/class-edu-guardians.php', 'static fn( object $row ): bool => $student_token === (string) $row->student_token', 'static fn( object $row ): bool => true' ],
		],
	],
	[
		'Removing a pair removes every address of the pupil',
		[
			[ 'includes/class-edu-guardians.php', 'array_filter( self::rows_for( $student ), static fn( object $row ): bool => hash_equals( (string) $row->email_hmac, $hmac ) )', 'self::rows_for( $student )' ],
		],
	],
	[
		'Uninstall keeps the capability',
		[
			[ 'uninstall.php', "\t\t\$wm_edu_role->remove_cap( 'manage_edu_consents' );\n", '' ],
		],
	],
	[
		'Uninstall keeps the settings',
		[
			[ 'uninstall.php', "delete_option( 'wm_edu_settings' );\n", '' ],
		],
	],
	// --- 2026-09-17: guardian form, confirmation and revocation links, rate limit, retention (test group 16) ---
	[
		'An unregistered pair gets a request and a mail',
		[
			[ 'includes/class-edu-consent-requests.php', "if ( ! WM_Edu_Guardians::matches( \$student_token, \$email ) ) {\n\t\t\treturn 'unregistered';", "if ( false ) {\n\t\t\treturn 'unregistered';" ],
		],
	],
	[
		'The confirmation token stored instead of its hash',
		[
			[ 'includes/class-edu-consent-requests.php', "'confirm_hash'  => hash( 'sha256', \$token ),", "'confirm_hash'  => \$token," ],
		],
	],
	[
		'A confirmation link that never expires',
		[
			[ 'includes/class-edu-consent-requests.php', " || (string) \$request->expires_at <= gmdate( 'Y-m-d H:i:s', \$now ?? time() )", '' ],
		],
	],
	[
		'A used confirmation link still offered',
		[
			[ 'includes/class-edu-consent-requests.php', "'pending' !== (string) \$request->status || ", '' ],
		],
	],
	[
		'Confirmation without claiming the request',
		[
			[ 'includes/class-edu-consent-requests.php', "if ( ! self::claim( \$id, 'pending', 'confirming' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'A retry records every decision again',
		[
			[ 'includes/class-edu-consent-requests.php', "if ( isset( \$decision['consent_id'] ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'An interrupted confirmation keeps the link claimed',
		[
			[ 'includes/class-edu-consent-requests.php', "(string) wp_json_encode( \$decisions ), 'pending', \$id ) );", "(string) wp_json_encode( \$decisions ), 'confirming', \$id ) );" ],
		],
	],
	[
		'A confirmed request keeps the pupil ID and the address',
		[
			[ 'includes/class-edu-consent-requests.php', "\t\t\t\t'',\n\t\t\t\t'',\n\t\t\t\t(string) wp_json_encode( \$decisions ),", "\t\t\t\t(string) \$request->student_token,\n\t\t\t\t(string) \$request->parent_email,\n\t\t\t\t(string) wp_json_encode( \$decisions )," ],
		],
	],
	[
		'No revocation link after a grant',
		[
			[ 'includes/class-edu-consent-requests.php', "\$revoke  = [] === \$granted ? '' : bin2hex( random_bytes( 32 ) );", "\$revoke  = '';" ],
		],
	],
	[
		'The revocation token stored instead of its hash',
		[
			[ 'includes/class-edu-consent-requests.php', "'' === \$revoke ? '' : hash( 'sha256', \$revoke ),", "'' === \$revoke ? '' : \$revoke," ],
		],
	],
	[
		'Denied consents offered for revocation',
		[
			[ 'includes/class-edu-consent-requests.php', "array_filter( \$decisions, static fn( array \$d ): bool => 'GRANTED' === \$d['status'] )", '$decisions' ],
		],
	],
	[
		'A used revocation link still offered',
		[
			[ 'includes/class-edu-consent-requests.php', "return null !== \$request && 'confirmed' === (string) \$request->status ? \$request : null;", 'return $request;' ],
		],
	],
	[
		'Revocation without claiming the request',
		[
			[ 'includes/class-edu-consent-requests.php', "if ( ! self::claim( \$id, 'confirmed', 'revoking' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'Revocation recorded as DENIED',
		[
			[ 'includes/class-edu-consent-requests.php', "'granted_status' => 'REVOKED',", "'granted_status' => 'DENIED'," ],
		],
	],
	[
		'A granted row missing from the ledger reported as revoked',
		[
			[ 'includes/class-edu-consent-requests.php', 'if ( count( $rows ) !== count( $ids ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'An interrupted revocation revokes everything again',
		[
			[ 'includes/class-edu-consent-requests.php', "\$remaining = array_values( array_diff( \$remaining, [ (int) \$row->id ] ) );", '' ],
		],
	],
	[
		'A request whose mail failed stays stored',
		[
			[ 'includes/class-edu-consent-requests.php', "if ( ! \$sent && (int) \$mail['request'] > 0 ) {", 'if ( false ) {' ],
		],
	],
	// --- 2026-09-17: the mail leaves after the response, so the answer time is not an oracle (test group 19) ---
	[
		'The confirmation mail is sent inside the request',
		[
			[ 'includes/class-edu-consent-requests.php', "self::queue_mail( \$email, sprintf( __( '[%s] Bitte bestätigen Sie Ihre Angaben zur Einwilligung', 'wm-edu-privacy-portal' ), self::site_name() ), \$body, \$id );", "self::deliver( [ 'to' => \$email, 'subject' => sprintf( __( '[%s] Bitte bestätigen Sie Ihre Angaben zur Einwilligung', 'wm-edu-privacy-portal' ), self::site_name() ), 'message' => \$body, 'request' => \$id ] );" ],
		],
	],
	[
		'The receipt mail is sent inside the request',
		[
			[ 'includes/class-edu-consent-requests.php', "self::queue_mail( (string) \$request->parent_email, sprintf( __( '[%s] Ihre Angaben zur Einwilligung wurden erfasst'", "self::deliver_now( (string) \$request->parent_email, sprintf( __( '[%s] Ihre Angaben zur Einwilligung wurden erfasst'" ],
			[ 'includes/class-edu-consent-requests.php', "\tprivate static function queue_mail( string \$to, string \$subject, string \$body, int \$request_id = 0 ): void {", "\tprivate static function deliver_now( string \$to, string \$subject, string \$body, int \$request_id = 0 ): void {\n\t\tself::deliver( [ 'to' => \$to, 'subject' => \$subject, 'message' => \$body, 'request' => \$request_id ] );\n\t}\n\n\tprivate static function queue_mail( string \$to, string \$subject, string \$body, int \$request_id = 0 ): void {" ],
		],
	],
	[
		'The response is not closed before the mails go out',
		[
			[ 'includes/class-edu-consent-requests.php', "\t\t\tself::close_response();\n", '' ],
		],
	],
	[
		'Mails delivered inside the request although the response cannot be closed',
		[
			[ 'includes/class-edu-consent-requests.php', 'if ( self::can_close_response() ) {', 'if ( true ) {' ],
		],
	],
	[
		'The queue is not emptied before delivering, so a second flush sends every mail again',
		[
			[ 'includes/class-edu-consent-requests.php', "\t\tself::\$mail_queue = [];\n", '' ],
		],
	],
	[
		'The scheduled queue is delivered from a key the caller chose',
		[
			[ 'includes/class-edu-consent-requests.php', 'if ( ! str_starts_with( $key, self::MAIL_TRANSIENT_PREFIX ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'The mail queue is never flushed (no shutdown hook)',
		[
			[ 'includes/class-edu-consent-requests.php', "\t\tadd_action( 'shutdown', [ self::class, 'flush_mail_queue' ], 100 );\n", '' ],
		],
	],
	[
		'The scheduled queue has no handler',
		[
			[ 'includes/class-edu-consent-requests.php', "\t\tadd_action( self::MAIL_HOOK, [ self::class, 'deliver_scheduled_mail' ] );\n", '' ],
		],
	],
	[
		'Rate limit never counts',
		[
			[ 'includes/class-edu-consent-requests.php', "\$window['n'] = (int) \$window['n'] + 1;", "\$window['n'] = (int) \$window['n'];" ],
		],
	],
	[
		'Rate limit ignores the e-mail address',
		[
			[ 'includes/class-edu-consent-requests.php', "strtolower( trim( \$email ) ), \$settings['rate_email_per_hour']", "'', \$settings['rate_email_per_hour']" ],
		],
	],
	[
		'Rate limit keyed by the raw address',
		[
			[ 'includes/class-edu-consent-requests.php', "'wm_edu_rl_' . \$kind . '_' . substr( hash_hmac( 'sha256', \$value, WM_Edu_Keys::derive( WM_Edu_Keys::RATE_LIMIT ) ), 0, 32 )", "'wm_edu_rl_' . \$kind . '_' . \$value" ],
		],
	],
	[
		'Rate-limit window never restarts',
		[
			[ 'includes/class-edu-consent-requests.php', "\$now - (int) \$window['start'] < HOUR_IN_SECONDS ? \$window :", "is_array( \$window ) ? \$window :" ],
		],
	],
	[
		'Cleanup deletes open revocation links',
		[
			[ 'includes/class-edu-consent-requests.php', '( status = %s OR ( status = %s AND revoke_hash = %s ) )', '( status = %s OR ( status = %s OR revoke_hash = %s ) )' ],
		],
	],
	[
		'Stale claims never released',
		[
			[ 'includes/class-edu-consent-requests.php', "\$stale  = gmdate( 'Y-m-d H:i:s', \$now - self::STALE_CLAIM_SECONDS );", "\$stale  = gmdate( 'Y-m-d H:i:s', \$now - 30 * DAY_IN_SECONDS );" ],
		],
	],
	[
		'Retention event scheduled on every load',
		[
			[ 'includes/class-edu-consent-requests.php', 'if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {', 'if ( true ) {' ],
		],
	],
	[
		'Retention event wired to activation only',
		[
			[ 'wm-edu-privacy-portal.php', "add_action( 'plugins_loaded', [ 'WM_Edu_Consent_Requests', 'sync_schedule' ] );\n", '' ],
		],
	],
	[
		'Deactivation leaves the retention event',
		[
			[ 'wm-edu-privacy-portal.php', "register_deactivation_hook( __FILE__, [ 'WM_Edu_Consent_Requests', 'unschedule' ] );\n", '' ],
		],
	],
	[
		'Uninstall leaves the retention event',
		[
			[ 'uninstall.php', "wp_clear_scheduled_hook( 'wm_edu_retention_cleanup' );\n", '' ],
		],
	],
	[
		'Form submissions not processed on template_redirect',
		[
			[ 'includes/class-edu-consent-form.php', "\t\tadd_action( 'template_redirect', [ self::class, 'handle_request' ] );\n", '' ],
		],
	],
	[
		'Honeypot ignored',
		[
			[ 'includes/class-edu-consent-form.php', "if ( '' !== self::posted_text( 'wm_edu_website' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'Form without rate limit',
		[
			[ 'includes/class-edu-consent-form.php', "if ( WM_Edu_Consent_Requests::rate_limited( WM_Edu_Consent_Requests::client_ip(), \$values['email'] ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'Confirmation page without the read box',
		[
			[ 'includes/class-edu-consent-form.php', "if ( 'confirm' === \$action && ! ( isset( \$_POST['wm_edu_read_confirm'] ) && '1' === \$_POST['wm_edu_read_confirm'] ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'Form without the read box',
		[
			[ 'includes/class-edu-consent-form.php', "if ( true !== ( \$values['read'] ?? false ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'Any choice other than grant read as a denial',
		[
			[ 'includes/class-edu-consent-form.php', "if ( 'grant' === \$choice || 'deny' === \$choice ) {", "if ( '' !== \$choice ) {" ],
		],
	],
	[
		'Busy link answered with 200',
		[
			[ 'includes/class-edu-consent-form.php', "\t\t\tstatus_header( 503 );\n", '' ],
		],
	],
	[
		'Token pages may be cached',
		[
			[ 'includes/class-edu-consent-form.php', "\t\t\tnocache_headers();\n", '' ],
		],
	],
	[
		'Token pages send a Referer',
		[
			[ 'includes/class-edu-consent-form.php', "\$headers['Referrer-Policy'] = 'no-referrer';", '' ],
		],
	],
	[
		'Result redirect with 302',
		[
			[ 'includes/class-edu-consent-form.php', 'add_query_arg( self::STATUS_PARAM, $status, $page ), 303 );', 'add_query_arg( self::STATUS_PARAM, $status, $page ), 302 );' ],
		],
	],
	[
		'Submitted address echoed without escaping',
		[
			[ 'includes/class-edu-consent-form.php', "esc_attr( (string) ( \$values['email'] ?? '' ) )", "(string) ( \$values['email'] ?? '' )" ],
		],
	],
	[
		'Error text below 4.5:1',
		[
			[ 'assets/css/wm-edu-portal.css', '--edu-error-text: #fca5a5;', '--edu-error-text: #7f1d1d;' ],
		],
	],
	// --- 2026-09-17: admin screen (test group 17) ---
	[
		'A manual entry without the acting user',
		[
			[ 'includes/class-edu-admin.php', "'source'         => 'admin_entry',\n\t\t\t\t'actor_id'       => \$user_id,", "'source'         => 'admin_entry',\n\t\t\t\t'actor_id'       => 0," ],
		],
	],
	[
		'A manual entry signed with another wording than the type has',
		[
			[ 'includes/class-edu-admin.php', "'scope_hash'     => (string) \$type->scope_hash,", "'scope_hash'     => str_repeat( 'a', 64 )," ],
		],
	],
	[
		'An unknown consent type accepted for a manual entry',
		[
			[ 'includes/class-edu-admin.php', "if ( null === \$type ) {\n\t\t\treturn 'type_unknown';", "if ( false ) {\n\t\t\treturn 'type_unknown';" ],
		],
	],
	[
		'Any status accepted for a manual entry',
		[
			[ 'includes/class-edu-admin.php', 'if ( ! in_array( $status, WM_Edu_Consent_Vault::STATUSES, true ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'An admin revocation signed with another wording than the entry it revokes',
		[
			[ 'includes/class-edu-admin.php', "'scope_hash'     => (string) \$newest->scope_hash,", "'scope_hash'     => str_repeat( 'b', 64 )," ],
		],
	],
	[
		'An admin revocation without an entry to revoke',
		[
			[ 'includes/class-edu-admin.php', "if ( null === \$newest ) {\n\t\t\treturn 'entry_unknown';", "if ( false ) {\n\t\t\treturn 'entry_unknown';" ],
		],
	],
	[
		'The history matches the pupil ID through the collation alone',
		[
			[ 'includes/class-edu-consent-vault.php', 'static fn( object $row ): bool => $student_token === (string) $row->student_token', 'static fn( object $row ): bool => true' ],
		],
	],
	[
		'The overview groups by pupil ID without the consent type',
		[
			[ 'includes/class-edu-consent-vault.php', "(string) wp_json_encode( [ (string) \$row->student_token, (string) \$row->consent_type ] )", '(string) $row->student_token' ],
		],
	],
	[
		'A wording that changed since the entry is not marked',
		[
			[ 'includes/class-edu-admin.php', "\$current = null !== \$type && hash_equals( (string) \$type->scope_hash, (string) \$row->scope_hash );", '$current = true;' ],
		],
	],
	[
		'A register that does not verify reported as checked',
		[
			[ 'includes/class-edu-admin.php', "if ( \$chain['valid'] ) {", 'if ( true ) {' ],
		],
	],
	[
		'A notice code the plugin does not know printed anyway',
		[
			[ 'includes/class-edu-admin.php', 'if ( isset( $good[ $code ] ) ) {', 'if ( true ) {' ],
		],
	],
	[
		'CSV cells not quoted',
		[
			[ 'includes/class-edu-admin.php', "static fn( string \$cell ): string => '\"' . str_replace( '\"', '\"\"', WM_Edu_PII_Tokenizer::sanitize_formula( \$cell ) ) . '\"',", 'static fn( string $cell ): string => $cell,' ],
		],
	],
	[
		'CSV quotes not doubled',
		[
			[ 'includes/class-edu-admin.php', "str_replace( '\"', '\"\"', WM_Edu_PII_Tokenizer::sanitize_formula( \$cell ) )", 'WM_Edu_PII_Tokenizer::sanitize_formula( $cell )' ],
		],
	],
	[
		'CSV formula cells not neutralised',
		[
			[ 'includes/class-edu-admin.php', 'WM_Edu_PII_Tokenizer::sanitize_formula( $cell )', '$cell' ],
		],
	],
	[
		'CSV without the byte order mark',
		[
			[ 'includes/class-edu-admin.php', 'return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";', 'return implode( "\r\n", $lines ) . "\r\n";' ],
		],
	],
	[
		'sanitize_formula ignores the line feed',
		[
			[ 'includes/class-edu-pii-tokenizer.php', "[ '=', '+', '-', '@', \"\\t\", \"\\r\", \"\\n\" ]", "[ '=', '+', '-', '@', \"\\t\", \"\\r\" ]" ],
		],
	],
	[
		'Admin handlers without a capability check',
		[
			[ 'includes/class-edu-admin.php', 'if ( ! current_user_can( $capability ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'Admin handlers without a nonce check',
		[
			[ 'includes/class-edu-admin.php', "\t\tcheck_admin_referer( \$action );\n", '' ],
		],
	],
	[
		'The settings guarded by the consent capability instead of manage_options',
		[
			[ 'includes/class-edu-admin.php', "self::guard( 'wm_edu_settings', WM_Edu_Access::SETTINGS_CAPABILITY );", "self::guard( 'wm_edu_settings' );" ],
		],
	],
	[
		'The settings menu entry open to the consent capability',
		[
			[ 'includes/class-edu-admin.php', 'WM_Edu_Access::SETTINGS_CAPABILITY, self::SETTINGS_SLUG', '$cap, self::SETTINGS_SLUG' ],
		],
	],
	[
		'A partly refused guardian import reported as complete',
		[
			[ 'includes/class-edu-admin.php', "( [] === \$result['invalid'] ? 'guardians_imported' : 'guardians_partly' )", "'guardians_imported'" ],
		],
	],
	[
		'Settings saved with role names the site does not have',
		[
			[ 'includes/class-edu-admin.php', 'array_keys( wp_roles()->role_objects )', "[ 'teacher', 'unbekannt' ]" ],
		],
	],
	// --- 2026-09-17: access control for the lookup and the tokenizer (test group 18) ---
	[
		'The consent lookup is public again',
		[
			[ 'includes/class-edu-shortcodes.php', 'if ( ! WM_Edu_Access::can_use_gate() ) {', 'if ( false ) {' ],
		],
	],
	[
		'The lookup open to every logged-in user',
		[
			[ 'includes/class-edu-access.php', "if ( current_user_can( self::CAPABILITY ) ) {\n\t\t\treturn true;\n\t\t}\n\t\t\$user = wp_get_current_user();", "if ( is_user_logged_in() ) {\n\t\t\treturn true;\n\t\t}\n\t\t\$user = wp_get_current_user();" ],
		],
	],
	[
		'Gate roles from the settings ignored',
		[
			[ 'includes/class-edu-access.php', "return [] !== array_intersect( (array) ( \$user->roles ?? [] ), (array) WM_Edu_Settings::get( 'gate_roles' ) );", 'return false;' ],
		],
	],
	[
		'The public-gate setting ignored',
		[
			[ 'includes/class-edu-access.php', "if ( true === WM_Edu_Settings::get( 'public_gate' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'The lookup does not check its nonce',
		[
			[ 'includes/class-edu-shortcodes.php', "if ( is_user_logged_in() && ! wp_verify_nonce( \$nonce, 'wm_edu_gate' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'The lookup form without a nonce field',
		[
			[ 'includes/class-edu-shortcodes.php', "\t\t\$output .= wp_nonce_field( 'wm_edu_gate', 'wm_edu_gate_nonce', true, false );\n", '' ],
		],
	],
	[
		'The lookup ignores the consent type of the shortcode',
		[
			[ 'includes/class-edu-shortcodes.php', 'WM_Edu_Consent_Vault::consent_state( $student_id, $type )', 'WM_Edu_Consent_Vault::consent_state( $student_id )' ],
		],
	],
	[
		'A refused visitor is not told where to log in',
		[
			[ 'includes/class-edu-shortcodes.php', "if ( ! is_user_logged_in() ) {\n\t\t\t\$here    = get_permalink();", "if ( false ) {\n\t\t\t\$here    = get_permalink();" ],
		],
	],
	[
		'The tokenize route public again',
		[
			[ 'includes/class-edu-rest-controller.php', "'callback'            => [ self::class, 'handle_tokenize' ],\n\t\t\t\t'permission_callback' => [ 'WM_Edu_Access', 'rest_tokenizer_permission' ],", "'callback'            => [ self::class, 'handle_tokenize' ],\n\t\t\t\t'permission_callback' => '__return_true'," ],
		],
	],
	[
		'The tokenizer routes without a capability check',
		[
			[ 'includes/class-edu-access.php', 'if ( ! current_user_can( self::tokenizer_capability() ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'An anonymous tokenizer request answered 403 instead of 401',
		[
			[ 'includes/class-edu-access.php', "if ( ! is_user_logged_in() ) {\n\t\t\treturn new WP_Error( 'rest_forbidden',", "if ( false ) {\n\t\t\treturn new WP_Error( 'rest_forbidden'," ],
		],
	],
	[
		'The public-tokenizer setting ignored in the route permission',
		[
			[ 'includes/class-edu-access.php', "if ( true === WM_Edu_Settings::get( 'public_tokenizer' ) ) {\n\t\t\treturn true;\n\t\t}\n\t\tif ( ! is_user_logged_in() ) {", "if ( false ) {\n\t\t\treturn true;\n\t\t}\n\t\tif ( ! is_user_logged_in() ) {" ],
		],
	],
	[
		'The tokenizer demo public again',
		[
			[ 'includes/class-edu-shortcodes.php', 'if ( ! WM_Edu_Access::can_use_tokenizer() ) {', 'if ( false ) {' ],
		],
	],
	[
		'The demo open to every logged-in user',
		[
			[ 'includes/class-edu-access.php', 'return is_user_logged_in() && current_user_can( self::tokenizer_capability() );', 'return is_user_logged_in();' ],
		],
	],
	[
		'The tokenizer capability hook passed as a constant (Plugin Check cannot resolve it)',
		[
			[ 'includes/class-edu-access.php', "apply_filters( 'wm_edu_tokenizer_capability', 'edit_posts' )", 'apply_filters( self::TOKENIZER_CAPABILITY_FILTER, \'edit_posts\' )' ],
		],
	],
	[
		'A filter value that is not a capability name used as one',
		[
			[ 'includes/class-edu-access.php', "return is_string( \$capability ) && '' !== \$capability ? \$capability : 'edit_posts';", 'return (string) $capability;' ],
		],
	],
	[
		'Telemetry reports 0 pending requests without the tables',
		[
			[ 'includes/class-edu-spoke-adapter.php', "'requests_pending'     => \$chain['missing_table'] ? null : self::pending_requests(),", "'requests_pending'     => (int) self::pending_requests()," ],
		],
	],
	[
		'The SBOM still says the ledger has no writer',
		[
			[ 'includes/class-edu-spoke-adapter.php', "'value' => 'Hash-chained consent table written under a named lock by the guardian confirmation and revocation links and by the admin screen, read by [wm_edu_consent_gate]',", "'value' => 'Hash-chained consent table read by [wm_edu_consent_gate]; no shipped writer'," ],
		],
	],
	[
		'wp edu status without the row count of the recorded tip',
		[
			[ 'includes/class-edu-cli.php', "' · Zeilen: ' . \$tip['count']", "' · Zeilen: ?'" ],
		],
	],
	[
		'The upgrade runs on every load',
		[
			[ 'includes/class-edu-consent-vault.php', 'if ( self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, \'\' ) ) {', 'if ( true ) {' ],
		],
	],
	[
		'An existing table keeps its GRANTED default',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( \$had_table ) {\n\t\t\t// dbDelta() sets column defaults but never drops one.", "if ( false ) {\n\t\t\t// dbDelta() sets column defaults but never drops one." ],
		],
	],
	[
		'Boundary recorded as 0 on an existing table',
		[
			[ 'includes/class-edu-consent-vault.php', '$boundary  = $had_table ? (int)', '$boundary  = false ? (int)' ],
		],
	],
	[
		'A repeated install overwrites the recorded boundary',
		[
			[ 'includes/class-edu-consent-vault.php', 'add_option( self::LEGACY_BOUNDARY_OPTION, (string) $boundary, \'\', false );', 'update_option( self::LEGACY_BOUNDARY_OPTION, (string) $boundary );' ],
		],
	],
	[
		'Version recorded although dbDelta() made no table',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( ! self::table_exists() ) {\n\t\t\treturn; // dbDelta() failed", "if ( false ) {\n\t\t\treturn; // dbDelta() failed" ],
		],
	],
	[
		'text not a required argument',
		[
			[ 'includes/class-edu-rest-controller.php', '\'required\'          => true,', '\'required\'          => false,' ],
		],
	],
	[
		'Maps registered without their validator',
		[
			[ 'includes/class-edu-rest-controller.php', "\t\t\t'validate_callback'    => [ self::class, 'validate_string_map' ],\n", '' ],
		],
	],
	[
		'A JSON list accepted as an object',
		[
			[ 'includes/class-edu-rest-controller.php', '$valid = is_array( $value ) && ( [] === $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) );', '$valid = is_array( $value );' ],
		],
	],
	[
		'Text not checked for UTF-8',
		[
			[ 'includes/class-edu-rest-controller.php', 'if ( ! is_string( $value ) || 1 !== preg_match( \'//u\', $value ) ) {', 'if ( ! is_string( $value ) ) {' ],
		],
	],
	[
		'The tokenize handler trusts core validation',
		[
			[ 'includes/class-edu-rest-controller.php', 'foreach ( [ self::validate_text( $text ), self::validate_string_map( $students ) ] as $check ) {', 'foreach ( [] as $check ) {' ],
		],
	],
	[
		'Route text sanitised as HTML again',
		[
			[ 'includes/class-edu-rest-controller.php', '$text     = $request->get_param( \'text\' );', '$text     = sanitize_text_field( (string) $request->get_param( \'text\' ) );' ],
		],
	],
	[
		'Sanitizer typed array again (TypeError on WordPress 6.4)',
		[
			[ 'includes/class-edu-shortcodes.php', 'public static function render_pii_sanitizer( array|string $atts = [] ): string {', 'public static function render_pii_sanitizer( array $atts = [] ): string {' ],
		],
	],
	[
		'Gate typed array again (TypeError on WordPress 6.4)',
		[
			[ 'includes/class-edu-shortcodes.php', 'public static function render_consent_gate( array|string $atts = [] ): string {', 'public static function render_consent_gate( array $atts = [] ): string {' ],
		],
	],
	[
		'Stylesheet not enqueued',
		[
			[ 'includes/class-edu-shortcodes.php', "\t\tself::enqueue_style();\n\n\t\tif ( ! WM_Edu_Access::can_use_tokenizer() ) {", "\t\tif ( ! WM_Edu_Access::can_use_tokenizer() ) {" ],
			[ 'includes/class-edu-shortcodes.php', "\t\tself::enqueue_style();\n\n\t\tif ( ! WM_Edu_Access::can_use_gate() ) {", "\t\tif ( ! WM_Edu_Access::can_use_gate() ) {" ],
		],
	],
	[
		'Inline style on the box again',
		[
			[ 'includes/class-edu-shortcodes.php', '\'<section class="wm-edu-box wm-edu-sanitizer-box"', '\'<section style="padding:24px" class="wm-edu-box wm-edu-sanitizer-box"' ],
		],
	],
	[
		'Demo text sanitised as HTML again',
		[
			[ 'includes/class-edu-shortcodes.php', '? wp_unslash( $_POST[\'wm_edu_raw\'] ) :', '? sanitize_text_field( wp_unslash( $_POST[\'wm_edu_raw\'] ) ) :' ],
		],
	],
	[
		'The gate has no unverifiable answer',
		[
			[ 'includes/class-edu-shortcodes.php', '} elseif ( \'unverifiable\' === $state ) {', '} elseif ( false ) {' ],
		],
	],
	[
		'Unused service field back in the gate',
		[
			[ 'includes/class-edu-shortcodes.php', 'placeholder="EDU-2026-9B-042" required></label>\';', 'placeholder="EDU-2026-9B-042" required></label><label class="wm-edu-field">Lernsoftware / Dienst <input type="text" name="edu_service" value="SchulCloud NRW / Lernplattform" class="wm-edu-input"></label>\';' ],
		],
	],
	[
		'Emoji instead of the SVG icon',
		[
			[ 'includes/class-edu-shortcodes.php', 'self::icon( \'check\' ) . \' \' . esc_html( sprintf(', '\'✅ \' . esc_html( sprintf(' ],
		],
	],
	[
		'The demo sends name lists to WP-CLI again',
		[
			[ 'includes/class-edu-shortcodes.php', 'Schülernamen werden nur ersetzt, wenn die Namensliste der Schule als known_students an die REST-Route wm-edu/v1/tokenize übergeben wird.', 'Schülernamen werden nur ersetzt, wenn die Namensliste der Schule an die REST-API oder das WP-CLI-Kommando übergeben wird.' ],
		],
	],
	[
		'Button says Anonymisieren again',
		[
			[ 'includes/class-edu-shortcodes.php', 'esc_html__( \'Text pseudonymisieren\', \'wm-edu-privacy-portal\' )', 'esc_html__( \'Text Anonymisieren\', \'wm-edu-privacy-portal\' )' ],
		],
	],
	[
		'Hex colour in a rule',
		[
			[ 'assets/css/wm-edu-portal.css', "\tbackground: var(--edu-accent);\n\tcolor: var(--edu-accent-contrast);", "\tbackground: #0369a1;\n\tcolor: var(--edu-accent-contrast);" ],
		],
	],
	[
		'Button colour of 2026-09-14 (4.10:1)',
		[
			[ 'assets/css/wm-edu-portal.css', '--edu-accent: #0369a1;', '--edu-accent: #0284c7;' ],
		],
	],
	[
		'Gate button colour of 2026-09-14 (3.77:1)',
		[
			[ 'assets/css/wm-edu-portal.css', '--edu-accent-gate: #047857;', '--edu-accent-gate: #059669;' ],
		],
	],
	[
		'Field border of 2026-09-14 (2.36:1)',
		[
			[ 'assets/css/wm-edu-portal.css', '--edu-field-border: #94a3b8;', '--edu-field-border: #475569;' ],
		],
	],
	[
		'Targets of 36px',
		[
			[ 'assets/css/wm-edu-portal.css', '--edu-target: 48px;', '--edu-target: 36px;' ],
		],
	],
	[
		'Health literal HEALTHY again',
		[
			[ 'includes/class-edu-spoke-adapter.php', 'return WM_Edu_Consent_Vault::verify_chain()[\'valid\'] ? \'HEALTHY\' : \'ERROR\';', 'return \'HEALTHY\';' ],
		],
	],
	[
		'ledger_rows is the verified count again',
		[
			[ 'includes/class-edu-spoke-adapter.php', '\'ledger_rows\'          => $chain[\'rows\'],', '\'ledger_rows\'          => $chain[\'checked\'],' ],
		],
	],
	[
		'Telemetry hides a missing table',
		[
			[ 'includes/class-edu-spoke-adapter.php', '\'missing_table\'        => $chain[\'missing_table\'] ? 1 : 0,', '\'missing_table\'        => 0,' ],
		],
	],
	[
		'self_test ignores the chain',
		[
			[ 'includes/class-edu-spoke-adapter.php', "if ( ! \$chain['valid'] ) {\n\t\t\t\$problems[] = (string) \$chain['error'];", "if ( false ) {\n\t\t\t\$problems[] = (string) \$chain['error'];" ],
		],
	],
	[
		'SBOM tokenizer property of 2026-09-14',
		[
			[ 'includes/class-edu-spoke-adapter.php', '\'value\' => \'Caller-supplied names, German dates and class identifiers replaced by HMAC-SHA256 tokens under a key derived from the site auth salt (8 hex characters, 6 for classes; caller-assigned tokens as given)\',', '\'value\' => \'Dates of birth, class identifiers and caller-supplied names replaced by HMAC-SHA256 tokens keyed with the site salt (8 hex characters)\',' ],
		],
	],
	[
		'wp edu status ends green on a broken chain',
		[
			[ 'includes/class-edu-cli.php', "if ( ! \$chain['valid'] ) {\n\t\t\tWP_CLI::error( (string) \$chain['error'] );", "if ( false ) {\n\t\t\tWP_CLI::error( (string) \$chain['error'] );" ],
		],
	],
	[
		'tokenize help promises names and grades again',
		[
			[ 'includes/class-edu-cli.php', 'wp edu tokenize "Aus der Kl. 7c, geboren am 15.03.2012."', 'wp edu tokenize "Max Mustermann aus Klasse 9b hat die Note 1 erhalten."' ],
		],
	],
	[
		'Text domain not loaded',
		[
			[ 'wm-edu-privacy-portal.php', 'load_plugin_textdomain( \'wm-edu-privacy-portal\', false, dirname( plugin_basename( WM_EDU_PORTAL_FILE ) ) . \'/languages\' );', '// text domain not loaded' ],
		],
	],
	[
		'Schema check not hooked to plugins_loaded',
		[
			[ 'wm-edu-privacy-portal.php', "add_action( 'plugins_loaded', [ 'WM_Edu_Consent_Vault', 'maybe_upgrade' ] );\n", '' ],
		],
	],
	[
		'Uninstall leaves the boundary option',
		[
			[ 'uninstall.php', "delete_option( 'wm_edu_legacy_row_boundary' );\n", '' ],
		],
	],
	[
		'Template without a string of the code',
		[
			[ 'languages/wm-edu-privacy-portal.pot', 'msgid "Text pseudonymisieren"', 'msgid "Text Anonymisieren"' ],
		],
	],
	[
		'de_DE catalog double-encoded again',
		[
			[ 'languages/wm-edu-privacy-portal-de_DE.po', 'msgid "Text mit Schülerdaten"', 'msgid "Text mit SchÃ¼lerdaten"' ],
		],
	],
	[
		'README embeds the marketing banner again',
		[
			[ 'README.md', "## Images\n\n", "## Images\n\n![WM Edu Privacy Portal banner](assets/images/edu-portal-banner.jpg)\n\n" ],
		],
	],
	[
		'humans.txt says Founder & CEO again',
		[
			[ 'public/humans.txt', 'Author: Arnold Wender', 'Author: Arnold Wender -- Founder & CEO' ],
		],
	],
	[
		'SECURITY.md promises a response time again',
		[
			[ 'SECURITY.md', 'Reports are acknowledged and handled as quickly as possible; no response time is guaranteed.', '- **Acknowledgement:** Within 24 hours.' ],
		],
	],
	[
		'AGENTS.md diverges from CLAUDE.md',
		[
			[ 'AGENTS.md', 'There is no `/src/`.', 'Core business logic is organized under `/src/` and `/includes/`.' ],
		],
	],
	[
		'readme.txt short description over 150 characters',
		[
			[ 'readme.txt', 'Records parental consent in a hash-chained register through a form confirmed by e-mail, and looks it up for the school.', 'Records parental consent in a hash-chained register through a form whose decision is confirmed by e-mail, looks it up for the school, and replaces German dates and class identifiers with placeholders.' ],
		],
	],
	[
		'Marketing images in the package again',
		[
			[ '.distignore', "\nassets/images/\n", "\nassets/imgs-kept/\n" ],
		],
	],
	[
		'llms.txt claims PSR-12 again',
		[
			[ 'llms.txt', '- PHP 8.1+ with strict types;', '- PHP 8.1+ / Strict Types / PSR-12 / WPCS;' ],
		],
	],
	[
		'CONTRIBUTING.md claims legal compliance again',
		[
			[ 'CONTRIBUTING.md', 'part of the WM Suite plugin suite.', 'which adheres to high standards of security, accessibility, and legal compliance.' ],
		],
	],
	[
		'CI without the mutation check',
		[
			[ '.github/workflows/ci.yml', 'run: bash tests/run-mutations.sh', 'run: echo skipped' ],
		],
	],
	// --- 2026-11-16: opt-in uninstall, literal shortcode registration, CSV filters, ecosystem bridges (test group 20) ---
	[
		'Uninstall deletes data without explicit opt-in',
		[
			[ 'uninstall.php', "if ( ! \$wm_edu_delete_all ) {\n\treturn;\n}", "if ( false ) {\n\treturn;\n}" ],
		],
	],
	[
		'Uninstall keeps custom tables even when opt-in is enabled',
		[
			[ 'uninstall.php', "\t\t\$wpdb->query( \$wpdb->prepare( 'DROP TABLE IF EXISTS %i', \$wpdb->prefix . \$wm_edu_table ) );\n", '' ],
		],
	],
	[
		'Settings handler ignores wm_edu_delete_data_on_uninstall',
		[
			[ 'includes/class-edu-admin.php', "WM_Edu_Settings::sanitize_delete_flag( \$post['wm_edu_delete_data_on_uninstall'] ?? '0' ),", "'0'," ],
		],
	],
	[
		'CSV export omits wm_edu_export_csv_output filter',
		[
			[ 'includes/class-edu-admin.php', "\t\t\$csv = (string) apply_filters( 'wm_edu_export_csv_output', \$csv );\n", '' ],
		],
	],
	[
		'Consent form shortcode registered via constant instead of literal tag',
		[
			[ 'includes/class-edu-consent-form.php', "add_shortcode( 'wm_edu_consent_form', [ self::class, 'render' ] );", 'add_shortcode( self::SHORTCODE, [ self::class, \'render\' ] );' ],
		],
	],
	[
		'wm_edu_ready action not fired on bootstrap',
		[
			[ 'includes/class-edu-ecosystem.php', "\t\tdo_action( 'wm_edu_ready', WM_EDU_PORTAL_VERSION );\n", '' ],
		],
	],
	[
		'Block Bindings source wm-edu/status not registered',
		[
			[ 'includes/class-edu-ecosystem.php', "if ( function_exists( 'register_block_bindings_source' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'GenerateBlocks dynamic tag wm_edu_status not registered',
		[
			[ 'includes/class-edu-ecosystem.php', "if ( class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'Ecosystem status reports active when chain is broken',
		[
			[ 'includes/class-edu-ecosystem.php', "\$value = \$chain['valid']\n\t\t\t\t? __( 'Einwilligungsregister aktiv', 'wm-edu-privacy-portal' )", "if ( true ) \$value = __( 'Einwilligungsregister aktiv', 'wm-edu-privacy-portal' ); else \$value = true\n\t\t\t\t? __( 'Einwilligungsregister aktiv', 'wm-edu-privacy-portal' )" ],
		],
	],
	[
		'Ecosystem pupil status ignores gate access control',
		[
			[ 'includes/class-edu-ecosystem.php', "if ( ! WM_Edu_Access::can_use_gate() ) {\n\t\t\t\treturn '';", "if ( false ) {\n\t\t\t\treturn '';" ],
		],
	],
	// --- 2026-11-18: multisite provisioning (wp_initialize_site, $network_wide), dbDelta guard, autoload = false (test group 21) ---
	[
		'wp_initialize_site not hooked in main plugin file',
		[
			[ 'wm-edu-privacy-portal.php', "add_action( 'wp_initialize_site', 'wm_edu_on_initialize_site' );", '// wp_initialize_site not hooked' ],
		],
	],
	[
		'wp_initialize_site ignores network-activation check',
		[
			[ 'wm-edu-privacy-portal.php', "if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'wp_initialize_site does not restore current blog in finally',
		[
			[ 'wm-edu-privacy-portal.php', "\t\tWM_Edu_Consent_Vault::install();\n\t} finally {\n\t\trestore_current_blog();\n\t}", "\t\tWM_Edu_Consent_Vault::install();\n\t} finally {\n\t}" ],
		],
	],
	[
		'install() ignores $network_wide on multisite',
		[
			[ 'includes/class-edu-consent-vault.php', "if ( \$network_wide && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'create_tables() requires upgrade.php without file_exists guard',
		[
			[ 'includes/class-edu-consent-vault.php', "\t\t\t\$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';\n\t\t\tif ( file_exists( \$upgrade_file ) ) {\n\t\t\t\trequire_once \$upgrade_file;\n\t\t\t}", "\t\t\trequire_once ABSPATH . 'wp-admin/includes/upgrade.php';" ],
		],
	],
	[
		'SCHEMA_OPTION stored without explicit autoload = false',
		[
			[ 'includes/class-edu-consent-vault.php', 'update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );', 'update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );' ],
		],
	],
	[
		'wm_edu_delete_data_on_uninstall stored without explicit autoload = false',
		[
			[ 'includes/class-edu-admin.php', "WM_Edu_Settings::sanitize_delete_flag( \$post['wm_edu_delete_data_on_uninstall'] ?? '0' ),\n\t\t\tfalse", "WM_Edu_Settings::sanitize_delete_flag( \$post['wm_edu_delete_data_on_uninstall'] ?? '0' )" ],
		],
	],
];
