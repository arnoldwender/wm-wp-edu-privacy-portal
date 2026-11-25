<?php
/**
 * Standalone test suite for wm-edu-privacy-portal.
 *
 * Runs without WordPress. WordPress functions are small fakes; $wpdb is a fake that runs the plugin's SQL on an
 * in-memory SQLite database whose consent table is built from WM_Edu_Consent_Vault::table_sql(), so the table the tests
 * use cannot drift from the schema. SQL the fake cannot run throws; a query against a missing table sets last_error and
 * returns nothing, as wpdb does; values come back as strings; a PHP warning or notice fails the case that raised it.
 * MySQL's case-insensitive default collation is imitated with COLLATE NOCASE on the token and type columns.
 *
 *   php tests/test-suite.php          exit 0 only when every assertion passes
 *   bash tests/run-mutations.sh       undoes each correction of tests/mutations.php; this suite must fail every time
 *
 * Until 2026-09-15 this file ran against a mock whose insert() always succeeded, whose chain-tip query ignored its
 * WHERE clause and whose reads never failed; its telemetry test compared a value with its own source.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	echo "pdo_sqlite is required for tests/test-suite.php.\n";
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
// The wp-config-sample.php phrase: core's wp_salt() ignores it, and no key of the plugin may come from it.
define( 'AUTH_KEY', 'put your unique phrase here' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

final class EduCliError extends RuntimeException {}

// ---------------------------------------------------------------------------------------------------------------------
// $wpdb on SQLite
// ---------------------------------------------------------------------------------------------------------------------

final class Edu_Test_WPDB {
	public string $prefix     = 'wp_';
	public string $options    = 'wp_options';
	public int $insert_id     = 0;
	public string $last_error = '';
	/** @var string[] */
	public array $queries = [];
	/** @var string[] ALTER … MODIFY / ALTER COLUMN statements (SQLite cannot run them; recorded instead). */
	public array $ddl = [];
	/** Regex: the next SELECT matching it fails (simulated read error). */
	public ?string $fail_select = null;
	/**
	 * MySQL named locks do not exist in SQLite. GET_LOCK() answers $lock_result ('1' the lock, '0' a timeout, null an
	 * error); every GET_LOCK / RELEASE_LOCK is recorded in $lock_calls in order.
	 */
	public ?string $lock_result = '1';
	/** @var string[] */
	public array $lock_calls = [];
	/** The next insert() fails like a refused MySQL insert. */
	public bool $fail_insert = false;
	/** When > 0: counts inserts down and fails the one that reaches 0 (2 lets one insert through, fails the second). */
	public int $fail_insert_on = 0;
	/** Regex: the next UPDATE matching it changes no row (another request changed the row first). */
	public ?string $zero_rows = null;
	/** Open START TRANSACTION blocks (SQLite runs them as BEGIN). */
	public int $transaction_depth = 0;
	private PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$this->pdo->query( "CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL DEFAULT 'yes')" );
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function esc_like( $text ): string {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$out = preg_replace_callback(
			'/%(%|i|s|d|f)/',
			function ( array $m ) use ( &$i, $args, $query ): string {
				if ( '%' === $m[1] ) {
					return '%';
				}
				if ( ! array_key_exists( $i, $args ) ) {
					throw new RuntimeException( 'prepare(): placeholder without argument in ' . $query );
				}
				$value = $args[ $i++ ];
				return match ( $m[1] ) {
					'i' => '`' . str_replace( '`', '``', (string) $value ) . '`',
					's' => $this->pdo->quote( (string) $value ),
					'd' => (string) (int) $value,
					'f' => sprintf( '%F', (float) $value ),
				};
			},
			(string) $query
		);
		if ( $i !== count( $args ) ) {
			throw new RuntimeException( 'prepare(): ' . count( $args ) . ' arguments for ' . $i . ' placeholders in ' . $query );
		}
		return (string) $out;
	}

	private function translate( string $sql ): string {
		$s = trim( $sql );
		if ( preg_match( "/^SHOW TABLES LIKE '((?:[^']|'')*)'$/i", $s, $m ) ) {
			$name = preg_replace( '/\\\\(.)/', '$1', str_replace( "''", "'", $m[1] ) );
			return "SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote( (string) $name );
		}
		return (string) preg_replace( "/LIKE ('(?:[^']|'')*')/i", "LIKE $1 ESCAPE '\\\\'", $s );
	}

	private function run( string $sql ): ?PDOStatement {
		$this->queries[]  = $sql;
		$this->last_error = '';
		if ( null !== $this->fail_select && 1 === preg_match( $this->fail_select, $sql ) ) {
			$this->fail_select = null;
			$this->last_error  = 'simulated read failure';
			return null;
		}
		try {
			return $this->pdo->query( $this->translate( $sql ) );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			// wpdb reports a missing table through last_error; any other failure is a broken query and fails loudly.
			if ( str_contains( $e->getMessage(), 'no such table' ) ) {
				return null;
			}
			throw new RuntimeException( 'SQL failed: ' . $e->getMessage() . ' in: ' . $sql );
		}
	}

	/** Statement for the test itself (table setup), not something the plugin calls. */
	public function run_raw( string $sql ): void {
		$this->pdo->query( $sql );
	}

	public function query( $sql ) {
		$sql = (string) $sql;
		if ( preg_match( '/^\s*(START\s+TRANSACTION|COMMIT|ROLLBACK)\s*$/i', $sql, $tx ) ) {
			$this->queries[] = $sql;
			$begin           = 0 === stripos( $tx[1], 'START' );
			$this->pdo->query( $begin ? 'BEGIN' : strtoupper( $tx[1] ) );
			$this->transaction_depth += $begin ? 1 : -1;
			return true;
		}
		if ( null !== $this->zero_rows && 1 === preg_match( $this->zero_rows, $sql ) ) {
			$this->queries[] = $sql;
			$this->zero_rows = null;
			return 0;
		}
		if ( preg_match( '/^\s*ALTER\s+TABLE\s+\S+\s+(MODIFY|ALTER\s+COLUMN)\b/i', $sql ) ) {
			$this->queries[] = $sql;
			$this->ddl[]     = $sql;
			return true;
		}
		$st = $this->run( $sql );
		if ( null === $st ) {
			return false;
		}
		return preg_match( '/^\s*(DELETE|UPDATE|INSERT)/i', $sql ) ? $st->rowCount() : true;
	}

	public function get_var( $sql ) {
		if ( preg_match( '/^\s*SELECT\s+(GET_LOCK|RELEASE_LOCK)\s*\(/i', (string) $sql, $lock ) ) {
			$this->queries[]    = (string) $sql;
			$this->lock_calls[] = strtoupper( $lock[1] );
			return 'GET_LOCK' === strtoupper( $lock[1] ) ? $this->lock_result : '1';
		}
		$st = $this->run( (string) $sql );
		if ( null === $st ) {
			return null;
		}
		$row = $st->fetch( PDO::FETCH_NUM );
		return false === $row ? null : $row[0];
	}

	public function get_results( $sql, $output = OBJECT ) {
		$st = $this->run( (string) $sql );
		if ( null === $st ) {
			return [];
		}
		$rows = $st->fetchAll( PDO::FETCH_ASSOC );
		return ARRAY_A === $output ? $rows : array_map( static fn( array $r ): object => (object) $r, $rows );
	}

	public function get_row( $sql, $output = OBJECT ) {
		return $this->get_results( $sql, $output )[0] ?? null;
	}

	public function insert( $table, $data, $format = null ) {
		$table            = (string) $table;
		$this->last_error = '';
		if ( null !== $format && count( (array) $format ) !== count( $data ) ) {
			throw new RuntimeException( 'insert(): ' . count( (array) $format ) . ' formats for ' . count( $data ) . ' columns into ' . $table );
		}
		$cols            = array_keys( $data );
		$sql             = 'INSERT INTO `' . $table . '` (`' . implode( '`, `', $cols ) . '`) VALUES (' . implode( ', ', array_fill( 0, count( $cols ), '?' ) ) . ')';
		$this->queries[] = $sql;
		if ( $this->fail_insert_on > 0 && 0 === --$this->fail_insert_on ) {
			$this->fail_insert = true;
		}
		if ( $this->fail_insert ) {
			$this->fail_insert = false;
			$this->last_error  = 'simulated insert failure';
			return false;
		}
		try {
			$st = $this->pdo->prepare( $sql );
			$st->execute( array_values( $data ) );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function update( $table, $data, $where ) {
		$set  = implode( ', ', array_map( static fn( $c ) => "`$c` = ?", array_keys( $data ) ) );
		$cond = implode( ' AND ', array_map( static fn( $c ) => "`$c` = ?", array_keys( $where ) ) );
		$st   = $this->pdo->prepare( "UPDATE `$table` SET $set WHERE $cond" );
		$st->execute( array_merge( array_values( $data ), array_values( $where ) ) );
		return $st->rowCount();
	}

	public function has_table( string $table ): bool {
		$st = $this->pdo->prepare( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?" );
		$st->execute( [ $table ] );
		return false !== $st->fetchColumn();
	}

	/** @return string[] */
	public function columns( string $table ): array {
		return array_column( $this->pdo->query( 'PRAGMA table_info(' . $this->pdo->quote( $table ) . ')' )->fetchAll( PDO::FETCH_ASSOC ), 'name' );
	}

	/** dbDelta() of the fake: creates a missing table, adds missing columns to an existing one. */
	public function apply_create_table( string $mysql ): void {
		[ $table, $create, $columns ] = edu_sqlite_ddl( $mysql );
		if ( ! $this->has_table( $table ) ) {
			$this->run_raw( $create );
			return;
		}
		$have = $this->columns( $table );
		foreach ( $columns as $name => $definition ) {
			if ( ! in_array( $name, $have, true ) ) {
				$this->run_raw( "ALTER TABLE {$table} ADD COLUMN {$definition}" );
			}
		}
	}

	public function ensure_options_table(): void {
		$tbl = str_replace( '`', '``', $this->options );
		$this->pdo->query( "CREATE TABLE IF NOT EXISTS `{$tbl}` (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL DEFAULT 'yes')" );
	}

	public function option_get( string $name ): ?string {
		$this->ensure_options_table();
		$tbl = str_replace( '`', '``', $this->options );
		$st  = $this->pdo->prepare( "SELECT option_value FROM `{$tbl}` WHERE option_name = ?" );
		$st->execute( [ $name ] );
		$v = $st->fetchColumn();
		return false === $v ? null : (string) $v;
	}

	public function option_set( string $name, string $value, string $autoload = 'yes' ): void {
		$this->ensure_options_table();
		$tbl = str_replace( '`', '``', $this->options );
		$st  = $this->pdo->prepare( "INSERT INTO `{$tbl}` (option_name, option_value, autoload) VALUES (?, ?, ?) ON CONFLICT(option_name) DO UPDATE SET option_value = excluded.option_value, autoload = excluded.autoload" );
		$st->execute( [ $name, $value, $autoload ] );
	}

	public function option_autoload( string $name ): ?string {
		$this->ensure_options_table();
		$tbl = str_replace( '`', '``', $this->options );
		$st  = $this->pdo->prepare( "SELECT autoload FROM `{$tbl}` WHERE option_name = ?" );
		$st->execute( [ $name ] );
		$v = $st->fetchColumn();
		return false === $v ? null : (string) $v;
	}

	public function option_delete( string $name ): void {
		$this->ensure_options_table();
		$tbl = str_replace( '`', '``', $this->options );
		$st  = $this->pdo->prepare( "DELETE FROM `{$tbl}` WHERE option_name = ?" );
		$st->execute( [ $name ] );
	}

	public function pdo_option_get( string $table, string $name ): ?string {
		if ( ! $this->has_table( $table ) ) {
			return null;
		}
		$tbl = str_replace( '`', '``', $table );
		$st  = $this->pdo->prepare( "SELECT option_value FROM `{$tbl}` WHERE option_name = ?" );
		$st->execute( [ $name ] );
		$v = $st->fetchColumn();
		return false === $v ? null : (string) $v;
	}
}

/**
 * CREATE TABLE of the plugin → SQLite: the auto-increment id becomes INTEGER PRIMARY KEY AUTOINCREMENT, UNSIGNED and
 * the keys go, and the token and type columns get COLLATE NOCASE like MySQL's default collation.
 *
 * @return array{0: string, 1: string, 2: array<string, string>} table, CREATE statement, column definitions by name
 */
function edu_sqlite_ddl( string $mysql ): array {
	if ( ! preg_match( '/^\s*CREATE TABLE\s+(\S+)\s*\((.*)\)\s*;\s*$/s', $mysql, $m ) ) {
		throw new RuntimeException( 'unexpected CREATE TABLE: ' . $mysql );
	}
	$table   = $m[1];
	$columns = [];
	foreach ( preg_split( '/,\s*\n/', trim( $m[2] ) ) as $line ) {
		$line = trim( $line );
		if ( preg_match( '/^(PRIMARY KEY|KEY|UNIQUE KEY)\b/i', $line ) ) {
			continue;
		}
		if ( ! preg_match( '/^(\w+)\s/', $line, $c ) ) {
			throw new RuntimeException( 'unexpected column line: ' . $line );
		}
		if ( preg_match( '/^\w+\s+BIGINT UNSIGNED NOT NULL AUTO_INCREMENT$/i', $line ) ) {
			$columns[ $c[1] ] = "{$c[1]} INTEGER PRIMARY KEY AUTOINCREMENT";
			continue;
		}
		$definition = (string) preg_replace( '/\s+UNSIGNED\b/i', '', $line );
		if ( in_array( $c[1], [ 'student_token', 'consent_type' ], true ) ) {
			$definition .= ' COLLATE NOCASE';
		}
		$columns[ $c[1] ] = $definition;
	}
	return [ $table, "CREATE TABLE {$table} (" . implode( ', ', $columns ) . ')', $columns ];
}

// ---------------------------------------------------------------------------------------------------------------------
// WordPress fakes
// ---------------------------------------------------------------------------------------------------------------------

$GLOBALS['edu_salt']          = 'site-salt-A';
$GLOBALS['edu_actions']       = [];
$GLOBALS['edu_fired_actions'] = [];
$GLOBALS['edu_shortcodes']    = [];
$GLOBALS['edu_routes']        = [];
$GLOBALS['edu_styles']        = [];
$GLOBALS['edu_textdomains']   = [];
$GLOBALS['edu_activation']    = [];
$GLOBALS['edu_dbdelta_calls']          = [];
$GLOBALS['edu_dbdelta_fail']           = false;
$GLOBALS['edu_dbdelta_throw']          = false;
$GLOBALS['edu_option_autoloads']       = [];
$GLOBALS['edu_is_multisite']           = false;
$GLOBALS['edu_network_active_plugins'] = [];
$GLOBALS['edu_multisite_sites']        = [ 1 ];
$GLOBALS['edu_current_blog_id']        = 1;
$GLOBALS['edu_blog_stack']             = [];
$GLOBALS['edu_blog_switches']          = [];
$GLOBALS['edu_roles_by_blog']          = [];
$GLOBALS['edu_registered_settings']    = [];
$GLOBALS['edu_block_bindings']      = [];
$GLOBALS['edu_gb_tags']             = [];
$GLOBALS['wpdb']              = new Edu_Test_WPDB();

function add_action( $tag, $callback, $priority = 10, $args = 1 ) { $GLOBALS['edu_actions'][ $tag ][] = $callback; return true; }
function do_action( $tag, ...$args ) {
	$GLOBALS['edu_fired_actions'][ $tag ][] = $args;
	foreach ( $GLOBALS['edu_actions'][ $tag ] ?? [] as $callback ) {
		$callback( ...$args );
	}
}
function register_setting( $option_group, $option_name, $args = [] ) {
	$GLOBALS['edu_registered_settings'][ (string) $option_name ] = [
		'group' => (string) $option_group,
		'args'  => is_array( $args ) ? $args : [],
	];
}
function register_block_bindings_source( string $source_name, array $source_properties ): bool {
	$GLOBALS['edu_block_bindings'][ $source_name ] = $source_properties;
	return true;
}
class GenerateBlocks_Register_Dynamic_Tag {
	public function __construct( array $args ) {
		$GLOBALS['edu_gb_tags'][ (string) ( $args['tag'] ?? '' ) ] = $args;
	}
}
function add_shortcode( $tag, $callback ) { $GLOBALS['edu_shortcodes'][ $tag ] = $callback; }
function register_activation_hook( $file, $callback ) { $GLOBALS['edu_activation'][] = $callback; }
function plugin_dir_path( $file ) { return dirname( (string) $file ) . '/'; }
function plugin_basename( $file ) { return 'wm-edu-privacy-portal/' . basename( (string) $file ); }
function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/wm-edu-privacy-portal/' . ltrim( (string) $path, '/' ); }
function load_plugin_textdomain( $domain, $deprecated = false, $path = false ) { $GLOBALS['edu_textdomains'][] = [ $domain, $path ]; return true; }
function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) { $GLOBALS['edu_styles'][ $handle ] = $src; }
function __( $text, $domain = 'default' ) { return $text; }
function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === (int) $number ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = 'default' ) { return esc_attr( $text ); }
function sanitize_text_field( $str ) {
	if ( is_array( $str ) || is_object( $str ) ) {
		return '';
	}
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) );
}
function sanitize_textarea_field( $str ) {
	if ( is_array( $str ) || is_object( $str ) ) {
		return '';
	}
	return trim( strip_tags( (string) $str ) );
}
function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function wp_salt( $scheme = 'auth' ) { return $GLOBALS['edu_salt'] . '-' . $scheme; }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time(); }
function get_option( $name, $default = false ) {
	$v = $GLOBALS['wpdb']->option_get( (string) $name );
	if ( null === $v ) {
		return $default;
	}
	// Arrays are stored serialized, as WordPress does; scalars come back as strings.
	return 1 === preg_match( '/^a:\d+:\{/', $v ) ? unserialize( $v, [ 'allowed_classes' => false ] ) : $v;
}
function update_option( $name, $value, $autoload = null ) {
	// A write the database refuses: update_option() returns false and stores nothing.
	if ( ( $GLOBALS['edu_option_write_fail'] ?? null ) === $name ) {
		return false;
	}
	$GLOBALS['edu_option_autoloads'][ (string) $name ] = $autoload;
	$autoload_col                                      = ( false === $autoload || 'no' === $autoload || 'off' === $autoload ) ? 'no' : 'yes';
	$GLOBALS['wpdb']->option_set( (string) $name, is_scalar( $value ) ? (string) $value : serialize( $value ), $autoload_col );
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( null !== $GLOBALS['wpdb']->option_get( (string) $name ) ) {
		return false;
	}
	$GLOBALS['edu_option_autoloads'][ (string) $name ] = $autoload;
	$autoload_col                                      = ( false === $autoload || 'no' === $autoload || 'off' === $autoload ) ? 'no' : 'yes';
	$GLOBALS['wpdb']->option_set( (string) $name, is_scalar( $value ) ? (string) $value : serialize( $value ), $autoload_col );
	return true;
}
function delete_option( $name ) {
	$GLOBALS['wpdb']->option_delete( (string) $name );
	unset( $GLOBALS['edu_option_autoloads'][ (string) $name ] );
	return true;
}
function wp_cache_delete( $key, $group = '' ) { $GLOBALS['edu_cache_deletes'][] = [ $key, $group ]; return true; }
function dbDelta( $queries = '', $execute = true ) {
	$GLOBALS['edu_dbdelta_calls'][] = $queries;
	if ( ! empty( $GLOBALS['edu_dbdelta_throw'] ) ) {
		throw new RuntimeException( 'simulated dbDelta failure' );
	}
	if ( ! $GLOBALS['edu_dbdelta_fail'] ) {
		foreach ( (array) $queries as $query ) {
			$GLOBALS['wpdb']->apply_create_table( (string) $query );
		}
	}
	return [];
}
function is_multisite() { return (bool) $GLOBALS['edu_is_multisite']; }
function is_plugin_active_for_network( $plugin ) { return in_array( (string) $plugin, $GLOBALS['edu_network_active_plugins'], true ); }
function get_sites( $args = [] ) { return $GLOBALS['edu_multisite_sites']; }
function switch_to_blog( $new_blog_id ) {
	$new_blog_id                                                   = (int) $new_blog_id;
	$GLOBALS['edu_roles_by_blog'][ $GLOBALS['edu_current_blog_id'] ] = $GLOBALS['edu_roles'];
	$GLOBALS['edu_blog_stack'][]                                   = [
		$GLOBALS['edu_current_blog_id'],
		$GLOBALS['wpdb']->prefix,
		$GLOBALS['wpdb']->options,
	];
	$GLOBALS['edu_blog_switches'][]   = [ 'switch', $new_blog_id ];
	$GLOBALS['edu_current_blog_id']   = $new_blog_id;
	$prefix                           = 1 === $new_blog_id ? 'wp_' : 'wp_' . $new_blog_id . '_';
	$GLOBALS['wpdb']->prefix          = $prefix;
	$GLOBALS['wpdb']->options         = $prefix . 'options';
	$GLOBALS['wpdb']->ensure_options_table();
	if ( ! isset( $GLOBALS['edu_roles_by_blog'][ $new_blog_id ] ) ) {
		$GLOBALS['edu_roles_by_blog'][ $new_blog_id ] = [
			'administrator' => new WP_Role( 'administrator', [ 'manage_options' => true, 'edit_posts' => true ] ),
			'editor'        => new WP_Role( 'editor', [ 'edit_posts' => true ] ),
			'teacher'       => new WP_Role( 'teacher', [ 'read' => true ] ),
		];
	}
	$GLOBALS['edu_roles'] = $GLOBALS['edu_roles_by_blog'][ $new_blog_id ];
	return true;
}
function restore_current_blog() {
	if ( [] === $GLOBALS['edu_blog_stack'] ) {
		return false;
	}
	$GLOBALS['edu_roles_by_blog'][ $GLOBALS['edu_current_blog_id'] ] = $GLOBALS['edu_roles'];
	[ $prev_blog, $prev_prefix, $prev_options ]                       = array_pop( $GLOBALS['edu_blog_stack'] );
	$GLOBALS['edu_blog_switches'][]                                   = [ 'restore', $prev_blog ];
	$GLOBALS['edu_current_blog_id']                                   = $prev_blog;
	$GLOBALS['wpdb']->prefix                                          = $prev_prefix;
	$GLOBALS['wpdb']->options                                         = $prev_options;
	$GLOBALS['edu_roles']                                             = $GLOBALS['edu_roles_by_blog'][ $prev_blog ];
	return true;
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['edu_routes'][ '/' . $namespace . $route ] = $args; return true; }

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Request {
	public function __construct( private array $params = [] ) {}
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
}
class WP_REST_Response {
	public function __construct( public mixed $data = null, public int $status = 200 ) {}
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}
class WP_CLI {
	public static array $out = [];
	public static function line( $m ) { self::$out[] = [ 'line', (string) $m ]; }
	public static function success( $m ) { self::$out[] = [ 'success', (string) $m ]; }
	public static function error( $m ) { throw new EduCliError( (string) $m ); }
	public static function add_command( ...$args ) {}
}

// --- Guardian flow: redirects, mail, transients, cron, filters, URLs ---------------------------------------------------

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/**
 * PHP-FPM's response closer. The suite defines it so that WM_Edu_Consent_Requests::can_close_response() is true by
 * default, as it is on the fleet's hosting, and records when it ran: a mail must leave AFTER the response is closed.
 */
function fastcgi_finish_request() { $GLOBALS['edu_response_events'][] = 'closed'; return true; }

/** wp_safe_redirect() is followed by exit in the plugin; the fake throws instead, so the case can read the target. */
final class EduRedirect extends RuntimeException {
	public function __construct( public string $location, public int $status ) {
		parent::__construct( 'redirect to ' . $location );
	}
}

$GLOBALS['edu_filters']    = [];
$GLOBALS['edu_mails']            = [];
$GLOBALS['edu_mail_ok']          = true;
$GLOBALS['edu_response_events']  = [];
$GLOBALS['edu_transients'] = [];
$GLOBALS['edu_cron']          = [];
$GLOBALS['edu_single_events'] = [];
$GLOBALS['edu_status']     = null;
$GLOBALS['edu_nocache']    = null;
$GLOBALS['edu_permalink']  = 'https://schule.example/einwilligung/';

