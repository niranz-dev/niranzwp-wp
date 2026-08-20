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
	private const MAX_READ        = 2097152; // 2 MB, per call rather than per file
	private const MAX_ENTRIES     = 5000;
	private const MAX_DEPTH       = 20;

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

		register_ability( 'niranzwp/read-file', [
			'label'               => __( 'Read file', 'niranzwp' ),
			'description'         => __( 'Reads a file inside the WordPress install, whole or in slices. A file larger than the 2 MB per-call limit is read with offset and limit rather than refused. wp-config.php is refused because it holds database credentials and salts.', 'niranzwp' ),
			'category'            => 'niranzwp-filesystem',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'path'   => [ 'type' => 'string' ],
					'offset' => [
						'type'        => 'integer',
						'description' => 'Byte to start at. Default 0.',
						'minimum'     => 0,
					],
					'limit'  => [
						'type'        => 'integer',
						'description' => 'How many bytes to return. Defaults to the rest of the file, capped at 2 MB per call. The response carries next_offset until eof.',
						'minimum'     => 0,
					],
				],
				'required'   => [ 'path' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'read' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/list-directory', [
			'label'               => __( 'List directory', 'niranzwp' ),
			'description'         => __( 'Lists files and directories inside the WordPress install, optionally walking subdirectories and filtering by name. Symlinks are skipped, so a recursive walk cannot report anything outside the install.', 'niranzwp' ),
			'category'            => 'niranzwp-filesystem',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'path'           => [ 'type' => 'string', 'default' => '' ],
					'recursive'      => [
						'type'        => 'boolean',
						'description' => 'Walk subdirectories. Names are returned relative to the starting path.',
						'default'     => false,
					],
					'pattern'        => [
						'type'        => 'string',
						'description' => 'Shell glob matched against each entry name, e.g. "*.php". Directories are still descended into when recursive.',
					],
					'max_depth'      => [
						'type'        => 'integer',
						'description' => 'How deep to walk. Default and maximum 20.',
						'minimum'     => 1,
					],
					'limit'          => [
						'type'        => 'integer',
						'description' => 'Most entries to return. Default and maximum 5000. The response says truncated when it stops early.',
						'minimum'     => 1,
					],
					'include_hidden' => [
						'type'        => 'boolean',
						'description' => 'Include dotfiles. Default false.',
						'default'     => false,
					],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'list_dir' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/write-file', [
			'label'               => __( 'Write file', 'niranzwp' ),
			'description'         => __( 'Writes a file inside the WordPress install, replacing it or appending to it. PHP that does not parse is refused before anything is written. Reports what would change unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp-filesystem',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'path'               => [ 'type' => 'string' ],
					'content'            => [ 'type' => 'string' ],
					'mode'               => [
						'type'        => 'string',
						'enum'        => [ 'overwrite', 'append' ],
						'description' => 'overwrite replaces the file, append adds to the end of it. Default overwrite.',
						'default'     => 'overwrite',
					],
					'encoding'           => [
						'type'        => 'string',
						'enum'        => [ 'utf-8', 'base64' ],
						'description' => 'base64 lets a binary file through, though create-upload-link is the better route for anything large.',
						'default'     => 'utf-8',
					],
					'create_directories' => [
						'type'        => 'boolean',
						'description' => 'Create missing parent directories. Default false.',
						'default'     => false,
					],
					'dry_run'            => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'path', 'content' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'write' ],
			'meta'                => $rw,
		] );

		register_ability( 'niranzwp/edit-file', [
			'label'               => __( 'Edit file', 'niranzwp' ),
			'description'         => __( 'Replaces an exact string inside an existing file. The old string must appear exactly once unless replace_all is set, so an edit cannot land somewhere it was not meant to. PHP is parsed after the replacement and refused if it breaks. Previews unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp-filesystem',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'path'        => [ 'type' => 'string' ],
					'old_string'  => [
						'type'        => 'string',
						'description' => 'Exact text to find, whitespace included.',
						'minLength'   => 1,
					],
					'new_string'  => [
						'type'        => 'string',
						'description' => 'What to put in its place. An empty string deletes the match.',
					],
					'replace_all' => [
						'type'        => 'boolean',
						'description' => 'Replace every occurrence. Without this, more than one match is an error rather than a guess.',
						'default'     => false,
					],
					'dry_run'     => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'path', 'old_string', 'new_string' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'edit' ],
			'meta'                => $rw,
		] );

		register_ability( 'niranzwp/disable-file', [
			'label'               => __( 'Disable file', 'niranzwp' ),
			'description'         => __( 'Renames a file to <name>.disabled so nothing loads it, without deleting it. Works anywhere inside the install, so a misbehaving mu-plugin, plugin or theme file can be switched off and switched back on. Previews unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp-filesystem',
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
			'execute_callback'    => [ self::class, 'disable' ],
			'meta'                => $rw,
		] );

		register_ability( 'niranzwp/enable-file', [
			'label'               => __( 'Enable file', 'niranzwp' ),
			'description'         => __( 'Removes the .disabled suffix so the file loads again. Give either path, with or without the suffix. Idempotent: enabling a file that is already enabled reports so rather than failing.', 'niranzwp' ),
			'category'            => 'niranzwp-filesystem',
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
			'execute_callback'    => [ self::class, 'enable' ],
			'meta'                => $rw,
		] );

		register_ability( 'niranzwp/delete-file', [
			'label'               => __( 'Delete file', 'niranzwp' ),
			'description'         => __( 'Deletes a file, or a whole directory when recursive is set. Core directories and wp-config.php are refused, and a tree containing a symlink is refused rather than followed. Previews unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp-filesystem',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'path'      => [ 'type' => 'string' ],
					'recursive' => [
						'type'        => 'boolean',
						'description' => 'Delete a directory and everything inside it. A recursive delete is not checkpointed and cannot be undone from here.',
						'default'     => false,
					],
					'dry_run'   => [ 'type' => 'boolean', 'default' => true ],
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

		// PHP keeps a realpath cache per process, for two minutes by default,
		// and PHP-FPM hands successive requests to whichever worker is free.
		// So a file renamed by one request can still look present to the next,
		// and every existence decision below would be answered from a cache
		// rather than from the disk. Ask about this one path specifically.
		clearstatcache( true, $full );

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

	/**
	 * Does this content parse as PHP?
	 *
	 * token_get_all() with TOKEN_PARSE runs the real parser and throws on a
	 * syntax error, without executing anything. That is the whole check: a
	 * file that parses may still be wrong, but a file that does not parse is
	 * certain to be fatal.
	 *
	 * @return string|null The parse error, or null when the content is fine.
	 */
	/**
	 * Exposed so the upload endpoint can apply the same gate to a file it
	 * received rather than one it was handed as a string.
	 */
	public static function parse_error( string $rel, string $content ): ?string {
		return self::php_parse_error( $rel, $content );
	}

	/**
	 * Exposed for the same reason: an upload has to land somewhere inside the
	 * install, decided by the same resolver and not by the caller.
	 *
	 * @return string|\WP_Error
	 */
	public static function resolve_path( string $path, bool $must_exist = true ) {
		return self::resolve( $path, $must_exist );
	}

	private static function php_parse_error( string $rel, string $content ): ?string {
		$looks_php = str_ends_with( strtolower( $rel ), '.php' ) || str_contains( $content, '<?php' );
		if ( ! $looks_php || '' === trim( $content ) ) {
			return null;
		}

		try {
			token_get_all( $content, TOKEN_PARSE );
			return null;
		} catch ( \ParseError $e ) {
			return sprintf( '%s on line %d', $e->getMessage(), $e->getLine() );
		}
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

		/*
		 * A cap on one response is reasonable; a cap on the file was not. A
		 * 3 MB debug log used to be unreadable outright, which is precisely
		 * when reading it matters. offset and limit turn the same file into a
		 * series of readable slices, and the cap now applies per call.
		 */
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : null;

		if ( $offset > $size ) {
			return new \WP_Error(
				'niranzwp_bad_offset',
				sprintf( 'Offset %d is past the end of the file (%d bytes).', $offset, $size ),
				[ 'status' => 400 ]
			);
		}

		$remaining = $size - $offset;
		$wanted    = null === $limit ? $remaining : max( 0, $limit );
		$wanted    = min( $wanted, $remaining );

		if ( $wanted > self::MAX_READ ) {
			if ( null !== $limit ) {
				return new \WP_Error(
					'niranzwp_too_large',
					sprintf( 'limit is %d bytes; the most one call may return is %d.', $limit, self::MAX_READ ),
					[ 'status' => 400 ]
				);
			}
			return new \WP_Error(
				'niranzwp_too_large',
				sprintf(
					'File is %d bytes and the most one call may return is %d. Read it in slices with offset and limit.',
					$size,
					self::MAX_READ
				),
				[ 'status' => 413 ]
			);
		}

		$content = 0 === $wanted ? '' : (string) file_get_contents( $path, false, null, $offset, $wanted );
		$next    = $offset + strlen( $content );

		return [
			'path'           => self::rel( $path ),
			'bytes'          => $size,
			'offset'         => $offset,
			'bytes_returned' => strlen( $content ),
			'next_offset'    => $next < $size ? $next : null,
			'eof'            => $next >= $size,
			'modified'       => gmdate( 'c', (int) filemtime( $path ) ),
			'content'        => $content,
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

		$recursive = ! empty( $input['recursive'] );
		$hidden    = ! empty( $input['include_hidden'] );
		$pattern   = trim( (string) ( $input['pattern'] ?? '' ) );
		$max_depth = isset( $input['max_depth'] ) ? max( 1, (int) $input['max_depth'] ) : self::MAX_DEPTH;
		$limit     = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : self::MAX_ENTRIES;
		$limit     = min( $limit, self::MAX_ENTRIES );

		$entries  = [];
		$stack    = [ [ $path, 0 ] ];
		$examined = 0;
		$truncated = false;

		while ( $stack ) {
			[ $dir, $depth ] = array_shift( $stack );

			foreach ( (array) scandir( $dir ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				if ( ! $hidden && str_starts_with( $name, '.' ) ) {
					continue;
				}

				$full   = $dir . '/' . $name;
				$is_dir = is_dir( $full );

				// A symlink pointing out of the install would otherwise let a
				// recursive walk report files that no other ability here can
				// touch.
				if ( is_link( $full ) ) {
					continue;
				}

				++$examined;

				// A directory still has to be descended into even when the
				// pattern excludes it from the listing.
				if ( $is_dir && $recursive && $depth + 1 < $max_depth ) {
					$stack[] = [ $full, $depth + 1 ];
				}

				if ( '' !== $pattern && ! fnmatch( $pattern, $name ) ) {
					continue;
				}

				if ( count( $entries ) >= $limit ) {
					$truncated = true;
					continue;
				}

				$entries[] = [
					'name'     => $recursive ? ltrim( str_replace( $path, '', $full ), '/' ) : $name,
					'type'     => $is_dir ? 'directory' : 'file',
					'size'     => $is_dir ? 0 : (int) filesize( $full ),
					'modified' => gmdate( 'c', (int) filemtime( $full ) ),
				];
			}
		}

		$out = [
			'path'      => self::rel( $path ),
			'total'     => count( $entries ),
			'recursive' => $recursive,
			'entries'   => $entries,
		];

		if ( '' !== $pattern ) {
			$out['pattern']  = $pattern;
			$out['examined'] = $examined;
		}

		// Say so rather than letting a capped list read as a complete one.
		if ( $truncated ) {
			$out['truncated'] = true;
			$out['note']      = sprintf( 'Stopped at %d entries. Narrow with pattern, or raise limit.', $limit );
		}

		return $out;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function write( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$dry     = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$content = (string) ( $input['content'] ?? '' );
		$mode    = 'append' === ( $input['mode'] ?? 'overwrite' ) ? 'append' : 'overwrite';

		// Text is the normal case; base64 is here so a binary file has a way
		// through this ability at all, though create-upload-link is the better
		// route for anything large.
		if ( 'base64' === ( $input['encoding'] ?? 'utf-8' ) ) {
			$decoded = base64_decode( $content, true );
			if ( false === $decoded ) {
				return new \WP_Error( 'niranzwp_bad_base64', 'content is not valid base64.', [ 'status' => 400 ] );
			}
			$content = $decoded;
		}

		$prepared = Upload::prepare_path(
			(string) ( $input['path'] ?? '' ),
			! empty( $input['create_directories'] )
		);
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$path = self::resolve( $prepared, false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$exists = file_exists( $path );
		$before = $exists ? (string) file_get_contents( $path ) : '';

		// Appending is a different intent from replacing, so what lands on
		// disk is built here and everything downstream - the parse gate, the
		// checkpoint, the guard - sees the file as it will actually be.
		$final = 'append' === $mode ? $before . $content : $content;

		$out = [
			'path'         => self::rel( $path ),
			'existed'      => $exists,
			'mode'         => $mode,
			'bytes_before' => strlen( $before ),
			'bytes_after'  => strlen( $final ),
			'dry_run'      => $dry,
		];

		if ( $before === $final ) {
			$out['status'] = 'unchanged';
			return $out;
		}
		// A PHP file that does not parse takes the whole site down the moment
		// WordPress loads it, and a checkpoint cannot be restored through a
		// site that will not boot. Catching it here costs nothing.
		$parse_error = self::php_parse_error( self::rel( $path ), $final );
		if ( null !== $parse_error ) {
			return new \WP_Error(
				'niranzwp_php_syntax',
				sprintf( 'That PHP does not parse: %s. Nothing was written.', $parse_error ),
				[ 'status' => 400 ]
			);
		}

		if ( $dry ) {
			$out['status'] = 'would_write';
			$out['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $out;
		}

		/*
		 * Snapshot first. A checkpoint that could not be taken is reported, not
		 * fatal -- refusing to repair a broken site because the undo failed
		 * would be worse. But it has to be reported in a way somebody reads:
		 * a null id is indistinguishable from a field nobody set, so the
		 * reason travels with it.
		 */
		$cp                   = Checkpoint::before_file_result( ltrim( self::rel( $path ), '/' ), 'write-file' );
		$out['checkpoint_id'] = $cp['id'];
		if ( null !== $cp['error'] ) {
			$out['checkpoint']       = false;
			$out['checkpoint_error'] = $cp['error'];
		}

		// Record what is being replaced before replacing it. If this request is
		// the last one the site manages, the guard puts it back on the next
		// load -- a checkpoint is no use through a site that will not boot.
		Recovery::arm( $path, $exists ? $before : null );

		if ( false === file_put_contents( $path, $final ) ) {
			Recovery::disarm();
			return new \WP_Error( 'niranzwp_write_failed', 'Could not write the file. Check filesystem permissions.' );
		}

		$out['status']    = 'written';
		$out['guarded']   = Recovery::installed();
		return $out;
	}

	/**
	 * Replace an exact string inside a file.
	 *
	 * Whole-file writes carry whole-file risk: to change one line you resend
	 * every line, and anything you got wrong about the rest goes with it. This
	 * changes only what it was pointed at, and refuses to guess -- a string
	 * that appears twice is an error rather than a coin toss about which one
	 * was meant.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function edit( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$dry   = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$old   = (string) ( $input['old_string'] ?? '' );
		$new   = (string) ( $input['new_string'] ?? '' );
		$all   = ! empty( $input['replace_all'] );

		if ( '' === $old ) {
			return new \WP_Error( 'niranzwp_no_old_string', 'old_string is required and cannot be empty.', [ 'status' => 400 ] );
		}

		$path = self::resolve( (string) ( $input['path'] ?? '' ) );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( is_dir( $path ) ) {
			return new \WP_Error( 'niranzwp_is_dir', 'That path is a directory.', [ 'status' => 400 ] );
		}

		$size = (int) filesize( $path );
		if ( $size > self::MAX_READ ) {
			return new \WP_Error(
				'niranzwp_too_large',
				sprintf( 'File is %d bytes; editing is limited to %d. Rewrite it with write-file or upload it instead.', $size, self::MAX_READ ),
				[ 'status' => 413 ]
			);
		}

		$before = (string) file_get_contents( $path );
		$count  = substr_count( $before, $old );

		if ( 0 === $count ) {
			return new \WP_Error( 'niranzwp_no_match', 'old_string does not appear in that file. Nothing was changed.', [ 'status' => 404 ] );
		}
		if ( $count > 1 && ! $all ) {
			return new \WP_Error(
				'niranzwp_ambiguous',
				sprintf( 'old_string appears %d times. Include more surrounding text to make it unique, or pass replace_all. Nothing was changed.', $count ),
				[ 'status' => 409 ]
			);
		}
		if ( $old === $new ) {
			return new \WP_Error( 'niranzwp_no_change', 'old_string and new_string are identical. Nothing to do.', [ 'status' => 400 ] );
		}

		$after       = $all ? str_replace( $old, $new, $before ) : self::replace_once( $before, $old, $new );
		$replacements = $all ? $count : 1;
		$rel         = self::rel( $path );

		$out = [
			'path'         => $rel,
			'matches'      => $count,
			'replacements' => $replacements,
			'bytes_before' => strlen( $before ),
			'bytes_after'  => strlen( $after ),
			'dry_run'      => $dry,
		];

		$parse_error = self::php_parse_error( $rel, $after );
		if ( null !== $parse_error ) {
			return new \WP_Error(
				'niranzwp_php_syntax',
				sprintf( 'That edit leaves PHP that does not parse: %s. Nothing was changed.', $parse_error ),
				[ 'status' => 400 ]
			);
		}

		if ( $dry ) {
			$out['status'] = 'would_edit';
			$out['note']   = 'Nothing was changed. Pass dry_run false to apply.';
			return $out;
		}

		$cp                   = Checkpoint::before_file_result( ltrim( $rel, '/' ), 'edit-file' );
		$out['checkpoint_id'] = $cp['id'];
		if ( null !== $cp['error'] ) {
			$out['checkpoint']       = false;
			$out['checkpoint_error'] = $cp['error'];
		}

		Recovery::arm( $path, $before );

		if ( false === file_put_contents( $path, $after ) ) {
			Recovery::disarm();
			return new \WP_Error( 'niranzwp_write_failed', 'Could not write the file. Check filesystem permissions.' );
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$out['status']  = 'edited';
		$out['guarded'] = Recovery::installed();
		return $out;
	}

	/** str_replace with no limit argument, so the first match is done by hand. */
	private static function replace_once( string $haystack, string $needle, string $replacement ): string {
		$at = strpos( $haystack, $needle );
		return false === $at ? $haystack : substr_replace( $haystack, $replacement, $at, strlen( $needle ) );
	}

	private const DISABLED_SUFFIX = '.disabled';

	/**
	 * Switch a file off by renaming it, rather than deleting it.
	 *
	 * The recovery guard covers a file that takes the site down; it does
	 * nothing for one that loads fine and behaves wrongly, which is the more
	 * common case and the one that wants bisecting. Renaming is the oldest
	 * answer to that and the only one that is instantly reversible.
	 *
	 * Deliberately not confined to a sandbox: the files worth switching off
	 * are mu-plugins, plugins and theme files, which is exactly where a
	 * sandbox-only version cannot reach.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function disable( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$dry   = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];

		$path = self::resolve( (string) ( $input['path'] ?? '' ) );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$rel = ltrim( self::rel( $path ), '/' );
		foreach ( self::PROTECTED_DIRS as $dir ) {
			if ( $rel === $dir || str_starts_with( $rel, $dir . '/' ) ) {
				// 400 rather than 403: the path is the problem, not the caller.
				// A 403 makes the CLI report insufficient_scope and send
				// whoever hit it off checking credentials.
				return new \WP_Error( 'niranzwp_protected', $dir . ' is a WordPress core directory and cannot be touched.', [ 'status' => 400 ] );
			}
		}
		if ( is_dir( $path ) ) {
			return new \WP_Error( 'niranzwp_is_dir', 'That path is a directory.', [ 'status' => 400 ] );
		}
		if ( str_ends_with( $path, self::DISABLED_SUFFIX ) ) {
			return new \WP_Error( 'niranzwp_already_disabled', 'That file is already disabled.', [ 'status' => 409 ] );
		}

		$target = $path . self::DISABLED_SUFFIX;
		if ( file_exists( $target ) ) {
			return new \WP_Error(
				'niranzwp_target_exists',
				sprintf( '%s already exists. Remove or enable it first, so nothing is overwritten.', self::rel( $target ) ),
				[ 'status' => 409 ]
			);
		}

		$out = [
			'path'     => '/' . $rel,
			'disabled' => self::rel( $target ),
			'bytes'    => (int) filesize( $path ),
			'dry_run'  => $dry,
		];

		if ( $dry ) {
			$out['status'] = 'would_disable';
			$out['note']   = 'Nothing was renamed. Pass dry_run false to apply.';
			return $out;
		}

		/*
		 * If switching this off is what breaks the next request, the guard
		 * puts the original back. The .disabled copy is left behind in that
		 * case rather than cleaned up, because a guard that deletes files
		 * while recovering is a worse idea than a stray file.
		 */
		Recovery::arm( $path, (string) file_get_contents( $path ) );

		if ( ! rename( $path, $target ) ) {
			Recovery::disarm();
			return new \WP_Error( 'niranzwp_rename_failed', 'Could not rename the file. Check filesystem permissions.' );
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$out['status']  = 'disabled';
		$out['guarded'] = Recovery::installed();
		$out['note']    = 'Reverse it with enable-file on the same path.';
		return $out;
	}

	/**
	 * Put a disabled file back.
	 *
	 * Takes the path either way round, because whoever disabled it knows the
	 * original name and whoever found it later knows the suffixed one.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function enable( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$dry   = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$given = (string) ( $input['path'] ?? '' );

		$suffixed = str_ends_with( $given, self::DISABLED_SUFFIX ) ? $given : $given . self::DISABLED_SUFFIX;
		$plain    = substr( $suffixed, 0, -strlen( self::DISABLED_SUFFIX ) );

		$path = self::resolve( $suffixed, false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! file_exists( $path ) ) {
			$already = self::resolve( $plain, false );
			if ( ! is_wp_error( $already ) && file_exists( $already ) ) {
				return [
					'path'    => self::rel( $already ),
					'status'  => 'already_enabled',
					'dry_run' => $dry,
					'note'    => 'Nothing to do - that file is not disabled.',
				];
			}
			return new \WP_Error( 'niranzwp_not_found', 'No disabled file at ' . $suffixed, [ 'status' => 404 ] );
		}

		$target = substr( $path, 0, -strlen( self::DISABLED_SUFFIX ) );
		if ( file_exists( $target ) ) {
			return new \WP_Error(
				'niranzwp_target_exists',
				sprintf( '%s already exists, so enabling would overwrite it. Delete one of the two first.', self::rel( $target ) ),
				[ 'status' => 409 ]
			);
		}

		$out = [
			'path'    => self::rel( $path ),
			'enabled' => self::rel( $target ),
			'bytes'   => (int) filesize( $path ),
			'dry_run' => $dry,
		];

		if ( $dry ) {
			$out['status'] = 'would_enable';
			$out['note']   = 'Nothing was renamed. Pass dry_run false to apply.';
			return $out;
		}

		// A file coming back could be the thing that fatals, so the same
		// arrangement applies in reverse: null means "it was not there".
		Recovery::arm( $target, null );

		if ( ! rename( $path, $target ) ) {
			Recovery::disarm();
			return new \WP_Error( 'niranzwp_rename_failed', 'Could not rename the file. Check filesystem permissions.' );
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $target, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$out['status']  = 'enabled';
		$out['guarded'] = Recovery::installed();
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
		$recursive = ! empty( $input['recursive'] );

		if ( is_dir( $path ) && ! $recursive ) {
			return new \WP_Error(
				'niranzwp_is_dir',
				'That is a directory. Pass recursive true to delete it and everything inside.',
				[ 'status' => 400 ]
			);
		}

		if ( is_dir( $path ) ) {
			$victims = self::walk_for_delete( $path );
			if ( is_wp_error( $victims ) ) {
				return $victims;
			}

			$bytes = 0;
			foreach ( $victims['files'] as $f ) {
				$bytes += (int) filesize( $f );
			}

			if ( $dry ) {
				return [
					'path'        => '/' . $rel,
					'files'       => count( $victims['files'] ),
					'directories' => count( $victims['dirs'] ),
					'bytes'       => $bytes,
					'status'      => 'would_delete',
					'dry_run'     => true,
					'note'        => 'Nothing was deleted. Pass dry_run false to apply.',
				];
			}

			// A whole tree is more than a checkpoint should be asked to hold,
			// and quietly snapshotting hundreds of files would be its own
			// surprise. Say so rather than implying an undo that is not there.
			foreach ( $victims['files'] as $f ) {
				if ( ! unlink( $f ) ) {
					return new \WP_Error( 'niranzwp_delete_failed', 'Could not delete ' . self::rel( $f ) . '. Some files may already be gone.' );
				}
			}
			foreach ( $victims['dirs'] as $d ) {
				@rmdir( $d ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}

			return [
				'path'        => '/' . $rel,
				'files'       => count( $victims['files'] ),
				'directories' => count( $victims['dirs'] ),
				'bytes'       => $bytes,
				'status'      => 'deleted',
				'dry_run'     => false,
				'checkpoint_id' => null,
				'note'        => 'A recursive delete is not checkpointed. Restore from a host backup if it was wrong.',
			];
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
		$cp         = Checkpoint::before_file_result( $rel, 'delete-file' );
		$checkpoint = $cp['id'];
		$cp_error   = $cp['error'];

		if ( ! unlink( $path ) ) {
			return new \WP_Error( 'niranzwp_delete_failed', 'Could not delete the file.' );
		}

		$out = [ 'path' => '/' . $rel, 'status' => 'deleted', 'dry_run' => false, 'checkpoint_id' => $checkpoint ];
		if ( null !== $cp_error ) {
			// Deleting without an undo is worth saying out loud, not leaving
			// to be inferred from a null.
			$out['checkpoint']       = false;
			$out['checkpoint_error'] = $cp_error;
		}
		return $out;
	}

	/**
	 * Collect what a recursive delete would remove, deepest first.
	 *
	 * Symlinks are refused outright rather than followed or skipped: following
	 * one would delete outside the install, and skipping it silently would
	 * leave the directory undeletable with no explanation.
	 *
	 * @return array{files: string[], dirs: string[]}|\WP_Error
	 */
	private static function walk_for_delete( string $dir ) {
		$files = [];
		$dirs  = [];
		$stack = [ $dir ];

		while ( $stack ) {
			$current = array_pop( $stack );
			$dirs[]  = $current;

			foreach ( (array) scandir( $current ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$full = $current . '/' . $name;

				if ( is_link( $full ) ) {
					return new \WP_Error(
						'niranzwp_symlink',
						'Refusing to delete a tree containing a symlink: ' . self::rel( $full ),
						[ 'status' => 400 ]
					);
				}

				if ( is_dir( $full ) ) {
					$stack[] = $full;
					continue;
				}
				$files[] = $full;

				if ( count( $files ) > self::MAX_ENTRIES ) {
					return new \WP_Error(
						'niranzwp_too_many',
						sprintf( 'That tree holds more than %d files. Delete it from the host instead.', self::MAX_ENTRIES ),
						[ 'status' => 400 ]
					);
				}
			}
		}

		// Deepest first, so rmdir finds each one empty.
		usort( $dirs, static fn( $a, $b ) => substr_count( $b, '/' ) <=> substr_count( $a, '/' ) );

		return [ 'files' => $files, 'dirs' => $dirs ];
	}
}
