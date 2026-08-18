<?php
/**
 * SEO and GEO abilities.
 *
 * GEO -- Generative Engine Optimization -- is about being citable by AI
 * answer engines rather than only ranking in blue links. Nothing else in the
 * WordPress ability ecosystem covers it, so it is the reason this plugin
 * exists.
 *
 * Every query here is written for a large site. Counting with an indexed
 * meta_key join is cheap; a LIKE across post_content is not, and is avoided.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Seo {

	/** AI crawlers worth knowing about, and who operates them. */
	private const AI_CRAWLERS = [
		'GPTBot'            => 'OpenAI (ChatGPT training)',
		'OAI-SearchBot'     => 'OpenAI (ChatGPT search)',
		'ChatGPT-User'       => 'OpenAI (user-initiated fetch)',
		'ClaudeBot'         => 'Anthropic (Claude training)',
		'Claude-User'       => 'Anthropic (user-initiated fetch)',
		'PerplexityBot'     => 'Perplexity',
		'Google-Extended'   => 'Google (Gemini / AI Overviews)',
		'Applebot-Extended' => 'Apple Intelligence',
		'CCBot'             => 'Common Crawl (feeds many models)',
		'Bytespider'        => 'ByteDance',
		'meta-externalagent' => 'Meta AI',
	];

	public static function register( callable|array $gate ): void {
		register_ability(
			'niranzwp/seo-audit',
			[
				'label'               => __( 'SEO audit', 'niranzwp' ),
				'description'         => __( 'Counts SEO gaps across the whole site: posts missing meta descriptions, SEO titles or focus keywords, images missing alt text, noindex pages, and content bloat. Detects Rank Math, Yoast or SEOPress automatically.', 'niranzwp' ),
				'category'            => 'niranzwp-seo',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_type' => [ 'type' => 'string', 'default' => 'post' ],
					],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'audit' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ],
			]
		);

		register_ability(
			'niranzwp/geo-check',
			[
				'label'               => __( 'GEO check', 'niranzwp' ),
				'description'         => __( 'Checks how reachable this site is by AI answer engines: whether AI crawlers are allowed in robots.txt and whether a sitemap is discoverable. Follows Google guidance, so it does not treat llms.txt as a ranking factor.', 'niranzwp' ),
				'category'            => 'niranzwp-seo',
				'input_schema'        => [ 'type' => 'object', 'properties' => (object) [] ],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'geo_check' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ],
			]
		);
	}

	/** Which SEO plugin owns the meta on this site? */
	private static function seo_plugin(): array {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return [
				'name'        => 'Rank Math',
				'description' => 'rank_math_description',
				'title'       => 'rank_math_title',
				'focus'       => 'rank_math_focus_keyword',
				'robots'      => 'rank_math_robots',
			];
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return [
				'name'        => 'Yoast SEO',
				'description' => '_yoast_wpseo_metadesc',
				'title'       => '_yoast_wpseo_title',
				'focus'       => '_yoast_wpseo_focuskw',
				'robots'      => '_yoast_wpseo_meta-robots-noindex',
			];
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return [
				'name'        => 'SEOPress',
				'description' => '_seopress_titles_desc',
				'title'       => '_seopress_titles_title',
				'focus'       => '_seopress_analysis_target_kw',
				'robots'      => '_seopress_robots_index',
			];
		}
		return [ 'name' => null ];
	}

	/**
	 * Count published items of $post_type that have a non-empty value for
	 * $meta_key. Uses the meta_key index, so it stays cheap on large sites.
	 */
	private static function count_with_meta( string $post_type, string $meta_key ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND m.meta_key = %s AND m.meta_value <> ''",
			$post_type,
			$meta_key
		) );
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
		$seo       = self::seo_plugin();

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$post_type
		) );

		$report = [
			'post_type'       => $post_type,
			'published'       => $total,
			'seo_plugin'      => $seo['name'],
			'issues'          => [],
		];

		if ( null === $seo['name'] ) {
			$report['issues'][] = [
				'key'      => 'no_seo_plugin',
				'severity' => 'warning',
				'count'    => null,
				'message'  => 'No supported SEO plugin detected (Rank Math, Yoast or SEOPress). Meta checks skipped.',
			];
		} else {
			$missing_desc  = $total - self::count_with_meta( $post_type, $seo['description'] );
			$missing_title = $total - self::count_with_meta( $post_type, $seo['title'] );
			$missing_focus = $total - self::count_with_meta( $post_type, $seo['focus'] );

			$report['meta'] = [
				'missing_description'   => $missing_desc,
				'missing_title'         => $missing_title,
				'missing_focus_keyword' => $missing_focus,
			];

			if ( $missing_desc > 0 ) {
				$report['issues'][] = [
					'key'      => 'missing_meta_description',
					'severity' => $missing_desc > $total * 0.1 ? 'high' : 'medium',
					'count'    => $missing_desc,
					'message'  => sprintf( '%d published %s have no meta description. Search and AI engines fall back to guessing a summary.', $missing_desc, $post_type ),
				];
			}
			if ( $missing_title > 0 ) {
				$report['issues'][] = [
					'key'      => 'missing_seo_title',
					'severity' => 'medium',
					'count'    => $missing_title,
					'message'  => sprintf( '%d published %s have no SEO title override.', $missing_title, $post_type ),
				];
			}

			// noindex is stored serialized by Rank Math, as a flag by others.
			$noindex = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				   FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				  WHERE p.post_type = %s AND p.post_status = 'publish'
				    AND m.meta_key = %s AND m.meta_value LIKE %s",
				$post_type,
				$seo['robots'],
				'%noindex%'
			) );
			$report['robots'] = [ 'noindex' => $noindex ];
			if ( $noindex > 0 ) {
				$report['issues'][] = [
					'key'      => 'noindex_published',
					'severity' => 'high',
					'count'    => $noindex,
					'message'  => sprintf( '%d published %s are set to noindex and cannot appear in search or AI answers. Confirm this is deliberate.', $noindex, $post_type ),
				];
			}
		}

		// Media alt text -- an accessibility and AI-citability signal.
		$img_total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		);
		$img_alt = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			  WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
			    AND m.meta_key = '_wp_attachment_image_alt' AND m.meta_value <> ''"
		);
		$missing_alt = $img_total - $img_alt;

		$report['media'] = [
			'images'       => $img_total,
			'missing_alt'  => $missing_alt,
			'coverage_pct' => $img_total > 0 ? (int) round( 100 * $img_alt / $img_total ) : 100,
		];

		if ( $missing_alt > 0 ) {
			$report['issues'][] = [
				'key'      => 'missing_alt_text',
				'severity' => $img_total > 0 && $missing_alt > $img_total * 0.2 ? 'high' : 'medium',
				'count'    => $missing_alt,
				'message'  => sprintf( '%d of %d images have no alt text (%d%% coverage).', $missing_alt, $img_total, $report['media']['coverage_pct'] ),
			];
		}

		// Content hygiene
		$no_thumb = $total - (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND m.meta_key = '_thumbnail_id' AND m.meta_value <> ''",
			$post_type
		) );

		$revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );

		$report['content'] = [
			'missing_featured_image' => $no_thumb,
			'revisions'              => $revisions,
		];

		if ( $revisions > 50000 ) {
			$report['issues'][] = [
				'key'      => 'revision_bloat',
				'severity' => 'low',
				'count'    => $revisions,
				'message'  => sprintf( '%d post revisions are stored. They do not affect SEO directly but bloat the database and slow backups.', $revisions ),
			];
		}

		// Autoload weight, reused from the performance ability.
		$autoload = Abilities::autoload_report( [ 'limit' => 3 ] );
		$report['performance'] = [
			'autoload_kb'   => $autoload['total_kb'],
			'healthy_under' => 800,
		];
		if ( $autoload['total_kb'] > 800 ) {
			$report['issues'][] = [
				'key'      => 'autoload_bloat',
				'severity' => $autoload['total_kb'] > 2000 ? 'high' : 'medium',
				'count'    => $autoload['total_kb'],
				'message'  => sprintf( 'Autoloaded options total %d KB and are read on every request. Largest: %s.', $autoload['total_kb'], $autoload['largest'][0]['option_name'] ?? 'unknown' ),
			];
		}

		// Rank severity high -> low so the caller can act on the top item first.
		$order = [ 'high' => 0, 'medium' => 1, 'warning' => 2, 'low' => 3 ];
		usort(
			$report['issues'],
			static fn( array $a, array $b ): int => ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 )
		);

		$report['issue_count'] = count( $report['issues'] );

		return $report;
	}

	/** @return array<string,mixed> */
	public static function geo_check(): array {
		$home = untrailingslashit( home_url() );

		$robots = '';
		$res    = wp_remote_get( $home . '/robots.txt', [ 'timeout' => 10 ] );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$robots = (string) wp_remote_retrieve_body( $res );
		}

		$crawlers = [];
		foreach ( self::AI_CRAWLERS as $agent => $owner ) {
			$blocked = false;
			// Find the block for this agent and look for a bare "Disallow: /".
			if ( $robots && preg_match( '/User-agent:\s*' . preg_quote( $agent, '/' ) . '\s*(.*?)(?=User-agent:|$)/is', $robots, $m ) ) {
				$blocked = (bool) preg_match( '/Disallow:\s*\/\s*$/im', $m[1] );
			}
			$crawlers[ $agent ] = [ 'owner' => $owner, 'blocked' => $blocked ];
		}

		$blocked_list = array_keys( array_filter( $crawlers, static fn( array $c ): bool => $c['blocked'] ) );

		// llms.txt -- an emerging convention telling models what a site is about.
		$llms      = wp_remote_get( $home . '/llms.txt', [ 'timeout' => 10 ] );
		$has_llms  = ! is_wp_error( $llms ) && 200 === wp_remote_retrieve_response_code( $llms );

		$sitemap_in_robots = (bool) ( $robots && stripos( $robots, 'sitemap:' ) !== false );

		$issues = [];
		if ( $blocked_list ) {
			$issues[] = [
				'key'      => 'ai_crawlers_blocked',
				'severity' => 'high',
				'message'  => 'Blocked from AI answer engines: ' . implode( ', ', $blocked_list ) . '. This site cannot be cited by them.',
			];
		}
		// Deliberately not an issue. Google lists llms.txt among the things
		// that do not help ("Create llms.txt or AI-specific markup files",
		// AI optimization guide, Myths), and that is corroborated by an
		// SE Ranking study across 300k domains and server-log audits showing
		// AI crawlers do not request it. Reported as a fact, not a finding.
		if ( ! $sitemap_in_robots ) {
			$issues[] = [
				'key'      => 'no_sitemap_in_robots',
				'severity' => 'medium',
				'message'  => 'robots.txt does not declare a Sitemap:, so crawlers must guess where it lives.',
			];
		}
		if ( ! $robots ) {
			$issues[] = [
				'key'      => 'no_robots_txt',
				'severity' => 'high',
				'message'  => 'robots.txt could not be fetched.',
			];
		}

		return [
			'site'              => $home,
			'robots_txt'        => (bool) $robots,
			// Informational only -- see the note above.
			'llms_txt'          => $has_llms,
			'llms_txt_note'     => 'Google lists llms.txt among practices that do not affect AI search. Absence is not a problem.',
			'sitemap_in_robots' => $sitemap_in_robots,
			'ai_crawlers'       => $crawlers,
			'blocked_count'     => count( $blocked_list ),
			'issues'            => $issues,
			'issue_count'       => count( $issues ),
		];
	}
}