function add_filter( $tag, $callback, $priority = 10, $args = 1 ) { $GLOBALS['edu_filters'][ $tag ][] = $callback; return true; }
function remove_filter( $tag, $callback, $priority = 10 ) {
	$GLOBALS['edu_filters'][ $tag ] = array_values( array_filter( $GLOBALS['edu_filters'][ $tag ] ?? [], static fn( $c ) => $c !== $callback ) );
	return true;
}
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['edu_filters'][ $tag ] ?? [] as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function register_deactivation_hook( $file, $callback ) { $GLOBALS['edu_deactivation'][] = $callback; }

// --- Admin screen: capabilities, nonces, menu, form helpers -----------------------------------------------------------

/** wp_die() ends the request in WordPress; the fake throws, so a case can see that a guard stopped the handler. */
final class EduDie extends RuntimeException {}

$GLOBALS['edu_caps']       = null;  // null: every capability granted (an administrator).
$GLOBALS['edu_nonce_ok']   = true;
$GLOBALS['edu_user_id']    = 7;
$GLOBALS['edu_menu']       = [];
$GLOBALS['edu_logged_in']  = true;
$GLOBALS['edu_user_roles'] = [ 'administrator' ];

function current_user_can( $capability, ...$args ) { return null === $GLOBALS['edu_caps'] ? true : (bool) ( $GLOBALS['edu_caps'][ $capability ] ?? false ); }
function is_user_logged_in() { return (bool) $GLOBALS['edu_logged_in']; }
function get_current_user_id() { return (int) $GLOBALS['edu_user_id']; }
/** The suite loads the plugin outside wp-admin; the admin class is required by the suite itself. */
function is_admin() { return false; }
function wp_get_current_user() { return (object) [ 'ID' => (int) $GLOBALS['edu_user_id'], 'roles' => $GLOBALS['edu_user_roles'] ]; }
function wp_die( $message = '', $title = '', $args = [] ) { throw new EduDie( is_string( $message ) ? $message : 'wp_die' ); }
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	if ( ! $GLOBALS['edu_nonce_ok'] ) {
		wp_die( 'Die Sicherheitsprüfung ist fehlgeschlagen.' );
	}
	return 1;
}
function wp_verify_nonce( $nonce, $action = -1 ) { return $GLOBALS['edu_nonce_ok'] ? 1 : false; }
function wp_create_nonce( $action = -1 ) { return 'nonce-' . md5( (string) $action ); }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$field = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce( $action ) . '">';
	if ( $display ) {
		echo $field; // phpcs:ignore
	}
	return $field;
}
function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) { return add_query_arg( $name, wp_create_nonce( $action ), $url ); }
function admin_url( $path = '' ) { return 'https://schule.example/wp-admin/' . ltrim( (string) $path, '/' ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function checked( $checked, $current = true, $display = true ) { return $checked === $current || ( true === $current && (bool) $checked ) ? ' checked' : ''; }
function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button type="submit" class="button button-' . $type . '">' . esc_html( (string) ( $text ?? 'Speichern' ) ) . '</button>'; }
function add_menu_page( $page_title, $menu_title, $capability, $slug, $callback = '', $icon = '', $position = null ) { $GLOBALS['edu_menu'][] = [ 'menu', $slug, $capability, $callback ]; return $slug; }
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback = '' ) { $GLOBALS['edu_menu'][] = [ 'submenu', $slug, $capability, $callback ]; return $slug; }
function sanitize_key( $key ) { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function add_query_arg( $key, $value = null, $url = null ) {
	$pairs = is_array( $key ) ? $key : [ $key => $value ];
	$url   = is_array( $key ) ? (string) $value : (string) $url;
	foreach ( $pairs as $name => $item ) {
		$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . $name . '=' . rawurlencode( (string) $item );
	}
	return $url;
}
function remove_query_arg( $keys, $url ) {
	$parts = explode( '?', (string) $url, 2 );
	if ( ! isset( $parts[1] ) ) {
		return $parts[0];
	}
	parse_str( $parts[1], $query );
	foreach ( (array) $keys as $key ) {
		unset( $query[ $key ] );
	}
	return $parts[0] . ( [] === $query ? '' : '?' . http_build_query( $query ) );
}
function get_queried_object_id() { return 42; }
function get_permalink( $post = 0 ) { return $GLOBALS['edu_permalink']; }
function home_url( $path = '' ) { return 'https://schule.example' . $path; }
function wp_safe_redirect( $location, $status = 302 ) { throw new EduRedirect( (string) $location, (int) $status ); }
function status_header( $code ) { $GLOBALS['edu_status'] = (int) $code; }
function nocache_headers() { $GLOBALS['edu_nocache'] = apply_filters( 'nocache_headers', [ 'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private' ] ); }
function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
	$GLOBALS['edu_mails'][]           = compact( 'to', 'subject', 'message', 'headers' );
	$GLOBALS['edu_response_events'][] = 'mail';
	return $GLOBALS['edu_mail_ok'];
}
function get_bloginfo( $show = '' ) { return 'Grundschule am Park &amp; Hort'; }
function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $text, $quote_style ); }
function wp_date( $format, $timestamp = null ) { return gmdate( $format, $timestamp ?? time() ); }
function get_transient( $key ) {
	$entry = $GLOBALS['edu_transients'][ $key ] ?? null;
	return null === $entry ? false : $entry[0];
}
function set_transient( $key, $value, $expiration = 0 ) { $GLOBALS['edu_transients'][ $key ] = [ $value, $expiration ]; return true; }
function delete_transient( $key ) { unset( $GLOBALS['edu_transients'][ $key ] ); return true; }
function wp_next_scheduled( $hook ) { return $GLOBALS['edu_cron'][ $hook ]['timestamp'] ?? false; }
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [] ) { $GLOBALS['edu_cron'][ $hook ] = compact( 'timestamp', 'recurrence' ); $GLOBALS['edu_cron_writes'][] = $hook; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['edu_cron'][ $hook ] ); return 1; }
function wp_schedule_single_event( $timestamp, $hook, $args = [] ) { $GLOBALS['edu_single_events'][] = [ 'hook' => $hook, 'timestamp' => $timestamp, 'args' => $args ]; return true; }

class WP_Role {
	/** @param array<string, bool> $capabilities */
	public function __construct( public string $name, public array $capabilities = [] ) {}
	public function has_cap( $cap ) { return ! empty( $this->capabilities[ $cap ] ); }
	public function add_cap( $cap, $grant = true ) { $this->capabilities[ $cap ] = (bool) $grant; $GLOBALS['edu_role_writes'][] = [ 'add', $this->name, $cap ]; }
	public function remove_cap( $cap ) { unset( $this->capabilities[ $cap ] ); $GLOBALS['edu_role_writes'][] = [ 'remove', $this->name, $cap ]; }
}

/** Roles of the fake site: an administrator without the plugin capability yet, and an editor. */
function edu_reset_roles(): void {
	$GLOBALS['edu_roles']         = [
		'administrator' => new WP_Role( 'administrator', [ 'manage_options' => true, 'edit_posts' => true ] ),
		'editor'        => new WP_Role( 'editor', [ 'edit_posts' => true ] ),
		'teacher'       => new WP_Role( 'teacher', [ 'read' => true ] ),
	];
	$GLOBALS['edu_role_writes']   = [];
	$GLOBALS['edu_roles_by_blog'] = [];
}
edu_reset_roles();
function get_role( $role ) { return $GLOBALS['edu_roles'][ $role ] ?? null; }
function wp_roles() { return (object) [ 'role_objects' => $GLOBALS['edu_roles'] ]; }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = [];
	foreach ( $pairs as $key => $default ) {
		$out[ $key ] = array_key_exists( $key, $atts ) ? $atts[ $key ] : $default;
	}
	return $out;
}
function wp_login_url( $redirect = '' ) { return 'https://schule.example/wp-login.php?redirect_to=' . rawurlencode( (string) $redirect ); }
function is_email( $email ) { return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? (string) $email : false; }
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text ) );
	return $remove_breaks ? trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) ) : trim( $text );
}

require_once __DIR__ . '/fixtures/hub-interface-stub.php';
require_once dirname( __DIR__ ) . '/wm-edu-privacy-portal.php';
require_once dirname( __DIR__ ) . '/includes/class-edu-cli.php';
require_once dirname( __DIR__ ) . '/includes/class-edu-admin.php';
// The main file registers the admin screen only inside wp-admin; the suite registers it itself.
WM_Edu_Admin::init();
require_once dirname( __DIR__ ) . '/includes/class-edu-spoke-adapter.php';

// ---------------------------------------------------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------------------------------------------------

$GLOBALS['edu_passed'] = 0;
$GLOBALS['edu_failed'] = 0;

function assert_test( string $name, bool $condition, string $details = '' ): void {
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		++$GLOBALS['edu_passed'];
	} else {
		echo "  [FAIL] {$name}" . ( '' !== $details ? ': ' . $details : '' ) . "\n";
		++$GLOBALS['edu_failed'];
	}
}

/** One case per closure: an exception, warning or notice is a failed case, not a dead suite. */
function edu_case( string $name, callable $fn ): void {
	set_error_handler(
		static function ( int $no, string $msg, string $file, int $line ): bool {
			throw new ErrorException( $msg, 0, $no, $file, $line );
		}
	);
	try {
		$fn( $name );
	} catch ( \Throwable $t ) {
		assert_test( $name, false, get_class( $t ) . ': ' . $t->getMessage() . ' @ ' . basename( $t->getFile() ) . ':' . $t->getLine() );
	} finally {
		restore_error_handler();
		$_POST = [];
	}
}

/** A database with the consent table created by install(), as on an activated site. */
function edu_fresh_db(): Edu_Test_WPDB {
	$db                                    = new Edu_Test_WPDB();
	$GLOBALS['wpdb']                       = $db;
	$GLOBALS['edu_dbdelta_calls']          = [];
	$GLOBALS['edu_dbdelta_fail']           = false;
	$GLOBALS['edu_dbdelta_throw']          = false;
	$GLOBALS['edu_option_autoloads']       = [];
	$GLOBALS['edu_is_multisite']           = false;
	$GLOBALS['edu_network_active_plugins'] = [];
	$GLOBALS['edu_multisite_sites']        = [ 1 ];
	$GLOBALS['edu_current_blog_id']        = 1;
	$GLOBALS['edu_blog_stack']             = [];
	$GLOBALS['edu_blog_switches']          = [];
	$GLOBALS['edu_roles_by_blog']          = [];
	WM_Edu_Consent_Vault::install();
	return $db;
}

function edu_table(): string {
	return $GLOBALS['wpdb']->prefix . 'wm_edu_parent_consents';
}

/** @param array<string, mixed> $overrides */
function edu_consent( array $overrides = [] ): int|false {
	return WM_Edu_Consent_Vault::record_consent(
		$overrides + [
			'student_token'  => 'EDU-001',
			'parent_email'   => 'eltern@beispiel.de',
			'consent_type'   => 'EXCURSION_BERLIN_2026',
			'scope_hash'     => hash( 'sha256', 'Schulausflug Berlin' ),
			'granted_status' => 'GRANTED',
			'ip_address'     => '203.0.113.9',
		]
	);
}

function edu_count(): int {
	return (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM `' . edu_table() . '`' );
}

/** @return array<string, string>|null */
function edu_row( int $id ): ?array {
	return $GLOBALS['wpdb']->get_row( 'SELECT * FROM `' . edu_table() . '` WHERE id = ' . $id, ARRAY_A );
}

function edu_luminance( string $hex ): float {
	$hex = ltrim( trim( $hex ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	$c = array_map(
		static function ( string $pair ): float {
			$v = hexdec( $pair ) / 255;
			return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
		},
		str_split( $hex, 2 )
	);
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function edu_contrast( string $a, string $b ): float {
	$la = edu_luminance( $a );
	$lb = edu_luminance( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

/** Text of a document without quoted passages and history lines, where earlier claims are cited on purpose. */
function edu_doc_text( string $path ): string {
	$text = (string) file_get_contents( $path );
	$text = (string) preg_replace( '/"[^"\n]*"|„[^“\n]*“|“[^”\n]*”|«[^»\n]*»/u', '', $text );
	$keep = [];
	foreach ( preg_split( '/\R/', $text ) as $line ) {
		if ( preg_match( '/\b(Until|Bis zum|Hasta el)\b/u', $line ) ) {
			continue;
		}
		$keep[] = $line;
	}
	return implode( "\n", $keep );
}

$root  = dirname( __DIR__ );
$emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B50}]/u';

echo "==============================================================================\n";
echo "  WM EDU PRIVACY PORTAL — STANDALONE SUITE (SQLite-backed \$wpdb)\n";
echo "==============================================================================\n\n";

// =====================================================================================================================
echo "[GROUP 1] Tokenizer: names, dates, class identifiers, reversal\n";
// =====================================================================================================================

edu_case( 'an assigned token replaces the name once and detokenize() puts it back', function ( string $name ) {
	$res      = WM_Edu_PII_Tokenizer::tokenize( 'Schüler Max Mustermann hat in der Klausur sehr gute Fortschritte gemacht.', [ 'Max Mustermann' => '[[STUDENT_TOKEN_MAX]]' ] );
	$restored = WM_Edu_PII_Tokenizer::detokenize( 'Für [[STUDENT_TOKEN_MAX]] empfehle ich Geometrie.', $res['token_map'] );
	assert_test( $name, ! str_contains( $res['sanitized_text'], 'Max Mustermann' ) && str_contains( $res['sanitized_text'], '[[STUDENT_TOKEN_MAX]]' ) && 1 === $res['detections'] && 'Für Max Mustermann empfehle ich Geometrie.' === $restored, var_export( $res, true ) );
} );

edu_case( 'both German date formats and a class identifier are replaced', function ( string $name ) {
	$a = WM_Edu_PII_Tokenizer::tokenize( 'Geboren am 14.04.2012 in Halle.' );
	$b = WM_Edu_PII_Tokenizer::tokenize( 'Geburtsdatum: 15. März 2011.' );
	$c = WM_Edu_PII_Tokenizer::tokenize( 'Schüler der Klasse 9b nehmen teil.' );
	assert_test( $name, str_contains( $a['sanitized_text'], '[[DOB_' ) && ! str_contains( $a['sanitized_text'], '14.04.2012' ) && ! str_contains( $b['sanitized_text'], '15. März 2011' ) && str_contains( $c['sanitized_text'], '[[CLASS_' ) && ! str_contains( $c['sanitized_text'], 'Klasse 9b' ), var_export( [ $a, $b, $c ], true ) );
} );

// =====================================================================================================================
echo "\n[GROUP 2] Tokenizer defects of 2026-09-15\n";
// =====================================================================================================================

edu_case( 'overlapping names: "Max" does not split "Max Müller"; no surname stays in clear (was: "[[STUDENT_…]] Müller")', function ( string $name ) {
	$res = WM_Edu_PII_Tokenizer::tokenize( 'Max Müller und Max Meier', [ 'Max' => '', 'Max Müller' => '' ] );
	assert_test( $name, ! str_contains( $res['sanitized_text'], 'Müller' ) && 2 === $res['detections'] && 2 === count( $res['token_map'] ) && ' Meier' === substr( $res['sanitized_text'], -6 ), var_export( $res, true ) );
} );

edu_case( 'tokens with $0 and \\1 are put in literally and restored (was: the name came back through $0)', function ( string $name ) {
	$res  = WM_Edu_PII_Tokenizer::tokenize( 'Max Müller fehlt, Anna Kurz auch.', [ 'Max Müller' => 'PUPIL_$0', 'Anna Kurz' => '[[S\\1]]' ] );
	$back = WM_Edu_PII_Tokenizer::detokenize( $res['sanitized_text'], $res['token_map'] );
	assert_test( $name, 'PUPIL_$0 fehlt, [[S\\1]] auch.' === $res['sanitized_text'] && 'Max Müller fehlt, Anna Kurz auch.' === $back, var_export( [ $res, $back ], true ) );
} );

edu_case( 'a shorter name does not match inside a token that was already put in', function ( string $name ) {
	$res = WM_Edu_PII_Tokenizer::tokenize( 'Anna und Ben', [ 'Anna' => 'KIND-Ben', 'Ben' => '' ] );
	assert_test( $name, str_starts_with( $res['sanitized_text'], 'KIND-Ben und [[STUDENT_' ) && 2 === $res['detections'], var_export( $res, true ) );
} );

edu_case( 'detokenize() with tokens S1 and S10 restores both (was: "Anna0")', function ( string $name ) {
	$out = WM_Edu_PII_Tokenizer::detokenize( 'Antwort für S10 und S1', [ 'S1' => 'Anna', 'S10' => 'Ben' ] );
	assert_test( $name, 'Antwort für Ben und Anna' === $out, $out );
} );

edu_case( 'a number as token gives a generated token; non-string map entries are ignored; no TypeError', function ( string $name ) {
	$res = WM_Edu_PII_Tokenizer::tokenize( 'Max fehlt', [ 'Max' => 7 ] );
	$out = WM_Edu_PII_Tokenizer::detokenize( 'Hallo [[X]] a', [ '[[X]]' => 5, 'a' ] );
	assert_test( $name, str_starts_with( $res['sanitized_text'], '[[STUDENT_' ) && 'Hallo [[X]] a' === $out, var_export( [ $res, $out ], true ) );
} );

edu_case( 'text that is not valid UTF-8 throws instead of returning a half-replaced text', function ( string $name ) {
	$thrown = false;
	try {
		WM_Edu_PII_Tokenizer::tokenize( "Klasse 9b \xff geboren am 15.03.2012" );
	} catch ( RuntimeException $e ) {
		$thrown = true;
	}
	assert_test( $name, $thrown );
} );

// =====================================================================================================================
echo "\n[GROUP 3] Keys come from wp_salt(), one per purpose\n";
// =====================================================================================================================

edu_case( 'the tokenizer key is derived from wp_salt(), not the raw AUTH_KEY (here the sample phrase)', function ( string $name ) {
	$text     = WM_Edu_PII_Tokenizer::tokenize( 'geboren am 15.03.2012' )['sanitized_text'];
	$derived  = 'geboren am [[DOB_' . strtoupper( substr( hash_hmac( 'sha256', '15.03.2012', WM_Edu_Keys::derive( WM_Edu_Keys::TOKENIZER ) ), 0, 8 ) ) . ']]';
	$outsider = 'geboren am [[DOB_' . strtoupper( substr( hash_hmac( 'sha256', '15.03.2012', 'put your unique phrase here' ), 0, 8 ) ) . ']]';
	assert_test( $name, $derived === $text && $outsider !== $text, $text );
} );

edu_case( 'derived keys differ per purpose and follow the site salt', function ( string $name ) {
	$sig = WM_Edu_Keys::derive( WM_Edu_Keys::CONSENT_SIGNATURE );
	$tok = WM_Edu_Keys::derive( WM_Edu_Keys::TOKENIZER );
	$GLOBALS['edu_salt'] = 'site-salt-B';
	$rotated             = WM_Edu_Keys::derive( WM_Edu_Keys::TOKENIZER );
	$GLOBALS['edu_salt'] = 'site-salt-A';
	assert_test( $name, $sig !== $tok && $rotated !== $tok && 64 === strlen( $sig ) && 16 === strlen( WM_Edu_Keys::signature_key_id() ) );
} );

// =====================================================================================================================
echo "\n[GROUP 4] record_consent() refuses instead of guessing\n";
// =====================================================================================================================

edu_case( 'a missing, lower-case, unknown or non-string status is refused and nothing is stored (was: stored as GRANTED)', function ( string $name ) {
	edu_fresh_db();
	$results = [];
	foreach ( [ null, 'revoked', 'WITHDRAWN', [ 'GRANTED' ] ] as $status ) {
		$data = [ 'student_token' => 'EDU-X', 'parent_email' => 'p@example.test', 'consent_type' => 'T', 'scope_hash' => str_repeat( 'a', 64 ), 'ip_address' => '203.0.113.1' ];
		if ( null !== $status ) {
			$data['granted_status'] = $status;
		}
		$results[] = WM_Edu_Consent_Vault::record_consent( $data );
	}
	assert_test( $name, [ false, false, false, false ] === $results && 0 === edu_count(), var_export( $results, true ) );
} );

edu_case( 'a missing or invalid IP, type, scope hash or e-mail, or a token over 64 characters, is refused (was: 127.0.0.1, EXCURSION, fixed scope)', function ( string $name ) {
	edu_fresh_db();
	$results = [
		edu_consent( [ 'ip_address' => null ] ),
		edu_consent( [ 'ip_address' => '999.1.1.1' ] ),
		edu_consent( [ 'consent_type' => '' ] ),
		edu_consent( [ 'scope_hash' => 'general_consent' ] ),
		edu_consent( [ 'parent_email' => 'keine-adresse' ] ),
		edu_consent( [ 'student_token' => str_repeat( 'T', 65 ) ] ),
	];
	assert_test( $name, array_fill( 0, 6, false ) === $results && 0 === edu_count(), var_export( $results, true ) );
} );

edu_case( 'a valid consent stores the key fingerprint, the IP blinded with wp_salt() and a schema-2 signature', function ( string $name ) {
	edu_fresh_db();
	$id  = edu_consent();
	$row = is_int( $id ) ? edu_row( $id ) : null;
	assert_test(
		$name,
		1 === $id && $row && WM_Edu_Keys::signature_key_id() === $row['key_id'] && hash_hmac( 'sha256', '203.0.113.9', wp_salt( 'auth' ) ) === $row['ip_hmac']
		&& WM_Edu_Consent_Vault::row_signature( $row ) === $row['signature_hash'] && WM_Edu_Consent_Vault::GENESIS_HASH === $row['prev_hash']
		&& true === WM_Edu_Consent_Vault::verify_chain()['valid'],
		var_export( $row, true )
	);
} );

edu_case( 'granted_status has no default: an insert without a status fails (was: DEFAULT GRANTED)', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	$sql      = WM_Edu_Consent_Vault::table_sql( 'wp_x', '' );
	$inserted = $wpdb->insert( edu_table(), [ 'student_token' => 'EDU-RAW', 'parent_email' => 'p@example.test', 'consent_type' => 'T', 'scope_hash' => str_repeat( 'a', 64 ), 'ip_hmac' => 'x', 'prev_hash' => 'x', 'signature_hash' => 'x' ] );
	assert_test( $name, str_contains( $sql, 'granted_status VARCHAR(20) NOT NULL,' ) && ! str_contains( $sql, "DEFAULT 'GRANTED'" ) && false === $inserted && 0 === edu_count(), $sql );
} );

edu_case( 'the signature covers the blinded IP (schema 1 did not): an edited ip_hmac breaks the chain', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	edu_consent();
	$wpdb->update( edu_table(), [ 'ip_hmac' => str_repeat( 'b', 64 ) ], [ 'id' => 1 ] );
	$chain = WM_Edu_Consent_Vault::verify_chain();
	assert_test( $name, false === $chain['valid'] && 1 === $chain['broken_at_id'], var_export( $chain, true ) );
} );

edu_case( 'a "|" inside a field cannot move the boundary between two signed fields', function ( string $name ) {
	$base = [ 'key_id' => 'k', 'consent_type' => 'T', 'scope_hash' => 's', 'granted_status' => 'GRANTED', 'ip_hmac' => 'i', 'created_at' => 'c', 'prev_hash' => 'p' ];
	$a    = WM_Edu_Consent_Vault::row_signature( [ 'student_token' => 'A|B', 'parent_email' => 'c@d.de' ] + $base );
	$b    = WM_Edu_Consent_Vault::row_signature( [ 'student_token' => 'A', 'parent_email' => 'B|c@d.de' ] + $base );
	assert_test( $name, $a !== $b );
} );

// =====================================================================================================================
echo "\n[GROUP 5] Chain verification and the consent lookup\n";
// =====================================================================================================================

edu_case( 'two rows verify; an edited status is found at its id and rows stays the row count', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	edu_consent();
	edu_consent( [ 'student_token' => 'EDU-002' ] );
	$ok = WM_Edu_Consent_Vault::verify_chain();
	$wpdb->update( edu_table(), [ 'granted_status' => 'REVOKED' ], [ 'id' => 1 ] );
	$bad = WM_Edu_Consent_Vault::verify_chain();
	assert_test( $name, true === $ok['valid'] && 2 === $ok['checked'] && 2 === $ok['rows'] && false === $bad['valid'] && 1 === $bad['broken_at_id'] && 2 === $bad['rows'] && 0 === $bad['checked'], var_export( [ $ok, $bad ], true ) );
} );

edu_case( 'a row that does not verify makes the whole register unverifiable for the gate (was: honoured)', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	edu_consent();
	$wpdb->insert( edu_table(), [ 'student_token' => 'EDU-UNSIGNED', 'parent_email' => 'p@example.test', 'consent_type' => 'X', 'scope_hash' => str_repeat( '0', 64 ), 'granted_status' => 'GRANTED', 'ip_hmac' => str_repeat( '0', 64 ), 'key_id' => WM_Edu_Keys::signature_key_id(), 'prev_hash' => WM_Edu_Consent_Vault::current_chain_tip(), 'signature_hash' => 'not-a-signature', 'created_at' => '2026-01-01 00:00:00' ] );
	assert_test(
		$name,
		'unverifiable' === WM_Edu_Consent_Vault::consent_state( 'EDU-UNSIGNED' ) && false === WM_Edu_Consent_Vault::has_valid_consent( 'EDU-001' ) && false === WM_Edu_Consent_Vault::verify_chain()['valid'],
		var_export( WM_Edu_Consent_Vault::verify_chain(), true )
	);
} );

