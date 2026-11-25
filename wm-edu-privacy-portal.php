<?php
/**
 * Plugin Name:       WM Edu Privacy Portal
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-edu-privacy-portal/
 * Description:       Ersetzt Datumsangaben, Klassenbezeichnungen und übergebene Schülernamen durch Platzhalter, bevor Text die Website verlässt, und prüft Eltern-Einwilligungen gegen ein hash-verkettetes Register.
 * Version:           1.2.1
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-edu-privacy-portal
 * Domain Path:       /languages
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WM_EDU_PORTAL_VERSION', '1.2.1' );
define( 'WM_EDU_PORTAL_FILE', __FILE__ );
define( 'WM_EDU_PORTAL_PATH', plugin_dir_path( __FILE__ ) );

// Core subsystems.
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-keys.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-settings.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-access.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-pii-tokenizer.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-consent-vault.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-consent-types.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-guardians.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-consent-requests.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-shortcodes.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-consent-form.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-rest-controller.php';
require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-ecosystem.php';

WM_Edu_Shortcodes::init();
WM_Edu_Consent_Form::init();
WM_Edu_Consent_Requests::init();
WM_Edu_REST_Controller::init();
WM_Edu_Ecosystem::init();

// The admin screen (register, manual entries, revocations, export, types, guardians, settings).
if ( is_admin() ) {
	require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-admin.php';
	WM_Edu_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-cli.php';
}

/**
 * Bundled catalogs. WordPress before 7.1 registers a plugin's own languages/ folder only through this call.
 */
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'wm-edu-privacy-portal', false, dirname( plugin_basename( WM_EDU_PORTAL_FILE ) ) . '/languages' );
	}
);

/**
 * Schema check on every load (the version option has autoload disabled): a site that never ran the activation hook,
 * such as a deployed copy or an older subsite, gets its table instead of an empty chain reported as valid.
 */
add_action( 'plugins_loaded', [ 'WM_Edu_Consent_Vault', 'maybe_upgrade' ] );

/**
 * Retention of guardian request data: the daily event is (re)scheduled on every load, because an update of an active
 * plugin never runs the activation hook again; deactivation removes it.
 */
add_action( 'plugins_loaded', [ 'WM_Edu_Consent_Requests', 'sync_schedule' ] );
add_action( WM_Edu_Consent_Requests::CRON_HOOK, [ 'WM_Edu_Consent_Requests', 'cleanup' ] );
register_deactivation_hook( __FILE__, [ 'WM_Edu_Consent_Requests', 'unschedule' ] );

/**
 * Register with WM Suite Hub if active.
 */
function wm_edu_register_spoke_module(): void {
	if ( interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
		require_once WM_EDU_PORTAL_PATH . 'includes/class-edu-spoke-adapter.php';
		if ( class_exists( 'WM_Edu_Spoke_Adapter' ) ) {
			add_filter(
				'wm_register_suite_module',
				static function ( array $modules ): array {
					$modules[] = new WM_Edu_Spoke_Adapter();
					return $modules;
				}
			);
		}
	}
}
add_action( 'plugins_loaded', 'wm_edu_register_spoke_module', 20 );

/**
 * Provision tables, tip and capability on newly created multisite sites when the plugin is network-activated.
 *
 * @param WP_Site|object $new_site Newly initialized site object.
 */
function wm_edu_on_initialize_site( $new_site ): void {
	if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
		return;
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		$plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
		if ( file_exists( $plugin_file ) ) {
			require_once $plugin_file;
		}
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}
	$blog_id = is_object( $new_site ) && isset( $new_site->blog_id ) ? (int) $new_site->blog_id : 0;
	if ( $blog_id <= 0 ) {
		return;
	}
	switch_to_blog( $blog_id );
	try {
		WM_Edu_Consent_Vault::install();
	} finally {
		restore_current_blog();
	}
}
add_action( 'wp_initialize_site', 'wm_edu_on_initialize_site' );

register_activation_hook( __FILE__, [ 'WM_Edu_Consent_Vault', 'install' ] );
