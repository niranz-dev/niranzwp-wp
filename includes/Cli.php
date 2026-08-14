<?php
/**
 * WP-CLI ability.
 *
 * Shells out to the `wp` binary against this installation. That is the same
 * reach as evaluating PHP, so it shares the "PHP evaluation" opt-in rather
 * than adding a fourth switch that could be confused for something milder.
 *
 * Many hosts disable proc_open or ship no wp binary; both are reported
 * plainly instead of failing with an empty result.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Cli {

	private const TIMEOUT   = 120;
	private const MAX_BYTES = 200000;

	public static function permission(): bool {
		// Deliberately the same gate as Runtime: a shell is not a smaller
		// permission than eval().
		return Runtime::permission();
	}

	public static function register(): void {
		wp_register_ability(
			'niranzwp/run-wp-cli',
			[
				'label'               => __( 'Run WP-CLI', 'niranzwp' ),
				'description'         => __( 'Runs a WP-CLI command against this installation and returns stdout, stderr and the exit code. Reports what it would run unless dry_run is false. Requires the wp binary and shell execution on the host.', 'niranzwp' ),
				'category'            => 'niranzwp',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'command' => [
							'type'        => 'string',
							'description' => 'The command without the leading "wp", e.g. "plugin list --status=active".',
						],
						'dry_run' => [ 'type' => 'boolean', 'default' => true ],
					],
					'required'   => [ 'command' ],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => [ self::class, 'permission' ],
				'execute_callback'    => [ self::class, 'run' ],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [ 'readonly' => false, 'destructive' => true ],
				],
			]
		);

		wp_register_ability(
			'niranzwp/wp-cli-status',
			[
				'label'               => __( 'WP-CLI status', 'niranzwp' ),
				'description'         => __( 'Reports whether WP-CLI can run on this host: shell availability, the resolved wp binary and its version.', 'niranzwp' ),
				'category'            => 'niranzwp',
				'input_schema'        => [ 'type' => 'object', 'properties' => (object) [] ],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => [ self::class, 'permission' ],
				'execute_callback'    => [ self::class, 'status' ],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [ 'readonly' => true, 'destructive' => false ],
				],
			]
		);
	}

	/** Is a shell usable here at all? */
	private static function shell_available(): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'proc_open', $disabled, true );
	}

	/** Find a wp binary, preferring one on PATH. */
	private static function binary(): ?string {
		if ( ! self::shell_available() ) {
			return null;
		}
		foreach ( [ 'wp', '/usr/local/bin/wp', '/usr/bin/wp', '/opt/homebrew/bin/wp' ] as $candidate ) {
			$found = self::exec( 'command -v ' . escapeshellarg( $candidate ) . ' 2>/dev/null', 5 );
			if ( 0 === $found['exit'] && '' !== trim( $found['stdout'] ) ) {
				return trim( $found['stdout'] );
			}
		}
		return null;
	}

	/**
	 * @return array{stdout:string,stderr:string,exit:int}
	 */
	private static function exec( string $command, int $timeout ): array {
		$descriptors = [
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];

		$process = proc_open( $command, $descriptors, $pipes, ABSPATH );
		if ( ! is_resource( $process ) ) {
			return [ 'stdout' => '', 'stderr' => 'could not start a shell', 'exit' => -1 ];
		}

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout   = '';
		$stderr   = '';
		$deadline = microtime( true ) + $timeout;

		while ( true ) {
			$stdout .= (string) stream_get_contents( $pipes[1] );
			$stderr .= (string) stream_get_contents( $pipes[2] );

			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				break;
			}
			if ( microtime( true ) > $deadline ) {
				proc_terminate( $process, 9 );
				$stderr .= "\ncommand exceeded the {$timeout}s limit and was terminated";
				break;
			}
			usleep( 50000 );
		}

		$stdout .= (string) stream_get_contents( $pipes[1] );
		$stderr .= (string) stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );

		return [
			'stdout' => substr( $stdout, 0, self::MAX_BYTES ),
			'stderr' => substr( $stderr, 0, self::MAX_BYTES ),
			'exit'   => $exit,
		];
	}

	/** @return array<string,mixed> */
	public static function status(): array {
		$shell  = self::shell_available();
		$binary = $shell ? self::binary() : null;

		$version = null;
		if ( $binary ) {
			$r = self::exec( escapeshellarg( $binary ) . ' --version --allow-root 2>&1', 15 );
			$version = trim( $r['stdout'] ) ?: null;
		}

		return [
			'shell_available' => $shell,
			'binary'          => $binary,
			'version'         => $version,
			'usable'          => (bool) $binary,
			'note'            => $shell
				? ( $binary ? null : 'No wp binary found on PATH. Install WP-CLI on the host to use this ability.' )
				: 'proc_open is unavailable or disabled on this host, so WP-CLI cannot run.',
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$dry     = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$command = trim( (string) ( $input['command'] ?? '' ) );

		if ( '' === $command ) {
			return new \WP_Error( 'niranzwp_no_command', 'No command supplied.' );
		}

		// Accept "wp plugin list" or "plugin list" alike.
		$command = (string) preg_replace( '/^wp\s+/', '', $command );

		if ( ! self::shell_available() ) {
			return new \WP_Error( 'niranzwp_no_shell', 'proc_open is unavailable or disabled on this host, so WP-CLI cannot run.' );
		}

		$binary = self::binary();
		if ( ! $binary ) {
			return new \WP_Error( 'niranzwp_no_wp_cli', 'No wp binary found on PATH. Install WP-CLI on the host to use this ability.' );
		}

		$full = sprintf(
			'%s %s --path=%s --allow-root',
			escapeshellarg( $binary ),
			$command,
			escapeshellarg( ABSPATH )
		);

		if ( $dry ) {
			return [
				'command' => 'wp ' . $command,
				'resolved'=> $full,
				'status'  => 'would_run',
				'dry_run' => true,
				'note'    => 'Nothing was run. Pass dry_run false to execute.',
			];
		}

		$started = microtime( true );
		$result  = self::exec( $full . ' 2>&1', self::TIMEOUT );

		return [
			'command'   => 'wp ' . $command,
			'exit_code' => $result['exit'],
			'success'   => 0 === $result['exit'],
			'output'    => $result['stdout'],
			'stderr'    => $result['stderr'],
			'ms'        => round( ( microtime( true ) - $started ) * 1000, 2 ),
			'dry_run'   => false,
		];
	}
}