edu_case( 'without a table: not valid, missing_table, rows null; nothing recorded; gate unverifiable (was: valid, 0 rows)', function ( string $name ) {
	$GLOBALS['wpdb'] = new Edu_Test_WPDB();
	update_option( WM_Edu_Consent_Vault::SCHEMA_OPTION, '2' );
	$chain = WM_Edu_Consent_Vault::verify_chain();
	assert_test(
		$name,
		false === $chain['valid'] && true === $chain['missing_table'] && null === $chain['rows'] && false === edu_consent() && 'unverifiable' === WM_Edu_Consent_Vault::consent_state( 'EDU-001' ),
		var_export( $chain, true )
	);
} );

edu_case( 'a failed read of the table is not a valid chain', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	edu_consent();
	$wpdb->fail_select = '/^SELECT \* FROM/';
	$chain             = WM_Edu_Consent_Vault::verify_chain();
	assert_test( $name, false === $chain['valid'] && false === $chain['missing_table'] && str_contains( (string) $chain['error'], 'gelesen' ), var_export( $chain, true ) );
} );

edu_case( 'a changed site salt is reported as a changed key, the gate shows nothing and health is ERROR', function ( string $name ) {
	edu_fresh_db();
	edu_consent();
	edu_consent( [ 'student_token' => 'EDU-002' ] );
	$GLOBALS['edu_salt'] = 'site-salt-ROTATED';
	$chain               = WM_Edu_Consent_Vault::verify_chain();
	$state               = WM_Edu_Consent_Vault::consent_state( 'EDU-001' );
	$health              = ( new WM_Edu_Spoke_Adapter() )->get_health_status();
	$GLOBALS['edu_salt'] = 'site-salt-A';
	assert_test( $name, false === $chain['valid'] && true === $chain['key_changed'] && 1 === $chain['broken_at_id'] && str_contains( (string) $chain['error'], 'Schlüsselkennung' ) && 'unverifiable' === $state && 'ERROR' === $health, var_export( $chain, true ) );
} );

edu_case( 'schema-1 rows verify before and after the upgrade; the upgrade adds key_id, drops the GRANTED default and records the boundary', function ( string $name ) {
	global $wpdb;
	$GLOBALS['wpdb']              = new Edu_Test_WPDB();
	$GLOBALS['edu_dbdelta_calls'] = [];
	$wpdb                         = $GLOBALS['wpdb'];
	$v1                           = "CREATE TABLE wp_wm_edu_parent_consents (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			student_token VARCHAR(64) NOT NULL,
			parent_email VARCHAR(190) NOT NULL,
			consent_type VARCHAR(50) NOT NULL,
			scope_hash VARCHAR(64) NOT NULL,
			granted_status VARCHAR(20) NOT NULL DEFAULT 'GRANTED',
			ip_hmac VARCHAR(64) NOT NULL,
			prev_hash VARCHAR(64) NOT NULL,
			signature_hash VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_student (student_token)
		) ;";
	$wpdb->apply_create_table( $v1 );
	$prev = WM_Edu_Consent_Vault::GENESIS_HASH;
	foreach ( [ 'EDU-OLD-1', 'EDU-OLD-2' ] as $token ) {
		$sig = WM_Edu_Consent_Vault::generate_signature( $token, 'p@example.test', 'T', str_repeat( 'a', 64 ), 'GRANTED', '2026-01-01 00:00:00', $prev );
		$wpdb->insert( edu_table(), [ 'student_token' => $token, 'parent_email' => 'p@example.test', 'consent_type' => 'T', 'scope_hash' => str_repeat( 'a', 64 ), 'granted_status' => 'GRANTED', 'ip_hmac' => 'x', 'prev_hash' => $prev, 'signature_hash' => $sig, 'created_at' => '2026-01-01 00:00:00' ] );
		$prev = $sig;
	}
	$before = WM_Edu_Consent_Vault::verify_chain();
	WM_Edu_Consent_Vault::maybe_upgrade();
	$after = WM_Edu_Consent_Vault::verify_chain();
	$new   = edu_consent( [ 'student_token' => 'EDU-NEW' ] );
	$mixed = WM_Edu_Consent_Vault::verify_chain();
	assert_test(
		$name,
		true === $before['valid'] && 2 === $before['legacy_rows'] && in_array( 'key_id', $wpdb->columns( edu_table() ), true )
		&& 1 === count( array_filter( $wpdb->ddl, static fn( $q ) => str_contains( $q, 'ALTER COLUMN granted_status DROP DEFAULT' ) ) )
		&& '2' === get_option( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION ) && '3' === get_option( WM_Edu_Consent_Vault::SCHEMA_OPTION )
		&& true === $after['valid'] && 2 === $after['legacy_rows'] && 3 === $new && true === $mixed['valid'] && 3 === $mixed['checked'] && 2 === $mixed['legacy_rows'],
		var_export( [ $before, $after, $mixed, $wpdb->ddl ], true )
	);
} );

edu_case( 'a schema-1 style row written after the boundary does not verify, even with a correct schema-1 signature', function ( string $name ) {
	global $wpdb;
	$tip = WM_Edu_Consent_Vault::current_chain_tip();
	$sig = WM_Edu_Consent_Vault::generate_signature( 'EDU-FORGED', 'p@example.test', 'T', str_repeat( 'a', 64 ), 'GRANTED', '2026-09-15 00:00:00', $tip );
	$wpdb->insert( edu_table(), [ 'student_token' => 'EDU-FORGED', 'parent_email' => 'p@example.test', 'consent_type' => 'T', 'scope_hash' => str_repeat( 'a', 64 ), 'granted_status' => 'GRANTED', 'ip_hmac' => 'x', 'key_id' => '', 'prev_hash' => $tip, 'signature_hash' => $sig, 'created_at' => '2026-09-15 00:00:00' ] );
	$chain = WM_Edu_Consent_Vault::verify_chain();
	assert_test( $name, false === $chain['valid'] && 4 === $chain['broken_at_id'] && str_contains( (string) $chain['error'], 'keine Schlüsselkennung' ), var_export( $chain, true ) );
} );

edu_case( 'the gate: a later REVOKED wins, a type narrows, and only the exact token matches (MySQL collation would match edu-003)', function ( string $name ) {
	edu_fresh_db();
	edu_consent( [ 'consent_type' => 'A' ] );
	edu_consent( [ 'consent_type' => 'B', 'granted_status' => 'REVOKED' ] );
	edu_consent( [ 'student_token' => 'EDU-003' ] );
	$states = [
		WM_Edu_Consent_Vault::consent_state( 'EDU-001' ),
		WM_Edu_Consent_Vault::consent_state( 'EDU-001', 'A' ),
		WM_Edu_Consent_Vault::consent_state( 'EDU-001', 'a' ),
		WM_Edu_Consent_Vault::consent_state( 'EDU-003' ),
		WM_Edu_Consent_Vault::consent_state( 'edu-003' ),
		WM_Edu_Consent_Vault::consent_state( 'EDU-999' ),
		WM_Edu_Consent_Vault::consent_state( '   ' ),
	];
	assert_test( $name, [ 'none', 'granted', 'none', 'granted', 'none', 'none', 'none' ] === $states, implode( ', ', $states ) );
} );

// =====================================================================================================================
echo "\n[GROUP 6] install() and maybe_upgrade()\n";
// =====================================================================================================================

edu_case( 'a fresh install creates the table and records schema 3 with boundary 0; a second load runs no dbDelta', function ( string $name ) {
	$db = edu_fresh_db();
	WM_Edu_Consent_Vault::maybe_upgrade();
	assert_test( $name, $db->has_table( edu_table() ) && '3' === get_option( WM_Edu_Consent_Vault::SCHEMA_OPTION ) && '0' === get_option( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION ) && 1 === count( $GLOBALS['edu_dbdelta_calls'] ) && [] === $db->ddl, var_export( [ $GLOBALS['edu_dbdelta_calls'], $db->ddl ], true ) );
} );

edu_case( 'a repeated install keeps the boundary an earlier upgrade recorded', function ( string $name ) {
	edu_fresh_db();
	update_option( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION, '1' );
	edu_consent();
	edu_consent( [ 'student_token' => 'EDU-002' ] );
	delete_option( WM_Edu_Consent_Vault::SCHEMA_OPTION );
	WM_Edu_Consent_Vault::maybe_upgrade();
	assert_test( $name, '1' === get_option( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION ) && '3' === get_option( WM_Edu_Consent_Vault::SCHEMA_OPTION ) );
} );

edu_case( 'when dbDelta() does not create the table, no schema version is recorded', function ( string $name ) {
	$GLOBALS['wpdb']              = new Edu_Test_WPDB();
	$GLOBALS['edu_dbdelta_fail']  = true;
	WM_Edu_Consent_Vault::install();
	$GLOBALS['edu_dbdelta_fail'] = false;
	assert_test( $name, false === get_option( WM_Edu_Consent_Vault::SCHEMA_OPTION ) && false === get_option( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION ) );
} );

// =====================================================================================================================
echo "\n[GROUP 7] REST routes\n";
// =====================================================================================================================

edu_case( 'both routes declare their arguments: text required and checked, the maps checked', function ( string $name ) {
	$GLOBALS['edu_routes'] = [];
	WM_Edu_REST_Controller::register_routes();
	$t = $GLOBALS['edu_routes']['/wm-edu/v1/tokenize']['args'] ?? [];
	$d = $GLOBALS['edu_routes']['/wm-edu/v1/detokenize']['args'] ?? [];
	assert_test(
		$name,
		true === ( $t['text']['required'] ?? false ) && 'string' === ( $t['text']['type'] ?? '' ) && is_callable( $t['text']['validate_callback'] ?? null ) && ! isset( $t['text']['sanitize_callback'] )
		&& is_callable( $t['known_students']['validate_callback'] ?? null ) && 'object' === ( $t['known_students']['type'] ?? '' )
		&& true === ( $d['text']['required'] ?? false ) && is_callable( $d['token_map']['validate_callback'] ?? null ),
		var_export( [ $t, $d ], true )
	);
} );

edu_case( 'validators: a list, a number, a string map value or invalid UTF-8 answer 400; an object of strings and {} pass', function ( string $name ) {
	$map  = static fn( $v ) => WM_Edu_REST_Controller::validate_string_map( $v );
	$bad  = [ $map( [ 'Max Müller' ] ), $map( [ 'Max' => 7 ] ), $map( 'Max' ), $map( [ "M\xffax" => '' ] ), WM_Edu_REST_Controller::validate_text( 5 ), WM_Edu_REST_Controller::validate_text( "a\xffb" ) ];
	$ok   = [ $map( [ 'Max Müller' => '' ] ), $map( [] ), WM_Edu_REST_Controller::validate_text( "Zeile 1\nZeile <3" ) ];
	$codes = array_map( static fn( $r ) => $r instanceof WP_Error ? $r->get_error_code() . '/' . ( $r->get_error_data()['status'] ?? '' ) : 'ok', $bad );
	assert_test( $name, array_fill( 0, 6, 'rest_invalid_param/400' ) === $codes && [ true, true, true ] === $ok, implode( ', ', $codes ) );
} );

edu_case( 'the handlers check again: a list of names or a number as token answers 400 (was: fatal TypeError, HTTP 500)', function ( string $name ) {
	$a = WM_Edu_REST_Controller::handle_tokenize( new WP_REST_Request( [ 'text' => 'Max Müller aus Klasse 9b', 'known_students' => [ 'Max Müller' ] ] ) );
	$b = WM_Edu_REST_Controller::handle_tokenize( new WP_REST_Request( [ 'text' => 'Max', 'known_students' => [ 'Max' => 7 ] ] ) );
	$c = WM_Edu_REST_Controller::handle_detokenize( new WP_REST_Request( [ 'text' => 'Hallo [[X]]', 'token_map' => [ '[[X]]' => 5 ] ] ) );
	$e = WM_Edu_REST_Controller::handle_tokenize( new WP_REST_Request( [] ) );
	assert_test( $name, is_wp_error( $a ) && is_wp_error( $b ) && is_wp_error( $c ) && is_wp_error( $e ) && 400 === ( $a->get_error_data()['status'] ?? 0 ), var_export( [ $a, $b, $c ], true ) );
} );

edu_case( 'line breaks, "<3" and "80%ad" survive the route; only dates, classes and names change (was: sanitize_text_field)', function ( string $name ) {
	$text = "Schülerin geboren am 15.03.2012, Klasse 9b.\nAufgabe 1: Punkte <3 von 10\nAufgabe 2: Quote 80%ad erreicht";
	$res  = WM_Edu_REST_Controller::handle_tokenize( new WP_REST_Request( [ 'text' => $text ] ) );
	$data = $res instanceof WP_REST_Response ? $res->get_data()['data'] : [];
	assert_test( $name, $res instanceof WP_REST_Response && WM_Edu_PII_Tokenizer::tokenize( $text ) === $data && 2 === substr_count( $data['sanitized_text'], "\n" ) && str_contains( $data['sanitized_text'], 'Punkte <3 von 10' ) && str_contains( $data['sanitized_text'], '80%ad' ), var_export( $data, true ) );
} );

edu_case( 'detokenize route with S1 and S10 restores both', function ( string $name ) {
	$res = WM_Edu_REST_Controller::handle_detokenize( new WP_REST_Request( [ 'text' => "Antwort für S10\nund S1", 'token_map' => [ 'S1' => 'Anna', 'S10' => 'Ben' ] ] ) );
	assert_test( $name, $res instanceof WP_REST_Response && "Antwort für Ben\nund Anna" === $res->get_data()['data']['restored_text'], var_export( $res, true ) );
} );

// =====================================================================================================================
echo "\n[GROUP 8] Shortcodes\n";
// =====================================================================================================================

edu_case( "both shortcodes accept '' as attributes (WordPress before 6.5; was: fatal TypeError)", function ( string $name ) {
	edu_fresh_db();
	$a = WM_Edu_Shortcodes::render_pii_sanitizer( '' );
	$b = WM_Edu_Shortcodes::render_consent_gate( '' );
	assert_test( $name, str_contains( $a, 'wm-edu-sanitizer-box' ) && str_contains( $b, 'wm-edu-consent-box' ) );
} );

edu_case( 'no inline style and no colour in the markup; the stylesheet is enqueued', function ( string $name ) {
	edu_fresh_db();
	$GLOBALS['edu_styles'] = [];
	$_POST                 = [ 'wm_edu_sanitize_submit' => '1', 'wm_edu_raw' => 'Klasse 9b', 'edu_consent_check' => '1', 'edu_student_id' => 'EDU-001' ];
	$html                  = WM_Edu_Shortcodes::render_pii_sanitizer() . WM_Edu_Shortcodes::render_consent_gate();
	assert_test( $name, ! str_contains( $html, 'style=' ) && 0 === preg_match( '/(?<!&)#[0-9a-fA-F]{3,6}\b/', $html ) && str_ends_with( (string) ( $GLOBALS['edu_styles'][ WM_Edu_Shortcodes::STYLE_HANDLE ] ?? '' ), 'assets/css/wm-edu-portal.css' ), var_export( $GLOBALS['edu_styles'], true ) );
} );

edu_case( 'the demo keeps line breaks and "<3" (escaped once) and says "pseudonymisieren", not "Anonymisieren"', function ( string $name ) {
	$_POST = [ 'wm_edu_sanitize_submit' => '1', 'wm_edu_raw' => "Klasse 9b\nPunkte <3 von 10" ];
	$html  = WM_Edu_Shortcodes::render_pii_sanitizer();
	assert_test( $name, 1 === preg_match( '#<pre class="wm-edu-pre">\[\[CLASS_[0-9A-F]{6}\]\]\nPunkte &lt;3 von 10</pre>#', $html ) && str_contains( $html, 'Text pseudonymisieren' ) && ! str_contains( $html, 'nonymisier' ) && ! str_contains( $html, 'WP-CLI' ), $html );
} );

edu_case( 'the gate has no unused service field and says REGISTER NICHT PRÜFBAR while the chain is broken', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	edu_consent();
	$_POST = [ 'edu_consent_check' => '1', 'edu_student_id' => 'EDU-001' ];
	$ok    = WM_Edu_Shortcodes::render_consent_gate();
	$wpdb->update( edu_table(), [ 'parent_email' => 'edited@example.test' ], [ 'id' => 1 ] );
	$broken = WM_Edu_Shortcodes::render_consent_gate();
	$_POST  = [ 'edu_consent_check' => '1', 'edu_student_id' => 'EDU-UNKNOWN' ];
	$wpdb->update( edu_table(), [ 'parent_email' => 'eltern@beispiel.de' ], [ 'id' => 1 ] );
	$none = WM_Edu_Shortcodes::render_consent_gate();
	assert_test(
		$name,
		! str_contains( $ok . $broken, 'edu_service' ) && ! str_contains( $ok, 'SchulCloud' ) && str_contains( $ok, 'GÜLTIGE EINWILLIGUNG' )
		&& str_contains( $broken, 'REGISTER NICHT PRÜFBAR' ) && ! str_contains( $broken, 'GÜLTIGE' ) && str_contains( $none, 'KEIN NACHWEIS' ),
		$broken
	);
} );

edu_case( 'no emoji and no conformity claim in any output; result messages keep their words and an SVG icon', function ( string $name ) use ( $emoji ) {
	edu_fresh_db();
	edu_consent();
	$_POST = [ 'edu_consent_check' => '1', 'edu_student_id' => 'EDU-001', 'wm_edu_sanitize_submit' => '1', 'wm_edu_raw' => 'geboren am 15.03.2012' ];
	$html  = WM_Edu_Shortcodes::render_pii_sanitizer() . WM_Edu_Shortcodes::render_consent_gate();
	assert_test( $name, 0 === preg_match( $emoji, $html ) && ! preg_match( '/konform|DSGVO|SHA-256 Prüfsumme/u', $html ) && 1 === preg_match( '#<svg class="wm-edu-icon"[^>]*aria-hidden="true"#', $html ) && str_contains( $html, 'GÜLTIGE EINWILLIGUNG' ) );
} );

// =====================================================================================================================
echo "\n[GROUP 9] Stylesheet: tokens only, contrast, target size\n";
// =====================================================================================================================

$css = (string) file_get_contents( $root . '/assets/css/wm-edu-portal.css' );
preg_match_all( '/^\s*--edu-([a-z0-9-]+):\s*([^;]+);/m', $css, $tm );
$tokens = array_combine( $tm[1], array_map( 'trim', $tm[2] ) );

edu_case( 'rules use var() only: no colour, px, rem or em outside the custom-property lines', function ( string $name ) use ( $css ) {
	$offending = [];
	foreach ( preg_split( '/\R/', (string) preg_replace( '#/\*.*?\*/#s', '', $css ) ) as $line ) {
		if ( preg_match( '/^\s*--edu-/', $line ) ) {
			continue;
		}
		if ( preg_match( '/#[0-9a-fA-F]{3,6}\b|\b\d+(\.\d+)?(px|rem|em)\b/', $line ) ) {
			$offending[] = trim( $line );
		}
	}
	assert_test( $name, [] === $offending, implode( ' | ', $offending ) );
} );

edu_case( 'contrast from the tokens: button text 4.5:1, text pairs 4.5:1, field border, buttons and focus 3:1 (was: 4.10, 3.77 and 2.36)', function ( string $name ) use ( $tokens ) {
	$t     = static fn( string $k ): string => $tokens[ $k ] ?? '#000';
	$pairs = [
		[ 'accent-contrast', 'accent', 4.5 ], [ 'accent-contrast', 'accent-gate', 4.5 ],
		[ 'text', 'surface', 4.5 ], [ 'text-muted', 'surface', 4.5 ], [ 'heading', 'surface', 4.5 ], [ 'heading-gate', 'surface', 4.5 ],
		[ 'text', 'surface-raised', 4.5 ], [ 'text-code', 'surface', 4.5 ], [ 'result-title', 'surface-raised', 4.5 ], [ 'notice', 'surface-raised', 4.5 ],
		[ 'valid-text', 'valid-bg', 4.5 ], [ 'blocked-text', 'blocked-bg', 4.5 ], [ 'unverifiable-text', 'unverifiable-bg', 4.5 ],
		[ 'field-border', 'surface', 3.0 ], [ 'field-border', 'surface-raised', 3.0 ], [ 'accent', 'surface', 3.0 ], [ 'accent-gate', 'surface', 3.0 ], [ 'focus', 'surface', 3.0 ],
	];
	$low = [];
	foreach ( $pairs as [ $fg, $bg, $min ] ) {
		$ratio = edu_contrast( $t( $fg ), $t( $bg ) );
		if ( $ratio < $min ) {
			$low[] = sprintf( '%s on %s %.2f < %.1f', $fg, $bg, $ratio, $min );
		}
	}
	assert_test( $name, [] === $low, implode( '; ', $low ) );
} );

edu_case( 'buttons and fields are at least 44px high', function ( string $name ) use ( $tokens, $css ) {
	$target = (int) ( $tokens['target'] ?? 0 );
	assert_test( $name, $target >= 44 && str_ends_with( (string) ( $tokens['target'] ?? '' ), 'px' ) && 2 === substr_count( $css, 'min-height: var(--edu-target);' ), (string) $target );
} );

// =====================================================================================================================
echo "\n[GROUP 10] Hub adapter\n";
// =====================================================================================================================

edu_case( 'settings URL points to wm-edu-consents-settings, an unknown action fails, self_test passes on a valid ledger', function ( string $name ) {
	edu_fresh_db();
	edu_consent();
	$adapter = new WM_Edu_Spoke_Adapter();
	$self    = $adapter->execute_action( 'self_test' );
	assert_test( $name, str_contains( (string) $adapter->get_settings_url(), 'page=wm-edu-consents-settings' ) && false === $adapter->execute_action( 'purge_everything' )['success'] && true === $self['success'] && str_contains( $self['message'], '1 von 1' ) && 'HEALTHY' === $adapter->get_health_status(), var_export( $self, true ) );
} );

edu_case( 'telemetry after an edited first row: 3 ledger rows, 0 verified, chain NO, health ERROR (was: consents 0)', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	edu_consent();
	edu_consent( [ 'student_token' => 'EDU-002', 'granted_status' => 'REVOKED' ] );
	edu_consent( [ 'student_token' => 'EDU-003', 'granted_status' => 'DENIED' ] );
	$wpdb->update( edu_table(), [ 'parent_email' => 'edited@example.test' ], [ 'id' => 1 ] );
	$adapter = new WM_Edu_Spoke_Adapter();
	$m       = $adapter->get_telemetry_metrics();
	assert_test( $name, 3 === $m['ledger_rows'] && 0 === $m['ledger_rows_verified'] && 'NO' === $m['chain_valid'] && 0 === $m['missing_table'] && ! isset( $m['status'] ) && 'ERROR' === $adapter->get_health_status() && false === $adapter->execute_action( 'self_test' )['success'], var_export( $m, true ) );
} );

