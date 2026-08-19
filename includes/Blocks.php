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
					'mode'    => [ 'type' => 'string', 'enum' => [ 'replace', 'append', 'prepend' ], 'default' => 'append' ],
				'expected_sha256' => [
					'type'        => 'string',
					'description' => 'sha256 of post_content as block-read returned it. The write is refused if the post has changed since, so two edits cannot silently overwrite each other.',
				],
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
			// Hand back what the body hashed to, so an edit built on this read
			// can prove nothing moved underneath it. block-write takes the
			// same value as expected_sha256.
			'sha256'       => hash( 'sha256', (string) $post->post_content ),
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
}
