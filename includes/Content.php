<?php
/**
 * Content quality abilities.
 *
 * Google's own AI-optimization guidance says the thing that matters is
 * unique, non-commodity, first-hand content -- not markup tricks. These
 * abilities surface the structural problems that stop good content being
 * found: pages too thin to rank, titles competing with each other, and
 * articles no other page links to.
 *
 * Every query is written for a site with tens of thousands of posts:
 * indexed columns, aggregates in SQL, and hard result caps.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Content {

	private const MAX_ROWS = 200;

	/** "1 post is" / "4 posts are" -- counts are read by people, not parsers. */
	private static function plural( int $n, string $noun ): array {
		return 1 === $n
			? [ $noun, 'is', 'contains', 'carries' ]
			: [ $noun . 's', 'are', 'contain', 'carry' ];
	}

	public static function register( callable|array $gate ): void {
		wp_register_ability(
			'niranzwp/content-audit',
			[
				'label'               => __( 'Content audit', 'niranzwp' ),
				'description'         => __( 'Reports content-quality problems across the site: thin posts, duplicate titles, posts with no internal links out, and posts never updated since publication.', 'niranzwp' ),
				'category'            => 'niranzwp-content',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_type'  => [ 'type' => 'string', 'default' => 'post' ],
						'thin_words' => [ 'type' => 'integer', 'default' => 300, 'minimum' => 50, 'maximum' => 3000 ],
					],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'audit' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ],
			]
		);

		wp_register_ability(
			'niranzwp/content-list',
			[
				'label'               => __( 'List content problems', 'niranzwp' ),
				'description'         => __( 'Returns the actual posts behind a content-audit finding, with IDs, titles, URLs and the measured value, so they can be fixed one by one.', 'niranzwp' ),
				'category'            => 'niranzwp-content',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'problem'    => [ 'type' => 'string', 'enum' => [ 'thin', 'duplicate_title', 'no_internal_links', 'stale' ], 'default' => 'thin' ],
						'post_type'  => [ 'type' => 'string', 'default' => 'post' ],
						'thin_words' => [ 'type' => 'integer', 'default' => 300 ],
						'limit'      => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 200 ],
						'offset'     => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0 ],
					],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'listing' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ],
			]
		);

		wp_register_ability(
			'niranzwp/schema-audit',
			[
				'label'               => __( 'Schema audit', 'niranzwp' ),
				'description'         => __( 'Reports which published posts carry structured data and which schema types are in use. Reads Rank Math or Yoast schema meta.', 'niranzwp' ),
				'category'            => 'niranzwp-content',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [ 'post_type' => [ 'type' => 'string', 'default' => 'post' ] ],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'schema_audit' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ],
			]
		);
	}

	/**
	 * Word count is derived in SQL from post_content length rather than by
	 * loading every post into PHP -- on 57k posts the difference is a second
	 * versus a timeout. It is an approximation, and labelled as one.
	 */
	private static function thin_sql( string $post_type, int $words ): array {
		global $wpdb;
		// Roughly 6 bytes per word once markup is discounted.
		$bytes = $words * 6;
		return [
			"p.post_type = %s AND p.post_status = 'publish' AND CHAR_LENGTH(p.post_content) < %d",
			[ $post_type, $bytes ],
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function audit( mixed $input = [] ): array {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		global $wpdb;

		$post_type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );
		$words     = max( 50, min( 3000, (int) ( $input['thin_words'] ?? 300 ) ) );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$post_type
		) );

		[ $where, $args ] = self::thin_sql( $post_type, $words );
		$thin = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL
			...$args
		) );

		$dupes = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM (
			   SELECT post_title FROM {$wpdb->posts}
			    WHERE post_type = %s AND post_status = 'publish' AND post_title <> ''
			    GROUP BY post_title HAVING COUNT(*) > 1
			 ) d",
			$post_type
		) );

		$home = untrailingslashit( home_url() );
		$no_links = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			  WHERE post_type = %s AND post_status = 'publish'
			    AND post_content NOT LIKE %s",
			$post_type,
			'%' . $wpdb->esc_like( 'href="' . $home ) . '%'
		) );

		// Never touched since publication, and old enough to matter.
		$stale = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			  WHERE post_type = %s AND post_status = 'publish'
			    AND post_modified <= DATE_ADD(post_date, INTERVAL 1 DAY)
			    AND post_date < DATE_SUB(NOW(), INTERVAL 2 YEAR)",
			$post_type
		) );

		$issues = [];
		$add    = static function ( string $key, string $sev, int $count, string $msg ) use ( &$issues ): void {
			if ( $count > 0 ) {
				$issues[] = [ 'key' => $key, 'severity' => $sev, 'count' => $count, 'message' => $msg ];
			}
		};

		[ $n1, $v1 ] = self::plural( $thin, $post_type );
		$add(
			'thin_content',
			$total && $thin > $total * 0.2 ? 'high' : 'medium',
			$thin,
			sprintf( '%d published %s %s under roughly %d words. Thin pages rarely rank and are rarely cited by AI answers.', $thin, $n1, $v1, $words )
		);

		$add(
			'duplicate_titles',
			'medium',
			$dupes,
			sprintf(
				1 === $dupes
					? '%d title is used by more than one published %s. They compete with each other in search.'
					: '%d titles are used by more than one published %s each. They compete with each other in search.',
				$dupes,
				$post_type
			)
		);

		[ $n3, , $v3 ] = self::plural( $no_links, $post_type );
		$add(
			'no_internal_links',
			$total && $no_links > $total * 0.5 ? 'high' : 'medium',
			$no_links,
			sprintf( '%d published %s %s no link to another page on this site. Internal links are how crawlers find and rank the rest of the site.', $no_links, $n3, $v3 )
		);

		[ $n4, $v4 ] = self::plural( $stale, $post_type );
		$add(
			'stale_content',
			'low',
			$stale,
			sprintf( '%d published %s %s over two years old and %s never been edited since publication.', $stale, $n4, $v4, 1 === $stale ? 'has' : 'have' )
		);

		$order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
		usort( $issues, static fn( array $a, array $b ): int => ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 ) );

		return [
			'post_type'        => $post_type,
			'published'        => $total,
			'thin_threshold'   => $words,
			'counts'           => [
				'thin'              => $thin,
				'duplicate_titles'  => $dupes,
				'no_internal_links' => $no_links,
				'stale'             => $stale,
			],
			'issues'           => $issues,
			'issue_count'      => count( $issues ),
			'word_count_note'  => 'Word counts are approximated from content length in SQL so the audit stays fast on large sites.',
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function listing( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		global $wpdb;

		$problem   = (string) ( $input['problem'] ?? 'thin' );
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );
		$limit     = min( self::MAX_ROWS, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
		$offset    = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$words     = max( 50, (int) ( $input['thin_words'] ?? 300 ) );
		$home      = untrailingslashit( home_url() );

		switch ( $problem ) {
			case 'thin':
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ID, post_title, post_date, CHAR_LENGTH(post_content) AS len
					   FROM {$wpdb->posts}
					  WHERE post_type = %s AND post_status = 'publish'
					    AND CHAR_LENGTH(post_content) < %d
					  ORDER BY len ASC LIMIT %d OFFSET %d",
					$post_type, $words * 6, $limit, $offset
				), ARRAY_A );
				break;

			case 'duplicate_title':
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_date, 0 AS len
					   FROM {$wpdb->posts} p
					   JOIN ( SELECT post_title FROM {$wpdb->posts}
					           WHERE post_type = %s AND post_status = 'publish' AND post_title <> ''
					           GROUP BY post_title HAVING COUNT(*) > 1 ) d
					     ON d.post_title = p.post_title
					  WHERE p.post_type = %s AND p.post_status = 'publish'
					  ORDER BY p.post_title, p.ID LIMIT %d OFFSET %d",
					$post_type, $post_type, $limit, $offset
				), ARRAY_A );
				break;

			case 'no_internal_links':
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ID, post_title, post_date, CHAR_LENGTH(post_content) AS len
					   FROM {$wpdb->posts}
					  WHERE post_type = %s AND post_status = 'publish'
					    AND post_content NOT LIKE %s
					  ORDER BY post_date DESC LIMIT %d OFFSET %d",
					$post_type, '%' . $wpdb->esc_like( 'href="' . $home ) . '%', $limit, $offset
				), ARRAY_A );
				break;

			case 'stale':
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ID, post_title, post_date, CHAR_LENGTH(post_content) AS len
					   FROM {$wpdb->posts}
					  WHERE post_type = %s AND post_status = 'publish'
					    AND post_modified <= DATE_ADD(post_date, INTERVAL 1 DAY)
					    AND post_date < DATE_SUB(NOW(), INTERVAL 2 YEAR)
					  ORDER BY post_date ASC LIMIT %d OFFSET %d",
					$post_type, $limit, $offset
				), ARRAY_A );
				break;

			default:
				return new \WP_Error( 'niranzwp_unknown_problem', 'Unknown problem type: ' . $problem );
		}

		return [
			'problem' => $problem,
			'count'   => count( (array) $rows ),
			'offset'  => $offset,
			'items'   => array_map(
				static fn( array $r ): array => [
					'id'          => (int) $r['ID'],
					'title'       => $r['post_title'],
					'date'        => substr( (string) $r['post_date'], 0, 10 ),
					'approx_words'=> (int) round( ( (int) $r['len'] ) / 6 ),
					'url'         => get_permalink( (int) $r['ID'] ),
				],
				(array) $rows
			),
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function schema_audit( mixed $input = [] ): array {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		global $wpdb;

		$post_type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$post_type
		) );

		// Rank Math stores one row per schema block, keyed rank_math_schema_*.
		$with_schema = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND ( m.meta_key LIKE %s OR m.meta_key = %s )
			    AND m.meta_value <> ''",
			$post_type,
			$wpdb->esc_like( 'rank_math_schema_' ) . '%',
			'_yoast_wpseo_schema_article_type'
		) );

		$missing = $total - $with_schema;
		$issues  = [];

		if ( $missing > 0 ) {
			$issues[] = [
				'key'      => 'missing_schema',
				'severity' => $total && $missing > $total * 0.5 ? 'high' : 'medium',
				'count'    => $missing,
				'message'  => sprintf(
					'%d published %s %s no structured data. Schema is how search and AI engines identify what a page is.',
					$missing,
					1 === $missing ? $post_type : $post_type . 's',
					1 === $missing ? 'carries' : 'carry'
				),
			];
		}

		return [
			'post_type'   => $post_type,
			'published'   => $total,
			'with_schema' => $with_schema,
			'missing'     => $missing,
			'coverage_pct'=> $total ? (int) round( 100 * $with_schema / $total ) : 100,
			'issues'      => $issues,
			'issue_count' => count( $issues ),
			'note'        => 'Google advises against over-investing in structured data specifically for AI features; this is reported as ordinary SEO hygiene.',
		];
	}
}
