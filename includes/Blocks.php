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

		register_ability( 'niranzwp/block-types', [
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

		register_ability( 'niranzwp/block-type', [
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

		register_ability( 'niranzwp/block-read', [
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

		register_ability( 'niranzwp/block-write', [
			'label'               => __( 'Write post blocks', 'niranzwp' ),
			'description'         => __( 'Adds, replaces or removes blocks in a post. Appends or prepends at the top level, or acts on one block named by its path - after, before, replace-block, delete - and replace discards the whole body. Validates every block name and attribute against this site\'s registry first, snapshots the post, and previews unless dry_run is false.', 'niranzwp' ),
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
								// Free-form by nature: every block type declares its own.
								'attributes' => [ 'type' => 'object', 'additionalProperties' => true ],
								'innerHTML'  => [ 'type' => 'string' ],
								// Strings are wrapper markup, nulls are slots for
								// inner blocks, one per child in order. Left
								// untyped because the array is genuinely mixed.
								'innerContent' => [ 'type' => 'array' ],
								'innerBlocks'=> [ 'type' => 'array' ],
								'allow_void' => [
									'type'        => 'boolean',
									'description' => 'Permit a block with no markup and no children. Only correct for a dynamic block that renders itself.',
									'default'     => false,
								],
							],
						],
					],
					'mode'    => [
						'type'        => 'string',
						'enum'        => [ 'replace', 'append', 'prepend', 'after', 'before', 'replace-block', 'delete' ],
						'default'     => 'append',
						'description' => 'append and prepend go at the top level. after, before, replace-block and delete act on target. replace discards the whole body.',
					],
					'target'  => [
						'type'        => 'string',
						'description' => 'Which block to act on, as the path block-read and block-find report: "2" is the third block, "2.1" its second child.',
					],
				'expected_sha256' => [
					'type'        => 'string',
					'description' => 'sha256 of post_content as block-read returned it. The write is refused if the post has changed since, so two edits cannot silently overwrite each other.',
				],
					'dry_run' => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'id' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'write' ],
			'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
		] );

		register_ability( 'niranzwp/block-find', [
			'label'               => __( 'Find blocks', 'niranzwp' ),
			'description'         => __( 'Finds blocks in a post by type, by an attribute they carry, or by the text they show, and reports the path of each - which is how block-update, block-move and block-write name a block. Searches the whole tree, however deep.', 'niranzwp' ),
			'category'            => 'niranzwp-gutenberg',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'        => [ 'type' => 'integer' ],
					'name'      => [ 'type' => 'string', 'description' => 'Block type, such as core/heading.' ],
					'attribute' => [ 'type' => 'string', 'description' => 'Only blocks carrying this attribute.' ],
					'value'     => [ 'description' => 'With attribute, only where it holds this.' ],
					'text'      => [ 'type' => 'string', 'description' => 'Only blocks whose visible text contains this.' ],
					'limit'     => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 500 ],
				],
				'required'   => [ 'id' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'find' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/block-update', [
			'label'               => __( 'Update a block', 'niranzwp' ),
			'description'         => __( 'Changes the attributes of one block, named by its path. Merges into what the block already has unless replace is true, checks the result against this site\'s registry, and reports the before and after unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp-gutenberg',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'         => [ 'type' => 'integer' ],
					'path'       => [ 'type' => 'string', 'description' => 'The block, as block-read and block-find report it.' ],
					'attributes' => [ 'type' => 'object', 'additionalProperties' => true ],
					'replace'    => [ 'type' => 'boolean', 'default' => false, 'description' => 'Discard the block\'s other attributes rather than merging.' ],
					'expected_sha256' => [ 'type' => 'string', 'description' => 'sha256 as block-read returned it. The write is refused if the post has changed since.' ],
					'dry_run'    => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'id', 'path', 'attributes' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'update' ],
			'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
		] );

		register_ability( 'niranzwp/block-move', [
			'label'               => __( 'Move a block', 'niranzwp' ),
			'description'         => __( 'Moves one block to sit before, after, or inside another. Refuses to move a block into itself, which would take the branch out of the post. Paths are re-read after the block is lifted out, so the destination means where it was before the move.', 'niranzwp' ),
			'category'            => 'niranzwp-gutenberg',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'       => [ 'type' => 'integer' ],
					'from'     => [ 'type' => 'string', 'description' => 'Path of the block to move.' ],
					'to'       => [ 'type' => 'string', 'description' => 'Path of the block to move it relative to.' ],
					'position' => [ 'type' => 'string', 'enum' => [ 'before', 'after', 'inside' ], 'default' => 'after' ],
					'expected_sha256' => [ 'type' => 'string' ],
					'dry_run'  => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'id', 'from', 'to' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'move' ],
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
	private static function summarize( array $b, int $depth, int $level = 0, string $path = '' ): array {
		$row = [
			// How to name this block to block-update, block-move, or the
			// targeted modes of block-write. A block has no id of its own.
			'path'       => $path,
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
				$children = [];
				foreach ( self::real( $inner ) as $nth => $at ) {
					$children[] = self::summarize( $inner[ $at ], $depth, $level + 1, $path . '.' . $nth );
				}
				$row['innerBlocks'] = $children;
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
			// Hand back what the body hashed to, so an edit built on this read
			// can prove nothing moved underneath it. block-write takes the
			// same value as expected_sha256.
			'sha256'       => hash( 'sha256', (string) $post->post_content ),
			'blocks'       => array_map(
				static fn( int $i ): array => self::summarize( $parsed[ $i ], $depth, 0, (string) $i ),
				array_keys( $parsed )
			),
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
			$html  = (string) ( $b['innerHTML'] ?? '' );
			$ic    = array_key_exists( 'innerContent', $b ) && is_array( $b['innerContent'] )
				? array_values( $b['innerContent'] )
				: null;

			$has_markup = '' !== trim( $html )
				|| ( null !== $ic && array_filter( $ic, static fn( $x ) => is_string( $x ) && '' !== trim( $x ) ) );

			/*
			 * V1 - a sourced attribute lives in the saved markup, never in the
			 * block comment. Write one with no markup and the value goes into
			 * the JSON, where parse_blocks() will not look for it, and the
			 * block comes back empty. core/image's url and alt, core/paragraph
			 * and core/heading's content are all sourced this way.
			 *
			 * is_dynamic() is the wrong test here: core/image registers a
			 * render callback, so a dynamic check waves the worst case
			 * straight through.
			 */
			$sourced = [];
			foreach ( (array) ( $b['attributes'] ?? [] ) as $key => $_ ) {
				if ( isset( $type->attributes[ $key ]['source'] ) ) {
					$sourced[] = $key;
				}
			}
			if ( $sourced && ! $has_markup ) {
				$problems[] = sprintf(
					'%s: "%s" reads %s out of the saved markup, not the block comment. With no innerHTML those values are written into the comment and lost on parse.',
					$at,
					$name,
					implode( ', ', $sourced )
				);
			}

			// V2 - nothing to render and nothing nested. Deliberate for a few
			// dynamic blocks, a mistake everywhere else, so it needs saying.
			if ( ! $has_markup && ! $inner && empty( $b['allow_void'] ) ) {
				$problems[] = sprintf(
					'%s: "%s" would be written with no markup and no inner blocks, which renders as nothing. Pass innerHTML, or allow_void true if that is intended.',
					$at,
					$name
				);
			}

			/*
			 * V3 - innerContent is how serialize_block() interleaves wrapper
			 * markup with children: strings are literal, each null is the slot
			 * for the next child. Supplying innerHTML alone alongside children
			 * is the shape that silently drops every one of them.
			 */
			if ( $inner ) {
				if ( null === $ic ) {
					if ( '' !== trim( $html ) ) {
						$problems[] = sprintf(
							'%s: "%s" has innerHTML and %d inner block(s) but no innerContent. innerHTML alone drops every child. Supply innerContent as the wrapper split around one null per child, e.g. ["<div>", null, "</div>"].',
							$at,
							$name,
							count( $inner )
						);
					}
				} else {
					$slots = count( array_filter( $ic, 'is_null' ) );
					if ( $slots !== count( $inner ) ) {
						$problems[] = sprintf(
							'%s: innerContent has %d null slot(s) but there are %d inner block(s). They must match, in order.',
							$at,
							$slots,
							count( $inner )
						);
					}
				}
			} elseif ( null !== $ic && array_filter( $ic, 'is_null' ) ) {
				$problems[] = sprintf(
					'%s: innerContent has null slots but there are no inner blocks to fill them.',
					$at
				);
			}

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

				// An explicit innerContent wins: it is the only way to say
				// where the children sit inside the wrapper. Otherwise fall
				// back to the two unambiguous shapes - all children, or all
				// markup.
				$ic = array_key_exists( 'innerContent', $b ) && is_array( $b['innerContent'] )
					? array_values( $b['innerContent'] )
					: ( $inner ? array_fill( 0, count( $inner ), null ) : [ $html ] );

				// serialize_block() ignores innerHTML, but parse_blocks() will
				// rebuild it from innerContent, so keep the two agreeing.
				if ( '' === $html ) {
					$html = implode( '', array_filter( $ic, 'is_string' ) );
				}

				return [
					'blockName'    => $b['name'],
					'attrs'        => (array) ( $b['attributes'] ?? [] ),
					'innerBlocks'  => $inner,
					'innerHTML'    => $html,
					'innerContent' => $ic,
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
		if ( ! $blocks && 'delete' !== $mode ) {
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

		/*
		 * The three original modes work on the markup, because they do not
		 * need to know where anything is. The four that act on one block do,
		 * so they go through the parsed tree and the path addressing instead.
		 */
		$targeted = [ 'after', 'before', 'replace-block', 'delete' ];
		if ( in_array( $mode, $targeted, true ) ) {
			return self::write_at( $post, $mode, $blocks, (string) ( $input['target'] ?? '' ), $dry, (string) ( $input['expected_sha256'] ?? '' ) );
		}

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

		/*
		 * A post read, edited and written back is a read-modify-write, and
		 * nothing here was checking that the post still says what it said when
		 * it was read. Two edits in flight meant the second one silently threw
		 * away the first. Optional, because a caller writing fresh content has
		 * nothing to compare against - but block-read returns the hash, so
		 * anything that edits in place can pass it.
		 */
		$expect = strtolower( trim( (string) ( $input['expected_sha256'] ?? '' ) ) );
		if ( '' !== $expect ) {
			if ( ! preg_match( '/^[0-9a-f]{64}$/', $expect ) ) {
				return new \WP_Error( 'niranzwp_bad_sha', 'expected_sha256 must be 64 hexadecimal characters.', [ 'status' => 400 ] );
			}
			$actual = hash( 'sha256', (string) $post->post_content );
			if ( ! hash_equals( $expect, $actual ) ) {
				return new \WP_Error(
					'niranzwp_content_changed',
					sprintf( 'Post %d has changed since it was read. Nothing was written. Read it again and retry.', $id ),
					[ 'status' => 409 ]
				);
			}
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
	/* ------------------------------------------------------- addressing */

	/*
	 * A Gutenberg block has no id. Elementor gives every element a seven-hex
	 * one, which is what elementor-find and elementor-move address; the block
	 * editor has nothing equivalent, so a block is addressed by where it sits:
	 * "2" is the third block, "2.1" its second child, "2.1.0" that child's
	 * first. Only real blocks are counted. parse_blocks() emits an unnamed
	 * block for the whitespace between two real ones, and counting those would
	 * make every path depend on how the markup happens to be indented.
	 */

	/**
	 * The array positions of the real blocks, in order.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return int[] Keys into $blocks, one per named block.
	 */
	private static function real( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $i => $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$out[] = (int) $i;
			}
		}
		return $out;
	}

	/**
	 * A path string as its segments, or null if it is not one.
	 *
	 * @param string $path Dot-separated indices.
	 * @return int[]|null
	 */
	private static function path_parts( string $path ): ?array {
		$path = trim( $path );
		if ( '' === $path || ! preg_match( '/^\d+(\.\d+)*$/', $path ) ) {
			return null;
		}
		return array_map( 'intval', explode( '.', $path ) );
	}

	/**
	 * The list a path's last segment indexes into, by reference.
	 *
	 * Returns the top-level list for a one-segment path, and the innerBlocks
	 * of the block named by every segment but the last otherwise. Null when a
	 * segment names nothing, so a caller can tell "no such block" from "found
	 * an empty list".
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks, by reference.
	 * @param int[]                          $parts  Path segments.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function &container( array &$blocks, array $parts ) {
		$nothing = null;
		$list    = &$blocks;

		for ( $level = 0; $level < count( $parts ) - 1; $level++ ) {
			$real = self::real( $list );
			if ( ! isset( $real[ $parts[ $level ] ] ) ) {
				return $nothing;
			}
			$at = $real[ $parts[ $level ] ];
			if ( ! isset( $list[ $at ]['innerBlocks'] ) || ! is_array( $list[ $at ]['innerBlocks'] ) ) {
				return $nothing;
			}
			$list = &$list[ $at ]['innerBlocks'];
		}

		return $list;
	}

	/**
	 * Where a path's last segment lands in its container.
	 *
	 * @param array<int,array<string,mixed>> $container The list from container().
	 * @param int                            $nth       Last path segment.
	 * @return int|null Array key, or null when there is no such block.
	 */
	private static function index_in( array $container, int $nth ): ?int {
		$real = self::real( $container );
		return $real[ $nth ] ?? null;
	}

	/**
	 * Every real block in the tree, flattened, with its path.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param string                         $prefix Path of the parent.
	 * @return array<int,array{path:string,block:array<string,mixed>}>
	 */
	private static function flatten( array $blocks, string $prefix = '' ): array {
		$out = [];
		foreach ( self::real( $blocks ) as $nth => $at ) {
			$path  = '' === $prefix ? (string) $nth : $prefix . '.' . $nth;
			$out[] = [ 'path' => $path, 'block' => $blocks[ $at ] ];
			$inner = (array) ( $blocks[ $at ]['innerBlocks'] ?? [] );
			if ( $inner ) {
				$out = array_merge( $out, self::flatten( $inner, $path ) );
			}
		}
		return $out;
	}

	/**
	 * A post's parsed blocks, or an error.
	 *
	 * @param int $id Post id.
	 * @return array{post:\WP_Post,blocks:array<int,array<string,mixed>>}|\WP_Error
	 */
	private static function parsed( int $id ) {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return new \WP_Error( 'niranzwp_not_found', 'No post with ID ' . $id );
		}
		$parent = wp_is_post_revision( $id );
		if ( $parent ) {
			return new \WP_Error(
				'niranzwp_is_revision',
				sprintf( 'Post %d is a revision of %d. Use %d.', $id, (int) $parent, (int) $parent )
			);
		}
		return [ 'post' => $post, 'blocks' => parse_blocks( (string) $post->post_content ) ];
	}

	/**
	 * Refuse the write when the post moved since it was read.
	 *
	 * @param \WP_Post $post   The post as it is now.
	 * @param string   $expect sha256 the caller was given, or ''.
	 * @return true|\WP_Error
	 */
	private static function unchanged( \WP_Post $post, string $expect ) {
		$expect = strtolower( trim( $expect ) );
		if ( '' === $expect ) {
			return true;
		}
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $expect ) ) {
			return new \WP_Error( 'niranzwp_bad_sha', 'expected_sha256 must be 64 hexadecimal characters.', [ 'status' => 400 ] );
		}
		if ( ! hash_equals( $expect, hash( 'sha256', (string) $post->post_content ) ) ) {
			return new \WP_Error(
				'niranzwp_content_changed',
				sprintf( 'Post %d has changed since it was read. Nothing was written. Read it again and retry.', $post->ID ),
				[ 'status' => 409 ]
			);
		}
		return true;
	}

	/** Temporary key that survives a splice, so a block can be found again. */
	private const MARK = '__nzwp_mark';

	/**
	 * The list holding the block carrying a mark, by reference.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks, by reference.
	 * @param string                         $mark   The mark to look for.
	 * @param int|null                       $at     Set to its key in the list.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function &marked( array &$blocks, string $mark, ?int &$at ) {
		$nothing = null;

		foreach ( $blocks as $i => $block ) {
			if ( isset( $block[ self::MARK ] ) && $block[ self::MARK ] === $mark ) {
				$at = (int) $i;
				return $blocks;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = &self::marked( $blocks[ $i ]['innerBlocks'], $mark, $at );
				if ( null !== $found ) {
					return $found;
				}
				unset( $found );
			}
		}

		return $nothing;
	}

	/**
	 * Make every wrapper's slots match the children it actually has.
	 *
	 * innerContent is how a block says where its children sit inside its own
	 * markup: each string is a piece of that markup and each null is one
	 * child, in order. serialize_block() walks it and pulls the next child at
	 * every null, so a wrapper left with more nulls than children reaches past
	 * the end of the list, and one with fewer drops a child silently.
	 *
	 * Adding or removing a child therefore has to fix the wrapper too. Rather
	 * than have each of the four edits remember that, it is done once here, on
	 * the way to being written, where nothing can get past it.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sync_slots( array $blocks ): array {
		foreach ( $blocks as $i => $block ) {
			$inner = (array) ( $block['innerBlocks'] ?? [] );
			if ( $inner ) {
				$blocks[ $i ]['innerBlocks'] = self::sync_slots( $inner );
			}

			if ( ! isset( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
				continue;
			}

			$content = array_values( $block['innerContent'] );
			$slots   = [];
			foreach ( $content as $at => $chunk ) {
				if ( null === $chunk ) {
					$slots[] = $at;
				}
			}

			$want = count( $inner );

			// Too many: drop the last of the surplus, so the markup between
			// the children that remain is left where it was.
			while ( count( $slots ) > $want ) {
				array_splice( $content, (int) array_pop( $slots ), 1 );
			}

			// Too few: put the missing ones just inside the closing markup,
			// which is where a child appended to the end belongs.
			if ( count( $slots ) < $want ) {
				$tail = count( $content );
				while ( $tail > 0 && is_string( $content[ $tail - 1 ] ) ) {
					--$tail;
				}
				array_splice( $content, $tail, 0, array_fill( 0, $want - count( $slots ), null ) );
			}

			$blocks[ $i ]['innerContent'] = $content;
		}

		return $blocks;
	}

	/**
	 * Put an edited tree back on the post.
	 *
	 * @param \WP_Post                       $post   Post being edited.
	 * @param array<int,array<string,mixed>> $blocks The whole tree, edited.
	 * @param string                         $why    What to label the snapshot.
	 * @param array<string,mixed>            $result Reply so far.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function commit( \WP_Post $post, array $blocks, string $why, array $result ) {
		$result['checkpoint_id'] = Checkpoint::before_post( (int) $post->ID, $why );

		$updated = wp_update_post(
			[ 'ID' => $post->ID, 'post_content' => serialize_blocks( self::sync_slots( $blocks ) ) ],
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		clean_post_cache( (int) $post->ID );
		$result['status'] = 'written';
		return $result;
	}
	/* --------------------------------------------------- one block at a time */

	/** @return array<string,mixed>|\WP_Error */
	public static function find( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];

		$parsed = self::parsed( (int) ( $input['id'] ?? 0 ) );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$name      = (string) ( $input['name'] ?? '' );
		$attribute = (string) ( $input['attribute'] ?? '' );
		$has_value = array_key_exists( 'value', $input );
		$text      = strtolower( trim( (string) ( $input['text'] ?? '' ) ) );
		$limit     = max( 1, min( 500, (int) ( $input['limit'] ?? 50 ) ) );

		$matches = [];
		$scanned = 0;

		foreach ( self::flatten( $parsed['blocks'] ) as $row ) {
			++$scanned;
			$block = $row['block'];
			$attrs = (array) ( $block['attrs'] ?? [] );

			if ( '' !== $name && $name !== (string) $block['blockName'] ) {
				continue;
			}
			if ( '' !== $attribute ) {
				if ( ! array_key_exists( $attribute, $attrs ) ) {
					continue;
				}
				if ( $has_value && $attrs[ $attribute ] !== $input['value'] ) {
					continue;
				}
			}

			$plain = trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
			if ( '' !== $text && ! str_contains( strtolower( $plain ), $text ) ) {
				continue;
			}

			if ( count( $matches ) >= $limit ) {
				continue;
			}

			$match = [
				'path'       => $row['path'],
				'name'       => (string) $block['blockName'],
				'attributes' => $attrs,
			];
			if ( '' !== $plain ) {
				$match['text'] = mb_substr( $plain, 0, 120 );
			}
			$inner = (array) ( $block['innerBlocks'] ?? [] );
			if ( $inner ) {
				$match['inner_count'] = count( self::real( $inner ) );
			}
			$matches[] = $match;
		}

		return [
			'id'      => (int) $parsed['post']->ID,
			'scanned' => $scanned,
			'count'   => count( $matches ),
			'sha256'  => hash( 'sha256', (string) $parsed['post']->post_content ),
			'blocks'  => $matches,
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function update( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$dry   = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];

		$parsed = self::parsed( (int) ( $input['id'] ?? 0 ) );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$parts = self::path_parts( (string) ( $input['path'] ?? '' ) );
		if ( null === $parts ) {
			return new \WP_Error( 'niranzwp_bad_path', 'path must be dot-separated indices, as block-read reports them: "2" or "2.1.0".' );
		}

		$attributes = $input['attributes'] ?? null;
		if ( ! is_array( $attributes ) ) {
			return new \WP_Error( 'niranzwp_missing', 'attributes must be an object of attribute => value.' );
		}

		$blocks    = $parsed['blocks'];
		$container = &self::container( $blocks, $parts );
		$at        = null === $container ? null : self::index_in( $container, end( $parts ) );
		if ( null === $at ) {
			return new \WP_Error(
				'niranzwp_block_not_found',
				sprintf( 'No block at path "%s" in post %d. Use block-read or block-find to see what is there.', (string) $input['path'], (int) $parsed['post']->ID )
			);
		}

		$block  = $container[ $at ];
		$before = (array) ( $block['attrs'] ?? [] );
		$after  = ! empty( $input['replace'] ) ? $attributes : array_merge( $before, $attributes );

		/*
		 * Checked as a block rather than as a bag of keys: validate() is what
		 * block-write uses, so an attribute this block type does not have is
		 * refused here for the same reason and with the same wording.
		 */
		$problems = self::validate( [ [ 'name' => (string) $block['blockName'], 'attributes' => $after, 'innerHTML' => (string) ( $block['innerHTML'] ?? '' ) ] ], 'block' );
		if ( $problems ) {
			return new \WP_Error(
				'niranzwp_invalid_blocks',
				"The block would no longer match this site's registry:\n  - " . implode( "\n  - ", $problems )
			);
		}

		$result = [
			'id'      => (int) $parsed['post']->ID,
			'path'    => (string) $input['path'],
			'name'    => (string) $block['blockName'],
			'before'  => $before,
			'after'   => $after,
			'dry_run' => $dry,
		];

		if ( $before === $after ) {
			$result['status'] = 'no_change';
			return $result;
		}
		if ( $dry ) {
			$result['status'] = 'would_update';
			$result['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $result;
		}

		$fresh = self::unchanged( $parsed['post'], (string) ( $input['expected_sha256'] ?? '' ) );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$container[ $at ]['attrs'] = $after;

		return self::commit( $parsed['post'], $blocks, 'block-update', $result );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function move( mixed $input = [] ) {
		$input    = is_array( $input ) ? $input : [];
		$dry      = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$position = (string) ( $input['position'] ?? 'after' );

		$parsed = self::parsed( (int) ( $input['id'] ?? 0 ) );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$from_s = (string) ( $input['from'] ?? '' );
		$to_s   = (string) ( $input['to'] ?? '' );
		$from   = self::path_parts( $from_s );
		$to     = self::path_parts( $to_s );
		if ( null === $from || null === $to ) {
			return new \WP_Error( 'niranzwp_bad_path', 'from and to must be dot-separated indices, as block-read reports them.' );
		}

		if ( $from_s === $to_s ) {
			return new \WP_Error( 'niranzwp_same_block', 'from and to name the same block.' );
		}

		/*
		 * Moving a block inside itself would take it, and everything under it,
		 * out of the post: the branch is lifted out and then put back into a
		 * list that is no longer attached to anything. A destination path that
		 * starts with the source path is exactly that case.
		 */
		if ( str_starts_with( $to_s . '.', $from_s . '.' ) ) {
			return new \WP_Error(
				'niranzwp_into_itself',
				sprintf( 'Block "%s" is inside "%s", so moving it there would remove both from the post.', $to_s, $from_s )
			);
		}

		$blocks = $parsed['blocks'];

		$probe    = &self::container( $blocks, $from );
		$probe_at = null === $probe ? null : self::index_in( $probe, end( $from ) );
		if ( null === $probe_at ) {
			return new \WP_Error( 'niranzwp_block_not_found', sprintf( 'No block at path "%s".', $from_s ) );
		}
		$moving = $probe[ $probe_at ];
		unset( $probe, $probe_at );

		$result = [
			'id'       => (int) $parsed['post']->ID,
			'from'     => $from_s,
			'to'       => $to_s,
			'position' => $position,
			'name'     => (string) $moving['blockName'],
			'dry_run'  => $dry,
		];

		if ( $dry ) {
			$result['status'] = 'would_move';
			$result['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $result;
		}

		$fresh = self::unchanged( $parsed['post'], (string) ( $input['expected_sha256'] ?? '' ) );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		/*
		 * Both paths describe the tree as it is now, but removing the source
		 * renumbers everything after it, so a destination read afterwards can
		 * be the wrong block or no block at all. The destination is marked
		 * while both are still where the caller saw them, and found again by
		 * that mark once the source is out.
		 */
		$dst = &self::container( $blocks, $to );
		$dst_at = null === $dst ? null : self::index_in( $dst, end( $to ) );
		if ( null === $dst_at ) {
			return new \WP_Error( 'niranzwp_block_not_found', sprintf( 'No block at path "%s".', $to_s ) );
		}
		$mark = 'nzwp-' . bin2hex( random_bytes( 8 ) );
		$dst[ $dst_at ][ self::MARK ] = $mark;
		unset( $dst, $dst_at );

		$src = &self::container( $blocks, $from );
		$src_at = self::index_in( $src, end( $from ) );
		array_splice( $src, $src_at, 1 );
		unset( $src );

		$dst = &self::marked( $blocks, $mark, $dst_at );
		if ( null === $dst ) {
			return new \WP_Error( 'niranzwp_block_not_found', sprintf( 'Lost the block at path "%s". Nothing was written.', $to_s ) );
		}
		unset( $dst[ $dst_at ][ self::MARK ] );

		if ( 'inside' === $position ) {
			if ( ! isset( $dst[ $dst_at ]['innerBlocks'] ) || ! is_array( $dst[ $dst_at ]['innerBlocks'] ) ) {
				$dst[ $dst_at ]['innerBlocks'] = [];
			}
			$dst[ $dst_at ]['innerBlocks'][] = $moving;
		} else {
			array_splice( $dst, 'before' === $position ? $dst_at : $dst_at + 1, 0, [ $moving ] );
		}

		return self::commit( $parsed['post'], $blocks, 'block-move', $result );
	}
	/**
	 * The four modes that act on one block, named by its path.
	 *
	 * @param \WP_Post                       $post   Post being edited.
	 * @param string                         $mode   after, before, replace-block or delete.
	 * @param array<int,array<string,mixed>> $blocks New blocks, in the ability's shape.
	 * @param string                         $target Path of the block to act on.
	 * @param bool                           $dry    Report only.
	 * @param string                         $expect sha256 the caller was given, or ''.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function write_at( \WP_Post $post, string $mode, array $blocks, string $target, bool $dry, string $expect ) {
		$parts = self::path_parts( $target );
		if ( null === $parts ) {
			return new \WP_Error(
				'niranzwp_bad_target',
				sprintf( 'Mode "%s" acts on one block, so it needs target: a path as block-read reports it, like "2" or "2.1".', $mode )
			);
		}

		$tree      = parse_blocks( (string) $post->post_content );
		$container = &self::container( $tree, $parts );
		$at        = null === $container ? null : self::index_in( $container, end( $parts ) );
		if ( null === $at ) {
			return new \WP_Error(
				'niranzwp_block_not_found',
				sprintf( 'No block at path "%s" in post %d. Use block-read or block-find to see what is there.', $target, (int) $post->ID )
			);
		}

		$result = [
			'id'      => (int) $post->ID,
			'mode'    => $mode,
			'target'  => $target,
			'name'    => (string) $container[ $at ]['blockName'],
			'dry_run' => $dry,
		];
		if ( 'delete' !== $mode ) {
			$result['blocks_added'] = count( $blocks );
		}

		if ( $dry ) {
			$result['status'] = 'would_write';
			$result['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $result;
		}

		$fresh = self::unchanged( $post, $expect );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$incoming = self::to_wp( $blocks );

		switch ( $mode ) {
			case 'delete':
				array_splice( $container, $at, 1 );
				break;
			case 'replace-block':
				array_splice( $container, $at, 1, $incoming );
				break;
			case 'before':
				array_splice( $container, $at, 0, $incoming );
				break;
			default: // after
				array_splice( $container, $at + 1, 0, $incoming );
		}

		return self::commit( $post, $tree, 'block-write', $result );
	}
}
