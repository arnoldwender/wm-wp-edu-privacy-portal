<?php
/**
 * WM Edu Privacy Portal — who may do what
 *
 * The plugin's own capability, manage_edu_consents, opens the admin screen (register, history, manual entries,
 * revocations, export, consent types, guardian registry). install() grants it to the administrator role; other roles
 * receive it through a role editor. The settings page itself needs manage_options.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Access {

	public const CAPABILITY = 'manage_edu_consents';

	/** The settings page switches public access on and off: that stays with site administrators. */
	public const SETTINGS_CAPABILITY = 'manage_options';

	/**
	 * Grants manage_edu_consents to the administrator role when the role exists and does not have it yet. WP_Role::add_cap()
	 * writes the roles option, so the check keeps repeated installs from rewriting it.
	 */
	public static function grant_capability(): void {
		$role = get_role( 'administrator' );
		if ( $role instanceof WP_Role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	public static function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Filter for the capability the tokenizer needs: an integration that runs as another role changes it here.
	 * A value that is not a non-empty string is ignored, so a filter that returns nothing cannot open the routes.
	 */
	public const TOKENIZER_CAPABILITY_FILTER = 'wm_edu_tokenizer_capability';

	public static function tokenizer_capability(): string {
		// The hook name is written out, not passed as the constant: Plugin Check cannot resolve a constant and reports an
		// unprefixed dynamic hook name. The suite asserts that the literal and TOKENIZER_CAPABILITY_FILTER stay the same.
		$capability = apply_filters( 'wm_edu_tokenizer_capability', 'edit_posts' );
		return is_string( $capability ) && '' !== $capability ? $capability : 'edit_posts';
	}

	/**
	 * Who may look a consent up in the portal: users with the capability, users in a role the settings name, and — only
	 * while the setting says so — everybody, which is how the plugin behaved before 2026-09-17. A gate open to everybody
	 * tells anyone who can guess a pupil ID whether a consent is on file.
	 */
	public static function can_use_gate(): bool {
		if ( true === WM_Edu_Settings::get( 'public_gate' ) ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( self::CAPABILITY ) ) {
			return true;
		}
		$user = wp_get_current_user();
		return [] !== array_intersect( (array) ( $user->roles ?? [] ), (array) WM_Edu_Settings::get( 'gate_roles' ) );
	}

	/**
	 * Who may turn text into tokens (the REST routes and the demo shortcode). Whoever can do this can also ask for the
	 * token of a guessed name or date and so re-identify a pseudonymised text; that is why it is not public by default.
	 */
	public static function can_use_tokenizer(): bool {
		if ( true === WM_Edu_Settings::get( 'public_tokenizer' ) ) {
			return true;
		}
		return is_user_logged_in() && current_user_can( self::tokenizer_capability() );
	}

	/**
	 * permission_callback of both tokenizer routes. A cookie-authenticated request without the `X-WP-Nonce` header of the
	 * `wp_rest` action is not logged in as far as WordPress is concerned (rest_cookie_check_errors() sets the current user
	 * to 0), so it is answered with 401 here; an application password needs no nonce and is logged in.
	 *
	 * @param WP_REST_Request|null $request
	 * @return true|WP_Error
	 */
	public static function rest_tokenizer_permission( $request = null ) {
		if ( true === WM_Edu_Settings::get( 'public_tokenizer' ) ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Diese Route setzt eine Anmeldung voraus. Cookie-Anmeldungen benötigen zusätzlich den REST-Nonce X-WP-Nonce.', 'wm-edu-privacy-portal' ), [ 'status' => 401 ] );
		}
		if ( ! current_user_can( self::tokenizer_capability() ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Für diese Route fehlt die Berechtigung.', 'wm-edu-privacy-portal' ), [ 'status' => 403 ] );
		}
		return true;
	}
}
