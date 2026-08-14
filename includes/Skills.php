<?php
/**
 * Skills: written instructions that live on the site, so every client that
 * connects works to the same rules without being told them again.
 *
 * A skill is a name, a one-line description used to decide relevance, and a
 * body of instructions. Nothing more -- the value is that it is written down
 * once and read by whatever connects, not in the format.
 *
 * They are stored as a private custom post type rather than as files, so they
 * are editable from wp-admin by people who do not use a terminal.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Skills {

	public const POST_TYPE = 'niranzwp_skill';

	/** Generous for prose, small enough that nothing here becomes a file store. */
	private const MAX_BODY = 65536; // 64 KiB

	private const MAX_SKILLS = 100;

	public static function init(): void {
		add_action( 'init', [ self::class, 'register_type' ] );
	}

	/* --------------------------------------------------------- SKILL.md */

	/**
	 * Skills are written as Markdown with a frontmatter block:
	 *
	 *     ---
	 *     name: Alt text
	 *     description: How to write alt text for photographs here
	 *     ---
	 *
	 *     - Do not start with "image of"
	 *
	 * This is the same shape agents already understand elsewhere, so a skill
	 * written for one system can be pasted into another without translation.
	 * Parsing is lenient: unknown keys and a missing block are not errors.
	 *
	 * @return array{name:string,description:string,body:string}
	 */
	public static function parse( string $raw ): array {
		$raw = self::unescape( $raw );
		$out = [ 'name' => '', 'description' => '', 'body' => $raw ];

		if ( ! preg_match( "/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s", $raw, $m ) ) {
			return $out;
		}

		$out['body'] = ltrim( $m[2], "\r\n" );

		foreach ( preg_split( "/\r?\n/", $m[1] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$key   = strtolower( trim( substr( $line, 0, $colon ) ) );
			$value = trim( substr( $line, $colon + 1 ) );
			// A quoted value is still just the value.
			if ( strlen( $value ) > 1 && $value[0] === substr( $value, -1 ) && in_array( $value[0], [ '"', "'" ], true ) ) {
				$value = substr( $value, 1, -1 );
			}
			if ( 'name' === $key || 'description' === $key ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/** Render a skill back out in the same format it is written in. */
	public static function render( string $name, string $description, string $body ): string {
		return "---\nname: " . $name . "\ndescription: " . $description . "\n---\n\n" . $body;
	}

	/**
	 * Undo one layer of C-style escaping.
	 *
	 * A skill body arrives as a JSON string inside a tool call, and some
	 * clients encode that payload twice. When they do, a real newline reaches
	 * us as the two characters backslash-n, and the skill reads as one long
	 * line of literal escape sequences. Nobody ever wants that text stored
	 * verbatim, so it is undone on the way in.
	 */
	private static function unescape( string $raw ): string {
		// Only act when the body has escape sequences and no real newlines --
		// otherwise a skill that legitimately mentions "\n" would be mangled.
		if ( str_contains( $raw, "\n" ) || ! preg_match( '/\\\\[nrt]/', $raw ) ) {
			return $raw;
		}
		return stripcslashes( $raw );
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
				'supports'            => [ 'title', 'editor', 'revisions' ],
			]
		);
	}

	/* ----------------------------------------------------------------- data */

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( bool $with_body = false ): array {
		$out = [];
		foreach ( get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => self::MAX_SKILLS,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] ) as $p ) {
			$row = [
				'slug'        => $p->post_name,
				'title'       => $p->post_title,
				'description' => (string) get_post_meta( $p->ID, '_niranzwp_description', true ),
				'updated'     => $p->post_modified_gmt,
				'bytes'       => strlen( $p->post_content ),
			];
			if ( $with_body ) {
				$row['body'] = $p->post_content;
			}
			$out[] = $row;
		}
		return $out;
	}

	/** @return \WP_Post|null */
	public static function find( string $slug ) {
		$post = get_page_by_path( sanitize_title( $slug ), OBJECT, self::POST_TYPE );
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Write a skill.
	 *
	 * The body may arrive as a bare instruction list or as a full SKILL.md
	 * with frontmatter. When it carries frontmatter, its name and description
	 * win over anything passed alongside -- the file is the source of truth,
	 * which is what makes one pasteable between sites.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function put( string $slug, string $title, string $description, string $body ) {
		$parsed = self::parse( $body );
		$body   = $parsed['body'];
		if ( '' !== $parsed['name'] ) {
			$title = $parsed['name'];
		}
		if ( '' !== $parsed['description'] ) {
			$description = $parsed['description'];
		}

		$slug = sanitize_title( '' !== $slug ? $slug : $title );
		if ( '' === $slug ) {
			return new \WP_Error( 'niranzwp_bad_slug', 'A skill needs a slug.' );
		}
		if ( strlen( $body ) > self::MAX_BODY ) {
			return new \WP_Error( 'niranzwp_too_large', sprintf( 'Skill body is %d bytes; the limit is %d.', strlen( $body ), self::MAX_BODY ) );
		}

		$existing = self::find( $slug );

		if ( ! $existing && count( self::all() ) >= self::MAX_SKILLS ) {
			return new \WP_Error( 'niranzwp_too_many', sprintf( 'This site already has %d skills.', self::MAX_SKILLS ) );
		}

		// Rewriting a skill loses whatever it said before, so snapshot first --
		// same rule as any other destructive write in this plugin.
		$checkpoint = $existing ? Checkpoint::before_post( $existing->ID, 'skill-write' ) : null;

		$fields = [
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'private',
			'post_name'    => $slug,
			'post_title'   => '' !== $title ? $title : $slug,
			'post_content' => $body,
		];
		if ( $existing ) {
			$fields['ID'] = $existing->ID;
		}

		// wp_insert_post() and wp_update_post() unslash on the way in.
		$id = $existing
			? wp_update_post( wp_slash( $fields ), true )
			: wp_insert_post( wp_slash( $fields ), true );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( (int) $id, '_niranzwp_description', $description );

		return [
			'slug'          => $slug,
			'title'         => $fields['post_title'],
			'description'   => $description,
			'bytes'         => strlen( $body ),
			'status'        => $existing ? 'updated' : 'created',
			'checkpoint_id' => $checkpoint,
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function forget( string $slug ) {
		$post = self::find( $slug );
		if ( ! $post ) {
			return new \WP_Error( 'niranzwp_not_found', 'No skill with slug ' . $slug );
		}
		$checkpoint = Checkpoint::before_post( $post->ID, 'skill-delete' );
		wp_delete_post( $post->ID, true );
		return [ 'slug' => $slug, 'status' => 'deleted', 'checkpoint_id' => $checkpoint ];
	}

	/* ------------------------------------------------------------ abilities */

	/** @param callable|array $gate */
	public static function register( callable|array $gate ): void {
		$ro = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ];
		$rw = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => false ] ];
		$rm = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ] ];

		wp_register_ability( 'niranzwp/skill-list', [
			'label'               => __( 'List skills', 'niranzwp' ),
			'description'         => __( 'Lists the written instructions this site keeps for anything working on it. Read these before editing content, writing alt text or changing SEO fields, and follow them.', 'niranzwp' ),
			'category'            => 'niranzwp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'include_body' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Return the full text of every skill, not just the descriptions.' ],
				],
			],
			'output_schema'       => [ 'type' => 'array' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'ability_list' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/skill-get', [
			'label'               => __( 'Get skill', 'niranzwp' ),
			'description'         => __( 'Returns the full text of one skill by slug.', 'niranzwp' ),
			'category'            => 'niranzwp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'slug' => [ 'type' => 'string' ] ],
				'required'   => [ 'slug' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'ability_get' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/skill-write', [
			'label'               => __( 'Write skill', 'niranzwp' ),
			'description'         => __( 'Creates or replaces a skill. Snapshots the previous version first.', 'niranzwp' ),
			'category'            => 'niranzwp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'slug'        => [ 'type' => 'string' ],
					'title'       => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string', 'description' => 'One line, used to decide whether this skill is relevant.' ],
					'body'        => [ 'type' => 'string' ],
				],
				'required'   => [ 'slug', 'body' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'ability_write' ],
			'meta'                => $rw,
		] );

		wp_register_ability( 'niranzwp/skill-delete', [
			'label'               => __( 'Delete skill', 'niranzwp' ),
			'description'         => __( 'Permanently removes a skill. Snapshots it first.', 'niranzwp' ),
			'category'            => 'niranzwp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'slug' => [ 'type' => 'string' ] ],
				'required'   => [ 'slug' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'ability_delete' ],
			'meta'                => $rm,
		] );
	}

	/** @return array<int,array<string,mixed>> */
	public static function ability_list( mixed $input = [] ): array {
		$input = is_array( $input ) ? $input : [];
		return self::all( (bool) ( $input['include_body'] ?? false ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function ability_get( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$post  = self::find( (string) ( $input['slug'] ?? '' ) );
		if ( ! $post ) {
			return new \WP_Error( 'niranzwp_not_found', 'No skill with that slug. Use skill-list to see what exists.' );
		}
		return [
			'slug'        => $post->post_name,
			'title'       => $post->post_title,
			'description' => (string) get_post_meta( $post->ID, '_niranzwp_description', true ),
			'updated'     => $post->post_modified_gmt,
			'body'        => $post->post_content,
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function ability_write( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		return self::put(
			(string) ( $input['slug'] ?? '' ),
			(string) ( $input['title'] ?? '' ),
			(string) ( $input['description'] ?? '' ),
			(string) ( $input['body'] ?? '' )
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function ability_delete( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		return self::forget( (string) ( $input['slug'] ?? '' ) );
	}
}
