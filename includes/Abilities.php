<?php
/**
 * Ability registration.
 *
 * Deliberately narrow to start with. There is no execute-php ability here: a
 * general code-execution endpoint is a full site takeover for anyone holding
 * the credential, and it should be a considered decision, not a default.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Abilities {

	public static function init(): void {
		// Categories and abilities register on two different hooks. Categories
		// must exist first, so core fires theirs earlier.
		add_action( 'wp_abilities_api_categories_init', [ self::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ self::class, 'register' ] );
	}

	/**
	 * One category per group, so the Abilities Hub can be read at a glance and
	 * a whole area can be switched off in one action. A single flat category
	 * turned the screen into an undifferentiated list of forty rows.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function categories(): array {
		return [
			'niranzwp-site'        => [ __( 'Site', 'niranzwp' ), __( 'Version, plugins, autoload weight, caches.', 'niranzwp' ) ],
			'niranzwp-seo'         => [ __( 'SEO and GEO', 'niranzwp' ), __( 'Audits, missing fields, meta and alt text, AI crawler access.', 'niranzwp' ) ],
			'niranzwp-content'     => [ __( 'Content', 'niranzwp' ), __( 'Thin, duplicate, orphaned and stale content; schema coverage.', 'niranzwp' ) ],
			'niranzwp-gutenberg'   => [ __( 'Gutenberg', 'niranzwp' ), __( 'Block types and reading or writing a post as a block tree.', 'niranzwp' ) ],
			'niranzwp-elementor'   => [ __( 'Elementor', 'niranzwp' ), __( 'Reading, searching and updating Elementor layouts.', 'niranzwp' ) ],
			'niranzwp-design'      => [ __( 'Design', 'niranzwp' ), __( 'The palette and rules this site works to, and a check against them.', 'niranzwp' ) ],
			'niranzwp-performance' => [ __( 'Performance', 'niranzwp' ), __( 'Database weight, what pages load, and image size.', 'niranzwp' ) ],
			'niranzwp-skills'      => [ __( 'Context and skills', 'niranzwp' ), __( 'The standing brief for this site and the per-job instructions.', 'niranzwp' ) ],
			'niranzwp-checkpoints' => [ __( 'Checkpoints', 'niranzwp' ), __( 'Snapshots taken before destructive writes, and restoring them.', 'niranzwp' ) ],
			'niranzwp-filesystem'  => [ __( 'Filesystem', 'niranzwp' ), __( 'Reading and writing files inside the WordPress install.', 'niranzwp' ) ],
			'niranzwp-runtime'     => [ __( 'Code execution', 'niranzwp' ), __( 'PHP evaluation and WP-CLI. Full control of the site.', 'niranzwp' ) ],
		];
	}

	public static function register_category(): void {
		foreach ( self::categories() as $slug => [ $label, $description ] ) {
			wp_register_ability_category( $slug, [ 'label' => $label, 'description' => $description ] );
		}
	}

	/** Every ability shares this gate. */
	public static function permission(): bool {
		return Settings::active() && current_user_can( CAPABILITY );
	}

	public static function register(): void {
		$gate = [ self::class, 'permission' ];

		Seo::register( $gate );
		SeoFix::register( $gate );
		Content::register( $gate );
		Blocks::register( $gate );
		Elementor::register( $gate );
		Checkpoint::register( $gate );
		Context::register( $gate );
		Skills::register( $gate );
		Design::register( $gate );
		Performance::register( $gate );
		SeoPlan::register( $gate );
		Files::register();
		Upload::register();
		Runtime::register();
		Cli::register();

		register_ability(
			'niranzwp/site-info',
			[
				'label'               => __( 'Site information', 'niranzwp' ),
				'description'         => __( 'Returns the site name, URL, WordPress and PHP versions, active theme and locale.', 'niranzwp' ),
				'category'            => 'niranzwp-site',
				'input_schema'        => [ 'type' => 'object', 'properties' => (object) [] ],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'site_info' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ],
			]
		);

		register_ability(
			'niranzwp/list-plugins',
			[
				'label'               => __( 'List plugins', 'niranzwp' ),
				'description'         => __( 'Lists installed plugins with version and active state. Optionally only the active ones.', 'niranzwp' ),
				'category'            => 'niranzwp-site',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'active_only' => [ 'type' => 'boolean', 'default' => false ],
					],
				],
				'output_schema'       => [ 'type' => 'array' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'list_plugins' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ],
			]
		);

		register_ability(
			'niranzwp/autoload-report',
			[
				'label'               => __( 'Autoload report', 'niranzwp' ),
				'description'         => __( 'Reports total autoloaded option size and the largest entries. Autoloaded options are read on every request, so bloat here slows the whole site.', 'niranzwp' ),
				'category'            => 'niranzwp-site',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'limit' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
					],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'autoload_report' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ],
			]
		);

		register_ability(
			'niranzwp/purge-cache',
			[
				'label'               => __( 'Purge caches', 'niranzwp' ),
				'description'         => __( 'Purges LiteSpeed, W3 Total Cache and the WP object cache. Give post_ids or urls to purge only those; scope "all" empties everything, which on a large site means rebuilding every page from the database at once. Does not reach an external CDN such as Cloudflare.', 'niranzwp' ),
				'category'            => 'niranzwp-site',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_ids' => [
							'type'        => 'array',
							'description' => 'Purge the cached page for each of these posts, and nothing else.',
							'items'       => [ 'type' => 'integer' ],
						],
						'urls'     => [
							'type'        => 'array',
							'description' => 'Purge these URLs, and nothing else. They must belong to this site.',
							'items'       => [ 'type' => 'string' ],
						],
						'scope'    => [
							'type'        => 'string',
							'enum'        => [ 'all' ],
							'description' => 'Set to "all" to empty every cache. Required when no post_ids or urls are given, so a full purge is always a decision rather than a default.',
						],
					],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'permission_callback' => $gate,
				'execute_callback'    => [ self::class, 'purge_cache' ],
				'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => false ] ],
			]
		);
	}

	/** @return array<string,mixed> */
	public static function site_info(): array {
		$theme = wp_get_theme();
		return [
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'locale'      => get_locale(),
			'theme'       => [
				'name'     => $theme->get( 'Name' ),
				'version'  => $theme->get( 'Version' ),
				'template' => $theme->get_template(),
			],
			'environment' => wp_get_environment_type(),
			'multisite'   => is_multisite(),
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_plugins( mixed $input = [] ): array {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_only = (bool) ( $input['active_only'] ?? false );
		$out         = [];

		foreach ( get_plugins() as $file => $data ) {
			$is_active = is_plugin_active( $file );
			if ( $active_only && ! $is_active ) {
				continue;
			}
			$out[] = [
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => $is_active,
			];
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function autoload_report( mixed $input = [] ): array {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		global $wpdb;

		$limit = min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) );

		// WordPress 6.6 widened the autoload column beyond yes/no.
		$autoloaded = "autoload IN ('yes','on','auto','auto-on')";

		$total = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE {$autoloaded}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$autoloaded}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$largest = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, LENGTH(option_value) AS bytes
			   FROM {$wpdb->options}
			  WHERE {$autoloaded}
			  ORDER BY LENGTH(option_value) DESC
			  LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$limit
		), ARRAY_A );

		return [
			'total_bytes'   => $total,
			'total_kb'      => (int) round( $total / 1024 ),
			'option_count'  => $count,
			// Roughly the point where autoload starts showing up in response times.
			'healthy_under' => '800 KB',
			'largest'       => array_map(
				static fn( array $r ): array => [
					'option_name' => $r['option_name'],
					'kb'          => (int) round( ( (int) $r['bytes'] ) / 1024 ),
				],
				$largest ?: []
			),
		];
	}

	/** @return array<string,mixed> */
	/**
	 * Purge caches, by default only what was named.
	 *
	 * A full purge is the expensive option, not the safe one. On a site with
	 * tens of thousands of posts and a database in the gigabytes, emptying
	 * every page means rebuilding all of them from scratch while traffic keeps
	 * arriving; doing it repeatedly during a deploy is how an origin ends up
	 * answering 504. The ability used to offer nothing else, which made the
	 * dangerous call the only call.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function purge_cache( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];

		$post_ids = array_values( array_filter( array_map( 'intval', (array) ( $input['post_ids'] ?? [] ) ) ) );
		$urls     = array_values( array_filter( array_map( 'strval', (array) ( $input['urls'] ?? [] ) ) ) );
		$scope    = (string) ( $input['scope'] ?? '' );

		if ( ! $post_ids && ! $urls && 'all' !== $scope ) {
			return new \WP_Error(
				'niranzwp_no_target',
				'Give post_ids or urls to purge those, or scope "all" to empty every cache. A full purge rebuilds every page and is never assumed.',
				[ 'status' => 400 ]
			);
		}

		if ( ( $post_ids || $urls ) && 'all' === $scope ) {
			return new \WP_Error(
				'niranzwp_conflicting_scope',
				'scope "all" purges everything, so post_ids and urls have no meaning alongside it. Send one or the other.',
				[ 'status' => 400 ]
			);
		}

		if ( 'all' === $scope ) {
			return self::purge_everything();
		}

		$home    = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$done    = [ 'posts' => [], 'urls' => [] ];
		$skipped = [];

		foreach ( $post_ids as $id ) {
			if ( ! get_post( $id ) ) {
				$skipped[] = 'post ' . $id . ' does not exist';
				continue;
			}
			// LiteSpeed listens for this whether or not its classes are
			// loadable from here, and other caches hook the same action.
			do_action( 'litespeed_purge_post', $id );
			clean_post_cache( $id );
			$done['posts'][] = $id;
		}

		foreach ( $urls as $url ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( '' !== $host && untrailingslashit( $host ) !== $home ) {
				$skipped[] = $url . ' belongs to another host';
				continue;
			}
			do_action( 'litespeed_purge_url', $url );
			$done['urls'][] = $url;
		}

		$out = [
			'scope'   => 'targeted',
			'purged'  => $done,
			'counts'  => [ 'posts' => count( $done['posts'] ), 'urls' => count( $done['urls'] ) ],
			'note'    => 'Only the listed entries were purged. External CDN caches such as Cloudflare are not affected.',
		];

		if ( $skipped ) {
			$out['skipped'] = $skipped;
		}

		return $out;
	}

	/** @return array<string,mixed> */
	private static function purge_everything(): array {
		$purged = [];

		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			\LiteSpeed\Purge::purge_all( 'niranzwp' );
			$purged[] = 'litespeed';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$purged[] = 'w3-total-cache';
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
			$purged[] = 'object-cache';
		}

		return [
			'scope'   => 'all',
			'purged'  => $purged,
			'note'    => 'Every cached page will be rebuilt on next request. External CDN caches such as Cloudflare are not affected and must be purged separately.',
		];
	}
}
