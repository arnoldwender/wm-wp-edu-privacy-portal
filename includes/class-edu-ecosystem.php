<?php
/**
 * WM Edu Privacy Portal — WordPress & GenerateBlocks ecosystem bridges
 *
 * Exposes the plugin readiness action (`wm_edu_ready`), the WordPress 6.5+ Block Bindings source (`wm-edu/status`),
 * and the GenerateBlocks 2.x Dynamic Tag (`{{wm_edu_status}}`) with status output filtered via `wm_edu_status_value`.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_Ecosystem {

	public const READY_ACTION   = 'wm_edu_ready';
	public const BINDING_SOURCE = 'wm-edu/status';
	public const DYNAMIC_TAG    = 'wm_edu_status';
	public const STATUS_FILTER  = 'wm_edu_status_value';

	public static function init(): void {
		add_action( 'init', [ self::class, 'bootstrap' ], 20 );
		add_filter( 'generateblocks_dynamic_tag_replacement', [ self::class, 'filter_generateblocks_tag_output' ], 10, 2 );
	}

	public static function bootstrap(): void {
		if ( function_exists( 'register_block_bindings_source' ) ) {
			register_block_bindings_source(
				'wm-edu/status',
				[
					'label'              => __( 'Einwilligungsregister-Status', 'wm-edu-privacy-portal' ),
					'get_value_callback' => [ self::class, 'block_binding_value' ],
					'uses_context'       => [ 'postId', 'postType' ],
				]
			);
		}

		if ( class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			new GenerateBlocks_Register_Dynamic_Tag(
				[
					'title'    => __( 'Einwilligungsregister-Status', 'wm-edu-privacy-portal' ),
					'tag'      => 'wm_edu_status',
					'type'     => 'wm-edu',
					'supports' => [ 'source' ],
					'return'   => [ self::class, 'render_dynamic_tag' ],
				]
			);
		}

		/**
		 * Fires once WM Edu Privacy Portal has registered its shortcodes, REST routes, textdomain, and block bridges.
		 *
		 * @param string $version Plugin version string.
		 */
		do_action( 'wm_edu_ready', WM_EDU_PORTAL_VERSION );
	}

	/**
	 * Resolves a plain-text status value for Block Bindings and GenerateBlocks dynamic tags.
	 *
	 * Never defaults to "all good": when the register table is missing or its hash chain does not verify, the status
	 * reports that the register is not verifiable. Pupil-specific lookups require `WM_Edu_Access::can_use_gate()`.
	 *
	 * @param array<string, mixed> $args Optional parameters (`key`, `student_token`, `type`).
	 */
	public static function resolve_status( array $args = [] ): string {
		$student = isset( $args['student_token'] ) && is_string( $args['student_token'] ) ? trim( sanitize_text_field( $args['student_token'] ) ) : '';
		if ( '' !== $student ) {
			if ( ! WM_Edu_Access::can_use_gate() ) {
				return '';
			}
			$type  = isset( $args['type'] ) && is_string( $args['type'] ) ? WM_Edu_Consent_Types::normalize_slug( $args['type'] ) : '';
			$state = WM_Edu_Consent_Vault::consent_state( $student, $type );
			$value = match ( $state ) {
				'granted'      => __( 'Einwilligung erteilt', 'wm-edu-privacy-portal' ),
				'unverifiable' => __( 'Register nicht prüfbar', 'wm-edu-privacy-portal' ),
				default        => __( 'Kein aktiver Einwilligungsnachweis', 'wm-edu-privacy-portal' ),
			};
			return (string) apply_filters( 'wm_edu_status_value', $value, $args );
		}

		$key   = isset( $args['key'] ) && is_string( $args['key'] ) ? sanitize_key( $args['key'] ) : 'chain';
		$chain = WM_Edu_Consent_Vault::verify_chain();

		if ( 'active_types' === $key ) {
			$value = $chain['missing_table'] ? '' : (string) count( WM_Edu_Consent_Types::active() );
		} else {
			$value = $chain['valid']
				? __( 'Einwilligungsregister aktiv', 'wm-edu-privacy-portal' )
				: __( 'Register nicht prüfbar', 'wm-edu-privacy-portal' );
		}

		return (string) apply_filters( 'wm_edu_status_value', $value, $args );
	}

	/**
	 * Callback for the `wm-edu/status` WordPress 6.5+ Block Bindings source.
	 *
	 * @param array<string, mixed> $source_args    Binding arguments.
	 * @param mixed                $block_instance Block instance.
	 */
	public static function block_binding_value( array $source_args, $block_instance = null, string $attribute_name = 'content' ): string {
		return self::resolve_status( $source_args );
	}

	/**
	 * Callback for the `{{wm_edu_status}}` GenerateBlocks 2.x Dynamic Tag.
	 *
	 * @param array<string, mixed> $options  Tag options.
	 * @param mixed                $block    Block array.
	 * @param mixed                $instance Block instance.
	 */
	public static function render_dynamic_tag( array $options = [], $block = [], $instance = null ): string {
		return esc_html( self::resolve_status( $options ) );
	}

	/**
	 * Fallback filter for GenerateBlocks 2.x dynamic tag replacement (`{{wm_edu_status}}`).
	 *
	 * @param mixed $output      Current replacement output.
	 * @param mixed $tag_details Parsed dynamic tag details.
	 * @param mixed $context     Optional third argument (e.g. raw scalar output).
	 * @return mixed
	 */
	public static function filter_generateblocks_tag_output( mixed $output, mixed $tag_details = [], mixed $context = null ): mixed {
		if ( is_array( $tag_details ) && 'wm_edu_status' === ( $tag_details['tag'] ?? '' ) && ( '' === $output || null === $output ) ) {
			$options = is_array( $tag_details['options'] ?? null ) ? $tag_details['options'] : [];
			return esc_html( self::resolve_status( $options ) );
		}
		return $output;
	}
}
