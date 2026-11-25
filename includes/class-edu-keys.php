<?php
/**
 * WM Edu Privacy Portal — key derivation
 *
 * Every HMAC key of the plugin comes from wp_salt( 'auth' ), one key per purpose. Until 2026-09-15 the consent
 * signature and the tokenizer used the raw AUTH_KEY constant whenever it was set. Core's wp_salt() ignores the
 * wp-config-sample.php phrase and generates a per-site value instead; the raw constant did not, so a site that kept the
 * sample phrase produced tokens and signatures anyone could compute. One key for both purposes also let the public
 * /tokenize route compute (truncated) HMACs under the key that signs consents.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Keys {

	public const TOKENIZER         = 'tokenizer';
	public const CONSENT_SIGNATURE = 'consent-signature';
	/** Signs the recorded chain tip (WM_Edu_Consent_Vault::TIP_OPTION). */
	public const CHAIN_TIP = 'chain-tip';
	/** HMAC of guardian addresses in the registry (WM_Edu_Guardians). */
	public const GUARDIAN_EMAIL = 'guardian-email';
	/** Transient keys of the guardian form's rate limit (WM_Edu_Consent_Requests::rate_limited()). */
	public const RATE_LIMIT = 'rate-limit';

	/**
	 * Key for one purpose: HMAC-SHA256 of a purpose label under the site's auth salt.
	 */
	public static function derive( string $purpose ): string {
		return hash_hmac( 'sha256', 'wm-edu-privacy-portal|' . $purpose, wp_salt( 'auth' ) );
	}

	/**
	 * Fingerprint of the signing key, stored with every consent row so that verify_chain() can tell a changed site key
	 * from an edited row. It is an HMAC of a fixed label under the key, not a part of the key.
	 */
	public static function signature_key_id(): string {
		return substr( hash_hmac( 'sha256', 'wm-edu-privacy-portal|key-id', self::derive( self::CONSENT_SIGNATURE ) ), 0, 16 );
	}

	/**
	 * The key rows of schema 1 were signed with. Used only to verify those rows; nothing is signed with it any more.
	 */
	public static function legacy_signature_key(): string {
		return defined( 'AUTH_KEY' ) && '' !== (string) AUTH_KEY ? (string) AUTH_KEY : wp_salt( 'auth' );
	}
}
