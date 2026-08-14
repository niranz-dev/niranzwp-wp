<?php
/**
 * Filesystem abilities.
 *
 * Confined to ABSPATH, with traversal and symlink escapes rejected at resolve
 * time rather than trusted from the caller. Writes and deletes preview by
 * default; wp-config.php and the core directories are refused outright.
 *
 * These are gated twice: the normal ability gate, plus a separate opt-in that
 * stays off even when the plugin's other abilities are enabled.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Files {

	private const PROTECTED_DIRS  = [ 'wp-admin', 'wp-includes' ];
	private const PROTECTED_FILES = [ 'wp-config.php' ];
	private const MAX_READ        = 2097152; // 2 MB

	public static function enabled(): bool {
		$s = get_option( OPTION_KEY, [] );
		return is_array( $s ) && ! empty( $s['files'] );
	}

	public static function permission(): bool {
		return Settings::active() && self::enabled() && current_user_can( CAPABILITY );
	}

	public static function register(): void {
		$gate = [ self::class, 'permission' ];
		$ro   = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ];
		$rw   = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ];

		wp_register_ability( 'niranzwp/read-file', [
			'label'               => __( 'Read file', 'niranzwp' ),
			'description'         => __( 'Reads a file inside the WordPress install. wp-config.php is refused because it holds database credentials and salts.', 'niranzwp' ),
			'category'            => 'niranzwp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'path' => [ 'type' => 'string' ] ],
				'required'   => [ 'path' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'read' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/list-directory', [
			'label'               => __( 'List directory', 'niranzwp' ),
			'description'         => __( 'Lists files and directories inside the WordPress install.', 'niranzwp' ),
			'category'            => 'niranzwp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'path' => [ 'type' => 'string', 'default' => '' ] ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'list_dir' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/write-file', [
			'label'               => __( 'Write file', 'niranzwp' ),
			'description'         => __( 'Writes a file inside the WordPress install. Reports what would change unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'path'    => [ 'type' => 'string' ],
					'content' => [ 'type' => 'string' ],
					'dry_run' => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'path', 'content' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'write' ],
			'meta'                => $rw,
		] );

		wp_register_ability( 'niranzwp/delete-file', [
			'label'               => __( 'Delete file', 'niranzwp' ),
			'description'         => __( 'Deletes a single file inside the WordPress install. Core directories and wp-config.php are refused. Previews unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'path'    => [ 'type' => 'string' ],
					'dry_run' => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'path' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'remove' ],
			'meta'                => $rw,
		] );
	}

	/**
	 * Resolve a caller-supplied path to a real path inside ABSPATH.
	 * Rejects traversal, symlinks that escape the root, and protected files.
	 *
	 * @return string|\WP_Error
	 */
	private static function resolve( string $path, bool $must_exist = true ) {
		$path = ltrim( str_replace( '\\', '/', trim( $path ) ), '/' );

		if ( '' === $path ) {
			return new \WP_Error( 'niranzwp_bad_path', 'Path is empty.' );
		}
		foreach ( self::PROTECTED_FILES as $blocked ) {
			if ( basename( $path ) === $blocked ) {
				return new \WP_Error( 'niranzwp_protected', $blocked . ' is protected and cannot be accessed through this ability.' );
			}
		}

		$base = (string) realpath( ABSPATH );
		$full = $base . '/' . $path;
		$real = realpath( $full );

		if ( false === $real ) {
			if ( $must_exist ) {
				return new \WP_Error( 'niranzwp_not_found', 'No such file: ' . $path );
			}
			// A new file is allowed only if its parent already sits inside root.
			$parent = realpath( dirname( $full ) );
			if ( false === $parent || ! str_starts_with( $parent . '/', $base . '/' ) ) {
				return new \WP_Error( 'niranzwp_outside_root', 'Path resolves outside the WordPress root.' );
			}
			return $full;
		}

		if ( $real !== $base && ! str_starts_with( $real . '/', $base . '/' ) ) {
			return new \WP_Error( 'niranzwp_outside_root', 'Path resolves outside the WordPress root.' );
		}

		return $real;
	}

	private static function rel( string $abs ): string {
		return '/' . ltrim( str_replace( (string) realpath( ABSPATH ), '', $abs ), '/' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function read( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$path = self::resolve( (string) ( $input['path'] ?? '' ) );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( is_dir( $path ) ) {
			return new \WP_Error( 'niranzwp_is_dir', 'That path is a directory. Use list-directory.' );
		}

		$size = (int) filesize( $path );
		if ( $size > self::MAX_READ ) {
			return new \WP_Error( 'niranzwp_too_large', sprintf( 'File is %d bytes; the limit is %d.', $size, self::MAX_READ ) );
		}

		return [
			'path'     => self::rel( $path ),
			'bytes'    => $size,
			'modified' => gmdate( 'c', (int) filemtime( $path ) ),
			'content'  => (string) file_get_contents( $path ),
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function list_dir( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$rel  = (string) ( $input['path'] ?? '' );
		$path = '' === $rel ? (string) realpath( ABSPATH ) : self::resolve( $rel );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! is_dir( $path ) ) {
			return new \WP_Error( 'niranzwp_not_dir', 'That path is not a directory.' );
		}

		$entries = [];
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$full      = $path . '/' . $name;
			$is_dir    = is_dir( $full );
			$entries[] = [
				'name'     => $name,
				'type'     => $is_dir ? 'directory' : 'file',
				'size'     => $is_dir ? 0 : (int) filesize( $full ),
				'modified' => gmdate( 'c', (int) filemtime( $full ) ),
			];
		}

		return [ 'path' => self::rel( $path ), 'total' => count( $entries ), 'entries' => $entries ];
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function write( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$dry     = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$content = (string) ( $input['content'] ?? '' );
		$path    = self::resolve( (string) ( $input['path'] ?? '' ), false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$exists = file_exists( $path );
		$before = $exists ? (string) file_get_contents( $path ) : '';

		$out = [
			'path'         => self::rel( $path ),
			'existed'      => $exists,
			'bytes_before' => strlen( $before ),
			'bytes_after'  => strlen( $content ),
			'dry_run'      => $dry,
		];

		if ( $before === $content ) {
			$out['status'] = 'unchanged';
			return $out;
		}
		if ( $dry ) {
			$out['status'] = 'would_write';
			$out['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $out;
		}

		// Snapshot first. A checkpoint that could not be taken is reported, not
		// fatal -- refusing the write because the undo failed would be worse.
		$out['checkpoint_id'] = Checkpoint::before_file( ltrim( self::rel( $path ), '/' ), 'write-file' );

		if ( false === file_put_contents( $path, $content ) ) {
			return new \WP_Error( 'niranzwp_write_failed', 'Could not write the file. Check filesystem permissions.' );
		}

		$out['status'] = 'written';
		return $out;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function remove( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$dry  = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$path = self::resolve( (string) ( $input['path'] ?? '' ) );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$rel = ltrim( self::rel( $path ), '/' );
		foreach ( self::PROTECTED_DIRS as $dir ) {
			if ( $rel === $dir || str_starts_with( $rel, $dir . '/' ) ) {
				return new \WP_Error( 'niranzwp_protected', $dir . ' is a WordPress core directory and cannot be touched.' );
			}
		}
		if ( is_dir( $path ) ) {
			return new \WP_Error( 'niranzwp_is_dir', 'Refusing to delete a directory. Delete files individually.' );
		}

		if ( $dry ) {
			return [
				'path'    => '/' . $rel,
				'bytes'   => (int) filesize( $path ),
				'status'  => 'would_delete',
				'dry_run' => true,
				'note'    => 'Nothing was deleted. Pass dry_run false to apply.',
			];
		}
		$checkpoint = Checkpoint::before_file( $rel, 'delete-file' );

		if ( ! unlink( $path ) ) {
			return new \WP_Error( 'niranzwp_delete_failed', 'Could not delete the file.' );
		}

		return [ 'path' => '/' . $rel, 'status' => 'deleted', 'dry_run' => false, 'checkpoint_id' => $checkpoint ];
	}
}
