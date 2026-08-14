<?php
/**
 * Performance: what is making this site slow, and removing the parts of it
 * that are simply accumulated rubbish.
 *
 * Three different questions, so three different abilities. What the database
 * is carrying that nothing reads. What the front page actually loads, and who
 * put it there. Which images are heavy enough to matter.
 *
 * db-cleanup is the one destructive ability here that a checkpoint cannot
 * cover -- snapshotting fifty thousand revisions to allow an undo would cost
 * more than the rubbish being removed. It says so, it previews by default, and
 * it never touches anything a person would miss.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Performance {

	/* ----------------------------------------------------------- database */

	/**
	 * @return array<string,mixed>
	 */
	public static function db_report( mixed $input = [] ): array {
		global $wpdb;
		$input = is_array( $input ) ? $input : [];

		$counts = self::junk_counts();

		// Table sizes come from information_schema, which some managed hosts
		// restrict. A missing answer is reported as null rather than zero.
		$tables = $wpdb->get_results( $wpdb->prepare(
			"SELECT table_name AS name,
			        ROUND( ( data_length + index_length ) / 1024 / 1024, 1 ) AS mb,
			        table_rows AS rows_approx
			   FROM information_schema.TABLES
			  WHERE table_schema = %s
			  ORDER BY ( data_length + index_length ) DESC
			  LIMIT 12",
			DB_NAME
		), ARRAY_A );

		$autoload = Abilities::autoload_report( [ 'limit' => 5 ] );

		return [
			'reclaimable' => $counts,
			'reclaimable_total' => array_sum( $counts ),
			'autoload'    => [
				'total_kb'      => $autoload['total_kb'],
				'healthy_under' => $autoload['healthy_under'],
				'largest'       => $autoload['largest'],
			],
			'largest_tables' => $tables ?: null,
			'note'           => 'Counts are rows that nothing reads. db-cleanup removes them, and previews first.',
		];
	}

	/**
	 * The things that pile up and are never read again.
	 *
	 * @return array<string,int>
	 */
	private static function junk_counts(): array {
		global $wpdb;

		return [
			'revisions'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'auto_drafts'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			'trashed_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ),
			'expired_transients' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				  WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			) ),
			'orphaned_postmeta'  => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				  LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE p.ID IS NULL"
			),
			'orphaned_termmeta'  => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->termmeta} tm
				  LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
				  WHERE t.term_id IS NULL"
			),
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function db_cleanup( mixed $input = [] ) {
		global $wpdb;
		$input = is_array( $input ) ? $input : [];

		$dry   = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$only  = array_filter( array_map( 'sanitize_key', (array) ( $input['only'] ?? [] ) ) );
		$before = self::junk_counts();

		$targets = $only ?: array_keys( $before );
		$unknown = array_diff( $targets, array_keys( $before ) );
		if ( $unknown ) {
			return new \WP_Error(
				'niranzwp_unknown_target',
				'Not something this can clean: ' . implode( ', ', $unknown ) . '. Valid: ' . implode( ', ', array_keys( $before ) ) . '.'
			);
		}

		if ( $dry ) {
			return [
				'dry_run' => true,
				'would_remove' => array_intersect_key( $before, array_flip( $targets ) ),
				'total'   => array_sum( array_intersect_key( $before, array_flip( $targets ) ) ),
				'note'    => 'Nothing was removed. This cannot be undone by a checkpoint, so read the numbers before passing dry_run false.',
			];
		}

		$removed = [];

		foreach ( $targets as $t ) {
			switch ( $t ) {
				case 'revisions':
					$removed[ $t ] = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" );
					break;
				case 'auto_drafts':
					$removed[ $t ] = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
					break;
				case 'trashed_posts':
					$removed[ $t ] = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'" );
					break;
				case 'spam_comments':
					$removed[ $t ] = (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
					break;
				case 'trashed_comments':
					$removed[ $t ] = (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );
					break;
				case 'expired_transients':
					// Remove the value alongside its timeout, or the option
					// table keeps the larger half of the pair.
					$names = $wpdb->get_col( $wpdb->prepare(
						"SELECT option_name FROM {$wpdb->options}
						  WHERE option_name LIKE %s AND option_value < %d",
						$wpdb->esc_like( '_transient_timeout_' ) . '%',
						time()
					) );
					$n = 0;
					foreach ( $names as $timeout_name ) {
						$key = substr( (string) $timeout_name, strlen( '_transient_timeout_' ) );
						$n  += (int) $wpdb->query( $wpdb->prepare(
							"DELETE FROM {$wpdb->options} WHERE option_name IN ( %s, %s )",
							'_transient_timeout_' . $key,
							'_transient_' . $key
						) );
					}
					$removed[ $t ] = $n;
					break;
				case 'orphaned_postmeta':
					$removed[ $t ] = (int) $wpdb->query(
						"DELETE pm FROM {$wpdb->postmeta} pm
						  LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						  WHERE p.ID IS NULL"
					);
					break;
				case 'orphaned_termmeta':
					$removed[ $t ] = (int) $wpdb->query(
						"DELETE tm FROM {$wpdb->termmeta} tm
						  LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
						  WHERE t.term_id IS NULL"
					);
					break;
			}
		}

		// Deleting posts directly leaves their meta behind, so sweep again.
		if ( array_intersect( $targets, [ 'revisions', 'auto_drafts', 'trashed_posts' ] ) ) {
			$removed['orphaned_postmeta'] = ( $removed['orphaned_postmeta'] ?? 0 ) + (int) $wpdb->query(
				"DELETE pm FROM {$wpdb->postmeta} pm
				  LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE p.ID IS NULL"
			);
		}

		return [
			'dry_run' => false,
			'removed' => $removed,
			'total'   => array_sum( $removed ),
			'note'    => 'Run db-report again to see what is left.',
		];
	}

	/* ------------------------------------------------------------- assets */

	/**
	 * What the front page actually loads.
	 *
	 * Fetched over HTTP rather than read from the enqueue registry, because
	 * the registry is what WordPress intended and this is what a browser gets
	 * -- after every optimiser, combiner and deferrer has had its turn.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function asset_audit( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$url   = (string) ( $input['url'] ?? home_url( '/' ) );

		if ( ! str_starts_with( $url, home_url() ) ) {
			return new \WP_Error( 'niranzwp_offsite', 'That URL is not on this site.' );
		}

		$started  = microtime( true );
		$response = wp_remote_get( $url, [
			'timeout'    => 20,
			'user-agent' => 'niranzwp-asset-audit',
		] );
		$ttfb = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$html = (string) wp_remote_retrieve_body( $response );

		$scripts = [];
		$styles  = [];
		if ( preg_match_all( '/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $tag ) {
				$scripts[] = [
					'src'      => $tag[1],
					'owner'    => self::owner( $tag[1] ),
					'blocking' => ! preg_match( '/\b(async|defer|type=["\']module)/i', $tag[0] ),
				];
			}
		}
		if ( preg_match_all( '/<link\b[^>]*rel=["\']stylesheet["\'][^>]*>/i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( preg_match( '/href=["\']([^"\']+)["\']/i', $tag, $h ) ) {
					$styles[] = [
						'href'  => $h[1],
						'owner' => self::owner( $h[1] ),
						// Anything not print or preloaded holds up first paint.
						'blocking' => ! preg_match( '/media=["\']print|rel=["\']preload/i', $tag ),
					];
				}
			}
		}

		$by_owner = [];
		foreach ( array_merge( $scripts, $styles ) as $a ) {
			$by_owner[ $a['owner'] ] = ( $by_owner[ $a['owner'] ] ?? 0 ) + 1;
		}
		arsort( $by_owner );

		return [
			'url'               => $url,
			'ttfb_ms'           => $ttfb,
			'html_kb'           => (int) round( strlen( $html ) / 1024 ),
			'scripts'           => count( $scripts ),
			'scripts_blocking'  => count( array_filter( $scripts, static fn( array $s ): bool => $s['blocking'] ) ),
			'stylesheets'       => count( $styles ),
			'styles_blocking'   => count( array_filter( $styles, static fn( array $s ): bool => $s['blocking'] ) ),
			'inline_style_tags' => preg_match_all( '/<style\b/i', $html ),
			'by_owner'          => $by_owner,
			'blocking_list'     => array_values( array_map(
				static fn( array $a ): string => $a['owner'] . ' — ' . basename( (string) ( $a['src'] ?? $a['href'] ) ),
				array_filter( array_merge( $scripts, $styles ), static fn( array $a ): bool => $a['blocking'] )
			) ),
			'note'              => 'Fetched as a visitor would, so this reflects whatever the caching and optimisation plugins do rather than what was enqueued.',
		];
	}

	/** Which plugin, theme or core directory an asset came out of. */
	private static function owner( string $url ): string {
		if ( preg_match( '#/wp-content/plugins/([^/]+)/#', $url, $m ) ) {
			return 'plugin: ' . $m[1];
		}
		if ( preg_match( '#/wp-content/themes/([^/]+)/#', $url, $m ) ) {
			return 'theme: ' . $m[1];
		}
		if ( preg_match( '#/wp-content/uploads/#', $url ) ) {
			return 'uploads';
		}
		if ( str_contains( $url, '/wp-includes/' ) ) {
			return 'core';
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return $host && $host !== (string) wp_parse_url( home_url(), PHP_URL_HOST ) ? 'external: ' . $host : 'other';
	}

	/* ------------------------------------------------------------- images */

	/**
	 * @return array<string,mixed>
	 */
	public static function image_weight( mixed $input = [] ): array {
		global $wpdb;
		$input = is_array( $input ) ? $input : [];
		$limit = min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, m.meta_value AS meta
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
			  WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%'
			  ORDER BY p.ID DESC
			  LIMIT %d",
			// Read more rows than are returned, since the weight is inside the
			// serialised meta and cannot be sorted on in SQL.
			max( 500, $limit * 20 )
		), ARRAY_A );

		$images = [];
		$total  = 0;
		$oversized = 0;

		foreach ( $rows as $r ) {
			$meta = maybe_unserialize( (string) $r['meta'] );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$bytes = (int) ( $meta['filesize'] ?? 0 );
			$w     = (int) ( $meta['width'] ?? 0 );
			$h     = (int) ( $meta['height'] ?? 0 );

			if ( ! $bytes ) {
				$path  = get_attached_file( (int) $r['ID'] );
				$bytes = $path && file_exists( $path ) ? (int) filesize( $path ) : 0;
			}

			$total += $bytes;
			// Anything wider than a full-bleed desktop image is being scaled
			// down in the browser, which costs bandwidth for nothing.
			if ( $w > 2560 ) {
				$oversized++;
			}

			$images[] = [
				'id'         => (int) $r['ID'],
				'title'      => $r['post_title'],
				'kb'         => (int) round( $bytes / 1024 ),
				'dimensions' => $w && $h ? $w . '×' . $h : null,
				'sizes'      => count( (array) ( $meta['sizes'] ?? [] ) ),
			];
		}

		usort( $images, static fn( array $a, array $b ): int => $b['kb'] <=> $a['kb'] );

		return [
			'examined'       => count( $images ),
			'total_mb'       => round( $total / 1048576, 1 ),
			'average_kb'     => $images ? (int) round( $total / 1024 / count( $images ) ) : 0,
			'wider_than_2560'=> $oversized,
			'heaviest'       => array_slice( $images, 0, $limit ),
			'note'           => 'Weight comes from the attachment metadata, falling back to the file on disk. Only the most recent images are examined, since the size is inside serialised meta and cannot be sorted on in SQL.',
		];
	}

	/* ---------------------------------------------------------- abilities */

	/** @param callable|array $gate */
	public static function register( callable|array $gate ): void {
		$ro = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ];

		wp_register_ability( 'niranzwp/db-report', [
			'label'               => __( 'Database report', 'niranzwp' ),
			'description'         => __( 'What the database is carrying that nothing reads: revisions, auto-drafts, trash, spam, expired transients and orphaned meta. Also the largest tables and the autoloaded option weight.', 'niranzwp' ),
			'category'            => 'niranzwp-performance',
			'input_schema'        => [ 'type' => 'object', 'properties' => (object) [] ],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'db_report' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/db-cleanup', [
			'label'               => __( 'Database cleanup', 'niranzwp' ),
			'description'         => __( 'Removes what db-report found. Previews unless dry_run is false. A checkpoint cannot cover this — snapshotting the rows would cost more than removing them — so read the preview first.', 'niranzwp' ),
			'category'            => 'niranzwp-performance',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'only'    => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Limit to some of: revisions, auto_drafts, trashed_posts, spam_comments, trashed_comments, expired_transients, orphaned_postmeta, orphaned_termmeta.',
					],
					'dry_run' => [ 'type' => 'boolean', 'default' => true ],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'db_cleanup' ],
			'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
		] );

		wp_register_ability( 'niranzwp/asset-audit', [
			'label'               => __( 'Asset audit', 'niranzwp' ),
			'description'         => __( 'Fetches a page as a visitor would and reports what it loads: scripts, stylesheets, which of them block first paint, and which plugin or theme each came from.', 'niranzwp' ),
			'category'            => 'niranzwp-performance',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'url' => [ 'type' => 'string', 'description' => 'A URL on this site. Defaults to the home page.' ] ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'asset_audit' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/image-weight', [
			'label'               => __( 'Image weight', 'niranzwp' ),
			'description'         => __( 'The heaviest images in the library, their dimensions, and how many are wider than any screen will use.', 'niranzwp' ),
			'category'            => 'niranzwp-performance',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'limit' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ] ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'image_weight' ],
			'meta'                => $ro,
		] );
	}
}
