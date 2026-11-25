<?php
/**
 * WM Edu Privacy Portal — REST routes
 *
 * - POST /wp-json/wm-edu/v1/tokenize    text, known_students
 * - POST /wp-json/wm-edu/v1/detokenize  text, token_map
 *
 * Both routes need an authenticated user with the tokenizer capability since 2026-09-17 (WM_Edu_Access): a public
 * /tokenize computes the token of any guessed name or date, which re-identifies a text that was pseudonymised with it.
 * The setting "public_tokenizer" restores the public behaviour of the earlier releases.
 *
 * Core validates the arguments BEFORE the permission callback runs, so a request with a malformed body is answered
 * with 400 and never reaches the permission check: a test of the guard sends a valid body. Until 2026-09-15 a JSON list
 * or a number as a token ended in a fatal TypeError (HTTP 500), and sanitize_text_field() removed line breaks and "%ad"
 * from the text and turned "<3" into an entity.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_REST_Controller {

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			'wm-edu/v1',
			'/tokenize',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_tokenize' ],
				'permission_callback' => [ 'WM_Edu_Access', 'rest_tokenizer_permission' ],
				'args'                => [
					'text'           => self::text_arg(),
					'known_students' => self::map_arg(),
				],
			]
		);

		register_rest_route(
			'wm-edu/v1',
			'/detokenize',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_detokenize' ],
				'permission_callback' => [ 'WM_Edu_Access', 'rest_tokenizer_permission' ],
				'args'                => [
					'text'      => self::text_arg(),
					'token_map' => self::map_arg(),
				],
			]
		);
	}

	/**
	 * The text is plain text returned as JSON: its type is checked, it is not HTML-sanitised.
	 *
	 * @return array<string, mixed>
	 */
	private static function text_arg(): array {
		return [
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => [ self::class, 'validate_text' ],
		];
	}

	/**
	 * An object of strings. Core's object type also accepts a JSON list; validate_string_map() refuses it.
	 *
	 * @return array<string, mixed>
	 */
	private static function map_arg(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => [ 'type' => 'string' ],
			'validate_callback'    => [ self::class, 'validate_string_map' ],
		];
	}

	/**
	 * @param mixed $value
	 * @return true|WP_Error
	 */
	public static function validate_text( $value, $request = null, string $param = 'text' ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) {
			return new WP_Error(
				'rest_invalid_param',
				/* translators: %s: parameter name. */
				sprintf( __( '%s muss ein Text in gültigem UTF-8 sein.', 'wm-edu-privacy-portal' ), $param ),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * @param mixed $value
	 * @return true|WP_Error
	 */
	public static function validate_string_map( $value, $request = null, string $param = 'known_students' ) {
		// A list has the keys 0..n-1. array_is_list() is native since PHP 8.1, but Plugin Check ties it to the polyfill of
		// WordPress 6.5 and this plugin supports 6.4.
		$valid = is_array( $value ) && ( [] === $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) );
		if ( $valid ) {
			foreach ( $value as $key => $item ) {
				if ( ! is_string( $item ) || 1 !== preg_match( '//u', $item ) || 1 !== preg_match( '//u', (string) $key ) ) {
					$valid = false;
					break;
				}
			}
		}
		if ( ! $valid ) {
			return new WP_Error(
				'rest_invalid_param',
				/* translators: %s: parameter name. */
				sprintf( __( '%s muss ein Objekt mit Texten als Werten sein, zum Beispiel {"Max Müller": ""}.', 'wm-edu-privacy-portal' ), $param ),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_tokenize( \WP_REST_Request $request ) {
		$text     = $request->get_param( 'text' );
		$students = $request->get_param( 'known_students' ) ?? [];

		// Checked again for callers that reach the handler without core's argument validation.
		foreach ( [ self::validate_text( $text ), self::validate_string_map( $students ) ] as $check ) {
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		try {
			$result = WM_Edu_PII_Tokenizer::tokenize( $text, $students );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'wm_edu_tokenize_failed', __( 'Der Text konnte nicht pseudonymisiert werden.', 'wm-edu-privacy-portal' ), [ 'status' => 500 ] );
		}

		return new \WP_REST_Response(
			[
				'success'   => true,
				'data'      => $result,
				'timestamp' => gmdate( 'c' ),
			],
			200
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_detokenize( \WP_REST_Request $request ) {
		$text      = $request->get_param( 'text' );
		$token_map = $request->get_param( 'token_map' ) ?? [];

		foreach ( [ self::validate_text( $text ), self::validate_string_map( $token_map, null, 'token_map' ) ] as $check ) {
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		return new \WP_REST_Response(
			[
				'success'   => true,
				'data'      => [ 'restored_text' => WM_Edu_PII_Tokenizer::detokenize( $text, $token_map ) ],
				'timestamp' => gmdate( 'c' ),
			],
			200
		);
	}
}
