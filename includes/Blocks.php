<?php
/**
 * Block editor abilities.
 *
 * Writing Gutenberg content blind is how blocks get corrupted: an attribute
 * the block does not declare, a name that is not registered, or a nesting the
 * parent refuses, and the editor silently shows "this block contains
 * unexpected or invalid content".
 *
 * So these abilities let a caller look before it writes -- list the block
 * types this site actually has, read one's attribute schema, and read a post
 * back as a parsed block tree -- and then validate on the way in.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	public static function register( callable|array $gate ): void {
		$ro = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ];

		wp_register_ability( 'niranzwp/block-types', [
			'label'               => __( 'List block types', 'niranzwp' ),
			'description'         => __( 'Lists the block types registered on this site, so content is composed only from blocks that actually exist here.', 'niranzwp' ),
			'category'            => 'niranzwp-gutenberg',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'search'    => [ 'type' => 'string' ],
					'namespace' => [ 'type' => 'string', 'description' => 'e.g. "core" or "kadence"' ],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'types' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/block-type', [
			'label'               => __( 'Describe a block type', 'niranzwp' ),
			'description'         => __( 'Returns one block type\'s attribute schema, supports, parent and ancestor constraints, so its attributes can be set correctly the first time.', 'niranzwp' ),
			'category'            => 'niranzwp-gutenberg',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'name' => [ 'type' => 'string' ] ],
				'required'   => [ 'name' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'type' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/block-read', [
			'label'               => __( 'Read post blocks', 'niranzwp' ),
			'description'         => __( 'Returns a post\'s content as a parsed block tree with names and attributes, rather than raw markup.', 'niranzwp' ),
			'category'            => 'niranzwp-gutenberg',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'    => [ 'type' => 'integer' ],
					'depth' => [ 'type' => 'integer', 'default' => 3, 'minimum' => 1, 'maximum' => 10 ],
				],
				'required'   => [ 'id' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'read' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/block-write', [
			'label'               => __( 'Write post blocks', 'niranzwp' ),
			'description'         => __( 'Replaces or appends a post\'s content from a block tree. Validates every block name and attribute against this site\'s registry first, and previews unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp-gutenberg',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'      => [ 'type' => 'integer' ],
					'blocks'  => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'name'       => [ 'type' => 'string' ],
								'attributes' => [ 'type' => 'object' ],
								'innerHTML'  => [ 'type' => 'string' ],
								'innerBlocks'=> [ 'type' => 'array' ],
							],
						],
					],
					'mode'    => [ 'type' => 'string', 'enum' => [ 'replace', 'append', 'prepend' ], 'default' => 'append' ],
					'dry_run' => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'id', 'blocks' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'write' ],
			'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
		] );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function types( mixed $input = [] ): array {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$registry = \WP_Block_Type_Registry::get_instance();
		$search   = strtolower( trim( (string) ( $input['search'] ?? '' ) ) );
		$ns       = trim( (string) ( $input['namespace'] ?? '' ) );

		$out    = [];
		$by_ns  = [];

		foreach ( $registry->get_all_registered() as $name => $block ) {
			$prefix = explode( '/', $name )[0];
			$by_ns[ $prefix ] = ( $by_ns[ $prefix ] ?? 0 ) + 1;

			if ( $ns && $prefix !== $ns ) {
				continue;
			}
			if ( $search && false === stripos( $name . ' ' . (string) $block->title, $search ) ) {
				continue;
			}

			$out[] = [
				'name'        => $name,
				'title'       => (string) $block->title,
				'category'    => $block->category,
				'attributes'  => count( (array) $block->attributes ),
				'parent'      => $block->parent,
				'has_inner'   => ! empty( $block->supports['inserter'] ) || null === $block->parent,
			];
		}

		ksort( $by_ns );

		return [
			'total'      => count( $out ),
			'namespaces' => $by_ns,
			'blocks'     => $out,
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function type( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$name  = (string) ( $input['name'] ?? '' );
		$block = \WP_Block_Type_Registry::get_instance()->get_registered( $name );

		if ( ! $block ) {
			return new \WP_Error( 'niranzwp_unknown_block', sprintf( 'Block type "%s" is not registered on this site.', $name ) );
		}

		return [
			'name'        => $block->name,
			'title'       => (string) $block->title,
			'description' => (string) $block->description,
			'category'    => $block->category,
			'parent'      => $block->parent,
			'ancestor'    => $block->ancestor ?? null,
			'supports'    => $block->supports,
			'attributes'  => $block->attributes,
			'uses_context'=> $block->uses_context,
		];
	}

	/** @param array<string,mixed> $b */
	private static function summarize( array $b, int $depth, int $level = 0 ): array {
		$row = [
			'name'       => $b['blockName'] ?? null,
			'attributes' => $b['attrs'] ?? [],
		];

		$text = trim( wp_strip_all_tags( (string) ( $b['innerHTML'] ?? '' ) ) );
		if ( '' !== $text ) {
			$row['text'] = mb_substr( $text, 0, 120 );
		}

		$inner = (array) ( $b['innerBlocks'] ?? [] );
		if ( $inner ) {
			$row['inner_count'] = count( $inner );
			if ( $level + 1 < $depth ) {
				$row['innerBlocks'] = array_map(
					static fn( array $c ): array => self::summarize( $c, $depth, $level + 1 ),
					$inner
				);
			}
		}

		return $row;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function read( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$id   = (int) ( $input['id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;

		if ( ! $post ) {
			return new \WP_Error( 'niranzwp_not_found', 'No post with ID ' . $id );
		}

		$depth  = max( 1, min( 10, (int) ( $input['depth'] ?? 3 ) ) );
		$parsed = parse_blocks( (string) $post->post_content );

		// parse_blocks() emits null-named blocks for the whitespace between
		// real ones; they carry no meaning and only add noise.
		$parsed = array_values( array_filter( $parsed, static fn( array $b ): bool => ! empty( $b['blockName'] ) ) );

		return [
			'id'           => $id,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'uses_blocks'  => has_blocks( $post ),
			'block_count'  => count( $parsed ),
			'blocks'       => array_map( static fn( array $b ): array => self::summarize( $b, $depth ), $parsed ),
		];
	}

	/**
	 * Check a block tree against the registry before anything is written.
	 *
	 * @param array<int,array<string,mixed>> $blocks
	 * @return array<int,string> problems, empty when the tree is sound
	 */
	private static function validate( array $blocks, string $path = 'blocks' ): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		$problems = [];

		foreach ( $blocks as $i => $b ) {
			$at   = "{$path}[{$i}]";
			$name = (string) ( $b['name'] ?? '' );

			if ( '' === $name ) {
				$problems[] = "{$at}.name is required";
				continue;
			}

			$type = $registry->get_registered( $name );
			if ( ! $type ) {
				$problems[] = "{$at}: block \"{$name}\" is not registered on this site";
				continue;
			}

			foreach ( (array) ( $b['attributes'] ?? [] ) as $key => $_ ) {
				if ( ! isset( $type->attributes[ $key ] ) ) {
					$problems[] = "{$at}: \"{$name}\" has no attribute \"{$key}\"";
				}
			}

			$inner = (array) ( $b['innerBlocks'] ?? [] );
			if ( $inner ) {
				// A block that declares parents may only appear inside them.
				foreach ( $inner as $j => $child ) {
					$child_name = (string) ( $child['name'] ?? '' );
					$child_type = $child_name ? $registry->get_registered( $child_name ) : null;
					if ( $child_type && ! empty( $child_type->parent ) && ! in_array( $name, (array) $child_type->parent, true ) ) {
						$problems[] = "{$at}.innerBlocks[{$j}]: \"{$child_name}\" may only be nested inside " . implode( ', ', (array) $child_type->parent );
					}
				}
				$problems = array_merge( $problems, self::validate( $inner, "{$at}.innerBlocks" ) );
			}
		}

		return $problems;
	}

	/** @param array<int,array<string,mixed>> $blocks */
	private static function to_wp( array $blocks ): array {
		return array_map(
			static function ( array $b ): array {
				$inner = self::to_wp( (array) ( $b['innerBlocks'] ?? [] ) );
				$html  = (string) ( $b['innerHTML'] ?? '' );
				return [
					'blockName'    => $b['name'],
					'attrs'        => (array) ( $b['attributes'] ?? [] ),
					'innerBlocks'  => $inner,
					'innerHTML'    => $html,
					'innerContent' => '' === $html && $inner ? array_fill( 0, count( $inner ), null ) : [ $html ],
				];
			},
			$blocks
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function write( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$id     = (int) ( $input['id'] ?? 0 );
		$blocks = (array) ( $input['blocks'] ?? [] );
		$mode   = (string) ( $input['mode'] ?? 'append' );
		$dry    = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];

		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return new \WP_Error( 'niranzwp_not_found', 'No post with ID ' . $id );
		}
		if ( ! $blocks ) {
			return new \WP_Error( 'niranzwp_no_blocks', 'No blocks supplied.' );
		}

		$problems = self::validate( $blocks );
		if ( $problems ) {
			return new \WP_Error(
				'niranzwp_invalid_blocks',
				"The block tree does not match this site's registry:\n  - " . implode( "\n  - ", $problems )
			);
		}

		$markup = serialize_blocks( self::to_wp( $blocks ) );
		$before = (string) $post->post_content;

		$after = match ( $mode ) {
			'replace' => $markup,
			'prepend' => $markup . "\n\n" . $before,
			default   => rtrim( $before ) . "\n\n" . $markup,
		};

		$result = [
			'id'           => $id,
			'mode'         => $mode,
			'blocks_added' => count( $blocks ),
			'bytes_before' => strlen( $before ),
			'bytes_after'  => strlen( $after ),
			'dry_run'      => $dry,
			'preview'      => mb_substr( $markup, 0, 600 ),
		];

		if ( $dry ) {
			$result['status'] = 'would_write';
			$result['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $result;
		}

		// Snapshot the post before its body is replaced, so the edit is undoable.
		$result['checkpoint_id'] = Checkpoint::before_post( $id, 'block-write' );

		$updated = wp_update_post( [ 'ID' => $id, 'post_content' => $after ], true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$result['status'] = 'written';
		return $result;
	}
}
