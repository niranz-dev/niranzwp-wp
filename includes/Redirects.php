<?php
/**
 * Redirects, as something that can be asked about.
 *
 * Rank Math keeps them in its own table with the source patterns serialised
 * inside a longtext column, which means the only way to answer "what points
 * at this page" has been to write SQL by hand. That gap is not academic: a
 * prune list was built here by joining Search Console to the site on slug,
 * and every post whose slug had ever been edited looked as though Google had
 * never seen it. 301 posts that were earning clicks were noindexed, and the
 * old URLs went on redirecting Google straight into the noindex.
 *
 * The answer was in this table the whole time. Nobody could get at it.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Redirects {

	private static function table(): ?string {
		global $wpdb;
		$t = $wpdb->prefix . 'rank_math_redirections';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ? $t : null;
	}

	/**
	 * The source patterns on one row.
	 *
	 * They arrive as a serialised array of ['pattern', 'comparison', 'ignore'].
	 * unserialize() runs with allowed_classes false: this column is written by
	 * another plugin, and a serialised object from a table is exactly the
	 * shape an object-injection bug takes.
	 *
	 * @return array<int,array{pattern:string,comparison:string}>
	 */
	private static function sources( string $blob ): array {
		$raw = @unserialize( $blob, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $s ) {
			if ( ! is_array( $s ) || ! isset( $s['pattern'] ) ) {
				continue;
			}
			$out[] = [
				'pattern'    => (string) $s['pattern'],
				'comparison' => (string) ( $s['comparison'] ?? 'exact' ),
			];
		}
		return $out;
	}

	/** The last path segment, which is how a WordPress URL names a post. */
	private static function slug( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = '' !== $path ? $path : $url;
		$bits = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		return $bits ? (string) end( $bits ) : '';
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function list_all( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$table = self::table();
		if ( ! $table ) {
			return new \WP_Error( 'niranzwp_no_redirects', 'Rank Math is not managing redirects on this site.', [ 'status' => 404 ] );
		}

		global $wpdb;
		$limit  = max( 1, min( 500, (int) ( $input['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$status = (string) ( $input['status'] ?? 'active' );
		$unused = ! empty( $input['never_used'] );

		$where = $wpdb->prepare( 'status = %s', $status );
		if ( $unused ) {
			$where .= ' AND hits = 0';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table` WHERE $where" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `$table` WHERE $where ORDER BY hits DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
		// phpcs:enable

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'id'            => (int) $r['id'],
				'from'          => array_column( self::sources( (string) $r['sources'] ), 'pattern' ),
				'to'            => (string) $r['url_to'],
				'code'          => (int) $r['header_code'],
				'hits'          => (int) $r['hits'],
				'last_accessed' => $r['last_accessed'] && '0000-00-00 00:00:00' !== $r['last_accessed'] ? $r['last_accessed'] : null,
				'created'       => $r['created'],
			];
		}

		return [
			'total'     => $total,
			'returned'  => count( $out ),
			'offset'    => $offset,
			'status'    => $status,
			'redirects' => $out,
			'note'      => 'Ordered by hits, busiest first. hits and last_accessed are how you tell a redirect that is carrying traffic from one that is dead weight.',
		];
	}

	/**
	 * What redirects at this page, and what this page redirects to.
	 *
	 * The first direction is the one that matters and the one nothing could
	 * answer before: a post can be sitting at the end of a redirect chain that
	 * carries all of its search history, under a URL that no longer appears
	 * anywhere on the site.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function find( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$table = self::table();
		if ( ! $table ) {
			return new \WP_Error( 'niranzwp_no_redirects', 'Rank Math is not managing redirects on this site.', [ 'status' => 404 ] );
		}

		$post_id = (int) ( $input['post_id'] ?? 0 );
		$url     = trim( (string) ( $input['url'] ?? '' ) );

		if ( $post_id ) {
			$permalink = get_permalink( $post_id );
			if ( ! $permalink ) {
				return new \WP_Error( 'niranzwp_no_post', 'No post with id ' . $post_id, [ 'status' => 404 ] );
			}
			$url = (string) $permalink;
		}
		if ( '' === $url ) {
			return new \WP_Error( 'niranzwp_no_target', 'Give a post_id or a url.', [ 'status' => 400 ] );
		}

		$slug = self::slug( $url );

		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT * FROM `$table` WHERE status = 'active'", ARRAY_A );
		// phpcs:enable

		$into = [];
		$from = [];
		foreach ( (array) $rows as $r ) {
			$dest    = (string) $r['url_to'];
			$sources = self::sources( (string) $r['sources'] );
			$row     = [
				'id'            => (int) $r['id'],
				'from'          => array_column( $sources, 'pattern' ),
				'to'            => $dest,
				'code'          => (int) $r['header_code'],
				'hits'          => (int) $r['hits'],
				'last_accessed' => $r['last_accessed'] && '0000-00-00 00:00:00' !== $r['last_accessed'] ? $r['last_accessed'] : null,
			];

			if ( self::slug( $dest ) === $slug && '' !== $slug ) {
				$into[] = $row;
			}
			foreach ( $sources as $s ) {
				if ( self::slug( $s['pattern'] ) === $slug && '' !== $slug ) {
					$from[] = $row;
					break;
				}
			}
		}

		return [
			'url'          => $url,
			'post_id'      => $post_id ?: null,
			'slug'         => $slug,
			'redirects_in' => $into,
			'redirects_out' => $from,
			'note'         => $into
				? 'Something redirects here. Whatever search history exists is likely to sit under those older URLs, not this one - so judging this page by what its current URL has earned will understate it.'
				: 'Nothing redirects here.',
		];
	}

	public static function register(): void {
		if ( ! function_exists( __NAMESPACE__ . '\\register_ability' ) ) {
			return;
		}
		$gate = static fn(): bool => Settings::active() && current_user_can( CAPABILITY );

		/*
		 * show_in_rest is what actually decides whether a client can see this.
		 * Registering without it produced two abilities that existed in the
		 * registry, passed their permission check, and were invisible to every
		 * client - which reads exactly like an ability that was never written.
		 * The flags belong under annotations; loose keys beside them are
		 * ignored rather than rejected.
		 */
		$ro = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ];

		register_ability( 'niranzwp/redirect-list', [
			'label'               => __( 'List redirects', 'niranzwp' ),
			'description'         => __( 'Lists the redirects Rank Math is serving, busiest first, with how many times each has been used and when it was last used. Pass never_used true for the ones that have never fired - those are the ones worth removing.', 'niranzwp' ),
			'category'            => 'niranzwp-seo',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'limit'      => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500 ],
					'offset'     => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0 ],
					'status'     => [ 'type' => 'string', 'default' => 'active', 'enum' => [ 'active', 'inactive', 'trashed' ] ],
					'never_used' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Only redirects with zero hits.' ],
				],
				'additionalProperties' => false,
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'list_all' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/redirect-find', [
			'label'               => __( 'Find redirects for a page', 'niranzwp' ),
			'description'         => __( 'What redirects to this page, and what it redirects to. Ask this before judging a page by its Search Console figures: a post whose URL was ever edited carries its history under the old URL, and looking only at the current one makes a page that earns look like a page that never has.', 'niranzwp' ),
			'category'            => 'niranzwp-seo',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [ 'type' => 'integer' ],
					'url'     => [ 'type' => 'string' ],
				],
				'additionalProperties' => false,
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'find' ],
			'meta'                => $ro,
		] );
	}
}
