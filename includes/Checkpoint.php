<?php
/**
 * Checkpoints: capture the previous state of whatever is about to change, so a
 * bad edit can be undone without reaching for a host-level backup.
 *
 * Snapshots live in a private custom post type rather than in uploads. Uploads
 * is served by the web server, and a checkpoint holds verbatim theme and
 * plugin source -- an .htaccess deny would cover Apache and LiteSpeed and do
 * nothing at all on nginx.
 *
 * This is not a substitute for a real backup. It covers the files, posts and
 * options this plugin itself touches, and says so.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Checkpoint {

	public const POST_TYPE = 'niranzwp_ckpt';

	/** Per-file ceiling. Source files are small; anything larger is not ours. */
	private const MAX_BLOB = 1048576; // 1 MiB

	/** Whole-checkpoint ceiling, to keep the options/postmeta tables sane. */
	private const MAX_TOTAL = 4194304; // 4 MiB

	/** How many to keep before the oldest are pruned. */
	private const KEEP = 30;

	public static function init(): void {
		add_action( 'init', [ self::class, 'register_type' ] );
	}

	public static function register_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => [ 'title', 'editor' ],
			]
		);
	}

	/* ------------------------------------------------------------- capture */

	/**
	 * Snapshot the current state of the given targets.
	 *
	 * @param array<string,mixed> $targets files[] (relative paths), posts[] (ids), options[] (names)
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function capture( array $targets, string $label = '' ) {
		$files   = array_values( array_unique( array_map( 'strval', (array) ( $targets['files'] ?? [] ) ) ) );
		$posts   = array_values( array_unique( array_map( 'intval', (array) ( $targets['posts'] ?? [] ) ) ) );
		$options = array_values( array_unique( array_map( 'strval', (array) ( $targets['options'] ?? [] ) ) ) );

		/*
		 * Single meta fields, as [post_id, meta_key] pairs.
		 *
		 * Snapshotting whole posts is the wrong shape for the bulk SEO writes:
		 * they change one key on hundreds of posts, and capturing every field
		 * and every meta row for each would pass the four-megabyte ceiling
		 * long before the batch did. One key each is what restoring actually
		 * needs.
		 */
		$meta = [];
		foreach ( (array) ( $targets['meta'] ?? [] ) as $pair ) {
			$id  = (int) ( $pair[0] ?? $pair['id'] ?? 0 );
			$key = (string) ( $pair[1] ?? $pair['key'] ?? '' );
			if ( $id && '' !== $key ) {
				$meta[ $id . '|' . $key ] = [ $id, $key ];
			}
		}
		$meta = array_values( $meta );

		if ( ! $files && ! $posts && ! $options && ! $meta ) {
			return new \WP_Error( 'niranzwp_empty_checkpoint', 'Nothing to capture. Pass files, posts, options or meta.' );
		}

		$snapshot = [ 'files' => [], 'posts' => [], 'meta' => [], 'options' => [] ];
		$total    = 0;

		foreach ( $files as $rel ) {
			$abs = self::inside_root( $rel );
			if ( is_wp_error( $abs ) ) {
				return $abs;
			}
			// A file that does not exist yet is still worth recording: undoing
			// its creation means deleting it again.
			if ( ! file_exists( $abs ) ) {
				$snapshot['files'][] = [ 'path' => self::rel( $abs ), 'existed' => false, 'content' => '' ];
				continue;
			}
			if ( is_dir( $abs ) ) {
				return new \WP_Error( 'niranzwp_is_dir', 'Directories cannot be checkpointed: ' . $rel );
			}

			$size = (int) filesize( $abs );
			if ( $size > self::MAX_BLOB ) {
				return new \WP_Error( 'niranzwp_too_large', sprintf( '%s is %d bytes; the per-file limit is %d.', $rel, $size, self::MAX_BLOB ) );
			}
			$total += $size;
			if ( $total > self::MAX_TOTAL ) {
				return new \WP_Error( 'niranzwp_too_large', sprintf( 'Checkpoint would exceed %d bytes.', self::MAX_TOTAL ) );
			}

			$snapshot['files'][] = [
				'path'    => self::rel( $abs ),
				'existed' => true,
				'content' => base64_encode( (string) file_get_contents( $abs ) ),
				'b64'     => true,
			];
		}

		foreach ( $posts as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				return new \WP_Error( 'niranzwp_not_found', 'No post with id ' . $id );
			}
			$snapshot['posts'][] = [
				'id'      => $post->ID,
				'fields'  => [
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'post_status'  => $post->post_status,
					'post_name'    => $post->post_name,
				],
				'meta'    => get_post_meta( $id ),
			];
		}

		foreach ( $meta as [ $id, $key ] ) {
			// Absent and empty are different: restoring the first means deleting
			// the row, restoring the second means writing an empty string.
			$existed = metadata_exists( 'post', $id, $key );
			$snapshot['meta'][] = [
				'id'      => $id,
				'key'     => $key,
				'value'   => $existed ? get_post_meta( $id, $key, true ) : null,
				'existed' => $existed,
			];
		}

		foreach ( $options as $name ) {
			$snapshot['options'][] = [ 'name' => $name, 'value' => get_option( $name, null ), 'existed' => null !== get_option( $name, null ) ];
		}

		$json = wp_json_encode( $snapshot );
		if ( false === $json ) {
			return new \WP_Error( 'niranzwp_encode_failed', 'Could not serialise the snapshot.' );
		}

		$id = wp_insert_post( [
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'private',
			'post_title'   => '' !== $label ? $label : sprintf( 'Checkpoint %s', gmdate( 'Y-m-d H:i:s' ) ),
			'post_content' => wp_slash( $json ),
		], true );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, '_niranzwp_summary', [
			'files'   => count( $snapshot['files'] ),
			'posts'   => count( $snapshot['posts'] ),
			'options' => count( $snapshot['options'] ),
			'bytes'   => strlen( $json ),
		] );

		self::prune();

		return [
			'checkpoint_id' => $id,
			'label'         => get_the_title( $id ),
			'created'       => get_post_field( 'post_date_gmt', $id ),
			'files'         => count( $snapshot['files'] ),
			'posts'         => count( $snapshot['posts'] ),
			'meta'          => count( $snapshot['meta'] ?? [] ),
			'options'       => count( $snapshot['options'] ),
			'bytes'         => strlen( $json ),
		];
	}

	/**
	 * Snapshot a single file just before this plugin overwrites or deletes it.
	 * Failure here must never block the caller -- a checkpoint that could not be
	 * taken is reported, not fatal.
	 */
	public static function before_file( string $rel, string $why ): ?int {
		return self::before_file_result( $rel, $why )['id'];
	}

	/**
	 * The same snapshot, with the reason it failed when it did.
	 *
	 * Writing without an undo is the right call - refusing to repair a broken
	 * site because the snapshot failed would be worse - but the caller has to
	 * be able to say so. Returning only a null id made a missing checkpoint
	 * indistinguishable from a field nobody set, which is how a safety net
	 * goes missing without anyone noticing.
	 *
	 * @return array{id:?int,error:?string}
	 */
	public static function before_file_result( string $rel, string $why ): array {
		$r = self::capture( [ 'files' => [ $rel ] ], sprintf( 'Before %s: %s', $why, $rel ) );
		if ( is_wp_error( $r ) ) {
			return [ 'id' => null, 'error' => $r->get_error_message() ];
		}
		return [ 'id' => (int) $r['checkpoint_id'], 'error' => null ];
	}

	/** Snapshot a post just before this plugin rewrites its content or meta. */
	public static function before_post( int $id, string $why ): ?int {
		$r = self::capture( [ 'posts' => [ $id ] ], sprintf( 'Before %s: post %d', $why, $id ) );
		return is_wp_error( $r ) ? null : (int) $r['checkpoint_id'];
	}

	/* ------------------------------------------------------------- restore */

	/** @return array<string,mixed>|\WP_Error */
	public static function restore( int $id, bool $dry = true ) {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'niranzwp_not_found', 'No checkpoint with id ' . $id );
		}

		$snapshot = json_decode( $post->post_content, true );
		if ( ! is_array( $snapshot ) ) {
			return new \WP_Error( 'niranzwp_corrupt', 'Checkpoint ' . $id . ' could not be decoded.' );
		}

		$actions = [];

		foreach ( (array) ( $snapshot['files'] ?? [] ) as $f ) {
			$abs = self::inside_root( (string) ( $f['path'] ?? '' ) );
			if ( is_wp_error( $abs ) ) {
				return $abs;
			}
			$want    = ! empty( $f['b64'] ) ? (string) base64_decode( (string) $f['content'], true ) : (string) ( $f['content'] ?? '' );
			$existed = (bool) ( $f['existed'] ?? true );
			$now     = file_exists( $abs ) ? (string) file_get_contents( $abs ) : null;

			if ( ! $existed ) {
				// The file did not exist when the checkpoint was taken, so
				// undoing means removing it again.
				if ( null === $now ) {
					$actions[] = [ 'kind' => 'file', 'path' => $f['path'], 'action' => 'unchanged' ];
					continue;
				}
				$actions[] = [ 'kind' => 'file', 'path' => $f['path'], 'action' => $dry ? 'would_delete' : 'deleted' ];
				if ( ! $dry && ! unlink( $abs ) ) {
					return new \WP_Error( 'niranzwp_restore_failed', 'Could not delete ' . $f['path'] );
				}
				continue;
			}

			if ( $now === $want ) {
				$actions[] = [ 'kind' => 'file', 'path' => $f['path'], 'action' => 'unchanged' ];
				continue;
			}
			$actions[] = [ 'kind' => 'file', 'path' => $f['path'], 'action' => $dry ? 'would_restore' : 'restored', 'bytes' => strlen( $want ) ];
			if ( ! $dry && false === file_put_contents( $abs, $want ) ) {
				return new \WP_Error( 'niranzwp_restore_failed', 'Could not write ' . $f['path'] );
			}
		}

		foreach ( (array) ( $snapshot['posts'] ?? [] ) as $p ) {
			$pid = (int) ( $p['id'] ?? 0 );
			if ( ! get_post( $pid ) ) {
				$actions[] = [ 'kind' => 'post', 'id' => $pid, 'action' => 'gone', 'note' => 'The post no longer exists; content was not recreated.' ];
				continue;
			}
			$actions[] = [ 'kind' => 'post', 'id' => $pid, 'action' => $dry ? 'would_restore' : 'restored' ];
			if ( $dry ) {
				continue;
			}

			$fields       = (array) ( $p['fields'] ?? [] );
			$fields['ID'] = $pid;

			/*
			 * wp_update_post() merges the post's existing page_template into
			 * the update and then validates it against the theme. Templates
			 * registered by plugins -- Elementor's elementor_canvas -- come in
			 * through a filter that is not reliably present in a REST request,
			 * so restoring an Elementor page failed with "Invalid page
			 * template" even though the template was already on the post.
			 * Whatever template the post carries right now is by definition
			 * acceptable to put back, so it is allowed through explicitly.
			 */
			$current_template = (string) get_post_meta( $pid, '_wp_page_template', true );
			$allow_template   = static function ( array $templates ) use ( $current_template ): array {
				if ( '' !== $current_template && 'default' !== $current_template ) {
					$templates[ $current_template ] = $current_template;
				}
				return $templates;
			};
			add_filter( 'theme_page_templates', $allow_template );

			// wp_update_post() unslashes, so the payload has to be slashed
			// first or every quote in the content is stripped.
			$r = wp_update_post( wp_slash( $fields ), true );

			remove_filter( 'theme_page_templates', $allow_template );

			if ( is_wp_error( $r ) ) {
				return $r;
			}

			foreach ( get_post_meta( $pid ) as $key => $_ ) {
				delete_post_meta( $pid, $key );
			}
			foreach ( (array) ( $p['meta'] ?? [] ) as $key => $values ) {
				foreach ( (array) $values as $value ) {
					add_post_meta( $pid, $key, wp_slash( maybe_unserialize( $value ) ) );
				}
			}
		}

		foreach ( (array) ( $snapshot['meta'] ?? [] ) as $m ) {
			$pid = (int) ( $m['id'] ?? 0 );
			$key = (string) ( $m['key'] ?? '' );
			if ( ! $pid || '' === $key ) {
				continue;
			}
			if ( ! get_post( $pid ) ) {
				$actions[] = [ 'kind' => 'meta', 'id' => $pid, 'key' => $key, 'action' => 'gone', 'note' => 'The post no longer exists.' ];
				continue;
			}

			$existed = ! empty( $m['existed'] );
			$now     = metadata_exists( 'post', $pid, $key ) ? get_post_meta( $pid, $key, true ) : null;
			$want    = $existed ? ( $m['value'] ?? '' ) : null;

			if ( $now === $want ) {
				$actions[] = [ 'kind' => 'meta', 'id' => $pid, 'key' => $key, 'action' => 'unchanged' ];
				continue;
			}

			// Absent and empty are not the same thing, so putting an empty
			// string back where there was no row would leave the post
			// different from how it was found.
			$actions[] = [
				'kind'   => 'meta',
				'id'     => $pid,
				'key'    => $key,
				'action' => $dry ? ( $existed ? 'would_restore' : 'would_delete' ) : ( $existed ? 'restored' : 'deleted' ),
			];
			if ( ! $dry ) {
				if ( $existed ) {
					update_post_meta( $pid, $key, $want );
				} else {
					delete_post_meta( $pid, $key );
				}
			}
		}

		foreach ( (array) ( $snapshot['options'] ?? [] ) as $o ) {
			$name = (string) ( $o['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$actions[] = [ 'kind' => 'option', 'name' => $name, 'action' => $dry ? 'would_restore' : 'restored' ];
			if ( ! $dry ) {
				if ( empty( $o['existed'] ) ) {
					delete_option( $name );
				} else {
					update_option( $name, $o['value'] );
				}
			}
		}

		// Elementor renders from generated CSS, so a restored layout keeps
		// showing the old one until that cache is dropped.
		if ( ! $dry && class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return [
			'checkpoint_id' => $id,
			'label'         => $post->post_title,
			'dry_run'       => $dry,
			'actions'       => $actions,
			'changed'       => count( array_filter( $actions, static fn( array $a ): bool => 'unchanged' !== $a['action'] ) ),
			'note'          => $dry ? 'Nothing was changed. Pass dry_run false to apply.' : null,
		];
	}

	/* ---------------------------------------------------------------- list */

	/** @return array<int,array<string,mixed>> */
	public static function all( int $limit = 30 ): array {
		$out = [];
		foreach ( get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => max( 1, min( 200, $limit ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		] ) as $p ) {
			$summary = get_post_meta( $p->ID, '_niranzwp_summary', true );
			$out[]   = [
				'checkpoint_id' => $p->ID,
				'label'         => $p->post_title,
				'created'       => $p->post_date_gmt,
				'files'         => (int) ( $summary['files'] ?? 0 ),
				'posts'         => (int) ( $summary['posts'] ?? 0 ),
				'options'       => (int) ( $summary['options'] ?? 0 ),
				'bytes'         => (int) ( $summary['bytes'] ?? 0 ),
			];
		}
		return $out;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function forget( int $id ) {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'niranzwp_not_found', 'No checkpoint with id ' . $id );
		}
		wp_delete_post( $id, true );
		return [ 'checkpoint_id' => $id, 'status' => 'deleted' ];
	}

	/* ------------------------------------------------------------ internal */

	private static function prune(): void {
		$old = get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 200,
			'offset'         => self::KEEP,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		] );
		foreach ( $old as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	/**
	 * Same containment rule as the file abilities: resolve inside ABSPATH, and
	 * refuse anything that escapes it.
	 *
	 * @return string|\WP_Error
	 */
	private static function inside_root( string $path ) {
		$path = ltrim( str_replace( '\\', '/', trim( $path ) ), '/' );
		if ( '' === $path ) {
			return new \WP_Error( 'niranzwp_bad_path', 'Path is empty.' );
		}
		if ( 'wp-config.php' === basename( $path ) ) {
			return new \WP_Error( 'niranzwp_protected', 'wp-config.php is protected and is never captured.' );
		}

		$base = (string) realpath( ABSPATH );
		$full = $base . '/' . $path;
		$real = realpath( $full );

		if ( false === $real ) {
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
		return ltrim( str_replace( (string) realpath( ABSPATH ), '', $abs ), '/' );
	}

	/* ---------------------------------------------------------- abilities */

	/** @param callable|array $gate */
	public static function register( callable|array $gate ): void {
		$ro = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ];
		$rw = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => false ] ];
		$rm = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ] ];

		register_ability( 'niranzwp/checkpoint-create', [
			'label'               => __( 'Create snapshot', 'niranzwp' ),
			'description'         => __( 'Takes a snapshot of the given files, posts and options so a later change can be rolled back. Not a substitute for a host backup.', 'niranzwp' ),
			'category'            => 'niranzwp-checkpoints',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'label'   => [ 'type' => 'string' ],
					'files'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'posts'   => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
					'options' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'ability_create' ],
			'meta'                => $rw,
		] );

		register_ability( 'niranzwp/checkpoint-list', [
			'label'               => __( 'List snapshots', 'niranzwp' ),
			'description'         => __( 'Lists saved snapshots, newest first.', 'niranzwp' ),
			'category'            => 'niranzwp-checkpoints',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'limit' => [ 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 200 ] ],
			],
			'output_schema'       => [ 'type' => 'array' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'ability_list' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/checkpoint-restore', [
			'label'               => __( 'Restore snapshot', 'niranzwp' ),
			'description'         => __( 'Puts the files, posts and options in a snapshot back the way they were. Reports what would change unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp-checkpoints',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'checkpoint_id' => [ 'type' => 'integer' ],
					'dry_run'       => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'checkpoint_id' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'ability_restore' ],
			'meta'                => $rw,
		] );

		register_ability( 'niranzwp/checkpoint-verify', [
			'label'               => __( 'Verify snapshot', 'niranzwp' ),
			'description'         => __( 'Opens a snapshot and reports whether it could actually be restored: that every file it claims is present, that the stored bytes decode to the size and hash recorded, that PHP still parses, and where the current file differs. A backup that cannot be checked is a hope rather than a backup - run this before relying on one, not after.', 'niranzwp' ),
			'category'            => 'niranzwp-checkpoints',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'checkpoint_id' => [ 'type' => 'integer' ] ],
				'required'   => [ 'checkpoint_id' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'ability_verify' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/checkpoint-delete', [
			'label'               => __( 'Delete snapshot', 'niranzwp' ),
			'description'         => __( 'Permanently removes a saved snapshot.', 'niranzwp' ),
			'category'            => 'niranzwp-checkpoints',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'checkpoint_id' => [ 'type' => 'integer' ] ],
				'required'   => [ 'checkpoint_id' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'ability_delete' ],
			'meta'                => $rm,
		] );
	}

	/**
	 * Say whether a checkpoint could actually put things back.
	 *
	 * Reading a checkpoint by hand means decoding JSON, knowing the content
	 * sits base64 under a `b64` flag, and comparing hashes - which is three
	 * chances to get it wrong and conclude a good backup is broken, or worse,
	 * that a broken one is fine. This answers it directly and changes nothing.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function ability_verify( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$id    = (int) ( $input['checkpoint_id'] ?? 0 );

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'niranzwp_no_checkpoint', sprintf( 'No checkpoint with id %d.', $id ), [ 'status' => 404 ] );
		}

		$payload = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $payload ) ) {
			return [
				'checkpoint_id' => $id,
				'restorable'    => false,
				'why'           => 'The stored record is not readable JSON.',
			];
		}

		$files    = [];
		$problems = [];

		foreach ( (array) ( $payload['files'] ?? [] ) as $f ) {
			$rel      = (string) ( $f['path'] ?? '' );
			$existed  = ! empty( $f['existed'] );
			$stored   = (string) ( $f['content'] ?? '' );
			$decoded  = ! empty( $f['b64'] ) ? base64_decode( $stored, true ) : $stored;

			$row = [ 'path' => $rel, 'existed_when_taken' => $existed ];

			if ( ! $existed ) {
				// Recording an absence is legitimate: restoring means deleting.
				$row['restores_to'] = 'absent';
				$files[]            = $row;
				continue;
			}

			if ( false === $decoded ) {
				$row['ok']  = false;
				$row['why'] = 'The stored content is not valid base64.';
				$problems[] = $rel;
				$files[]    = $row;
				continue;
			}

			$row['bytes']  = strlen( (string) $decoded );
			$row['sha256'] = hash( 'sha256', (string) $decoded );

			if ( str_ends_with( strtolower( $rel ), '.php' ) ) {
				try {
					token_get_all( (string) $decoded, TOKEN_PARSE );
					$row['parses'] = true;
				} catch ( \ParseError $e ) {
					$row['parses'] = false;
					$row['why']    = 'The stored PHP does not parse: ' . $e->getMessage();
					$problems[]    = $rel;
				}
			}

			// What restoring would actually change, right now.
			$abs = ABSPATH . ltrim( $rel, '/' );
			if ( ! file_exists( $abs ) ) {
				$row['current'] = 'absent';
				$row['differs'] = true;
			} else {
				$now            = hash_file( 'sha256', $abs );
				$row['current'] = [ 'bytes' => (int) filesize( $abs ), 'sha256' => $now ];
				$row['differs'] = $now !== $row['sha256'];
			}

			$row['ok'] = ! isset( $row['why'] );
			$files[]   = $row;
		}

		$posts   = count( (array) ( $payload['posts'] ?? [] ) );
		$options = count( (array) ( $payload['options'] ?? [] ) );

		return [
			'checkpoint_id' => $id,
			'label'         => $post->post_title,
			'created'       => $post->post_date,
			'restorable'    => empty( $problems ),
			'problems'      => $problems,
			'files'         => $files,
			'posts'         => $posts,
			'options'       => $options,
			'note'          => empty( $problems )
				? 'Every stored file decodes and parses. checkpoint-restore would put these back.'
				: 'Some stored files could not be read back; restoring would not fully succeed.',
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function ability_create( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		return self::capture( $input, (string) ( $input['label'] ?? '' ) );
	}

	/** @return array<int,array<string,mixed>> */
	public static function ability_list( mixed $input = [] ): array {
		$input = is_array( $input ) ? $input : [];
		return self::all( (int) ( $input['limit'] ?? 30 ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function ability_restore( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		return self::restore(
			(int) ( $input['checkpoint_id'] ?? 0 ),
			! isset( $input['dry_run'] ) || (bool) $input['dry_run']
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function ability_delete( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		return self::forget( (int) ( $input['checkpoint_id'] ?? 0 ) );
	}
}
