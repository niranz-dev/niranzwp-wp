<?php
/**
 * One-file upload endpoint.
 *
 * write-file carries the whole file as a JSON string, which is fine for a
 * snippet and wrong for anything large: the payload has to be escaped, held in
 * memory twice, and pushed through a request body sized for arguments rather
 * than content. The usual workaround is to send it in pieces, and that is the
 * dangerous part -- on 18 August a 176 KB file went into wp-content/mu-plugins/
 * as four chunks written under its final name, and the first chunk left an
 * unterminated array being included on every request. WordPress auto-loads
 * everything at the top level of that directory, REST included, so the chunk
 * that broke the site also removed the only route the remaining chunks had.
 * The upload could not have finished.
 *
 * So this hands out a short-lived, single-use endpoint instead. One request,
 * one file, no chunking to get wrong.
 *
 * The bytes land in a .part file, which nothing loads, and only move into place
 * after they have been counted, hashed against what the caller said they were
 * sending, and -- for PHP -- parsed. The move is a rename(), which is atomic:
 * the destination is either the old file or the new one and never half of
 * either. A transfer that dies partway leaves a stray .part behind and nothing
 * else.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Upload {

	private const NAMESPACE   = 'niranzwp/v1';
	private const ROUTE       = '/upload';
	private const PREFIX      = 'niranzwp_upload_';
	private const MAX_BYTES   = 67108864;  // 64 MB
	private const MAX_EXPIRY  = 3600;      // one hour
	private const DEF_EXPIRY  = 900;       // fifteen minutes
	private const READ_CHUNK  = 262144;    // 256 KB

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'route' ] );
	}

	/** Same gate as the rest of the filesystem abilities, and the same opt-in. */
	public static function permission(): bool {
		return Files::permission();
	}

	public static function register(): void {
		register_ability(
			'niranzwp/create-upload-link',
			[
				'label'               => __( 'Create upload link', 'niranzwp' ),
				'description'         => __( 'Mints a single-use endpoint and bearer token for uploading one file into the WordPress install. Use this instead of write-file for anything large or binary: ZIPs, plugins, themes, media, generated PHP. The upload lands in a .part file and is only moved into place once its size and hash match what was declared, and once PHP parses. Nothing is written on failure.', 'niranzwp' ),
				'category'            => 'niranzwp-filesystem',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'path'               => [
							'type'        => 'string',
							'description' => 'Destination, relative to the WordPress root.',
						],
						'sha256'             => [
							'type'        => 'string',
							'description' => 'Hex sha256 of the file being sent. The upload is rejected if what arrives does not match. Strongly recommended.',
						],
						'bytes'              => [
							'type'        => 'integer',
							'description' => 'Exact size in bytes, if known. Checked on arrival.',
						],
						'max_bytes'          => [
							'type'        => 'integer',
							'description' => 'Refuse anything larger. Defaults to 64 MB.',
						],
						'expires_in'         => [
							'type'        => 'integer',
							'description' => 'Seconds the link stays valid. Default 900, maximum 3600.',
						],
						'overwrite'          => [
							'type'        => 'boolean',
							'description' => 'Allow replacing an existing file. Default false.',
							'default'     => false,
						],
						'create_directories' => [
							'type'        => 'boolean',
							'description' => 'Create missing parent directories. Default false.',
							'default'     => false,
						],
					],
					'required'   => [ 'path' ],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => [ self::class, 'permission' ],
				'execute_callback'    => [ self::class, 'create' ],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [ 'readonly' => false, 'destructive' => true ],
				],
			]
		);
	}

	/**
	 * Mint a ticket.
	 *
	 * The token is returned once and stored only as a hash, so a leaked
	 * database row cannot be replayed as an upload.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];

		// The parent has to exist before the path can be resolved, because
		// resolution is realpath-based and realpath answers nothing about a
		// directory that is not there yet. So containment is established
		// first, against the deepest ancestor that does exist, and only then
		// is the tree created.
		$prepared = self::prepare_path(
			(string) ( $input['path'] ?? '' ),
			! empty( $input['create_directories'] )
		);
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$path = Files::resolve_path( $prepared, false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$overwrite = ! empty( $input['overwrite'] );

		if ( file_exists( $path ) && ! $overwrite ) {
			return new \WP_Error(
				'niranzwp_exists',
				'That file already exists. Pass overwrite true to replace it.',
				[ 'status' => 409 ]
			);
		}

		$dir = dirname( $path );

		if ( ! is_writable( $dir ) ) {
			return new \WP_Error( 'niranzwp_not_writable', 'The destination directory is not writable.' );
		}

		$max = (int) ( $input['max_bytes'] ?? self::MAX_BYTES );
		$max = max( 1, min( $max, self::MAX_BYTES ) );

		$ttl = (int) ( $input['expires_in'] ?? self::DEF_EXPIRY );
		$ttl = max( 30, min( $ttl, self::MAX_EXPIRY ) );

		$sha = strtolower( trim( (string) ( $input['sha256'] ?? '' ) ) );
		if ( '' !== $sha && ! preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
			return new \WP_Error( 'niranzwp_bad_sha', 'sha256 must be 64 hexadecimal characters.', [ 'status' => 400 ] );
		}

		$token = bin2hex( random_bytes( 32 ) );

		set_transient(
			self::PREFIX . hash( 'sha256', $token ),
			[
				'path'      => $path,
				'sha256'    => $sha,
				'bytes'     => isset( $input['bytes'] ) ? (int) $input['bytes'] : null,
				'max_bytes' => $max,
				'overwrite' => $overwrite,
				'user'      => get_current_user_id(),
			],
			$ttl
		);

		return [
			'url'        => rest_url( self::NAMESPACE . self::ROUTE ),
			'token'      => $token,
			'method'     => 'POST',
			'header'     => 'Authorization: Bearer ' . $token,
			'expires_in' => $ttl,
			'max_bytes'  => $max,
			'path'       => ltrim( str_replace( (string) realpath( ABSPATH ), '', $path ), '/' ),
			'note'       => 'Single use. POST the raw file bytes as the request body with that Authorization header. The link stops working after one attempt, successful or not.',
		];
	}

	/**
	 * Make sure the destination's parent exists, without ever trusting the
	 * caller's path to stay inside the install.
	 *
	 * Traversal is rejected on the normalised segments rather than on the
	 * string, so "a/../../etc" is caught whatever spelling arrives. Containment
	 * is then checked against the deepest ancestor that actually exists, which
	 * is the only part realpath can speak for; the missing tail is created
	 * beneath a directory already proven to be inside the root.
	 *
	 * Public because write-file needs the same treatment for its own
	 * create_directories: resolution is realpath-based either way.
	 *
	 * @return string|\WP_Error The relative path, ready for Files::resolve_path.
	 */
	public static function prepare_path( string $path, bool $create ) {
		$path = ltrim( str_replace( '\\', '/', trim( $path ) ), '/' );

		if ( '' === $path ) {
			return new \WP_Error( 'niranzwp_bad_path', 'Path is empty.', [ 'status' => 400 ] );
		}

		$segments = [];
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return new \WP_Error( 'niranzwp_traversal', 'Path may not contain "..".', [ 'status' => 400 ] );
			}
			$segments[] = $segment;
		}

		if ( count( $segments ) < 2 ) {
			return new \WP_Error( 'niranzwp_bad_path', 'Path must name a file inside a directory.', [ 'status' => 400 ] );
		}

		$relative = implode( '/', $segments );
		$base     = (string) realpath( ABSPATH );
		$dir      = $base . '/' . implode( '/', array_slice( $segments, 0, -1 ) );

		if ( is_dir( $dir ) ) {
			$real = realpath( $dir );
			if ( false === $real || ! str_starts_with( $real . '/', $base . '/' ) ) {
				return new \WP_Error( 'niranzwp_outside_root', 'Path resolves outside the WordPress root.', [ 'status' => 400 ] );
			}
			return $relative;
		}

		if ( ! $create ) {
			return new \WP_Error(
				'niranzwp_no_directory',
				'The parent directory does not exist. Pass create_directories true to create it.',
				[ 'status' => 400 ]
			);
		}

		// Walk up to something real, and prove that is inside the root before
		// creating anything beneath it.
		$probe = $dir;
		while ( ! is_dir( $probe ) && dirname( $probe ) !== $probe ) {
			$probe = dirname( $probe );
		}
		$anchor = realpath( $probe );
		if ( false === $anchor || ( $anchor !== $base && ! str_starts_with( $anchor . '/', $base . '/' ) ) ) {
			return new \WP_Error( 'niranzwp_outside_root', 'Path resolves outside the WordPress root.', [ 'status' => 400 ] );
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'niranzwp_mkdir_failed', 'Could not create the parent directory.' );
		}

		return $relative;
	}

	public static function route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'receive' ],
				// The bearer token is the credential here, deliberately: the
				// point of the endpoint is that a tool which cannot hold a
				// WordPress login can still deliver one file to one path.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Receive the bytes.
	 *
	 * The ticket is consumed before anything is read, so a transfer that fails
	 * or is replayed cannot get a second attempt at the same destination.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function receive( \WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( ! preg_match( '/^Bearer\s+([0-9a-f]{64})$/i', trim( $header ), $m ) ) {
			return new \WP_Error( 'niranzwp_no_token', 'Missing or malformed bearer token.', [ 'status' => 401 ] );
		}

		$key    = self::PREFIX . hash( 'sha256', strtolower( $m[1] ) );
		$ticket = get_transient( $key );

		if ( ! is_array( $ticket ) ) {
			return new \WP_Error( 'niranzwp_bad_token', 'That upload link is unknown, already used, or expired.', [ 'status' => 403 ] );
		}

		// Single use, decided now rather than after a long transfer.
		delete_transient( $key );

		$path = (string) $ticket['path'];
		$part = $path . '.part';

		$in = fopen( 'php://input', 'rb' );
		if ( ! $in ) {
			return new \WP_Error( 'niranzwp_no_body', 'Could not read the request body.', [ 'status' => 400 ] );
		}

		$out = fopen( $part, 'wb' );
		if ( ! $out ) {
			fclose( $in );
			return new \WP_Error( 'niranzwp_no_temp', 'Could not open a temporary file next to the destination.' );
		}

		$written = 0;
		$hash    = hash_init( 'sha256' );
		$oversize = false;

		while ( ! feof( $in ) ) {
			$buf = fread( $in, self::READ_CHUNK );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			$written += strlen( $buf );
			if ( $written > (int) $ticket['max_bytes'] ) {
				$oversize = true;
				break;
			}
			hash_update( $hash, $buf );
			if ( false === fwrite( $out, $buf ) ) {
				fclose( $in );
				fclose( $out );
				@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new \WP_Error( 'niranzwp_write_failed', 'Could not write to disk. Check filesystem permissions.' );
			}
		}

		fclose( $in );
		fclose( $out );

		if ( $oversize ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error(
				'niranzwp_too_large',
				sprintf( 'Body exceeded max_bytes (%d). Nothing was written.', (int) $ticket['max_bytes'] ),
				[ 'status' => 413 ]
			);
		}

		if ( 0 === $written ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'niranzwp_empty', 'The request body was empty. Nothing was written.', [ 'status' => 400 ] );
		}

		$digest = hash_final( $hash );

		if ( null !== $ticket['bytes'] && $written !== (int) $ticket['bytes'] ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error(
				'niranzwp_size_mismatch',
				sprintf( 'Expected %d bytes, received %d. Nothing was written.', (int) $ticket['bytes'], $written ),
				[ 'status' => 400 ]
			);
		}

		if ( '' !== $ticket['sha256'] && ! hash_equals( (string) $ticket['sha256'], $digest ) ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error(
				'niranzwp_hash_mismatch',
				'What arrived does not match the declared sha256. Nothing was written.',
				[ 'status' => 422 ]
			);
		}

		// A PHP file that does not parse takes the site down the moment
		// WordPress loads it, and the .part file is the last moment it can be
		// caught for free.
		$rel = ltrim( str_replace( (string) realpath( ABSPATH ), '', $path ), '/' );
		if ( str_ends_with( strtolower( $rel ), '.php' ) ) {
			$parse = Files::parse_error( $rel, (string) file_get_contents( $part ) );
			if ( null !== $parse ) {
				@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new \WP_Error(
					'niranzwp_php_syntax',
					sprintf( 'That PHP does not parse: %s. Nothing was written.', $parse ),
					[ 'status' => 400 ]
				);
			}
		}

		$existed = file_exists( $path );
		$before  = $existed ? (string) file_get_contents( $path ) : null;

		if ( $existed && empty( $ticket['overwrite'] ) ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'niranzwp_exists', 'That file appeared while the upload was in flight, and overwrite was not set.', [ 'status' => 409 ] );
		}

		$checkpoint = Checkpoint::before_file( $rel, 'create-upload-link' );

		// Record the previous contents before the swap. If this request is the
		// last one the site survives, the guard puts them back on the next load.
		Recovery::arm( $path, $before );

		if ( ! rename( $part, $path ) ) {
			Recovery::disarm();
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'niranzwp_rename_failed', 'Could not move the upload into place. Nothing was changed.' );
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		return new \WP_REST_Response(
			[
				'path'          => '/' . $rel,
				'bytes'         => $written,
				'sha256'        => $digest,
				'replaced'      => $existed,
				'checkpoint_id' => $checkpoint,
				'guarded'       => Recovery::installed(),
				'status'        => 'written',
			],
			201
		);
	}
}
