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

	/**
	 * Warnings are collected rather than printed, so a cap is needed: a loop
	 * that warns on every iteration would otherwise build an unbounded array
	 * and the response would be the warnings rather than the answer.
	 */
	private const MAX_DIAGNOSTICS = 100;

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
				'category'            => 'niranzwp-runtime',
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
	public static function evaluate( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$code = (string) ( $input['code'] ?? '' );

		if ( '' === trim( $code ) ) {
			return new \WP_Error( 'niranzwp_no_code', 'No code supplied.' );
		}

		// A leading <?php is a common paste artefact and is not valid here.
		$code = (string) preg_replace( '/^\s*<\?php\s*/', '', $code );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIME_LIMIT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$started     = microtime( true );
		$value       = null;
		$error       = null;
		$diagnostics = [];

		/*
		 * Warnings, notices and deprecations are not thrown, so the catch below
		 * never sees them. Left alone they either print into the captured
		 * output or vanish entirely depending on display_errors, and code that
		 * half-worked reports success either way -- an undefined array key
		 * reads exactly like a clean run.
		 *
		 * The handler returns true, which stops PHP printing them as well, so
		 * they arrive as data instead of contaminating `output`. Fatals are
		 * unaffected: set_error_handler never sees those, and the recovery
		 * guard's error_get_last() still does.
		 */
		set_error_handler(
			static function ( int $severity, string $message, string $file = '', int $line = 0 ) use ( &$diagnostics ): bool {
				if ( count( $diagnostics ) < self::MAX_DIAGNOSTICS ) {
					$diagnostics[] = [
						'type'    => self::severity_name( $severity ),
						'message' => $message,
						'file'    => $file,
						'line'    => $line,
					];
				}
				return true;
			}
		);

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
			restore_error_handler();
		}

		$truncated = count( $diagnostics ) >= self::MAX_DIAGNOSTICS;

		return [
			'success'      => null === $error,
			'return_value' => self::serializable( $value ),
			'output'       => $printed,
			'error'        => $error,
			'errors'       => $diagnostics,
			'errors_note'  => $truncated
				? 'Stopped collecting at ' . self::MAX_DIAGNOSTICS . '; more were raised.'
				: null,
			'ms'           => round( ( microtime( true ) - $started ) * 1000, 2 ),
		];
	}

	/**
	 * A readable name for an E_* constant. Unknown values are reported as their
	 * number rather than guessed at, since new levels do get added.
	 */
	private static function severity_name( int $severity ): string {
		$names = [
			E_WARNING           => 'warning',
			E_NOTICE            => 'notice',
			E_DEPRECATED        => 'deprecated',
			E_STRICT            => 'strict',
			E_RECOVERABLE_ERROR => 'recoverable error',
			E_USER_WARNING      => 'user warning',
			E_USER_NOTICE       => 'user notice',
			E_USER_DEPRECATED   => 'user deprecated',
			E_USER_ERROR        => 'user error',
		];

		return $names[ $severity ] ?? 'severity ' . $severity;
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