edu_case( 'without a table: health ERROR, self_test fails, telemetry says so', function ( string $name ) {
	$GLOBALS['wpdb'] = new Edu_Test_WPDB();
	$adapter         = new WM_Edu_Spoke_Adapter();
	$m               = $adapter->get_telemetry_metrics();
	assert_test( $name, 'ERROR' === $adapter->get_health_status() && false === $adapter->execute_action( 'self_test' )['success'] && null === $m['ledger_rows'] && 1 === $m['missing_table'], var_export( $m, true ) );
} );

edu_case( 'telemetry counts the active consent types and the requests waiting for a confirmation, and says nothing rather than 0 without the tables', function ( string $name ) {
	edu_seed_flow();
	edu_form_request( edu_guardian_post() );
	$filled          = ( new WM_Edu_Spoke_Adapter() )->get_telemetry_metrics();
	$GLOBALS['wpdb'] = new Edu_Test_WPDB();
	$missing         = ( new WM_Edu_Spoke_Adapter() )->get_telemetry_metrics();
	$ledger          = ( new WM_Edu_Spoke_Adapter() )->get_sbom_data()['properties'][1]['value'] ?? '';
	assert_test(
		$name,
		2 === $filled['consent_types_active'] && 1 === $filled['requests_pending'] && null === $missing['consent_types_active'] && null === $missing['requests_pending']
		&& str_contains( $ledger, 'written under a named lock' ) && ! str_contains( $ledger, 'no shipped writer' ),
		var_export( [ $filled, $missing, $ledger ], true )
	);
} );

edu_case( 'the SBOM tokenizer property names the derived key and both token lengths', function ( string $name ) {
	$value = ( new WM_Edu_Spoke_Adapter() )->get_sbom_data()['properties'][0]['value'] ?? '';
	assert_test( $name, str_contains( $value, 'derived from the site auth salt' ) && str_contains( $value, '6 for classes' ) && ! str_contains( $value, 'site salt (8 hex characters)' ), $value );
} );

// =====================================================================================================================
echo "\n[GROUP 11] WP-CLI\n";
// =====================================================================================================================

edu_case( 'wp edu status prints rows and verified rows, and ends with an error on a broken chain', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	edu_consent();
	$cli       = new WM_Edu_CLI();
	WP_CLI::$out = [];
	$cli->status( [], [] );
	$ok = WP_CLI::$out;
	$wpdb->update( edu_table(), [ 'granted_status' => 'DENIED' ], [ 'id' => 1 ] );
	$error = null;
	try {
		$cli->status( [], [] );
	} catch ( EduCliError $e ) {
		$error = $e->getMessage();
	}
	assert_test( $name, in_array( [ 'line', 'Zeilen im Register: 1' ], $ok, true ) && 'success' === end( $ok )[0] && null !== $error && str_contains( $error, 'Einwilligung-ID 1' ), var_export( [ $ok, $error ], true ) );
} );

edu_case( 'wp edu tokenize help promises neither names nor grades', function ( string $name ) use ( $root ) {
	$src = (string) file_get_contents( $root . '/includes/class-edu-cli.php' );
	assert_test( $name, ! str_contains( $src, 'Note 1' ) && ! str_contains( $src, 'Max Mustermann' ) && ! str_contains( $src, 'remove student PII' ) && str_contains( $src, 'known_students' ) );
} );

// =====================================================================================================================
echo "\n[GROUP 12] Main file, uninstall, catalogs\n";
// =====================================================================================================================

edu_case( 'the text domain is loaded on init from languages/; the schema check runs on plugins_loaded; activation installs', function ( string $name ) {
	$GLOBALS['edu_textdomains'] = [];
	foreach ( $GLOBALS['edu_actions']['init'] ?? [] as $callback ) {
		$callback();
	}
	assert_test(
		$name,
		[ [ 'wm-edu-privacy-portal', 'wm-edu-privacy-portal/languages' ] ] === $GLOBALS['edu_textdomains']
		&& in_array( [ 'WM_Edu_Consent_Vault', 'maybe_upgrade' ], $GLOBALS['edu_actions']['plugins_loaded'] ?? [], true )
		&& [ [ 'WM_Edu_Consent_Vault', 'install' ] ] === $GLOBALS['edu_activation'],
		var_export( [ $GLOBALS['edu_textdomains'], $GLOBALS['edu_actions']['plugins_loaded'] ?? null ], true )
	);
} );

edu_case( 'every msgid of the code is in the template; no catalog carries double-encoded UTF-8', function ( string $name ) use ( $root ) {
	$code = '';
	foreach ( glob( $root . '/includes/*.php' ) as $file ) {
		$code .= (string) file_get_contents( $file );
	}
	preg_match_all( "/\b(?:__|esc_html__|esc_attr__)\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $single );
	preg_match_all( "/\b_n\(\s*'((?:[^'\\\\]|\\\\.)*)',\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $plural );
	$pot     = (string) @file_get_contents( $root . '/languages/wm-edu-privacy-portal.pot' );
	$missing = [];
	foreach ( array_merge( $single[1], $plural[1], $plural[2] ) as $msgid ) {
		$po_form = addcslashes( stripslashes( $msgid ), '"' );
		if ( ! str_contains( $pot, '"' . $po_form . '"' ) ) {
			$missing[] = $msgid;
		}
	}
	$broken = [];
	foreach ( glob( $root . '/languages/*.{po,pot}', GLOB_BRACE ) as $file ) {
		if ( preg_match( '/Ã[\x{0080}-\x{00BF}]|Ã[¤¶¼Ÿ„–œ©]/u', (string) file_get_contents( $file ) ) ) {
			$broken[] = basename( $file );
		}
	}
	assert_test( $name, count( $single[1] ) >= 20 && [] === $missing && [] === $broken, 'missing: ' . implode( ' | ', $missing ) . ' · double-encoded: ' . implode( ', ', $broken ) );
} );

edu_case( 'uninstall preserves options, capabilities and the consent table by default unless wm_edu_delete_data_on_uninstall is enabled', function ( string $name ) use ( $root ) {
	$db = edu_fresh_db();
	WM_Edu_Settings::save( [ 'public_gate' => '1' ], [] );
	edu_consent();
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'wm-edu-privacy-portal/wm-edu-privacy-portal.php' );
	}
	require $root . '/uninstall.php';
	assert_test(
		$name,
		'3' === get_option( WM_Edu_Consent_Vault::SCHEMA_OPTION )
		&& '0' === get_option( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION )
		&& is_array( get_option( WM_Edu_Settings::OPTION ) )
		&& get_role( 'administrator' )->has_cap( WM_Edu_Access::CAPABILITY )
		&& $db->has_table( edu_table() ) && 1 === edu_count()
	);
} );

// =====================================================================================================================
echo "\n[GROUP 13] Documents say what the code does\n";
// =====================================================================================================================

edu_case( 'README: no marketing image embedded; names only through the REST route; the gate shows nothing on a broken chain', function ( string $name ) use ( $root ) {
	$readme = (string) file_get_contents( $root . '/README.md' );
	assert_test( $name, 0 === preg_match( '/!\[[^\]]*\]\((?!https?:)[^)]*assets\/images/', $readme ) && str_contains( $readme, 'REGISTER NICHT PRÜFBAR' ) && str_contains( $readme, 'wm_edu_schema_version' ) && ! str_contains( $readme, 'Translations-24%20EU%20Languages' ) );
} );

edu_case( 'humans.txt, llms.txt, SECURITY.md and CONTRIBUTING.md carry no CEO, unbuilt stack, SLA, legal article or emoji', function ( string $name ) use ( $root, $emoji ) {
	$humans = edu_doc_text( $root . '/public/humans.txt' );
	$llms   = edu_doc_text( $root . '/llms.txt' );
	$sec    = edu_doc_text( $root . '/SECURITY.md' );
	$contr  = edu_doc_text( $root . '/CONTRIBUTING.md' );
	$hits   = array_filter(
		[
			'humans CEO'        => str_contains( $humans, 'CEO' ),
			'humans stack'      => (bool) preg_match( '/Gutenberg|React|Transaction-Safe|Audit Logs|Compliant Architecture|WCAG 2\.2 AA|PSR-12/', $humans ),
			'llms PSR-12'       => str_contains( $llms, 'PSR-12' ),
			'security SLA'      => (bool) preg_match( '/Within 24 hours|Within 72 hours|CRA Art\. 14|BSI Guidelines|:white_check_mark:/', $sec ),
			'contributing claim' => str_contains( $contr, 'high standards of security, accessibility, and legal compliance' ),
			'emoji'             => 1 === preg_match( $emoji, $humans . $llms . $sec . $contr . (string) file_get_contents( $root . '/CHANGELOG.md' ) ),
		]
	);
	assert_test( $name, [] === $hits, implode( ', ', array_keys( $hits ) ) );
} );

edu_case( 'AGENTS.md is a symlink to CLAUDE.md; readme.txt short description within 150 characters; images and repository documents out of the package', function ( string $name ) use ( $root ) {
	$readme_txt = (string) file_get_contents( $root . '/readme.txt' );
	$short      = preg_match( '/^License URI:.*\R\R(.+)$/m', $readme_txt, $m ) ? trim( $m[1] ) : '';
	$dist       = (string) file_get_contents( $root . '/.distignore' );
	$claude     = (string) file_get_contents( $root . '/CLAUDE.md' );
	assert_test(
		$name,
		is_link( $root . '/AGENTS.md' ) && 'CLAUDE.md' === readlink( $root . '/AGENTS.md' )
		&& file_get_contents( $root . '/AGENTS.md' ) === $claude && str_contains( $claude, 'There is no `/src/`.' )
		&& '' !== $short && strlen( $short ) <= 150
		&& 1 === preg_match( '/^assets\/images\/$/m', $dist ) && 1 === preg_match( '/^DEVELOPERS\.md$/m', $dist ),
		strlen( $short ) . ': ' . $short
	);
} );

edu_case( 'CI installs pdo_sqlite and runs the mutation check', function ( string $name ) use ( $root ) {
	$ci = (string) file_get_contents( $root . '/.github/workflows/ci.yml' );
	assert_test( $name, str_contains( $ci, 'pdo_sqlite' ) && str_contains( $ci, 'run: bash tests/run-mutations.sh' ) && str_contains( $ci, 'TEST SUITE RESULTS' ) );
} );

// ---------------------------------------------------------------------------------------------------------------------

// =====================================================================================================================
echo "\n[GROUP 14] Writing the chain: named lock, recorded tip, one transaction, schema 3\n";
// =====================================================================================================================

/** DELETE statement for a test that removes rows the way someone with database access would. */
function edu_delete_row( int $id ): void {
	$GLOBALS['wpdb']->run_raw( 'DELETE FROM ' . edu_table() . ' WHERE id = ' . $id );
}

edu_case( 'record_consent() takes the named lock before the insert, releases it and commits; the lock name is per database and table and fits MySQL\'s 64 characters', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	$wpdb->queries    = [];
	$wpdb->lock_calls = [];
	$id               = edu_consent();
	$lock_at          = array_key_first( array_filter( $wpdb->queries, static fn( $q ) => str_contains( $q, 'GET_LOCK' ) ) );
	$insert_at        = array_key_first( array_filter( $wpdb->queries, static fn( $q ) => str_starts_with( $q, 'INSERT INTO `' . edu_table() ) ) );
	$lock_name        = WM_Edu_Consent_Vault::lock_name();
	$wpdb->prefix     = 'wp_2_';
	$subsite_lock     = WM_Edu_Consent_Vault::lock_name();
	$wpdb->prefix     = 'wp_';
	assert_test(
		$name,
		1 === $id && [ 'GET_LOCK', 'RELEASE_LOCK' ] === $wpdb->lock_calls && null !== $lock_at && null !== $insert_at && $lock_at < $insert_at
		&& 0 === $wpdb->transaction_depth && strlen( $lock_name ) <= 64 && $lock_name !== $subsite_lock,
		var_export( [ $wpdb->lock_calls, $lock_at, $insert_at, $wpdb->transaction_depth, $lock_name, $subsite_lock ], true )
	);
} );

edu_case( 'without the lock (timeout 0 or error NULL) nothing is written: wm_edu_chain_busy with status 503, no insert, no release of a lock never held', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	$results = [];
	foreach ( [ '0', null ] as $answer ) {
		$wpdb->lock_result = $answer;
		$wpdb->lock_calls  = [];
		$wpdb->queries     = [];
		$error             = WM_Edu_Consent_Vault::append_consent( [ 'student_token' => 'EDU-001', 'parent_email' => 'eltern@beispiel.de', 'consent_type' => 'T', 'scope_hash' => str_repeat( 'a', 64 ), 'granted_status' => 'GRANTED', 'ip_address' => '203.0.113.9' ] );
		$results[]         = [
			$error instanceof WP_Error ? $error->get_error_code() : 'written',
			$error instanceof WP_Error ? ( $error->get_error_data()['status'] ?? 0 ) : 0,
			$wpdb->lock_calls,
			count( array_filter( $wpdb->queries, static fn( $q ) => str_starts_with( $q, 'INSERT' ) ) ),
		];
	}
	$wpdb->lock_result = '0';
	$refused           = edu_consent();
	$wpdb->lock_result = '1';
	$busy              = [ 'wm_edu_chain_busy', 503, [ 'GET_LOCK' ], 0 ];
	assert_test( $name, [ $busy, $busy ] === $results && false === $refused && 0 === edu_count(), var_export( $results, true ) );
} );

edu_case( 'an append links to the recorded tip: a deleted newest row stays reported after the next consent (linking to the table end would launder it)', function ( string $name ) {
	edu_fresh_db();
	edu_consent();
	edu_consent( [ 'student_token' => 'EDU-002' ] );
	edu_consent( [ 'student_token' => 'EDU-003' ] );
	$deleted_signature = edu_row( 3 )['signature_hash'];
	edu_delete_row( 3 );
	$after_delete = WM_Edu_Consent_Vault::verify_chain();
	$next         = edu_consent( [ 'student_token' => 'EDU-004' ] );
	$after_append = WM_Edu_Consent_Vault::verify_chain();
	assert_test(
		$name,
		false === $after_delete['valid'] && true === $after_delete['tip_mismatch'] && 2 === $after_delete['checked']
		&& 4 === $next && $deleted_signature === edu_row( 4 )['prev_hash'] && false === $after_append['valid'],
		var_export( [ $after_delete, $after_append ], true )
	);
} );

edu_case( 'the recorded tip is signed: a rewritten tip is reported, and the next write neither trusts nor re-signs it', function ( string $name ) {
	edu_fresh_db();
	edu_consent();
	edu_consent( [ 'student_token' => 'EDU-002' ] );
	$first = edu_row( 1 )['signature_hash'];
	edu_delete_row( 2 );
	$forged = (string) json_encode( [ 'hash' => $first, 'count' => 1, 'sig' => str_repeat( 'f', 64 ) ] );
	update_option( WM_Edu_Consent_Vault::TIP_OPTION, $forged );
	$chain  = WM_Edu_Consent_Vault::verify_chain();
	$next   = edu_consent( [ 'student_token' => 'EDU-003' ] );
	$after  = WM_Edu_Consent_Vault::verify_chain();
	assert_test(
		$name,
		false === $chain['valid'] && true === $chain['tip_mismatch'] && str_contains( (string) $chain['error'], 'Signatur der aufgezeichneten Kettenspitze' )
		&& is_int( $next ) && false === $after['valid'] && $forged === get_option( WM_Edu_Consent_Vault::TIP_OPTION ),
		var_export( [ $chain, $after, get_option( WM_Edu_Consent_Vault::TIP_OPTION ) ], true )
	);
} );

edu_case( 'a missing recorded tip is reported once schema 3 is installed, and a later write does not recreate it', function ( string $name ) {
	edu_fresh_db();
	edu_consent();
	delete_option( WM_Edu_Consent_Vault::TIP_OPTION );
	$chain = WM_Edu_Consent_Vault::verify_chain();
	edu_consent( [ 'student_token' => 'EDU-002' ] );
	assert_test(
		$name,
		false === $chain['valid'] && true === $chain['tip_mismatch'] && str_contains( (string) $chain['error'], 'fehlt' ) && null === WM_Edu_Consent_Vault::recorded_tip()
		&& false === WM_Edu_Consent_Vault::verify_chain()['valid'] && 'unverifiable' === WM_Edu_Consent_Vault::consent_state( 'EDU-001' ),
		var_export( $chain, true )
	);
} );

edu_case( 'row and tip in one transaction: a refused tip update leaves no row, a refused insert leaves the tip, and the chain still verifies', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	edu_consent();
	$GLOBALS['edu_cache_deletes']     = [];
	$GLOBALS['edu_option_write_fail'] = WM_Edu_Consent_Vault::TIP_OPTION;
	$tip_refused                      = WM_Edu_Consent_Vault::append_consent( [ 'student_token' => 'EDU-002', 'parent_email' => 'eltern@beispiel.de', 'consent_type' => 'T', 'scope_hash' => str_repeat( 'a', 64 ), 'granted_status' => 'GRANTED', 'ip_address' => '203.0.113.9' ] );
	$GLOBALS['edu_option_write_fail'] = null;
	$rows_after_tip                   = edu_count();
	$depth_after_tip                  = $wpdb->transaction_depth;
	$wpdb->fail_insert                = true;
	$insert_refused                   = WM_Edu_Consent_Vault::append_consent( [ 'student_token' => 'EDU-003', 'parent_email' => 'eltern@beispiel.de', 'consent_type' => 'T', 'scope_hash' => str_repeat( 'a', 64 ), 'granted_status' => 'GRANTED', 'ip_address' => '203.0.113.9' ] );
	$tip                              = WM_Edu_Consent_Vault::recorded_tip();
	assert_test(
		$name,
		$tip_refused instanceof WP_Error && 'wm_edu_consent_not_written' === $tip_refused->get_error_code() && 1 === $rows_after_tip && 0 === $depth_after_tip
		&& in_array( [ WM_Edu_Consent_Vault::TIP_OPTION, 'options' ], $GLOBALS['edu_cache_deletes'], true )
		&& $insert_refused instanceof WP_Error && 1 === edu_count() && 0 === $wpdb->transaction_depth && 1 === ( $tip['count'] ?? 0 )
		&& true === WM_Edu_Consent_Vault::verify_chain()['valid'],
		var_export( [ $tip_refused, $insert_refused, $tip, $rows_after_tip ], true )
	);
} );

edu_case( 'schema 3 signs source, actor and note: editing any of them, blanking row_version or naming an unknown one breaks the chain', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	$id    = edu_consent( [ 'source' => 'admin_entry', 'actor_id' => 7, 'note' => 'Telefonat mit der Mutter am 16.09.' ] );
	$row   = is_int( $id ) ? edu_row( $id ) : [];
	$valid = WM_Edu_Consent_Vault::verify_chain()['valid'];
	$edits = [];
	foreach ( [ 'note' => 'geändert', 'actor_id' => '8', 'source' => 'direct', 'row_version' => '' ] as $column => $value ) {
		$wpdb->update( edu_table(), [ $column => $value ], [ 'id' => 1 ] );
		$edits[ $column ] = WM_Edu_Consent_Vault::verify_chain()['valid'];
		$wpdb->update( edu_table(), [ $column => $row[ $column ] ], [ 'id' => 1 ] );
	}
	$wpdb->update( edu_table(), [ 'row_version' => '9' ], [ 'id' => 1 ] );
	$unknown = WM_Edu_Consent_Vault::verify_chain();
	assert_test(
		$name,
		1 === $id && 'admin_entry' === $row['source'] && '7' === $row['actor_id'] && '3' === $row['row_version'] && true === $valid
		&& [ 'note' => false, 'actor_id' => false, 'source' => false, 'row_version' => false ] === $edits
		&& false === $unknown['valid'] && str_contains( (string) $unknown['error'], 'unbekannte Zeilenversion' ),
		var_export( [ $row, $edits, $unknown ], true )
	);
} );

edu_case( 'an admin entry without actor or note, an unknown source, a note over 500 characters or a non-integer actor is refused; a plain call is source "direct"', function ( string $name ) {
	edu_fresh_db();
	$refused = [
		edu_consent( [ 'source' => 'admin_entry', 'actor_id' => 7, 'note' => '   ' ] ),
		edu_consent( [ 'source' => 'admin_revocation', 'actor_id' => 0, 'note' => 'Anruf' ] ),
		edu_consent( [ 'source' => 'import' ] ),
		edu_consent( [ 'note' => str_repeat( 'n', 501 ) ] ),
		edu_consent( [ 'actor_id' => '7' ] ),
		edu_consent( [ 'actor_id' => -1 ] ),
	];
	$plain = edu_consent();
	$row   = is_int( $plain ) ? edu_row( $plain ) : [];
	assert_test( $name, array_fill( 0, 6, false ) === $refused && 1 === edu_count() && 'direct' === ( $row['source'] ?? '' ) && '0' === ( $row['actor_id'] ?? '' ), var_export( [ $refused, $row ], true ) );
} );

/** The consent table as schema 2 created it (2026-09-15), for the upgrade tests. */
function edu_schema2_table_sql(): string {
	return "CREATE TABLE wp_wm_edu_parent_consents (
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
			PRIMARY KEY (id)
		) ;";
}

/** Appends a schema-2 row (v2 payload) directly, the way schema 2 wrote it. */
function edu_insert_schema2_row( string $token, string $prev ): string {
	$row                   = [ 'student_token' => $token, 'parent_email' => 'p@example.test', 'consent_type' => 'T', 'scope_hash' => str_repeat( 'a', 64 ), 'granted_status' => 'GRANTED', 'ip_hmac' => str_repeat( 'b', 64 ), 'key_id' => WM_Edu_Keys::signature_key_id(), 'prev_hash' => $prev, 'created_at' => '2026-09-15 10:00:00' ];
	$row['signature_hash'] = WM_Edu_Consent_Vault::row_signature( $row );
	$GLOBALS['wpdb']->insert( edu_table(), $row );
	return $row['signature_hash'];
}

edu_case( 'schema-2 rows keep verifying after the upgrade to schema 3; the upgrade records the tip from the table and the next consent is a v3 row', function ( string $name ) {
	global $wpdb;
	$GLOBALS['wpdb']              = new Edu_Test_WPDB();
	$GLOBALS['edu_dbdelta_calls'] = [];
	$wpdb                         = $GLOBALS['wpdb'];
	$wpdb->apply_create_table( edu_schema2_table_sql() );
	update_option( WM_Edu_Consent_Vault::SCHEMA_OPTION, '2' );
	update_option( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION, '0' );
	$second = edu_insert_schema2_row( 'EDU-V2-B', edu_insert_schema2_row( 'EDU-V2-A', WM_Edu_Consent_Vault::GENESIS_HASH ) );
	$before = WM_Edu_Consent_Vault::verify_chain();
	WM_Edu_Consent_Vault::maybe_upgrade();
	$tip    = WM_Edu_Consent_Vault::recorded_tip();
	$after  = WM_Edu_Consent_Vault::verify_chain();
	$new    = edu_consent( [ 'student_token' => 'EDU-V3' ] );
	$mixed  = WM_Edu_Consent_Vault::verify_chain();
	assert_test(
		$name,
		true === $before['valid'] && in_array( 'row_version', $wpdb->columns( edu_table() ), true ) && '3' === get_option( WM_Edu_Consent_Vault::SCHEMA_OPTION )
		&& [ 'hash' => $second, 'count' => 2, 'signed' => true ] === $tip && true === $after['valid'] && 2 === $after['checked'] && 0 === $after['legacy_rows']
		&& 3 === $new && '3' === edu_row( 3 )['row_version'] && $second === edu_row( 3 )['prev_hash'] && true === $mixed['valid'] && 3 === $mixed['checked'],
		var_export( [ $before, $tip, $after, $mixed ], true )
	);
} );

