<?php
/**
 * Runtime evaluation ability.
 *
 * This is the same capability WP-CLI's `wp eval` has offered for years, and
 * the same one snippet plugins such as WPCode and Code Snippets expose through
 * the admin UI. It is included because inspecting a live site's runtime state
 * is the single most useful thing an agent can do when debugging WordPress.
 *
 * It is also, plainly, complete control of the site for whoever holds the
 * credential. It is therefore gated behind its own opt-in, separate from every
 * other switch in this plugin, and that opt-in is off by default and resets
 * when the plugin is deactivated.
 *
 * Use it on development and staging installs. Keep it off in production.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Runtime {

	/** Hard ceiling so a runaway snippet cannot hold a worker forever. */
	private const TIME_LIMIT = 30;

	public static function enabled(): bool {
		$s = get_option( OPTION_KEY, [] );
		return is_array( $s ) && ! empty( $s['runtime'] );
	}

	/**
	 * Three independent conditions, all required: abilities on for this
	 * domain, this ability opted into separately, and an administrator.
	 */
	public static function permission(): bool {
		return Settings::active() && self::enabled() && current_user_can( CAPABILITY );
	}

	public static function register(): void {
		wp_register_ability(
			'niranzwp/evaluate',
			[
				'label'               => __( 'Evaluate PHP', 'niranzwp' ),
				'description'         => __( 'Evaluates PHP inside the loaded WordPress runtime and returns the value it produces, anything it printed, and any error it raised. Equivalent to "wp eval". This is full control of the site: enable it on development and staging only.', 'niranzwp' ),
				'category'            => 'niranzwp',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'code' => [
							'type'        => 'string',
							'description' => 'PHP to evaluate. Use return to send a value back.',
						],
					],
					'required'   => [ 'code' ],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => [ self::class, 'permission' ],
				'execute_callback'    => [ self::class, 'evaluate' ],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [ 'readonly' => false, 'destructive' => true ],
				],
			]
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function evaluate( array $input = [] ) {
		$code = (string) ( $input['code'] ?? '' );

		if ( '' === trim( $code ) ) {
			return new \WP_Error( 'niranzwp_no_code', 'No code supplied.' );
		}

		// A leading <?php is a common paste artefact and is not valid here.
		$code = (string) preg_replace( '/^\s*<\?php\s*/', '', $code );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIME_LIMIT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$started = microtime( true );
		$value   = null;
		$error   = null;

		ob_start();
		try {
			$value = eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		} catch ( \Throwable $e ) {
			$error = [
				'class'   => get_class( $e ),
				'message' => $e->getMessage(),
				'line'    => $e->getLine(),
			];
		} finally {
			$printed = (string) ob_get_clean();
		}

		return [
			'success'      => null === $error,
			'return_value' => self::serializable( $value ),
			'output'       => $printed,
			'error'        => $error,
			'ms'           => round( ( microtime( true ) - $started ) * 1000, 2 ),
		];
	}

	/**
	 * Objects and resources cannot cross the REST boundary intact, so describe
	 * them rather than letting json_encode silently produce something useless.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function serializable( $value ) {
		if ( is_object( $value ) ) {
			if ( $value instanceof \JsonSerializable ) {
				return $value->jsonSerialize();
			}
			return [ '__class' => get_class( $value ), '__vars' => get_object_vars( $value ) ];
		}
		if ( is_resource( $value ) ) {
			return '__resource:' . get_resource_type( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'serializable' ], $value );
		}
		return $value;
	}
}
