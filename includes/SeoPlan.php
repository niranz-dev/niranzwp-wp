<?php
/**
 * What to do next, and why.
 *
 * The audits already say what is wrong. On a site with a few thousand posts
 * that produces several numbers in the tens of thousands and no way to choose
 * between them, so somebody has to sit down and join them up by hand. These
 * abilities do that joining.
 *
 * They report and rank. They do not publish, rewrite or link anything. On a
 * magazine every one of these calls is an editorial judgement, and a tool that
 * makes those quietly is worse than one that makes none.
 *
 * The scoring is a heuristic and is labelled as one. It is a way of putting a
 * list in a sensible order, not a measurement.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class SeoPlan {

	/* ---------------------------------------------------------- priorities */

	/**
	 * Everything the audits found, in the order worth doing it.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function priorities( mixed $input = [] ): array {
		$input     = is_array( $input ) ? $input : [];
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );

		$content = Content::audit( [ 'post_type' => $post_type ] );
		$total   = max( 1, (int) ( $content['published'] ?? 1 ) );

		$found = [];
		foreach ( (array) ( $content['issues'] ?? [] ) as $issue ) {
			$found[ (string) $issue['key'] ] = (int) $issue['count'];
		}

		$missing = static function ( string $field ) use ( $post_type ): ?int {
			$r = SeoFix::list_missing( [ 'field' => $field, 'post_type' => $post_type, 'limit' => 1 ] );
			return is_wp_error( $r ) ? null : (int) ( $r['total'] ?? 0 );
		};

		$alt  = $missing( 'alt' );
		$desc = $missing( 'description' );

		/*
		 * Each entry carries the count, an effort estimate, and the reason it
		 * is worth doing. Effort is what actually separates these: fixing
		 * forty thousand alt attributes and fixing four duplicate titles are
		 * not the same size of job even when both are "wrong".
		 */
		$items = [];

		$add = static function ( string $key, ?int $count, string $effort, string $impact, string $why, string $how ) use ( &$items, $total ): void {
			if ( null === $count || $count < 1 ) {
				return;
			}
			$items[] = [
				'key'       => $key,
				'count'     => $count,
				'share'     => round( min( 1, $count / $total ) * 100 ) . '%',
				'effort'    => $effort,
				'impact'    => $impact,
				'why'       => $why,
				'how'       => $how,
				'score'     => self::score( $count, $total, $effort, $impact ),
			];
		};

		$add(
			'missing_meta_description', $desc, 'batch', 'high',
			'Google writes its own snippet when there is no description, and it is usually the first sentence of the page rather than a reason to click.',
			'niranzwp/seo-list-missing then seo-set-meta, in batches of 200.'
		);
		$add(
			'no_internal_links', $found['no_internal_links'] ?? null, 'judgement', 'high',
			'A post nothing links to is reachable only from an archive page. Internal links are also how a site says which of its own pages matter.',
			'niranzwp/internal-link-suggest, then edit the posts by hand.'
		);
		$add(
			'thin_content', $found['thin_content'] ?? null, 'writing', 'medium',
			'Short posts rarely answer the query that brought someone to them. Thin is only a problem when the subject deserved more.',
			'niranzwp/content-list problem=thin, then decide per post: expand, merge or remove.'
		);
		$add(
			'stale_content', $found['stale_content'] ?? null, 'writing', 'medium',
			'Old posts that still rank are the cheapest wins on any site: the ranking already exists, the content just stopped being true.',
			'niranzwp/content-refresh.'
		);
		$add(
			'duplicate_titles', $found['duplicate_titles'] ?? null, 'quick', 'medium',
			'Two pages with one title compete with each other for the same query and split whatever authority either had.',
			'niranzwp/content-list problem=duplicate_title.'
		);
		$add(
			'missing_alt_text', $alt, 'batch', 'low',
			'Alt text is an accessibility obligation first. The image-search benefit is real but secondary, and it is not worth writing keyword-stuffed alt for.',
			'niranzwp/seo-list-missing field=alt then media-set-alt, in batches of 200.'
		);

		usort( $items, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return [
			'post_type'   => $post_type,
			'published'   => $total,
			'items'       => $items,
			'top'         => $items ? $items[0]['key'] : null,
			'scoring'     => 'count, share of the site, effort and impact. A heuristic for ordering a list, not a measurement.',
			'not_covered' => 'Rankings, traffic and competition are not visible from inside WordPress. Where Rank Math is active, rank-math/get-top-keywords adds real Search Console data to this picture.',
		];
	}

	private static function score( int $count, int $total, string $effort, string $impact ): int {
		$impact_weight = [ 'high' => 3.0, 'medium' => 2.0, 'low' => 1.0 ][ $impact ] ?? 1.0;
		// A batch job on forty thousand rows is one decision; a judgement call
		// on forty thousand rows is forty thousand decisions.
		$effort_weight = [ 'batch' => 1.0, 'quick' => 0.9, 'judgement' => 0.5, 'writing' => 0.4 ][ $effort ] ?? 0.5;

		return (int) round( min( 1, $count / max( 1, $total ) ) * 100 * $impact_weight * $effort_weight );
	}

	/* -------------------------------------------------------- linking */

	/**
	 * Which posts should link to which.
	 *
	 * Candidates share a category or tag with the source, and the source text
	 * already contains a phrase from the candidate's title -- so the link has
	 * somewhere natural to go and does not need a sentence invented for it.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function link_suggest( mixed $input = [] ): array {
		global $wpdb;
		$input = is_array( $input ) ? $input : [];

		$limit     = min( 50, max( 1, (int) ( $input['limit'] ?? 10 ) ) );
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );
		$only      = (int) ( $input['id'] ?? 0 );
		$home      = untrailingslashit( home_url() );

		$sources = $only
			? array_filter( [ get_post( $only ) ] )
			: get_posts( [
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				// Posts with nothing pointing out of them are where a link is
				// most obviously missing.
				's'              => '',
			] );

		$suggestions = [];

		foreach ( $sources as $source ) {
			if ( ! $source instanceof \WP_Post ) {
				continue;
			}
			if ( ! $only && str_contains( $source->post_content, 'href="' . $home ) ) {
				continue; // already links somewhere internal
			}

			$terms = wp_get_post_terms( $source->ID, [ 'category', 'post_tag' ], [ 'fields' => 'ids' ] );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}

			$candidates = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 40,
				'post__not_in'   => [ $source->ID ],
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					[ 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $terms, 'operator' => 'IN' ],
					[ 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $terms, 'operator' => 'IN' ],
				],
			] );

			$haystack = strtolower( wp_strip_all_tags( $source->post_content ) );
			$matches  = [];

			foreach ( $candidates as $c ) {
				$anchor = self::anchor_in( $haystack, $c->post_title );
				if ( null === $anchor ) {
					continue;
				}
				$matches[] = [
					'target_id'    => $c->ID,
					'target_title' => $c->post_title,
					'target_url'   => get_permalink( $c->ID ),
					'anchor'       => $anchor,
					'why'          => 'Shares a category or tag, and this phrase already appears in the text.',
				];
				if ( count( $matches ) >= 3 ) {
					break;
				}
			}

			if ( $matches ) {
				$suggestions[] = [
					'source_id'    => $source->ID,
					'source_title' => $source->post_title,
					'source_url'   => get_permalink( $source->ID ),
					'links'        => $matches,
				];
			}
		}

		return [
			'examined'    => count( $sources ),
			'suggestions' => $suggestions,
			'note'        => 'Suggestions only. Nothing was edited. A link is worth adding when the sentence it sits in was going to be written anyway; if the anchor reads as bolted on, skip it.',
		];
	}

	/**
	 * The longest phrase from the title that appears verbatim in the text.
	 * Anything shorter than three words is too generic to be a good anchor.
	 */
	private static function anchor_in( string $haystack, string $title ): ?string {
		$words = preg_split( '/\s+/', strtolower( wp_strip_all_tags( $title ) ) ) ?: [];
		$words = array_values( array_filter( $words, static fn( string $w ): bool => strlen( $w ) > 2 ) );

		for ( $len = min( 6, count( $words ) ); $len >= 3; $len-- ) {
			for ( $i = 0; $i + $len <= count( $words ); $i++ ) {
				$phrase = implode( ' ', array_slice( $words, $i, $len ) );
				if ( str_contains( $haystack, $phrase ) ) {
					return $phrase;
				}
			}
		}
		return null;
	}

	/* --------------------------------------------------------- refresh */

	/**
	 * Old posts worth updating rather than replacing.
	 *
	 * Cheap to identify from inside WordPress: published long ago, never
	 * meaningfully edited since, still substantial, and linked to from
	 * elsewhere on the site -- which is the closest thing here to evidence
	 * that it mattered.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function refresh_list( mixed $input = [] ): array {
		global $wpdb;
		$input = is_array( $input ) ? $input : [];

		$limit     = min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );
		$years     = max( 1, (int) ( $input['older_than_years'] ?? 2 ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_date, post_modified, CHAR_LENGTH(post_content) AS len
			   FROM {$wpdb->posts}
			  WHERE post_type = %s AND post_status = 'publish'
			    AND post_modified <= DATE_ADD(post_date, INTERVAL 30 DAY)
			    AND post_date < DATE_SUB(NOW(), INTERVAL %d YEAR)
			    AND CHAR_LENGTH(post_content) > 1800
			  ORDER BY post_date ASC
			  LIMIT %d",
			$post_type,
			$years,
			$limit
		), ARRAY_A );

		$home  = untrailingslashit( home_url() );
		$items = [];

		foreach ( (array) $rows as $r ) {
			$url = get_permalink( (int) $r['ID'] );

			// How many other posts point at it. A rough stand-in for whether
			// the site itself treats it as important.
			$inbound = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				  WHERE post_type = %s AND post_status = 'publish' AND ID <> %d
				    AND post_content LIKE %s",
				$post_type,
				(int) $r['ID'],
				'%' . $wpdb->esc_like( (string) $url ) . '%'
			) );

			$items[] = [
				'id'           => (int) $r['ID'],
				'title'        => $r['post_title'],
				'url'          => $url,
				'published'    => substr( (string) $r['post_date'], 0, 10 ),
				'age_years'    => (int) floor( ( time() - strtotime( (string) $r['post_date'] ) ) / YEAR_IN_SECONDS ),
				'approx_words' => (int) round( ( (int) $r['len'] ) / 6 ),
				'inbound_links'=> $inbound,
				'why'          => $inbound > 0
					? sprintf( 'Substantial, never revised, and %d other post(s) link to it.', $inbound )
					: 'Substantial and never revised since publication.',
			];
		}

		usort( $items, static fn( array $a, array $b ): int => $b['inbound_links'] <=> $a['inbound_links'] );

		return [
			'older_than_years' => $years,
			'count'            => count( $items ),
			'items'            => $items,
			'note'             => 'Ordered by how many other posts link to each one, since that is the only evidence of importance available from inside WordPress. Where Rank Math is active, rank-math/get-top-keywords will say which of these still earn clicks, which is the better signal.',
		];
	}

	/* -------------------------------------------------------- abilities */

	/** @param callable|array $gate */
	public static function register( callable|array $gate ): void {
		$ro = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ];

		wp_register_ability( 'niranzwp/seo-priorities', [
			'label'               => __( 'SEO priorities', 'niranzwp' ),
			'description'         => __( 'Joins every audit into one list in the order worth working through, with the reason and the effort for each. Start here rather than with an individual audit.', 'niranzwp' ),
			'category'            => 'niranzwp-seo',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'post_type' => [ 'type' => 'string', 'default' => 'post' ] ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'priorities' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/internal-link-suggest', [
			'label'               => __( 'Suggest internal links', 'niranzwp' ),
			'description'         => __( 'For posts that link nowhere internally, finds related posts whose title already appears as a phrase in the text, so the link has somewhere natural to go. Suggests only; edits nothing.', 'niranzwp' ),
			'category'            => 'niranzwp-seo',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'        => [ 'type' => 'integer', 'description' => 'One post. Omit to scan recent posts with no internal links.' ],
					'post_type' => [ 'type' => 'string', 'default' => 'post' ],
					'limit'     => [ 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50 ],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'link_suggest' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/content-refresh', [
			'label'               => __( 'Content worth refreshing', 'niranzwp' ),
			'description'         => __( 'Old, substantial posts that were never revised, ordered by how many other posts link to them. Updating one of these is usually cheaper than writing something new.', 'niranzwp' ),
			'category'            => 'niranzwp-seo',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_type'        => [ 'type' => 'string', 'default' => 'post' ],
					'older_than_years' => [ 'type' => 'integer', 'default' => 2, 'minimum' => 1, 'maximum' => 20 ],
					'limit'            => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'refresh_list' ],
			'meta'                => $ro,
		] );
	}
}