edu_case( 'on a site still at schema 2 the recorded boundary applies: a schema-1 style row written after it does not verify', function ( string $name ) {
	global $wpdb;
	$GLOBALS['wpdb'] = new Edu_Test_WPDB();
	$wpdb            = $GLOBALS['wpdb'];
	$wpdb->apply_create_table( edu_schema2_table_sql() );
	update_option( WM_Edu_Consent_Vault::SCHEMA_OPTION, '2' );
	update_option( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION, '1' );
	$first = edu_insert_schema2_row( 'EDU-V2-A', WM_Edu_Consent_Vault::GENESIS_HASH );
	$sig   = WM_Edu_Consent_Vault::generate_signature( 'EDU-FORGED', 'p@example.test', 'T', str_repeat( 'a', 64 ), 'GRANTED', '2026-09-16 00:00:00', $first );
	$wpdb->insert( edu_table(), [ 'student_token' => 'EDU-FORGED', 'parent_email' => 'p@example.test', 'consent_type' => 'T', 'scope_hash' => str_repeat( 'a', 64 ), 'granted_status' => 'GRANTED', 'ip_hmac' => 'x', 'key_id' => '', 'prev_hash' => $first, 'signature_hash' => $sig, 'created_at' => '2026-09-16 00:00:00' ] );
	$chain = WM_Edu_Consent_Vault::verify_chain();
	assert_test( $name, false === $chain['valid'] && 2 === $chain['broken_at_id'] && str_contains( (string) $chain['error'], 'keine Schlüsselkennung' ), var_export( $chain, true ) );
} );

edu_case( 'wp edu status prints the recorded tip with its row count and whether its signature verifies', function ( string $name ) {
	edu_fresh_db();
	edu_consent();
	WP_CLI::$out = [];
	( new WM_Edu_CLI() )->status( [], [] );
	$lines = array_column( WP_CLI::$out, 1 );
	$tip   = WM_Edu_Consent_Vault::recorded_tip();
	assert_test( $name, in_array( 'Aufgezeichnet:      ' . ( $tip['hash'] ?? '?' ) . ' · Zeilen: 1 · signiert', $lines, true ), var_export( $lines, true ) );
} );

// =====================================================================================================================
echo "\n[GROUP 15] Settings, capability, consent types and the guardian registry\n";
// =====================================================================================================================

edu_case( 'install() creates the consent, type, text and guardian tables and grants manage_edu_consents to administrators once', function ( string $name ) {
	edu_reset_roles();
	$db     = edu_fresh_db();
	$tables = array_map( static fn( string $t ): bool => $db->has_table( 'wp_' . $t ), [ 'wm_edu_parent_consents', 'wm_edu_consent_types', 'wm_edu_consent_texts', 'wm_edu_guardians' ] );
	delete_option( WM_Edu_Consent_Vault::SCHEMA_OPTION );
	WM_Edu_Consent_Vault::maybe_upgrade();
	assert_test(
		$name,
		[ true, true, true, true ] === $tables && get_role( 'administrator' )->has_cap( WM_Edu_Access::CAPABILITY ) && ! get_role( 'editor' )->has_cap( WM_Edu_Access::CAPABILITY )
		&& [ [ 'add', 'administrator', WM_Edu_Access::CAPABILITY ] ] === $GLOBALS['edu_role_writes'],
		var_export( [ $tables, $GLOBALS['edu_role_writes'] ], true )
	);
} );

edu_case( 'settings: defaults keep gate and tokenizer closed; the form accepts "1" only, known roles only, and clamps numbers; a tampered stored value reads as closed', function ( string $name ) {
	edu_fresh_db();
	$defaults = WM_Edu_Settings::all();
	WM_Edu_Settings::save( [ 'public_gate' => 'on', 'public_tokenizer' => '1', 'gate_roles' => [ 'teacher', 'ghost', 5 ], 'confirm_hours' => '100000', 'retention_days' => '-3', 'rate_ip_per_hour' => 'viele', 'rate_email_per_hour' => '0' ], [ 'administrator', 'editor', 'teacher' ] );
	$saved = WM_Edu_Settings::all();
	update_option( WM_Edu_Settings::OPTION, [ 'public_gate' => 'yes', 'confirm_hours' => 99999 ] );
	$tampered = WM_Edu_Settings::all();
	assert_test(
		$name,
		false === $defaults['public_gate'] && false === $defaults['public_tokenizer'] && 48 === $defaults['confirm_hours']
		&& false === $saved['public_gate'] && true === $saved['public_tokenizer'] && [ 'teacher' ] === $saved['gate_roles'] && 720 === $saved['confirm_hours']
		&& 7 === $saved['retention_days'] && 10 === $saved['rate_ip_per_hour'] && 1 === $saved['rate_email_per_hour']
		&& false === $tampered['public_gate'] && 720 === $tampered['confirm_hours'],
		var_export( [ $defaults, $saved, $tampered ], true )
	);
} );

edu_case( 'consent types: slug upper-cased and checked, scope hash is the SHA-256 of the stored text, every text archived, the slug cannot change and cannot repeat', function ( string $name ) {
	edu_fresh_db();
	$text    = "Ich willige ein, dass mein Kind an der Klassenfahrt teilnimmt.\r\nDie Fahrt dauert drei Tage.";
	$stored  = "Ich willige ein, dass mein Kind an der Klassenfahrt teilnimmt.\nDie Fahrt dauert drei Tage.";
	$id      = WM_Edu_Consent_Types::save( [ 'slug' => ' fahrt_2026 ', 'label' => 'Klassenfahrt 2026', 'description' => 'Berlin, 3 Tage', 'consent_text' => $text, 'active' => true ] );
	$type    = is_int( $id ) ? WM_Edu_Consent_Types::get( $id ) : null;
	$bad     = [
		WM_Edu_Consent_Types::save( [ 'slug' => 'FAHRT_2026', 'label' => 'Doppelt', 'consent_text' => 'x' ] ),
		WM_Edu_Consent_Types::save( [ 'slug' => 'Fahrt 2026', 'label' => 'Leerzeichen', 'consent_text' => 'x' ] ),
		WM_Edu_Consent_Types::save( [ 'slug' => 'ÜBUNG', 'label' => 'Umlaut', 'consent_text' => 'x' ] ),
		WM_Edu_Consent_Types::save( [ 'slug' => 'LEER', 'label' => '', 'consent_text' => 'x' ] ),
		WM_Edu_Consent_Types::save( [ 'slug' => 'KEINTEXT', 'label' => 'Kein Text', 'consent_text' => '<b></b>' ] ),
		WM_Edu_Consent_Types::save( [ 'slug' => 'UTF', 'label' => 'Kaputt', 'consent_text' => "a\xffb" ] ),
	];
	$codes   = array_map( static fn( $r ) => $r instanceof WP_Error ? $r->get_error_code() : 'saved', $bad );
	$updated = WM_Edu_Consent_Types::save( [ 'slug' => 'ANDERS', 'label' => 'Klassenfahrt 2026 (neu)', 'consent_text' => 'Neuer Text', 'active' => false ], (int) $id );
	$after   = WM_Edu_Consent_Types::get( (int) $id );
	assert_test(
		$name,
		is_int( $id ) && 'FAHRT_2026' === $type->slug && hash( 'sha256', $stored ) === $type->scope_hash && $stored === WM_Edu_Consent_Types::text_for_scope( $type->scope_hash )
		&& [ 'wm_edu_type_exists', 'wm_edu_type_invalid', 'wm_edu_type_invalid', 'wm_edu_type_invalid', 'wm_edu_type_invalid', 'wm_edu_type_invalid' ] === $codes
		&& $id === $updated && 'FAHRT_2026' === $after->slug && hash( 'sha256', 'Neuer Text' ) === $after->scope_hash && '0' === (string) $after->active
		&& $stored === WM_Edu_Consent_Types::text_for_scope( $type->scope_hash ) && 'Neuer Text' === WM_Edu_Consent_Types::text_for_scope( $after->scope_hash )
		&& [] === WM_Edu_Consent_Types::active() && null !== WM_Edu_Consent_Types::get_by_slug( 'fahrt_2026' ) && 1 === count( WM_Edu_Consent_Types::all() ),
		var_export( [ $type, $codes, $after ], true )
	);
} );

edu_case( 'consent text: tags removed, line breaks kept; a type saved again with the same text adds no second archive row', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	$id = WM_Edu_Consent_Types::save( [ 'slug' => 'FOTO', 'label' => 'Fotos', 'consent_text' => "<p>Fotos auf der Website</p>\n<script>alert(1)</script>Zeile 2", 'active' => true ] );
	WM_Edu_Consent_Types::save( [ 'label' => 'Fotos', 'consent_text' => "<p>Fotos auf der Website</p>\n<script>alert(1)</script>Zeile 2", 'active' => true ], (int) $id );
	$type     = WM_Edu_Consent_Types::get( (int) $id );
	$archived = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_wm_edu_consent_texts' );
	assert_test( $name, "Fotos auf der Website\nZeile 2" === WM_Edu_Consent_Types::text_for_scope( (string) $type->scope_hash ) && 1 === $archived, var_export( [ $type, $archived ], true ) );
} );

edu_case( 'guardian registry: stores only an HMAC of the address; matches the exact pupil ID and the address in any case; import counts added, existing and refused lines', function ( string $name ) {
	global $wpdb;
	edu_fresh_db();
	$import = WM_Edu_Guardians::import( "EDU-9B-042;mutter@beispiel.de\r\nEDU-9B-042, Vater@Beispiel.de\n\nEDU-9B-042\tmutter@beispiel.de\nohne-adresse\nEDU-7C-001;keine-adresse\n10234;eltern@beispiel.de" );
	$stored = $wpdb->get_results( 'SELECT * FROM wp_wm_edu_guardians', ARRAY_A );
	$checks = [
		WM_Edu_Guardians::matches( 'EDU-9B-042', 'MUTTER@beispiel.de' ),
		WM_Edu_Guardians::matches( 'EDU-9B-042', 'vater@beispiel.de' ),
		WM_Edu_Guardians::matches( 'edu-9b-042', 'mutter@beispiel.de' ),
		WM_Edu_Guardians::matches( 'EDU-9B-042', 'fremd@beispiel.de' ),
		WM_Edu_Guardians::matches( 'EDU-0000', 'mutter@beispiel.de' ),
	];
	assert_test(
		$name,
		[ 'added' => 3, 'exists' => 1, 'invalid' => [ 5, 6 ] ] === $import && 3 === count( $stored ) && ! str_contains( (string) json_encode( $stored ), '@' )
		&& [ true, true, false, false, false ] === $checks
		&& [ [ 'student_token' => '10234', 'addresses' => 1 ], [ 'student_token' => 'EDU-9B-042', 'addresses' => 2 ] ] === WM_Edu_Guardians::students(),
		var_export( [ $import, $stored, $checks, WM_Edu_Guardians::students() ], true )
	);
} );

edu_case( 'guardian registry: removing a pair removes only that address; removing a pupil removes all of its addresses and no other pupil', function ( string $name ) {
	edu_fresh_db();
	WM_Edu_Guardians::import( "EDU-1;a@beispiel.de\nEDU-1;b@beispiel.de\nEDU-2;a@beispiel.de\nedu-1;c@beispiel.de" );
	$one = WM_Edu_Guardians::remove( 'EDU-1', 'A@beispiel.de' );
	$mid = [ WM_Edu_Guardians::matches( 'EDU-1', 'a@beispiel.de' ), WM_Edu_Guardians::matches( 'EDU-1', 'b@beispiel.de' ), WM_Edu_Guardians::matches( 'EDU-2', 'a@beispiel.de' ) ];
	$all = WM_Edu_Guardians::remove_student( 'EDU-1' );
	assert_test( $name, 1 === $one && [ false, true, true ] === $mid && 1 === $all && WM_Edu_Guardians::matches( 'EDU-2', 'a@beispiel.de' ) && WM_Edu_Guardians::matches( 'edu-1', 'c@beispiel.de' ), var_export( [ $one, $mid, $all ], true ) );
} );

edu_case( 'uninstall with explicit wm_edu_delete_data_on_uninstall opt-in deletes options, capabilities and custom tables', function ( string $name ) use ( $root ) {
	$db = edu_fresh_db();
	WM_Edu_Settings::save( [ 'public_gate' => '1' ], [] );
	WM_Edu_Consent_Types::save( [ 'slug' => 'FOTO', 'label' => 'Fotos', 'consent_text' => 'Text', 'active' => true ] );
	WM_Edu_Guardians::add( 'EDU-1', 'a@beispiel.de' );
	edu_consent();
	update_option( 'wm_edu_delete_data_on_uninstall', '1' );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'wm-edu-privacy-portal/wm-edu-privacy-portal.php' );
	}
	require $root . '/uninstall.php';
	assert_test(
		$name,
		false === get_option( WM_Edu_Consent_Vault::SCHEMA_OPTION )
		&& false === get_option( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION )
		&& false === get_option( WM_Edu_Settings::OPTION )
		&& false === get_option( 'wm_edu_delete_data_on_uninstall' )
		&& null === WM_Edu_Consent_Vault::recorded_tip()
		&& ! get_role( 'administrator' )->has_cap( WM_Edu_Access::CAPABILITY )
		&& ! $db->has_table( edu_table() ) && ! $db->has_table( 'wp_wm_edu_guardians' )
	);
} );

// =====================================================================================================================
echo "\n[GROUP 16] Guardian form, confirmation link, revocation link, rate limit, retention\n";
// =====================================================================================================================

/**
 * A site with two active consent types (FAHRT, FOTO), one inactive (ALT), one registered guardian pair, no mails, no
 * transients, default settings and a client address.
 */
function edu_seed_flow(): Edu_Test_WPDB {
	$db = edu_fresh_db();
	WM_Edu_Consent_Form::reset();
	// A mail an earlier case queued and never flushed must not land in this one.
	WM_Edu_Consent_Requests::flush_mail_queue();
	$GLOBALS['edu_mails']           = [];
	$GLOBALS['edu_mail_ok']         = true;
	$GLOBALS['edu_response_events'] = [];
	$GLOBALS['edu_transients'] = [];
	$GLOBALS['edu_status']     = null;
	$GLOBALS['edu_nocache']    = null;
	$_GET                      = [];
	$_SERVER['REMOTE_ADDR']    = '203.0.113.50';
	WM_Edu_Consent_Types::save( [ 'slug' => 'FAHRT', 'label' => 'Klassenfahrt 2026', 'description' => 'Berlin, 3 Tage', 'consent_text' => 'Ich willige ein, dass mein Kind an der Klassenfahrt nach Berlin teilnimmt.', 'active' => true ] );
	WM_Edu_Consent_Types::save( [ 'slug' => 'FOTO', 'label' => 'Website-Fotos', 'consent_text' => 'Ich willige ein, dass Fotos meines Kindes auf der Website der Schule erscheinen.', 'active' => true ] );
	WM_Edu_Consent_Types::save( [ 'slug' => 'ALT', 'label' => 'Alte Einwilligung', 'consent_text' => 'Nicht mehr verwendet.', 'active' => false ] );
	WM_Edu_Guardians::add( 'EDU-9B-042', 'mutter@beispiel.de' );
	return $db;
}

/** One request to the page: $_POST and $_GET set, template_redirect run; the redirect if one was sent. */
function edu_form_request( array $post, array $get = [] ): ?EduRedirect {
	WM_Edu_Consent_Form::reset();
	$_POST                 = $post;
	$_GET                  = $get;
	$GLOBALS['edu_status'] = null;
	try {
		WM_Edu_Consent_Form::handle_request();
	} catch ( EduRedirect $redirect ) {
		return $redirect;
	} finally {
		// The end of the request: WordPress fires `shutdown`, where the queued mails leave.
		WM_Edu_Consent_Requests::flush_mail_queue();
	}
	return null;
}

/** The registered guardian's complete, valid submission. */
function edu_guardian_post( array $overrides = [] ): array {
	return array_merge( [ 'wm_edu_action' => 'request', 'wm_edu_student_id' => 'EDU-9B-042', 'wm_edu_email' => 'Mutter@Beispiel.de', 'wm_edu_decision' => [ 'FAHRT' => 'grant', 'FOTO' => 'deny' ], 'wm_edu_read' => '1' ], $overrides );
}

function edu_mail_token( string $param, int $mail = -1 ): string {
	// A case that calls the API directly never reaches `shutdown`; the mails leave here instead.
	WM_Edu_Consent_Requests::flush_mail_queue();
	$mails = $GLOBALS['edu_mails'];
	$body  = (string) ( ( $mail < 0 ? end( $mails ) : ( $mails[ $mail ] ?? [] ) )['message'] ?? '' );
	return preg_match( '/' . $param . '=([0-9a-f]{64})/', $body, $m ) ? $m[1] : '';
}

function edu_requests(): array {
	return $GLOBALS['wpdb']->get_results( 'SELECT * FROM wp_wm_edu_consent_requests ORDER BY id ASC', ARRAY_A );
}

edu_case( 'the form lists the active types with their wording, hides the honeypot, has no inline style and enqueues the sheet; without active types it shows no form', function ( string $name ) use ( $emoji ) {
	edu_seed_flow();
	$GLOBALS['edu_styles'] = [];
	$html                  = WM_Edu_Consent_Form::render();
	foreach ( [ 'FAHRT' => 'Klassenfahrt 2026', 'FOTO' => 'Website-Fotos' ] as $slug => $label ) {
		$type = WM_Edu_Consent_Types::get_by_slug( $slug );
		WM_Edu_Consent_Types::save( [ 'label' => $label, 'consent_text' => (string) WM_Edu_Consent_Types::text_for_scope( $type->scope_hash ), 'active' => false ], (int) $type->id );
	}
	WM_Edu_Consent_Form::reset();
	$empty = WM_Edu_Consent_Form::render();
	assert_test(
		$name,
		str_contains( $html, 'name="wm_edu_decision[FAHRT]" value="grant"' ) && str_contains( $html, 'name="wm_edu_decision[FOTO]" value="deny"' ) && ! str_contains( $html, 'wm_edu_decision[ALT]' )
		&& str_contains( $html, '<legend class="wm-edu-legend">Klassenfahrt 2026</legend>' ) && 1 === preg_match( '#<details class="wm-edu-details">.*nach Berlin teilnimmt\.</div></details>#s', $html )
		&& str_contains( $html, '<p class="wm-edu-hp" aria-hidden="true">' ) && str_contains( $html, 'tabindex="-1"' ) && str_contains( $html, 'name="wm_edu_read" value="1" required' )
		&& ! str_contains( $html, 'style=' ) && 0 === preg_match( '/(?<!&)#[0-9a-fA-F]{3,6}\b/', $html ) && 0 === preg_match( $emoji, $html )
		&& isset( $GLOBALS['edu_styles'][ WM_Edu_Shortcodes::STYLE_HANDLE ] ) && ! str_contains( $empty, '<form' ) && str_contains( $empty, 'keine Einwilligungen erteilt' ),
		$html
	);
} );

edu_case( 'an incomplete submission gets field errors linked to their fields; nothing is stored, nothing is mailed', function ( string $name ) {
	edu_seed_flow();
	$redirect = edu_form_request( [ 'wm_edu_action' => 'request', 'wm_edu_student_id' => '', 'wm_edu_email' => '"><b>keine-adresse', 'wm_edu_decision' => [ 'FAHRT' => 'vielleicht' ] ] );
	$html     = WM_Edu_Consent_Form::render();
	assert_test(
		$name,
		null === $redirect && 4 === substr_count( $html, 'class="wm-edu-error"' ) && str_contains( $html, 'id="wm-edu-student-1" name="wm_edu_student_id"' )
		&& str_contains( $html, 'aria-invalid="true" aria-describedby="wm-edu-student-1-error"' ) && str_contains( $html, 'href="#wm-edu-email-1"' ) && str_contains( $html, 'value="&quot;&gt;keine-adresse"' )
		&& [] === edu_requests() && [] === $GLOBALS['edu_mails'],
		$html
	);
} );

edu_case( 'registered and unregistered pairs get the same 303 answer; only the registered pair gets a mail and a pending request, which stores the SHA-256 of the token, never the token', function ( string $name ) {
	edu_seed_flow();
	$answers   = [
		edu_form_request( edu_guardian_post() ),
		edu_form_request( edu_guardian_post( [ 'wm_edu_student_id' => 'EDU-FREMD' ] ) ),
		edu_form_request( edu_guardian_post( [ 'wm_edu_email' => 'fremd@beispiel.de' ] ) ),
	];
	$targets   = array_map( static fn( ?EduRedirect $r ): string => null === $r ? 'none' : $r->location . ' ' . $r->status, $answers );
	$token     = edu_mail_token( WM_Edu_Consent_Requests::CONFIRM_PARAM, 0 );
	$requests  = edu_requests();
	$decisions = json_decode( (string) ( $requests[0]['decisions'] ?? '' ), true );
	$mail      = $GLOBALS['edu_mails'][0] ?? [];
	assert_test(
		$name,
		array_fill( 0, 3, 'https://schule.example/einwilligung/?wm_edu_status=requested 303' ) === $targets && 1 === count( $GLOBALS['edu_mails'] ) && 1 === count( $requests )
		&& 64 === strlen( $token ) && hash( 'sha256', $token ) === $requests[0]['confirm_hash'] && ! str_contains( (string) json_encode( $requests ), $token ) && 'pending' === $requests[0]['status']
		&& [ [ 'FAHRT', 'GRANTED' ], [ 'FOTO', 'DENIED' ] ] === array_map( static fn( array $d ): array => [ $d['slug'], $d['status'] ], (array) $decisions )
		&& 'Mutter@Beispiel.de' === $mail['to'] && str_contains( $mail['message'], 'Klassenfahrt 2026: erteilen' ) && str_contains( $mail['message'], 'Website-Fotos: verweigern' )
		&& str_contains( $mail['message'], 'Grundschule am Park & Hort' ) && str_contains( $mail['message'], '48 Stunden' ) && [ 'Content-Type: text/plain; charset=UTF-8' ] === $mail['headers']
		&& 0 === edu_count(),
		var_export( [ $targets, $requests, $GLOBALS['edu_mails'] ], true )
	);
} );

edu_case( 'rate limit: the submission over the hourly limit per address or per client IP is refused with 429 and no mail; counters are keyed by HMAC; the window restarts after an hour', function ( string $name ) {
	edu_seed_flow();
	WM_Edu_Settings::save( [ 'rate_ip_per_hour' => '3', 'rate_email_per_hour' => '2' ], [] );
	$per_address = [];
	foreach ( [ 1, 2, 3 ] as $i ) {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . $i;
		$per_address[]          = null !== edu_form_request( edu_guardian_post() );
	}
	$address_status = $GLOBALS['edu_status'];
	$address_html   = WM_Edu_Consent_Form::render();
	$mails          = count( $GLOBALS['edu_mails'] );
	$per_ip         = [];
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
	foreach ( [ 1, 2, 3, 4 ] as $i ) {
		$per_ip[] = null !== edu_form_request( edu_guardian_post( [ 'wm_edu_email' => "kind{$i}@beispiel.de" ] ) );
	}
	$keys = implode( ' ', array_keys( $GLOBALS['edu_transients'] ) );
	assert_test(
		$name,
		[ true, true, false ] === $per_address && 429 === $address_status && str_contains( $address_html, 'zu viele Anfragen' ) && 2 === $mails
		&& [ true, true, true, false ] === $per_ip && 429 === $GLOBALS['edu_status'] && ! str_contains( $keys, '@' ) && ! str_contains( $keys, '198.51' )
		&& false === WM_Edu_Consent_Requests::rate_limited( '198.51.100.7', 'neu@beispiel.de', time() + HOUR_IN_SECONDS + 1 ),
		var_export( [ $per_address, $per_ip, $keys ], true )
	);
} );

