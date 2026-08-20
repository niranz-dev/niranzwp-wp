<?php
/**
 * SEO abilities that write.
 *
 * Everything here defaults to dry_run = true. On a site with tens of
 * thousands of posts an unpreviewed bulk write is unrecoverable, so the
 * caller has to ask for the write explicitly.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class SeoFix {

	/** Never touch more than this in one call, whatever the caller asks for. */
	private const MAX_BATCH = 200;

	public static function register( callable|array $gate ): void {
		register_ability(
			'niranzwp/seo-list-missing',
			[
				'label'               => __( 'List posts missing SEO data', 'niranzwp' ),
				'description'         => __( 'Returns a page of published posts that are missing a given SEO field (description, title, focus keyword, alt text or featured image), with IDs, titles and URLs so they can be fixed.', 'niranzwp' ),
				'category'            => 'niranzwp-seo',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'field'     => [ 'type' => 'string', 'enum' => [ 'description', 'title', 'focus', 'thumbnail', 'alt' ], 'default' => 'description' ],
						'post_type' => [ 'type' => 'string', 'default' => 'post' ],
						'limit'     => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 200 ],
						'offset'    => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0 ],
					],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'list_missing' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ],
			]
		);

		register_ability(
			'niranzwp/seo-set-meta',
			[
				'label'               => __( 'Set SEO meta', 'niranzwp' ),
				'description'         => __( 'Sets the SEO description, title or focus keyword on one or more posts. Previews by default: pass dry_run false to actually write.', 'niranzwp' ),
				'category'            => 'niranzwp-seo',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'items'   => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'    => [ 'type' => 'integer' ],
									'field' => [ 'type' => 'string', 'enum' => [ 'description', 'title', 'focus' ] ],
									'value' => [ 'type' => 'string' ],
								],
							],
						],
						'dry_run' => [ 'type' => 'boolean', 'default' => true ],
					],
					'required'   => [ 'items' ],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'set_meta' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
			]
		);

		register_ability(
			'niranzwp/media-set-alt',
			[
				'label'               => __( 'Set image alt text', 'niranzwp' ),
				'description'         => __( 'Sets alt text on one or more image attachments. Previews by default: pass dry_run false to actually write.', 'niranzwp' ),
				'category'            => 'niranzwp-seo',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'items'   => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'  => [ 'type' => 'integer' ],
									'alt' => [ 'type' => 'string' ],
								],
							],
						],
						'dry_run' => [ 'type' => 'boolean', 'default' => true ],
					],
					'required'   => [ 'items' ],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'set_alt' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
			]
		);

		register_ability(
			'niranzwp/geo-llms-txt',
			[
				'label'               => __( 'Generate llms.txt', 'niranzwp' ),
				'description'         => __( 'Builds an llms.txt listing this site\'s pages and recent articles. Note that Google lists llms.txt among practices that do not affect AI search, and server-log studies show AI crawlers do not request it; this exists because some people want the file, not because it is known to help. Returns the content by default: pass write true to save it.', 'niranzwp' ),
				'category'            => 'niranzwp-seo',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'write' => [ 'type' => 'boolean', 'default' => false ],
						'limit' => [ 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 200 ],
					],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'llms_txt' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => false ] ],
			]
		);
	}

	/** Map a friendly field name onto the active SEO plugin's meta key. */
	private static function meta_key( string $field ): ?string {
		$map = [];
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$map = [ 'description' => 'rank_math_description', 'title' => 'rank_math_title', 'focus' => 'rank_math_focus_keyword' ];
		} elseif ( defined( 'WPSEO_VERSION' ) ) {
			$map = [ 'description' => '_yoast_wpseo_metadesc', 'title' => '_yoast_wpseo_title', 'focus' => '_yoast_wpseo_focuskw' ];
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$map = [ 'description' => '_seopress_titles_desc', 'title' => '_seopress_titles_title', 'focus' => '_seopress_analysis_target_kw' ];
		}
		return $map[ $field ] ?? null;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function list_missing( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		global $wpdb;

		$field     = (string) ( $input['field'] ?? 'description' );
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );
		$limit     = min( self::MAX_BATCH, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
		$offset    = max( 0, (int) ( $input['offset'] ?? 0 ) );

		// Images are attachments, so they need their own query shape.
		if ( 'alt' === $field ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, p.post_title, p.guid
				   FROM {$wpdb->posts} p
				   LEFT JOIN {$wpdb->postmeta} m
				          ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
				  WHERE p.post_type = 'attachment'
				    AND p.post_mime_type LIKE 'image/%%'
				    AND ( m.meta_id IS NULL OR m.meta_value = '' )
				  ORDER BY p.ID DESC
				  LIMIT %d OFFSET %d",
				$limit,
				$offset
			), ARRAY_A );

			// A page count is useless for planning. On a site with forty
			// thousand images, "count: 20" says nothing about the size of the
			// job -- SQL_CALC_FOUND_ROWS is deprecated, so this is a second
			// COUNT with the same WHERE.
			$total = (int) $wpdb->get_var(
				"SELECT COUNT(*)
				   FROM {$wpdb->posts} p
				   LEFT JOIN {$wpdb->postmeta} m
				          ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
				  WHERE p.post_type = 'attachment'
				    AND p.post_mime_type LIKE 'image/%'
				    AND ( m.meta_id IS NULL OR m.meta_value = '' )"
			);

			return [
				'field'      => 'alt',
				'total'      => $total,
				'returned'   => count( $rows ),
				'offset'     => $offset,
				'remaining'  => max( 0, $total - $offset - count( $rows ) ),
				'items'  => array_map(
					static fn( array $r ): array => [
						'id'    => (int) $r['ID'],
						'title' => $r['post_title'],
						'url'   => $r['guid'],
					],
					$rows ?: []
				),
			];
		}

		$meta_key = 'thumbnail' === $field ? '_thumbnail_id' : self::meta_key( $field );
		if ( ! $meta_key ) {
			return new \WP_Error( 'niranzwp_no_seo_plugin', 'No supported SEO plugin is active, so this field cannot be resolved.' );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_date
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND ( m.meta_id IS NULL OR m.meta_value = '' )
			  ORDER BY p.post_date DESC
			  LIMIT %d OFFSET %d",
			$meta_key,
			$post_type,
			$limit,
			$offset
		), ARRAY_A );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND ( m.meta_id IS NULL OR m.meta_value = '' )",
			$meta_key,
			$post_type
		) );

		return [
			'field'     => $field,
			'meta_key'  => $meta_key,
			'total'     => $total,
			'returned'  => count( $rows ),
			'offset'    => $offset,
			'remaining' => max( 0, $total - $offset - count( $rows ) ),
			'items'    => array_map(
				static fn( array $r ): array => [
					'id'    => (int) $r['ID'],
					'title' => $r['post_title'],
					'date'  => substr( (string) $r['post_date'], 0, 10 ),
					'url'   => get_permalink( (int) $r['ID'] ),
				],
				$rows ?: []
			),
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function set_meta( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$items   = (array) ( $input['items'] ?? [] );
		$dry_run = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];

		if ( ! $items ) {
			return new \WP_Error( 'niranzwp_no_items', 'No items supplied.' );
		}
		if ( count( $items ) > self::MAX_BATCH ) {
			return new \WP_Error(
				'niranzwp_batch_too_large',
				sprintf( 'Batch of %d exceeds the %d item limit. Split it and call again.', count( $items ), self::MAX_BATCH )
			);
		}

		$changes = [];
		$written = 0;
		$targets = [];
		$pending = [];

		foreach ( $items as $item ) {
			$id    = (int) ( $item['id'] ?? 0 );
			$field = (string) ( $item['field'] ?? 'description' );
			$value = (string) ( $item['value'] ?? '' );

			$post = $id ? get_post( $id ) : null;
			if ( ! $post ) {
				$changes[] = [ 'id' => $id, 'status' => 'skipped', 'reason' => 'post not found' ];
				continue;
			}

			$key = self::meta_key( $field );
			if ( ! $key ) {
				$changes[] = [ 'id' => $id, 'status' => 'skipped', 'reason' => 'unknown field or no SEO plugin' ];
				continue;
			}

			$before = (string) get_post_meta( $id, $key, true );
			if ( $before === $value ) {
				$changes[] = [ 'id' => $id, 'status' => 'unchanged' ];
				continue;
			}

			$row = [
				'id'     => $id,
				'title'  => $post->post_title,
				'field'  => $field,
				'before' => '' === $before ? null : $before,
				'after'  => $value,
			];

			if ( $dry_run ) {
				$row['status'] = 'would_update';
			} else {
				$targets[] = [ $id, $key ];
				$pending[] = [ 'id' => $id, 'key' => $key, 'value' => $value, 'row' => count( $changes ) ];
				$row['status'] = 'pending';
			}

			$changes[] = $row;
		}

		/*
		 * One snapshot for the batch, taken after everything has been resolved
		 * and before anything is written. Only the key being changed is
		 * captured: these calls change one field across hundreds of posts, and
		 * a whole-post snapshot each would pass the checkpoint ceiling long
		 * before the batch did.
		 */
		$checkpoint = null;
		$cp_error   = null;
		if ( ! $dry_run && $targets ) {
			$cp = Checkpoint::capture(
				[ 'meta' => $targets ],
				sprintf( 'Before seo-set-meta: %d posts', count( $targets ) )
			);
			if ( is_wp_error( $cp ) ) {
				$cp_error = $cp->get_error_message();
			} else {
				$checkpoint = (int) $cp['checkpoint_id'];
			}

			foreach ( $pending as $w ) {
				update_post_meta( $w['id'], $w['key'], $w['value'] );
				$changes[ $w['row'] ]['status'] = 'updated';
				++$written;
			}
		}

		return [
			'dry_run' => $dry_run,
			'total'   => count( $items ),
			'written' => $written,
			'checkpoint_id' => $checkpoint,
			'checkpoint'    => null !== $checkpoint,
			'checkpoint_error' => $cp_error,
			'changes' => $changes,
			'note'    => $dry_run ? 'Nothing was written. Pass dry_run false to apply.' : null,
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function set_alt( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$items   = (array) ( $input['items'] ?? [] );
		$dry_run = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];

		if ( ! $items ) {
			return new \WP_Error( 'niranzwp_no_items', 'No items supplied.' );
		}
		if ( count( $items ) > self::MAX_BATCH ) {
			return new \WP_Error( 'niranzwp_batch_too_large', sprintf( 'Batch of %d exceeds the %d item limit.', count( $items ), self::MAX_BATCH ) );
		}

		$changes = [];
		$written = 0;

		$targets = [];
		$pending = [];

		foreach ( $items as $item ) {
			$id  = (int) ( $item['id'] ?? 0 );
			$alt = trim( (string) ( $item['alt'] ?? '' ) );

			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				$changes[] = [ 'id' => $id, 'status' => 'skipped', 'reason' => 'not an attachment' ];
				continue;
			}
			if ( '' === $alt ) {
				$changes[] = [ 'id' => $id, 'status' => 'skipped', 'reason' => 'empty alt text' ];
				continue;
			}

			$before = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( $before === $alt ) {
				$changes[] = [ 'id' => $id, 'status' => 'unchanged' ];
				continue;
			}

			$row = [ 'id' => $id, 'before' => '' === $before ? null : $before, 'after' => $alt ];

			if ( $dry_run ) {
				$row['status'] = 'would_update';
			} else {
				$targets[] = [ $id, '_wp_attachment_image_alt' ];
				$pending[] = [ 'id' => $id, 'value' => $alt, 'row' => count( $changes ) ];
				$row['status'] = 'pending';
			}

			$changes[] = $row;
		}

		// One snapshot for the batch, before any of it is written.
		$checkpoint = null;
		$cp_error   = null;
		if ( ! $dry_run && $targets ) {
			$cp = Checkpoint::capture(
				[ 'meta' => $targets ],
				sprintf( 'Before media-set-alt: %d images', count( $targets ) )
			);
			if ( is_wp_error( $cp ) ) {
				$cp_error = $cp->get_error_message();
			} else {
				$checkpoint = (int) $cp['checkpoint_id'];
			}

			foreach ( $pending as $w ) {
				update_post_meta( $w['id'], '_wp_attachment_image_alt', $w['value'] );
				$changes[ $w['row'] ]['status'] = 'updated';
				++$written;
			}
		}

		return [
			'dry_run' => $dry_run,
			'total'   => count( $items ),
			'written' => $written,
			'checkpoint_id'    => $checkpoint,
			'checkpoint'       => null !== $checkpoint,
			'checkpoint_error' => $cp_error,
			'changes' => $changes,
			'note'    => $dry_run ? 'Nothing was written. Pass dry_run false to apply.' : null,
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function llms_txt( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$write = (bool) ( $input['write'] ?? false );
		$limit = min( 200, max( 1, (int) ( $input['limit'] ?? 30 ) ) );

		$name = get_bloginfo( 'name' );
		$desc = get_bloginfo( 'description' );
		$home = untrailingslashit( home_url() );

		$lines   = [];
		$lines[] = '# ' . $name;
		$lines[] = '';
		if ( $desc ) {
			$lines[] = '> ' . $desc;
			$lines[] = '';
		}
		$lines[] = 'Site: ' . $home;
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d' ) . ' by NiranzWP';
		$lines[] = '';

		// Pages first -- they describe what the site is.
		$pages = get_posts( [
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'numberposts'      => 20,
			'orderby'          => 'menu_order title',
			'order'            => 'ASC',
			'suppress_filters' => true,
		] );
		if ( $pages ) {
			$lines[] = '## Pages';
			$lines[] = '';
			foreach ( $pages as $p ) {
				$lines[] = sprintf( '- [%s](%s)', $p->post_title, get_permalink( $p ) );
			}
			$lines[] = '';
		}

		$posts = get_posts( [
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'numberposts'      => $limit,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => true,
		] );
		if ( $posts ) {
			$lines[] = '## Recent articles';
			$lines[] = '';
			foreach ( $posts as $p ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( $p->post_excerpt ?: $p->post_content ), 20, '' );
				$lines[] = sprintf( '- [%s](%s): %s', $p->post_title, get_permalink( $p ), $excerpt );
			}
			$lines[] = '';
		}

		$content = implode( "\n", $lines );
		$path    = ABSPATH . 'llms.txt';

		$result = [
			'bytes'   => strlen( $content ),
			'path'    => 'llms.txt',
			'url'     => $home . '/llms.txt',
			'written' => false,
			'content' => $content,
		];

		if ( $write ) {
			if ( false === file_put_contents( $path, $content ) ) {
				return new \WP_Error( 'niranzwp_write_failed', 'Could not write llms.txt to the site root. Check filesystem permissions.' );
			}
			$result['written'] = true;
		} else {
			$result['note'] = 'Not written. Pass write true to save it to the site root.';
		}

		return $result;
	}
}