edu_case( 'the mailed link: a GET only shows the decisions; a POST without the read box records nothing; with it GRANTED and DENIED are recorded, a receipt with a revocation link is mailed, ID and address leave the request, and the link is used up', function ( string $name ) {
	global $wpdb;
	edu_seed_flow();
	edu_form_request( edu_guardian_post() );
	$token = edu_mail_token( WM_Edu_Consent_Requests::CONFIRM_PARAM );
	$get   = [ WM_Edu_Consent_Requests::CONFIRM_PARAM => $token ];
	edu_form_request( [], $get );
	$view        = WM_Edu_Consent_Form::render();
	$after_get   = edu_count();
	$no_box      = edu_form_request( [ 'wm_edu_action' => 'confirm', 'wm_edu_token' => $token ], $get );
	$no_box_html = WM_Edu_Consent_Form::render();
	$after_nobox = edu_count();
	$confirmed   = edu_form_request( [ 'wm_edu_action' => 'confirm', 'wm_edu_token' => $token, 'wm_edu_read_confirm' => '1' ], $get );
	$rows        = [ edu_row( 1 ), edu_row( 2 ) ];
	$receipt     = end( $GLOBALS['edu_mails'] );
	$revoke      = edu_mail_token( WM_Edu_Consent_Requests::REVOKE_PARAM );
	$request     = edu_requests()[0];
	$again       = edu_form_request( [ 'wm_edu_action' => 'confirm', 'wm_edu_token' => $token, 'wm_edu_read_confirm' => '1' ], $get );
	$again_html  = WM_Edu_Consent_Form::render();
	$fahrt       = WM_Edu_Consent_Types::get_by_slug( 'FAHRT' );
	assert_test(
		$name,
		str_contains( $view, 'Für die Schüler-ID EDU-9B-042' ) && str_contains( $view, 'name="wm_edu_token" value="' . $token . '"' ) && str_contains( $view, 'nach Berlin teilnimmt.' ) && 0 === $after_get
		&& null === $no_box && str_contains( $no_box_html, 'Wortlaut gelesen haben' ) && 0 === $after_nobox
		&& null !== $confirmed && 'https://schule.example/einwilligung/?wm_edu_status=confirmed' === $confirmed->location
		&& 'FAHRT' === $rows[0]['consent_type'] && 'GRANTED' === $rows[0]['granted_status'] && 'parent_confirmation' === $rows[0]['source'] && $fahrt->scope_hash === $rows[0]['scope_hash']
		&& 'Mutter@Beispiel.de' === $rows[0]['parent_email'] && hash_hmac( 'sha256', '203.0.113.50', wp_salt( 'auth' ) ) === $rows[0]['ip_hmac']
		&& 'FOTO' === $rows[1]['consent_type'] && 'DENIED' === $rows[1]['granted_status'] && 2 === edu_count() && true === WM_Edu_Consent_Vault::verify_chain()['valid']
		&& 'granted' === WM_Edu_Consent_Vault::consent_state( 'EDU-9B-042', 'FAHRT' ) && 'none' === WM_Edu_Consent_Vault::consent_state( 'EDU-9B-042', 'FOTO' )
		&& str_contains( $receipt['message'], 'Klassenfahrt 2026: erteilt' ) && str_contains( $receipt['message'], 'Website-Fotos: verweigert' ) && 64 === strlen( $revoke )
		&& 'confirmed' === $request['status'] && '' === $request['student_token'] && '' === $request['parent_email'] && hash( 'sha256', $revoke ) === $request['revoke_hash'] && '[1]' === $request['consent_ids']
		&& null === $again && str_contains( $again_html, 'ungültig, abgelaufen oder wurde bereits verwendet' ) && 2 === edu_count(),
		var_export( [ $rows, $request, $receipt ], true )
	);
} );

edu_case( 'an expired link records nothing, on GET or POST', function ( string $name ) {
	global $wpdb;
	edu_seed_flow();
	edu_form_request( edu_guardian_post() );
	$token = edu_mail_token( WM_Edu_Consent_Requests::CONFIRM_PARAM );
	$wpdb->update( 'wp_wm_edu_consent_requests', [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ], [ 'id' => 1 ] );
	$get      = [ WM_Edu_Consent_Requests::CONFIRM_PARAM => $token ];
	$redirect = edu_form_request( [ 'wm_edu_action' => 'confirm', 'wm_edu_token' => $token, 'wm_edu_read_confirm' => '1' ], $get );
	$html     = WM_Edu_Consent_Form::render();
	assert_test( $name, null === $redirect && 0 === edu_count() && str_contains( $html, 'ungültig, abgelaufen' ) && ! str_contains( $html, 'name="wm_edu_token"' ), $html );
} );

edu_case( 'a locked ledger answers 503 and keeps the link; a failure after the first decision keeps it recorded and the retry records only the rest', function ( string $name ) {
	global $wpdb;
	edu_seed_flow();
	edu_form_request( edu_guardian_post() );
	$token             = edu_mail_token( WM_Edu_Consent_Requests::CONFIRM_PARAM );
	$get               = [ WM_Edu_Consent_Requests::CONFIRM_PARAM => $token ];
	$post              = [ 'wm_edu_action' => 'confirm', 'wm_edu_token' => $token, 'wm_edu_read_confirm' => '1' ];
	$wpdb->lock_result = '0';
	$locked            = edu_form_request( $post, $get );
	$locked_status     = $GLOBALS['edu_status'];
	$locked_html       = WM_Edu_Consent_Form::render();
	$locked_request    = edu_requests()[0]['status'];
	$wpdb->lock_result = '1';
	$wpdb->fail_insert_on = 2;
	$partial           = edu_form_request( $post, $get );
	$partial_count     = edu_count();
	$partial_status    = $GLOBALS['edu_status'];
	$retry             = edu_form_request( $post, $get );
	assert_test(
		$name,
		null === $locked && 503 === $locked_status && str_contains( $locked_html, 'der Link bleibt gültig' ) && 'pending' === $locked_request
		&& null === $partial && 1 === $partial_count && 503 === $partial_status
		&& null !== $retry && 2 === edu_count() && 'FAHRT' === edu_row( 1 )['consent_type'] && 'FOTO' === edu_row( 2 )['consent_type'] && true === WM_Edu_Consent_Vault::verify_chain()['valid'],
		var_export( [ $locked_status, $partial_status, edu_requests() ], true )
	);
} );

edu_case( 'a link that another request is confirming right now answers busy and records nothing', function ( string $name ) {
	global $wpdb;
	edu_seed_flow();
	edu_form_request( edu_guardian_post() );
	$token = edu_mail_token( WM_Edu_Consent_Requests::CONFIRM_PARAM );
	$wpdb->update( 'wp_wm_edu_consent_requests', [ 'status' => 'confirming' ], [ 'id' => 1 ] );
	$redirect = edu_form_request( [ 'wm_edu_action' => 'confirm', 'wm_edu_token' => $token, 'wm_edu_read_confirm' => '1' ], [ WM_Edu_Consent_Requests::CONFIRM_PARAM => $token ] );
	assert_test( $name, null === $redirect && 503 === $GLOBALS['edu_status'] && 0 === edu_count() && 'confirming' === edu_requests()[0]['status'] );
} );

edu_case( 'the revocation link: a GET lists the granted consents and records nothing; a POST records REVOKED from parent_revocation, mails a receipt, and the link is used up', function ( string $name ) {
	edu_seed_flow();
	edu_form_request( edu_guardian_post() );
	$token = edu_mail_token( WM_Edu_Consent_Requests::CONFIRM_PARAM );
	edu_form_request( [ 'wm_edu_action' => 'confirm', 'wm_edu_token' => $token, 'wm_edu_read_confirm' => '1' ], [ WM_Edu_Consent_Requests::CONFIRM_PARAM => $token ] );
	$revoke = edu_mail_token( WM_Edu_Consent_Requests::REVOKE_PARAM );
	$get    = [ WM_Edu_Consent_Requests::REVOKE_PARAM => $revoke ];
	edu_form_request( [], $get );
	$view      = WM_Edu_Consent_Form::render();
	$after_get = edu_count();
	$revoked   = edu_form_request( [ 'wm_edu_action' => 'revoke', 'wm_edu_token' => $revoke ], $get );
	$row       = edu_row( 3 );
	$mail      = end( $GLOBALS['edu_mails'] );
	$request   = edu_requests()[0];
	$again     = edu_form_request( [ 'wm_edu_action' => 'revoke', 'wm_edu_token' => $revoke ], $get );
	$again_msg = WM_Edu_Consent_Form::render();
	assert_test(
		$name,
		str_contains( $view, '<li class="wm-edu-decision">Klassenfahrt 2026</li>' ) && ! str_contains( $view, 'Fotos' ) && str_contains( $view, 'value="revoke"' ) && 2 === $after_get
		&& null !== $revoked && str_ends_with( $revoked->location, 'wm_edu_status=revoked' ) && 'REVOKED' === $row['granted_status'] && 'FAHRT' === $row['consent_type'] && 'parent_revocation' === $row['source']
		&& edu_row( 1 )['scope_hash'] === $row['scope_hash'] && 'Mutter@Beispiel.de' === $row['parent_email'] && 'none' === WM_Edu_Consent_Vault::consent_state( 'EDU-9B-042', 'FAHRT' )
		&& true === WM_Edu_Consent_Vault::verify_chain()['valid'] && str_contains( $mail['subject'], 'Widerruf' ) && str_contains( $mail['message'], '- Klassenfahrt 2026' )
		&& 'revoked' === $request['status'] && '[]' === $request['consent_ids'] && null === $again && str_contains( $again_msg, 'ungültig, abgelaufen oder wurde bereits verwendet' ) && 3 === edu_count(),
		var_export( [ $row, $request, $mail ], true )
	);
} );

edu_case( 'a claim another request won (the UPDATE … WHERE status changes no row) records nothing, for confirmation and for revocation', function ( string $name ) {
	global $wpdb;
	edu_seed_flow();
	edu_form_request( edu_guardian_post() );
	$token           = edu_mail_token( WM_Edu_Consent_Requests::CONFIRM_PARAM );
	$wpdb->zero_rows = "/SET status = 'confirming'/";
	$lost_confirm    = WM_Edu_Consent_Requests::confirm( $token, '203.0.113.50', 'https://schule.example/einwilligung/' );
	$after_confirm   = edu_count();
	WM_Edu_Consent_Requests::confirm( $token, '203.0.113.50', 'https://schule.example/einwilligung/' );
	$revoke          = edu_mail_token( WM_Edu_Consent_Requests::REVOKE_PARAM );
	$wpdb->zero_rows = "/SET status = 'revoking'/";
	$lost_revoke     = WM_Edu_Consent_Requests::revoke( $revoke, '203.0.113.50' );
	assert_test( $name, 'busy' === $lost_confirm && 0 === $after_confirm && 'busy' === $lost_revoke && 2 === edu_count(), var_export( [ $lost_confirm, $lost_revoke ], true ) );
} );

edu_case( 'a filled honeypot gets the usual answer and nothing else: no request, no mail', function ( string $name ) {
	edu_seed_flow();
	$redirect = edu_form_request( edu_guardian_post( [ 'wm_edu_website' => 'https://spam.example' ] ) );
	assert_test( $name, null !== $redirect && str_ends_with( $redirect->location, 'wm_edu_status=requested' ) && [] === edu_requests() && [] === $GLOBALS['edu_mails'] );
} );

edu_case( 'revocation: an interrupted revocation keeps what it revoked and the retry revokes only the rest; a granted row that is gone from the ledger is an error, not a success', function ( string $name ) {
	global $wpdb;
	edu_seed_flow();
	$confirm_both = static function () use ( &$revoke_token ): void {
		edu_form_request( edu_guardian_post( [ 'wm_edu_decision' => [ 'FAHRT' => 'grant', 'FOTO' => 'grant' ] ] ) );
		$token = edu_mail_token( WM_Edu_Consent_Requests::CONFIRM_PARAM );
		WM_Edu_Consent_Requests::confirm( $token, '203.0.113.50', 'https://schule.example/einwilligung/' );
		$revoke_token = edu_mail_token( WM_Edu_Consent_Requests::REVOKE_PARAM );
	};
	$revoke_token = '';
	$confirm_both();
	$wpdb->fail_insert_on = 2;
	$interrupted          = WM_Edu_Consent_Requests::revoke( $revoke_token, '203.0.113.50' );
	$after_interruption   = edu_count();
	$retry                = WM_Edu_Consent_Requests::revoke( $revoke_token, '203.0.113.50' );
	$after_retry          = edu_count();
	$state_after_retry    = WM_Edu_Consent_Vault::consent_state( 'EDU-9B-042', 'FAHRT' );

	$confirm_both();
	edu_delete_row( 6 );
	$missing = WM_Edu_Consent_Requests::revoke( $revoke_token, '203.0.113.50' );
	assert_test(
		$name,
		'busy' === $interrupted && 3 === $after_interruption && 'revoked' === $retry && 4 === $after_retry && 'none' === $state_after_retry
		&& 'error' === $missing && 'confirmed' === edu_requests()[1]['status'],
		var_export( [ $interrupted, $after_interruption, $retry, $after_retry, $missing, edu_requests() ], true )
	);
} );

edu_case( 'the form sheet: error text at least 4.5:1 on the box, choices reach the target size, the honeypot is hidden with tokens only', function ( string $name ) use ( $css, $tokens ) {
	assert_test(
		$name,
		edu_contrast( $tokens['error-text'] ?? '#000', $tokens['surface'] ?? '#000' ) >= 4.5 && 1 === preg_match( '/\.wm-edu-choice \{[^}]*min-block-size: var\(--edu-target\);/', $css )
		&& 1 === preg_match( '/\.wm-edu-hp \{[^}]*clip-path: inset\(50%\);/', $css ),
		(string) edu_contrast( $tokens['error-text'] ?? '#000', $tokens['surface'] ?? '#000' )
	);
} );

edu_case( 'a confirmation mail that cannot be sent leaves no request behind', function ( string $name ) {
	edu_seed_flow();
	$GLOBALS['edu_mail_ok'] = false;
	$redirect               = edu_form_request( edu_guardian_post() );
	$GLOBALS['edu_mail_ok'] = true;
	assert_test( $name, null !== $redirect && str_ends_with( $redirect->location, 'wm_edu_status=requested' ) && [] === edu_requests() );
} );

edu_case( 'retention: expired pending requests and closed requests go after the period, open revocation links and fresh requests stay, stale claims are released, the ledger is untouched', function ( string $name ) {
	global $wpdb;
	edu_seed_flow();
	edu_consent();
	$now = time();
	$row = static function ( string $status, int $expires, int $updated, string $revoke = '' ) use ( $wpdb ): void {
		$wpdb->insert( 'wp_wm_edu_consent_requests', [ 'confirm_hash' => bin2hex( random_bytes( 32 ) ), 'revoke_hash' => $revoke, 'status' => $status, 'student_token' => '', 'parent_email' => '', 'decisions' => '[]', 'consent_ids' => '[]', 'created_at' => gmdate( 'Y-m-d H:i:s', $updated ), 'expires_at' => gmdate( 'Y-m-d H:i:s', $expires ), 'updated_at' => gmdate( 'Y-m-d H:i:s', $updated ) ] );
	};
	$day = DAY_IN_SECONDS;
	$row( 'pending', $now - 8 * $day, $now - 10 * $day );                       // 1 deleted
	$row( 'pending', $now - 6 * $day, $now - 8 * $day );                        // 2 kept (expired 6 days ago)
	$row( 'revoked', $now + $day, $now - 8 * $day );                            // 3 deleted
	$row( 'confirmed', $now + $day, $now - 8 * $day );                          // 4 deleted (no revocation link)
	$row( 'confirmed', $now + $day, $now - 8 * $day, str_repeat( 'c', 64 ) );   // 5 kept
	$row( 'confirming', $now + $day, $now - 7200 );                             // 6 released to pending
	$row( 'revoking', $now + $day, $now - 7200, str_repeat( 'd', 64 ) );        // 7 released to confirmed
	$row( 'confirming', $now + $day, $now - 600 );                              // 8 still claimed
	$first  = WM_Edu_Consent_Requests::cleanup( $now );
	$left   = array_map( static fn( array $r ): string => $r['id'] . ':' . $r['status'], edu_requests() );
	WM_Edu_Settings::save( [ 'retention_days' => '0' ], [] );
	$second = WM_Edu_Consent_Requests::cleanup( $now );
	assert_test(
		$name,
		[ 'pending_deleted' => 1, 'closed_deleted' => 2, 'claims_released' => 2 ] === $first && [ '2:pending', '5:confirmed', '6:pending', '7:confirmed', '8:confirming' ] === $left
		&& 1 === $second['pending_deleted'] && 1 === edu_count(),
		var_export( [ $first, $left, $second ], true )
	);
} );

edu_case( 'the retention event is scheduled on plugins_loaded once and runs cleanup; deactivation and uninstall remove it; wp edu cleanup prints the counts', function ( string $name ) use ( $root ) {
	edu_seed_flow();
	$GLOBALS['edu_cron']        = [];
	$GLOBALS['edu_cron_writes'] = [];
	WM_Edu_Consent_Requests::sync_schedule();
	WM_Edu_Consent_Requests::sync_schedule();
	$scheduled = $GLOBALS['edu_cron'][ WM_Edu_Consent_Requests::CRON_HOOK ]['recurrence'] ?? '';
	WM_Edu_Consent_Requests::unschedule();
	$after_deactivation = wp_next_scheduled( WM_Edu_Consent_Requests::CRON_HOOK );
	WM_Edu_Consent_Requests::sync_schedule();
	require $root . '/uninstall.php';
	WP_CLI::$out = [];
	( new WM_Edu_CLI() )->cleanup( [], [] );
	assert_test(
		$name,
		in_array( [ 'WM_Edu_Consent_Requests', 'sync_schedule' ], $GLOBALS['edu_actions']['plugins_loaded'] ?? [], true )
		&& in_array( [ 'WM_Edu_Consent_Requests', 'cleanup' ], $GLOBALS['edu_actions'][ WM_Edu_Consent_Requests::CRON_HOOK ] ?? [], true )
		&& in_array( [ 'WM_Edu_Consent_Requests', 'unschedule' ], $GLOBALS['edu_deactivation'] ?? [], true )
		&& 'daily' === $scheduled && [ WM_Edu_Consent_Requests::CRON_HOOK, WM_Edu_Consent_Requests::CRON_HOOK ] === $GLOBALS['edu_cron_writes'] && false === $after_deactivation
		&& false === wp_next_scheduled( WM_Edu_Consent_Requests::CRON_HOOK ) && in_array( [ 'line', 'Abgelaufene Anfragen gelöscht:    0' ], WP_CLI::$out, true ) && 'success' === end( WP_CLI::$out )[0],
		var_export( [ $GLOBALS['edu_cron_writes'], WP_CLI::$out ], true )
	);
} );

edu_case( 'token links are sent with no-cache and Referrer-Policy no-referrer; submissions are processed on template_redirect, never while the shortcode renders', function ( string $name ) {
	edu_seed_flow();
	edu_form_request( [], [ WM_Edu_Consent_Requests::REVOKE_PARAM => str_repeat( 'a', 64 ) ] );
	$headers = $GLOBALS['edu_nocache'];
	WM_Edu_Consent_Form::reset();
	$_POST = edu_guardian_post();
	WM_Edu_Consent_Form::render();
	WM_Edu_Consent_Form::render();
	assert_test(
		$name,
		'no-referrer' === ( $headers['Referrer-Policy'] ?? '' ) && [] === $GLOBALS['edu_mails'] && [] === edu_requests()
		&& in_array( [ 'WM_Edu_Consent_Form', 'handle_request' ], $GLOBALS['edu_actions']['template_redirect'] ?? [], true ) && isset( $GLOBALS['edu_shortcodes'][ WM_Edu_Consent_Form::SHORTCODE ] ),
		var_export( $headers, true )
	);
} );

// =====================================================================================================================
echo "\n[GROUP 17] Admin screen: entries, revocation, types, guardians, export, guards\n";
// =====================================================================================================================

/** Runs an admin-post handler and returns the redirect target, 'die' when a guard stopped it, or 'none'. */
function edu_admin_handler( string $handler, array $post ): string {
	$_POST = $post;
	try {
		WM_Edu_Admin::$handler();
	} catch ( EduRedirect $redirect ) {
		return $redirect->location;
	} catch ( EduDie $die ) {
		return 'die';
	} finally {
		$_POST = [];
	}
	return 'none';
}

function edu_admin_page( string $page, array $get = [] ): string {
	$_GET = $get;
	ob_start();
	WM_Edu_Admin::$page();
	$html = (string) ob_get_clean();
	$_GET = [];
	return $html;
}

edu_case( 'the menu registers the five pages behind manage_edu_consents, the settings behind manage_options, and every handler on admin_post', function ( string $name ) {
	$GLOBALS['edu_menu'] = [];
	WM_Edu_Admin::menu();
	$pages    = array_map( static fn( array $entry ): string => $entry[1] . ':' . $entry[2], $GLOBALS['edu_menu'] );
	$handlers = array_map( static fn( string $action ): bool => in_array( [ 'WM_Edu_Admin', 'handle_' . $action ], $GLOBALS['edu_actions'][ 'admin_post_wm_edu_' . $action ] ?? [], true ), [ 'record', 'revoke', 'type', 'guardian', 'settings', 'export' ] );
	assert_test(
		$name,
		[
			'wm-edu-consents:manage_edu_consents',
			'wm-edu-consents:manage_edu_consents',
			'wm-edu-consents-entry:manage_edu_consents',
			'wm-edu-consents-types:manage_edu_consents',
			'wm-edu-consents-guardians:manage_edu_consents',
			'wm-edu-consents-settings:manage_options',
		] === $pages && array_fill( 0, 6, true ) === $handlers,
		var_export( [ $pages, $handlers ], true )
	);
} );

edu_case( 'a manual entry is signed with the acting user, the reason and the type\'s current wording; an unknown type, a bad status or a missing reason records nothing', function ( string $name ) {
	edu_seed_flow();
	$fahrt    = WM_Edu_Consent_Types::get_by_slug( 'FAHRT' );
	$complete = [ 'student_token' => 'EDU-9B-042', 'parent_email' => 'mutter@beispiel.de', 'type' => 'FAHRT', 'status' => 'GRANTED', 'note' => 'Unterschriebenes Formular vom 12.09.2026, Sekretariat.' ];
	$refused  = [
		WM_Edu_Admin::process_record( array_merge( $complete, [ 'type' => 'GIBTESNICHT' ] ), 7, '203.0.113.50' ),
		WM_Edu_Admin::process_record( array_merge( $complete, [ 'status' => 'granted' ] ), 7, '203.0.113.50' ),
		WM_Edu_Admin::process_record( array_merge( $complete, [ 'note' => '  ' ] ), 7, '203.0.113.50' ),
		WM_Edu_Admin::process_record( array_merge( $complete, [ 'parent_email' => 'keine-adresse' ] ), 7, '203.0.113.50' ),
		WM_Edu_Admin::process_record( $complete, 0, '203.0.113.50' ),
	];
	$empty  = edu_count();
	$code   = WM_Edu_Admin::process_record( $complete, 7, '203.0.113.50' );
	$row    = edu_row( 1 );
	$target = edu_admin_handler( 'handle_record', array_merge( $complete, [ 'student_token' => 'EDU-9B-043' ] ) );
	assert_test(
		$name,
		[ 'type_unknown', 'status_invalid', 'entry_invalid', 'entry_invalid', 'entry_invalid' ] === $refused && 0 === $empty
		&& 'recorded' === $code && 'admin_entry' === $row['source'] && '7' === $row['actor_id'] && str_starts_with( $row['note'], 'Unterschriebenes Formular' )
		&& $fahrt->scope_hash === $row['scope_hash'] && 'GRANTED' === $row['granted_status'] && true === WM_Edu_Consent_Vault::verify_chain()['valid']
		&& str_contains( $target, 'page=wm-edu-consents-entry&wm_edu_notice=recorded' ) && 2 === edu_count(),
		var_export( [ $refused, $row, $target ], true )
	);
} );

edu_case( 'the admin revocation copies address and wording from the newest entry and needs a reason; without an entry it says so, and a pupil ID in another spelling is not one', function ( string $name ) {
	edu_seed_flow();
	WM_Edu_Admin::process_record( [ 'student_token' => 'EDU-9B-042', 'parent_email' => 'mutter@beispiel.de', 'type' => 'FAHRT', 'status' => 'GRANTED', 'note' => 'Formular vom 12.09.2026.' ], 7, '203.0.113.50' );
	$unknown = [
		WM_Edu_Admin::process_revoke( [ 'student_token' => 'EDU-9B-042', 'type' => 'FOTO', 'note' => 'Anruf' ], 7, '203.0.113.50' ),
		WM_Edu_Admin::process_revoke( [ 'student_token' => 'edu-9b-042', 'type' => 'FAHRT', 'note' => 'Anruf' ], 7, '203.0.113.50' ),
		WM_Edu_Admin::process_revoke( [ 'student_token' => 'EDU-9B-042', 'type' => 'FAHRT', 'note' => '' ], 7, '203.0.113.50' ),
	];
	$code = WM_Edu_Admin::process_revoke( [ 'student_token' => 'EDU-9B-042', 'type' => 'fahrt', 'note' => 'Telefonischer Widerruf der Mutter am 16.09.2026.' ], 7, '203.0.113.50' );
	$row  = edu_row( 2 );
	assert_test(
		$name,
		[ 'entry_unknown', 'entry_unknown', 'entry_invalid' ] === $unknown && 'revoked' === $code
		&& 'REVOKED' === $row['granted_status'] && 'admin_revocation' === $row['source'] && 'mutter@beispiel.de' === $row['parent_email']
		&& edu_row( 1 )['scope_hash'] === $row['scope_hash'] && '7' === $row['actor_id'] && 'none' === WM_Edu_Consent_Vault::consent_state( 'EDU-9B-042', 'FAHRT' ),
		var_export( [ $unknown, $row ], true )
	);
} );

edu_case( 'the overview lists the newest row per pupil and type, the history of one pupil exactly, marks a wording that changed, and escapes what the register holds', function ( string $name ) {
	edu_seed_flow();
	$note = 'Mail von <script>alert(1)</script> "Mutter"';
	WM_Edu_Admin::process_record( [ 'student_token' => 'EDU-1', 'parent_email' => 'a@beispiel.de', 'type' => 'FAHRT', 'status' => 'GRANTED', 'note' => $note ], 7, '203.0.113.50' );
	WM_Edu_Admin::process_record( [ 'student_token' => 'EDU-1', 'parent_email' => 'a@beispiel.de', 'type' => 'FOTO', 'status' => 'DENIED', 'note' => 'Papier' ], 7, '203.0.113.50' );
	WM_Edu_Admin::process_revoke( [ 'student_token' => 'EDU-1', 'type' => 'FAHRT', 'note' => 'Anruf' ], 7, '203.0.113.50' );
	WM_Edu_Admin::process_record( [ 'student_token' => 'edu-1', 'parent_email' => 'b@beispiel.de', 'type' => 'FAHRT', 'status' => 'GRANTED', 'note' => 'Andere Schreibweise' ], 7, '203.0.113.50' );
	$current = WM_Edu_Consent_Vault::current_entries();
	$history = WM_Edu_Consent_Vault::rows_for_student( 'EDU-1' );
	$fahrt   = WM_Edu_Consent_Types::get_by_slug( 'FAHRT' );
	WM_Edu_Consent_Types::save( [ 'label' => 'Klassenfahrt 2026', 'consent_text' => 'Neuer Wortlaut nach der Änderung.', 'active' => true ], (int) $fahrt->id );
	$overview = edu_admin_page( 'page_overview' );
	$detail   = edu_admin_page( 'page_overview', [ 'page' => 'wm-edu-consents', 'wm_edu_student' => 'EDU-1' ] );
	assert_test(
		$name,
		3 === count( $current ) && [ 'EDU-1|FAHRT|REVOKED', 'EDU-1|FOTO|DENIED', 'edu-1|FAHRT|GRANTED' ] === array_map( static fn( object $r ): string => $r->student_token . '|' . $r->consent_type . '|' . $r->granted_status, $current )
		&& 3 === count( $history ) && str_contains( $overview, 'seit der Erteilung geändert' ) && str_contains( $overview, 'Signaturkette geprüft: 4 Einträge' )
		&& str_contains( $detail, 'Verlauf für EDU-1' ) && ! str_contains( $detail, 'Andere Schreibweise' )
		&& str_contains( $detail, esc_html( (string) $history[0]->note ) ) && str_contains( $history[0]->note, 'alert(1)' ) && ! str_contains( $detail, '<script>' ),
		var_export( array_map( static fn( object $r ): array => (array) $r, $current ), true )
	);
} );

edu_case( 'the export quotes every cell, doubles quotes inside, neutralises the characters a spreadsheet reads as a formula and carries a byte order mark', function ( string $name ) {
	$rows = [
		(object) [ 'id' => '1', 'created_at' => '2026-09-17 10:00:00', 'student_token' => 'EDU-1', 'parent_email' => 'a@beispiel.de', 'consent_type' => 'FAHRT', 'granted_status' => 'GRANTED', 'scope_hash' => 'abc', 'source' => 'admin_entry', 'actor_id' => '7', 'note' => 'Sie sagte "ja"; mit Semikolon', 'ip_hmac' => 'h', 'key_id' => 'k', 'row_version' => '3', 'prev_hash' => 'p', 'signature_hash' => 's' ],
		(object) [ 'id' => '2', 'note' => '=1+1', 'student_token' => '-EDU', 'parent_email' => "@rolle", 'consent_type' => "\tTab", 'granted_status' => "\nZeile" ],
	];
	$csv   = WM_Edu_Admin::csv( $rows );
	$lines = explode( "\r\n", $csv );
	assert_test(
		$name,
		str_starts_with( $csv, "\xEF\xBB\xBF" ) && str_starts_with( $lines[0], "\xEF\xBB\xBF\"id\";\"created_at\";\"student_token\"" )
		&& str_contains( $lines[1], '"Sie sagte ""ja""; mit Semikolon"' ) && str_contains( $lines[2], '"\'=1+1"' ) && str_contains( $lines[2], '"\'-EDU"' )
		&& str_contains( $lines[2], '"\'@rolle"' ) && str_contains( $lines[2], "\"'\tTab\"" ) && str_contains( $lines[2], "\"'\nZeile\"" ) && 4 === count( $lines ),
		var_export( $lines, true )
	);
} );

edu_case( 'every handler refuses without the capability and without a valid nonce; the settings need manage_options, not the consent capability', function ( string $name ) {
	edu_seed_flow();
	$post                    = [ 'student_token' => 'EDU-1', 'parent_email' => 'a@beispiel.de', 'type' => 'FAHRT', 'status' => 'GRANTED', 'note' => 'Formular' ];
	$GLOBALS['edu_caps']     = [];
	$without_capability      = array_map( static fn( string $h ): string => edu_admin_handler( $h, $post ), [ 'handle_record', 'handle_revoke', 'handle_type', 'handle_guardian', 'handle_settings', 'handle_export' ] );
	$GLOBALS['edu_caps']     = [ WM_Edu_Access::CAPABILITY => true ];
	$settings_without_option = edu_admin_handler( 'handle_settings', [ 'public_gate' => '1' ] );
	$GLOBALS['edu_caps']     = null;
	$GLOBALS['edu_nonce_ok'] = false;
	$without_nonce           = edu_admin_handler( 'handle_record', $post );
	$GLOBALS['edu_nonce_ok'] = true;
	$rows_after              = edu_count();
	$settings_after          = WM_Edu_Settings::all()['public_gate'];
	assert_test(
		$name,
		array_fill( 0, 6, 'die' ) === $without_capability && 'die' === $settings_without_option && 'die' === $without_nonce && 0 === $rows_after && false === $settings_after,
		var_export( [ $without_capability, $settings_without_option, $without_nonce ], true )
	);
} );

edu_case( 'the type and guardian pages report what happened by code, and the settings handler saves through the known roles', function ( string $name ) {
	edu_fresh_db();
	$type_codes = [
		WM_Edu_Admin::process_type( [ 'slug' => 'AG_2026', 'label' => 'Arbeitsgemeinschaft', 'consent_text' => 'Teilnahme an der AG.', 'active' => '1' ] ),
		WM_Edu_Admin::process_type( [ 'slug' => 'AG_2026', 'label' => 'Doppelt', 'consent_text' => 'Text' ] ),
		WM_Edu_Admin::process_type( [ 'slug' => 'mit leerzeichen', 'label' => 'Ungültig', 'consent_text' => 'Text' ] ),
		WM_Edu_Admin::process_type( [ 'id' => '1', 'label' => 'Arbeitsgemeinschaft 2026', 'consent_text' => 'Teilnahme an der AG.', 'active' => '1' ] ),
	];
	$guardian_codes = [
		WM_Edu_Admin::process_guardian( [ 'what' => 'add', 'student_token' => 'EDU-1', 'email' => 'a@beispiel.de' ] ),
		WM_Edu_Admin::process_guardian( [ 'what' => 'add', 'student_token' => 'EDU-1', 'email' => 'A@beispiel.de' ] ),
		WM_Edu_Admin::process_guardian( [ 'what' => 'add', 'student_token' => '', 'email' => 'a@beispiel.de' ] ),
		WM_Edu_Admin::process_guardian( [ 'what' => 'import', 'pairs' => "EDU-2;b@beispiel.de\nkaputt" ] ),
		WM_Edu_Admin::process_guardian( [ 'what' => 'remove', 'student_token' => 'EDU-1', 'email' => 'a@beispiel.de' ] ),
		WM_Edu_Admin::process_guardian( [ 'what' => 'remove', 'student_token' => 'EDU-1', 'email' => 'a@beispiel.de' ] ),
		WM_Edu_Admin::process_guardian( [ 'what' => 'remove_student', 'student_token' => 'EDU-2' ] ),
	];
	$target   = edu_admin_handler( 'handle_settings', [ 'public_gate' => '1', 'gate_roles' => [ 'teacher', 'unbekannt' ], 'confirm_hours' => '24' ] );
	$settings = WM_Edu_Settings::all();
	assert_test(
		$name,
		[ 'type_created', 'type_exists', 'type_invalid', 'type_updated' ] === $type_codes
		&& [ 'guardian_added', 'guardian_exists', 'guardian_invalid', 'guardians_partly', 'guardian_removed', 'guardian_unknown', 'guardian_removed' ] === $guardian_codes
		&& str_contains( $target, 'wm_edu_notice=settings_saved' ) && true === $settings['public_gate'] && [ 'teacher' ] === $settings['gate_roles'] && 24 === $settings['confirm_hours'],
		var_export( [ $type_codes, $guardian_codes, $settings ], true )
	);
} );

edu_case( 'a notice code the plugin does not know prints nothing, and a register that does not verify is said so above every page', function ( string $name ) {
	global $wpdb;
	edu_seed_flow();
	edu_consent();
	$unknown = edu_admin_page( 'page_overview', [ 'wm_edu_notice' => 'boom<script>alert(1)</script>' ] );
	$wpdb->update( edu_table(), [ 'note' => 'nachträglich geändert' ], [ 'id' => 1 ] );
	$broken = edu_admin_page( 'page_overview' );
	assert_test(
		$name,
		! str_contains( $unknown, 'boom' ) && ! str_contains( $unknown, 'alert(1)' ) && ! str_contains( $unknown, 'notice-error' )
		&& str_contains( $broken, 'Das Register ist nicht prüfbar' ) && str_contains( $broken, 'Signatur-Ungültigkeit bei Einwilligung-ID 1' ) && ! str_contains( $broken, 'Signaturkette geprüft' ),
		substr( $broken, 0, 300 )
	);
} );

edu_case( 'the admin pages render with a nonce field, the export link carries one, and no page prints an inline style', function ( string $name ) {
	edu_seed_flow();
	$pages = [ edu_admin_page( 'page_overview' ), edu_admin_page( 'page_entry' ), edu_admin_page( 'page_types' ), edu_admin_page( 'page_guardians' ), edu_admin_page( 'page_settings' ) ];
	$html  = implode( '', $pages );
	assert_test(
		$name,
		5 === count( array_filter( $pages, static fn( string $p ): bool => str_contains( $p, '<div class="wrap">' ) ) )
		&& 7 === substr_count( $html, 'action="https://schule.example/wp-admin/admin-post.php"' ) && 7 === substr_count( $html, 'name="_wpnonce" value="nonce-' )
		&& str_contains( $html, 'admin-post.php?action=wm_edu_export' ) && str_contains( $pages[0], '_wpnonce=nonce-' ) && ! str_contains( $html, 'style=' )
		&& str_contains( $pages[4], 'name="public_tokenizer"' ) && str_contains( $pages[2], 'name="consent_text"' ) && str_contains( $pages[3], 'name="pairs"' ),
		substr( $html, 0, 400 )
	);
} );

// =====================================================================================================================
echo "\n[GROUP 18] Access: the lookup and the tokenizer are no longer public\n";
// =====================================================================================================================

/** Runs $fn as a visitor with the given login state, capabilities and roles, then restores the administrator. */
function edu_as( bool $logged_in, ?array $caps, array $roles, callable $fn ) {
	$before = [ $GLOBALS['edu_logged_in'], $GLOBALS['edu_caps'], $GLOBALS['edu_user_roles'] ];
	[ $GLOBALS['edu_logged_in'], $GLOBALS['edu_caps'], $GLOBALS['edu_user_roles'] ] = [ $logged_in, $caps, $roles ];
	try {
		return $fn();
	} finally {
		[ $GLOBALS['edu_logged_in'], $GLOBALS['edu_caps'], $GLOBALS['edu_user_roles'] ] = $before;
	}
}

edu_case( 'the lookup is closed by default: a visitor gets a login link, a user without the capability a refusal, a user with it the form; the public setting brings the old behaviour back', function ( string $name ) {
	edu_seed_flow();
	edu_consent();
	$_POST   = [ 'edu_consent_check' => '1', 'edu_student_id' => 'EDU-001' ];
	$visitor = edu_as( false, [], [], static fn(): string => WM_Edu_Shortcodes::render_consent_gate() );
	$user    = edu_as( true, [ 'edit_posts' => true ], [ 'editor' ], static fn(): string => WM_Edu_Shortcodes::render_consent_gate() );
	$manager = edu_as( true, [ WM_Edu_Access::CAPABILITY => true ], [ 'administrator' ], static fn(): string => WM_Edu_Shortcodes::render_consent_gate() );
	WM_Edu_Settings::save( [ 'public_gate' => '1' ], [] );
	$public = edu_as( false, [], [], static fn(): string => WM_Edu_Shortcodes::render_consent_gate() );
	$_POST  = [];
	assert_test(
		$name,
		! str_contains( $visitor, '<form' ) && str_contains( $visitor, 'Berechtigung offen' ) && str_contains( $visitor, 'wp-login.php' ) && ! str_contains( $visitor, 'GÜLTIGE' )
		&& ! str_contains( $user, '<form' ) && ! str_contains( $user, 'wp-login.php' ) && ! str_contains( $user, 'GÜLTIGE' )
		&& str_contains( $manager, '<form' ) && str_contains( $manager, 'GÜLTIGE EINWILLIGUNG' )
		&& str_contains( $public, '<form' ) && str_contains( $public, 'GÜLTIGE EINWILLIGUNG' ),
		$user
	);
} );

edu_case( 'a role named in the settings may use the lookup, a role that is not may not', function ( string $name ) {
	edu_seed_flow();
	WM_Edu_Settings::save( [ 'gate_roles' => [ 'teacher' ] ], [ 'administrator', 'editor', 'teacher' ] );
	$teacher = edu_as( true, [], [ 'teacher' ], static fn(): bool => WM_Edu_Access::can_use_gate() );
	$editor  = edu_as( true, [], [ 'editor' ], static fn(): bool => WM_Edu_Access::can_use_gate() );
	$visitor = edu_as( false, [], [ 'teacher' ], static fn(): bool => WM_Edu_Access::can_use_gate() );
	assert_test( $name, true === $teacher && false === $editor && false === $visitor, var_export( [ $teacher, $editor, $visitor ], true ) );
} );

edu_case( 'the lookup form of a logged-in user carries a nonce, and without a valid one no lookup runs', function ( string $name ) {
	edu_seed_flow();
	edu_consent();
	$_POST                   = [ 'edu_consent_check' => '1', 'edu_student_id' => 'EDU-001' ];
	$with_nonce              = WM_Edu_Shortcodes::render_consent_gate();
	$GLOBALS['edu_nonce_ok'] = false;
	$without_nonce           = WM_Edu_Shortcodes::render_consent_gate();
	WM_Edu_Settings::save( [ 'public_gate' => '1' ], [] );
	$public_visitor          = edu_as( false, [], [], static fn(): string => WM_Edu_Shortcodes::render_consent_gate() );
	$GLOBALS['edu_nonce_ok'] = true;
	$_POST                   = [];
	assert_test(
		$name,
		str_contains( $with_nonce, 'name="wm_edu_gate_nonce" value="nonce-' ) && str_contains( $with_nonce, 'GÜLTIGE EINWILLIGUNG' )
		&& str_contains( $without_nonce, 'Sicherheitsprüfung ist fehlgeschlagen' ) && ! str_contains( $without_nonce, 'GÜLTIGE' )
		&& str_contains( $public_visitor, 'GÜLTIGE EINWILLIGUNG' ),
		$without_nonce
	);
} );

edu_case( 'the lookup answers for one consent type when the shortcode names one, and names the type it checked', function ( string $name ) {
	edu_seed_flow();
	$fahrt = WM_Edu_Consent_Types::get_by_slug( 'FAHRT' );
	WM_Edu_Admin::process_record( [ 'student_token' => 'EDU-1', 'parent_email' => 'a@beispiel.de', 'type' => 'FAHRT', 'status' => 'GRANTED', 'note' => 'Papierformular' ], 7, '203.0.113.50' );
	WM_Edu_Admin::process_record( [ 'student_token' => 'EDU-1', 'parent_email' => 'a@beispiel.de', 'type' => 'FOTO', 'status' => 'DENIED', 'note' => 'Papierformular' ], 7, '203.0.113.50' );
	$_POST = [ 'edu_consent_check' => '1', 'edu_student_id' => 'EDU-1' ];
	$fahrt_gate = WM_Edu_Shortcodes::render_consent_gate( [ 'type' => 'fahrt' ] );
	$foto_gate  = WM_Edu_Shortcodes::render_consent_gate( [ 'type' => 'FOTO' ] );
	$any_gate   = WM_Edu_Shortcodes::render_consent_gate( '' );
	$_POST      = [];
	assert_test(
		$name,
		str_contains( $fahrt_gate, 'GÜLTIGE EINWILLIGUNG' ) && str_contains( $fahrt_gate, 'Geprüft wird nur die Einwilligungsart: ' . $fahrt->label )
		&& str_contains( $foto_gate, 'KEIN NACHWEIS' ) && str_contains( $any_gate, 'KEIN NACHWEIS' ) && ! str_contains( $any_gate, 'Geprüft wird nur' ),
		$foto_gate
	);
} );

edu_case( 'both tokenizer routes are registered behind the access check: 401 without a login, 403 without the capability, and the capability is filterable', function ( string $name ) {
	edu_seed_flow();
	$GLOBALS['edu_routes'] = [];
	WM_Edu_REST_Controller::register_routes();
	$callbacks = [ $GLOBALS['edu_routes']['/wm-edu/v1/tokenize']['permission_callback'] ?? null, $GLOBALS['edu_routes']['/wm-edu/v1/detokenize']['permission_callback'] ?? null ];
	$anonymous = edu_as( false, [], [], static fn() => WM_Edu_Access::rest_tokenizer_permission() );
	$without   = edu_as( true, [ 'read' => true ], [ 'subscriber' ], static fn() => WM_Edu_Access::rest_tokenizer_permission() );
	$with      = edu_as( true, [ 'edit_posts' => true ], [ 'editor' ], static fn() => WM_Edu_Access::rest_tokenizer_permission() );
	add_filter( 'wm_edu_tokenizer_capability', static fn(): string => 'manage_options' );
	$filtered     = edu_as( true, [ 'edit_posts' => true ], [ 'editor' ], static fn() => WM_Edu_Access::rest_tokenizer_permission() );
	$filtered_cap = WM_Edu_Access::tokenizer_capability();
	remove_filter( 'wm_edu_tokenizer_capability', static fn(): string => 'manage_options' );
	$GLOBALS['edu_filters']['wm_edu_tokenizer_capability'] = [];
	WM_Edu_Settings::save( [ 'public_tokenizer' => '1' ], [] );
	$public = edu_as( false, [], [], static fn() => WM_Edu_Access::rest_tokenizer_permission() );
	assert_test(
		$name,
		[ [ 'WM_Edu_Access', 'rest_tokenizer_permission' ], [ 'WM_Edu_Access', 'rest_tokenizer_permission' ] ] === $callbacks
		&& $anonymous instanceof WP_Error && 401 === $anonymous->get_error_data()['status'] && 'rest_forbidden' === $anonymous->get_error_code()
		&& $without instanceof WP_Error && 403 === $without->get_error_data()['status'] && true === $with
		&& $filtered instanceof WP_Error && 'manage_options' === $filtered_cap && true === $public,
		var_export( [ $callbacks, $anonymous, $without, $with, $filtered ], true )
	);
} );

edu_case( 'the filter hook is written out with its prefix and matches the constant callers use', function ( string $name ) use ( $root ) {
	$src = (string) file_get_contents( $root . '/includes/class-edu-access.php' );
	assert_test( $name, str_contains( $src, "apply_filters( '" . WM_Edu_Access::TOKENIZER_CAPABILITY_FILTER . "', 'edit_posts' )" ) && 'wm_edu_tokenizer_capability' === WM_Edu_Access::TOKENIZER_CAPABILITY_FILTER );
} );

edu_case( 'a filter that returns something other than a capability name does not open the routes', function ( string $name ) {
	edu_seed_flow();
	add_filter( 'wm_edu_tokenizer_capability', static fn() => '' );
	$empty = WM_Edu_Access::tokenizer_capability();
	$GLOBALS['edu_filters']['wm_edu_tokenizer_capability'] = [];
	add_filter( 'wm_edu_tokenizer_capability', static fn() => true );
	$boolean = WM_Edu_Access::tokenizer_capability();
	$GLOBALS['edu_filters']['wm_edu_tokenizer_capability'] = [];
	assert_test( $name, 'edit_posts' === $empty && 'edit_posts' === $boolean, $empty . ' / ' . $boolean );
} );

edu_case( 'the demo shortcode is behind the same rule as the routes: no form for a visitor, the form with the capability, and the public setting restores it', function ( string $name ) {
	edu_seed_flow();
	$_POST   = [ 'wm_edu_sanitize_submit' => '1', 'wm_edu_raw' => 'geboren am 15.03.2012' ];
	$visitor = edu_as( false, [], [], static fn(): string => WM_Edu_Shortcodes::render_pii_sanitizer() );
	$without = edu_as( true, [ 'read' => true ], [ 'subscriber' ], static fn(): string => WM_Edu_Shortcodes::render_pii_sanitizer() );
	$with    = edu_as( true, [ 'edit_posts' => true ], [ 'editor' ], static fn(): string => WM_Edu_Shortcodes::render_pii_sanitizer() );
	WM_Edu_Settings::save( [ 'public_tokenizer' => '1' ], [] );
	$public = edu_as( false, [], [], static fn(): string => WM_Edu_Shortcodes::render_pii_sanitizer() );
	$_POST  = [];
	assert_test(
		$name,
		! str_contains( $visitor, '<form' ) && ! str_contains( $visitor, '[[DOB_' ) && str_contains( $visitor, 'wp-login.php' )
		&& ! str_contains( $without, '<form' ) && ! str_contains( $without, '[[DOB_' ) && ! str_contains( $without, 'wp-login.php' )
		&& str_contains( $with, '<form' ) && str_contains( $with, '[[DOB_' ) && str_contains( $public, '[[DOB_' ),
		$visitor
	);
} );

// =====================================================================================================================
echo "\n[GROUP 19] The mail leaves after the response, so the answer time says nothing about the registry\n";
// =====================================================================================================================

/** A request that is NOT finished: template_redirect only, without the shutdown flush. */
function edu_form_request_unfinished( array $post ): ?EduRedirect {
	WM_Edu_Consent_Form::reset();
	$_POST = $post;
	$_GET  = [];
	try {
		WM_Edu_Consent_Form::handle_request();
	} catch ( EduRedirect $redirect ) {
		return $redirect;
	}
	return null;
}

edu_case( 'a submission sends no mail inside the request: the queue leaves at the end of it, and flushing twice sends nothing twice', function ( string $name ) {
	edu_seed_flow();
	$redirect        = edu_form_request_unfinished( edu_guardian_post() );
	$during          = count( $GLOBALS['edu_mails'] );
	$requests_during = count( edu_requests() );
	WM_Edu_Consent_Requests::flush_mail_queue();
	$after = count( $GLOBALS['edu_mails'] );
	WM_Edu_Consent_Requests::flush_mail_queue();
	assert_test(
		$name,
		null !== $redirect && str_ends_with( $redirect->location, 'wm_edu_status=requested' ) && 0 === $during && 1 === $requests_during
		&& 1 === $after && 1 === count( $GLOBALS['edu_mails'] ),
		var_export( [ $during, $requests_during, $after, count( $GLOBALS['edu_mails'] ) ], true )
	);
} );

edu_case( 'an unregistered pair leaves the request without a mail to send, so both answers cost the same work', function ( string $name ) {
	edu_seed_flow();
	edu_form_request_unfinished( edu_guardian_post( [ 'wm_edu_student_id' => 'EDU-FREMD' ] ) );
	$queued_unregistered = count( $GLOBALS['edu_mails'] );
	WM_Edu_Consent_Requests::flush_mail_queue();
	$after_unregistered = count( $GLOBALS['edu_mails'] );
	edu_form_request_unfinished( edu_guardian_post() );
	$queued_registered = count( $GLOBALS['edu_mails'] );
	WM_Edu_Consent_Requests::flush_mail_queue();
	assert_test( $name, 0 === $queued_unregistered && 0 === $after_unregistered && 0 === $queued_registered && 1 === count( $GLOBALS['edu_mails'] ), var_export( [ $queued_unregistered, $after_unregistered, $queued_registered ], true ) );
} );

edu_case( 'the receipt of a confirmation is queued as well: the guardian waits for the page, not for the mail server', function ( string $name ) {
	edu_seed_flow();
	edu_form_request( edu_guardian_post() );
	$token                  = edu_mail_token( WM_Edu_Consent_Requests::CONFIRM_PARAM );
	$GLOBALS['edu_mails']   = [];
	$_GET                   = [ WM_Edu_Consent_Requests::CONFIRM_PARAM => $token ];
	$redirect               = edu_form_request_unfinished( [ 'wm_edu_action' => 'confirm', 'wm_edu_token' => $token, 'wm_edu_read_confirm' => '1' ] );
	$during                 = count( $GLOBALS['edu_mails'] );
	$rows_during            = edu_count();
	WM_Edu_Consent_Requests::flush_mail_queue();
	assert_test( $name, null !== $redirect && str_ends_with( $redirect->location, 'wm_edu_status=confirmed' ) && 0 === $during && 2 === $rows_during && 1 === count( $GLOBALS['edu_mails'] ), var_export( [ $during, $rows_during, $GLOBALS['edu_mails'] ], true ) );
} );

edu_case( 'a delivery event with a key of somebody else\'s choosing sends nothing and deletes nothing', function ( string $name ) {
	edu_seed_flow();
	set_transient( 'wm_edu_settings_backup', [ [ 'to' => 'fremd@beispiel.de', 'subject' => 'x', 'message' => 'y', 'request' => 0 ] ], 60 );
	WM_Edu_Consent_Requests::deliver_scheduled_mail( 'wm_edu_settings_backup' );
	assert_test( $name, [] === $GLOBALS['edu_mails'] && false !== get_transient( 'wm_edu_settings_backup' ), var_export( $GLOBALS['edu_mails'], true ) );
} );

edu_case( 'the response is closed before the first mail goes out', function ( string $name ) {
	edu_seed_flow();
	WM_Edu_Consent_Requests::submit( 'EDU-9B-042', 'mutter@beispiel.de', [ [ 'slug' => 'FAHRT', 'status' => 'GRANTED', 'scope_hash' => str_repeat( 'a', 64 ), 'label' => 'Klassenfahrt 2026' ] ], 'https://schule.example/einwilligung/' );
	$GLOBALS['edu_response_events'] = [];
	WM_Edu_Consent_Requests::flush_mail_queue();
	assert_test( $name, true === WM_Edu_Consent_Requests::can_close_response() && [ 'closed', 'mail' ] === $GLOBALS['edu_response_events'], var_export( $GLOBALS['edu_response_events'], true ) );
} );

edu_case( 'a server that cannot close the response hands the queue to one immediate event, which delivers it and drops the transient', function ( string $name ) {
	edu_seed_flow();
	add_filter( 'wm_edu_can_close_response', static fn(): bool => false );
	WM_Edu_Consent_Requests::submit( 'EDU-9B-042', 'mutter@beispiel.de', [ [ 'slug' => 'FAHRT', 'status' => 'GRANTED', 'scope_hash' => str_repeat( 'a', 64 ), 'label' => 'Klassenfahrt 2026' ] ], 'https://schule.example/einwilligung/' );
	$GLOBALS['edu_single_events'] = [];
	WM_Edu_Consent_Requests::flush_mail_queue();
	$during = count( $GLOBALS['edu_mails'] );
	$event  = $GLOBALS['edu_single_events'][0] ?? [];
	$key    = (string) ( $event['args'][0] ?? '' );
	$queued = get_transient( $key );
	WM_Edu_Consent_Requests::deliver_scheduled_mail( $key );
	$GLOBALS['edu_filters']['wm_edu_can_close_response'] = [];
	assert_test(
		$name,
		0 === $during && WM_Edu_Consent_Requests::MAIL_HOOK === ( $event['hook'] ?? '' ) && str_starts_with( $key, 'wm_edu_mail_' ) && is_array( $queued ) && 1 === count( $queued )
		&& 1 === count( $GLOBALS['edu_mails'] ) && false === get_transient( $key ) && 'mutter@beispiel.de' === strtolower( (string) $GLOBALS['edu_mails'][0]['to'] ),
		var_export( [ $during, $event, $GLOBALS['edu_mails'] ], true )
	);
} );

edu_case( 'a confirmation mail that cannot be sent deletes its pending request when it fails, and a receipt that fails deletes nothing', function ( string $name ) {
	edu_seed_flow();
	$GLOBALS['edu_mail_ok'] = false;
	edu_form_request( edu_guardian_post() );
	$after_failed_confirmation = edu_requests();
	$GLOBALS['edu_mail_ok']    = true;
	edu_form_request( edu_guardian_post() );
	$token                  = edu_mail_token( WM_Edu_Consent_Requests::CONFIRM_PARAM );
	$GLOBALS['edu_mail_ok'] = false;
	$confirmed              = edu_form_request( [ 'wm_edu_action' => 'confirm', 'wm_edu_token' => $token, 'wm_edu_read_confirm' => '1' ], [ WM_Edu_Consent_Requests::CONFIRM_PARAM => $token ] );
	$GLOBALS['edu_mail_ok'] = true;
	$requests               = edu_requests();
	assert_test(
		$name,
		[] === $after_failed_confirmation && null !== $confirmed && 1 === count( $requests ) && 'confirmed' === $requests[0]['status'] && 2 === edu_count(),
		var_export( [ $after_failed_confirmation, $requests ], true )
	);
} );

edu_case( 'both ends of the mail queue are hooked: the flush on shutdown and the delivery on its own event', function ( string $name ) {
	assert_test(
		$name,
		in_array( [ 'WM_Edu_Consent_Requests', 'flush_mail_queue' ], $GLOBALS['edu_actions']['shutdown'] ?? [], true )
		&& in_array( [ 'WM_Edu_Consent_Requests', 'deliver_scheduled_mail' ], $GLOBALS['edu_actions'][ WM_Edu_Consent_Requests::MAIL_HOOK ] ?? [], true ),
		var_export( array_keys( $GLOBALS['edu_actions'] ), true )
	);
} );

// =====================================================================================================================
echo "\n[GROUP 20] Ecosystem bridges, CSV export filters, opt-in uninstall setting, SBOM and Blueprint\n";
// =====================================================================================================================

edu_case( 'wm_edu_consent_form is registered with a literal tag matching WM_Edu_Consent_Form::SHORTCODE', function ( string $name ) use ( $root ) {
	$src = (string) file_get_contents( $root . '/includes/class-edu-consent-form.php' );
	assert_test(
		$name,
		'wm_edu_consent_form' === WM_Edu_Consent_Form::SHORTCODE
		&& isset( $GLOBALS['edu_shortcodes']['wm_edu_consent_form'] )
		&& str_contains( $src, "add_shortcode( 'wm_edu_consent_form', [ self::class, 'render' ] );" )
	);
} );

edu_case( 'WM_Edu_Ecosystem fires wm_edu_ready, registers wm-edu/status Block Bindings and {{wm_edu_status}} GenerateBlocks tag, and enforces chain & gate rules', function ( string $name ) {
	global $wpdb;
	edu_seed_flow();
	$GLOBALS['edu_fired_actions']  = [];
	$GLOBALS['edu_block_bindings'] = [];
	$GLOBALS['edu_gb_tags']        = [];
	WM_Edu_Ecosystem::bootstrap();

	$valid_status = WM_Edu_Ecosystem::block_binding_value( [] );
	$active_types = WM_Edu_Ecosystem::resolve_status( [ 'key' => 'active_types' ] );
	$gb_fallback  = WM_Edu_Ecosystem::filter_generateblocks_tag_output( '', [ 'tag' => 'wm_edu_status', 'options' => [] ] );
	$gb_native    = WM_Edu_Ecosystem::filter_generateblocks_tag_output( 'Post Title', [ 'source' => 'post' ], 'Post Title' );

	edu_consent();
	$denied_pupil  = edu_as( false, [], [], static fn(): string => WM_Edu_Ecosystem::resolve_status( [ 'student_token' => 'EDU-001' ] ) );
	$granted_pupil = WM_Edu_Ecosystem::resolve_status( [ 'student_token' => 'EDU-001' ] );

	add_filter( 'wm_edu_status_value', static fn( string $v ): string => $v . ' [filtered]' );
	$filtered_tag = WM_Edu_Ecosystem::render_dynamic_tag( [] );
	$GLOBALS['edu_filters']['wm_edu_status_value'] = [];

	$wpdb->update( edu_table(), [ 'granted_status' => 'DENIED' ], [ 'id' => 1 ] );
	$broken_status = WM_Edu_Ecosystem::resolve_status( [] );

	assert_test(
		$name,
		[ [ WM_EDU_PORTAL_VERSION ] ] === ( $GLOBALS['edu_fired_actions']['wm_edu_ready'] ?? [] )
		&& isset( $GLOBALS['edu_block_bindings']['wm-edu/status'], $GLOBALS['edu_gb_tags']['wm_edu_status'] )
		&& 'Einwilligungsregister aktiv' === $valid_status
		&& '2' === $active_types
		&& 'Einwilligungsregister aktiv' === $gb_fallback
		&& 'Post Title' === $gb_native
		&& '' === $denied_pupil
		&& 'Einwilligung erteilt' === $granted_pupil
		&& 'Einwilligungsregister aktiv [filtered]' === $filtered_tag
		&& 'Register nicht prüfbar' === $broken_status,
		var_export( [ $valid_status, $active_types, $denied_pupil, $granted_pupil, $filtered_tag, $broken_status ], true )
	);
} );

edu_case( 'register_settings registers wm_edu_delete_data_on_uninstall, settings page renders and saves it, and CSV export exposes filters', function ( string $name ) use ( $root ) {
	edu_fresh_db();
	$GLOBALS['edu_registered_settings'] = [];
	WM_Edu_Admin::register_settings();
	$default_flag = WM_Edu_Settings::delete_data_on_uninstall();
	WM_Edu_Admin::process_settings( [ 'wm_edu_delete_data_on_uninstall' => '1' ] );
	$enabled_flag = WM_Edu_Settings::delete_data_on_uninstall();
	$settings_html = edu_admin_page( 'page_settings' );
	WM_Edu_Admin::process_settings( [] );
	$reset_flag = WM_Edu_Settings::delete_data_on_uninstall();
	$admin_src = (string) file_get_contents( $root . '/includes/class-edu-admin.php' );

	assert_test(
		$name,
		isset( $GLOBALS['edu_registered_settings']['wm_edu_delete_data_on_uninstall'] )
		&& false === $default_flag
		&& true === $enabled_flag
		&& false === $reset_flag
		&& str_contains( $settings_html, 'name="wm_edu_delete_data_on_uninstall" value="1" checked' )
		&& str_contains( $admin_src, "\$csv = (string) apply_filters( 'wm_edu_export_csv_output', \$csv );" )
		&& str_contains( $admin_src, "echo (string) apply_filters( 'wm_edu_export_csv_stream', \$csv );" ),
		var_export( [ $default_flag, $enabled_flag, $reset_flag ], true )
	);
} );

edu_case( 'bom.json matches wm-edu-privacy-portal.php SHA-256, blueprint.json targets /wp-admin/, and all 29 .mo catalogs are compiled', function ( string $name ) use ( $root ) {
	$bom       = json_decode( (string) file_get_contents( $root . '/bom.json' ), true );
	$blueprint = json_decode( (string) file_get_contents( $root . '/.wordpress-org/blueprints/blueprint.json' ), true );
	$expected  = hash_file( 'sha256', $root . '/wm-edu-privacy-portal.php' );
	$mos       = glob( $root . '/languages/*.mo' ) ?: [];
	$tiny      = array_filter( $mos, static fn( string $f ): bool => filesize( $f ) < 1024 );

	assert_test(
		$name,
		is_array( $bom )
		&& 'CycloneDX' === ( $bom['bomFormat'] ?? '' )
		&& '1.6' === ( $bom['specVersion'] ?? '' )
		&& WM_EDU_PORTAL_VERSION === ( $bom['metadata']['component']['version'] ?? '' )
		&& 'GPL-2.0-or-later' === ( $bom['metadata']['component']['licenses'][0]['license']['id'] ?? '' )
		&& $expected === ( $bom['metadata']['component']['hashes'][0]['content'] ?? '' )
		&& is_array( $blueprint ) && str_starts_with( (string) ( $blueprint['landingPage'] ?? '' ), '/wp-admin/' )
		&& 29 === count( $mos ) && [] === $tiny,
		var_export( [ count( $mos ), $tiny ], true )
	);
} );

// =====================================================================================================================
echo "\n[GROUP 21] Multisite provisioning and autoload = false on non-frontend options\n";
// =====================================================================================================================

edu_case( 'wp_initialize_site is hooked and provisions a new subsite only when multisite and network-activated, always restoring current blog', function ( string $name ) {
	$db                                    = new Edu_Test_WPDB();
	$GLOBALS['wpdb']                       = $db;
	$GLOBALS['edu_dbdelta_calls']          = [];
	$GLOBALS['edu_dbdelta_fail']           = false;
	$GLOBALS['edu_dbdelta_throw']          = false;
	$GLOBALS['edu_option_autoloads']       = [];
	$GLOBALS['edu_current_blog_id']        = 1;
	$GLOBALS['edu_blog_stack']             = [];
	$GLOBALS['edu_blog_switches']          = [];
	edu_reset_roles();

	$hooked = in_array( 'wm_edu_on_initialize_site', $GLOBALS['edu_actions']['wp_initialize_site'] ?? [], true );

	$GLOBALS['edu_is_multisite']           = false;
	$GLOBALS['edu_network_active_plugins'] = [];
	do_action( 'wp_initialize_site', (object) [ 'blog_id' => 2 ], [] );
	$switches_single = $GLOBALS['edu_blog_switches'];
	$has_table_single = $db->has_table( 'wp_2_wm_edu_parent_consents' );

	$GLOBALS['edu_is_multisite']           = true;
	$GLOBALS['edu_network_active_plugins'] = [];
	do_action( 'wp_initialize_site', (object) [ 'blog_id' => 2 ], [] );
	$switches_inactive = $GLOBALS['edu_blog_switches'];
	$has_table_inactive = $db->has_table( 'wp_2_wm_edu_parent_consents' );

	$GLOBALS['edu_network_active_plugins'] = [ 'wm-edu-privacy-portal/wm-edu-privacy-portal.php' ];
	do_action( 'wp_initialize_site', (object) [ 'blog_id' => 2 ], [] );
	$switches_active = $GLOBALS['edu_blog_switches'];
	$subsite_tables  = array_map(
		static fn( string $t ): bool => $db->has_table( 'wp_2_' . $t ),
		[ 'wm_edu_parent_consents', 'wm_edu_consent_types', 'wm_edu_consent_texts', 'wm_edu_guardians', 'wm_edu_consent_requests' ]
	);
	$subsite_schema = (string) $db->pdo_option_get( 'wp_2_options', WM_Edu_Consent_Vault::SCHEMA_OPTION );
	$subsite_cap    = $GLOBALS['edu_roles_by_blog'][2]['administrator']->has_cap( WM_Edu_Access::CAPABILITY );
	$main_untouched = ! $db->has_table( 'wp_wm_edu_parent_consents' );

	$GLOBALS['edu_dbdelta_throw'] = true;
	$threw                        = false;
	try {
		do_action( 'wp_initialize_site', (object) [ 'blog_id' => 3 ], [] );
	} catch ( RuntimeException $e ) {
		$threw = true;
	}
	$GLOBALS['edu_dbdelta_throw'] = false;

	assert_test(
		$name,
		$hooked
		&& [] === $switches_single && ! $has_table_single
		&& [] === $switches_inactive && ! $has_table_inactive
		&& [ [ 'switch', 2 ], [ 'restore', 1 ] ] === $switches_active
		&& [ true, true, true, true, true ] === $subsite_tables
		&& '3' === $subsite_schema
		&& $subsite_cap
		&& $main_untouched
		&& $threw
		&& 1 === $GLOBALS['edu_current_blog_id']
		&& 'wp_' === $db->prefix
		&& [] === $GLOBALS['edu_blog_stack']
		&& [ 'restore', 1 ] === end( $GLOBALS['edu_blog_switches'] ),
		var_export( [ $hooked, $switches_active, $subsite_tables, $subsite_schema, $subsite_cap, $threw, $GLOBALS['edu_blog_switches'] ], true )
	);
} );

edu_case( 'install( true ) activates across all multisite blogs when $network_wide is true, and current blog only when false', function ( string $name ) {
	$db                                    = new Edu_Test_WPDB();
	$GLOBALS['wpdb']                       = $db;
	$GLOBALS['edu_dbdelta_calls']          = [];
	$GLOBALS['edu_dbdelta_fail']           = false;
	$GLOBALS['edu_dbdelta_throw']          = false;
	$GLOBALS['edu_option_autoloads']       = [];
	$GLOBALS['edu_is_multisite']           = true;
	$GLOBALS['edu_multisite_sites']        = [ 1, 2, 3 ];
	$GLOBALS['edu_current_blog_id']        = 1;
	$GLOBALS['edu_blog_stack']             = [];
	$GLOBALS['edu_blog_switches']          = [];
	edu_reset_roles();

	WM_Edu_Consent_Vault::install( false );
	$after_single = [
		$db->has_table( 'wp_wm_edu_parent_consents' ),
		$db->has_table( 'wp_2_wm_edu_parent_consents' ),
		$db->has_table( 'wp_3_wm_edu_parent_consents' ),
	];

	WM_Edu_Consent_Vault::install( true );
	$after_network = [
		$db->has_table( 'wp_wm_edu_parent_consents' ),
		$db->has_table( 'wp_2_wm_edu_parent_consents' ),
		$db->has_table( 'wp_3_wm_edu_parent_consents' ),
		$db->has_table( 'wp_2_wm_edu_consent_requests' ),
		$db->has_table( 'wp_3_wm_edu_guardians' ),
	];

	assert_test(
		$name,
		[ true, false, false ] === $after_single
		&& [ true, true, true, true, true ] === $after_network
		&& 1 === $GLOBALS['edu_current_blog_id']
		&& 'wp_' === $db->prefix
		&& [] === $GLOBALS['edu_blog_stack']
		&& '3' === $db->pdo_option_get( 'wp_2_options', WM_Edu_Consent_Vault::SCHEMA_OPTION )
		&& '3' === $db->pdo_option_get( 'wp_3_options', WM_Edu_Consent_Vault::SCHEMA_OPTION )
		&& $GLOBALS['edu_roles_by_blog'][2]['administrator']->has_cap( WM_Edu_Access::CAPABILITY )
		&& $GLOBALS['edu_roles_by_blog'][3]['administrator']->has_cap( WM_Edu_Access::CAPABILITY ),
		var_export( [ $after_single, $after_network, $GLOBALS['edu_blog_switches'] ], true )
	);
} );

edu_case( 'create_tables() guards the upgrade.php require with file_exists() and checks function_exists(dbDelta)', function ( string $name ) use ( $root ) {
	$src = (string) file_get_contents( $root . '/includes/class-edu-consent-vault.php' );
	assert_test(
		$name,
		1 === preg_match( '/function create_tables\(\): void \{.*?upgrade\.php.*?file_exists\(.*?require_once.*?if \( ! function_exists\( \'dbDelta\' \) \)/s', $src ),
		'missing file_exists / function_exists guard in create_tables()'
	);
} );

edu_case( 'non-frontend options (schema version, legacy boundary, chain tip, delete on uninstall) pass autoload = false while frontend settings stay autoloaded', function ( string $name ) {
	$db = edu_fresh_db();
	WM_Edu_Admin::process_settings( [ 'public_gate' => '1', 'wm_edu_delete_data_on_uninstall' => '1' ] );
	assert_test(
		$name,
		array_key_exists( WM_Edu_Consent_Vault::SCHEMA_OPTION, $GLOBALS['edu_option_autoloads'] )
		&& false === $GLOBALS['edu_option_autoloads'][ WM_Edu_Consent_Vault::SCHEMA_OPTION ]
		&& 'no' === $db->option_autoload( WM_Edu_Consent_Vault::SCHEMA_OPTION )
		&& array_key_exists( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION, $GLOBALS['edu_option_autoloads'] )
		&& false === $GLOBALS['edu_option_autoloads'][ WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION ]
		&& 'no' === $db->option_autoload( WM_Edu_Consent_Vault::LEGACY_BOUNDARY_OPTION )
		&& array_key_exists( WM_Edu_Consent_Vault::TIP_OPTION, $GLOBALS['edu_option_autoloads'] )
		&& false === $GLOBALS['edu_option_autoloads'][ WM_Edu_Consent_Vault::TIP_OPTION ]
		&& 'no' === $db->option_autoload( WM_Edu_Consent_Vault::TIP_OPTION )
		&& array_key_exists( 'wm_edu_delete_data_on_uninstall', $GLOBALS['edu_option_autoloads'] )
		&& false === $GLOBALS['edu_option_autoloads']['wm_edu_delete_data_on_uninstall']
		&& 'no' === $db->option_autoload( 'wm_edu_delete_data_on_uninstall' )
		&& array_key_exists( WM_Edu_Settings::OPTION, $GLOBALS['edu_option_autoloads'] )
		&& null === $GLOBALS['edu_option_autoloads'][ WM_Edu_Settings::OPTION ]
		&& 'yes' === $db->option_autoload( WM_Edu_Settings::OPTION ),
		var_export( $GLOBALS['edu_option_autoloads'], true )
	);
} );

echo "\n==============================================================================\n";
echo "  TEST SUITE RESULTS: {$GLOBALS['edu_passed']} PASSED | {$GLOBALS['edu_failed']} FAILED\n";
echo "==============================================================================\n";

exit( $GLOBALS['edu_failed'] > 0 ? 1 : 0 );
