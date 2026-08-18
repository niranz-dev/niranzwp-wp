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

		wp_register_ability(
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

		wp_register_ability(
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

		wp_register_ability(
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

		wp_register_ability(
			'niranzwp/purge-cache',
			[
				'label'               => __( 'Purge caches', 'niranzwp' ),
				'description'         => __( 'Purges LiteSpeed, W3 Total Cache and WP object cache where present. Does not reach an external CDN such as Cloudflare.', 'niranzwp' ),
				'category'            => 'niranzwp-site',
				'input_schema'        => [ 'type' => 'object', 'properties' => (object) [] ],
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
	public static function purge_cache(): array {
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
			'purged' => $purged,
			'note'   => 'External CDN caches such as Cloudflare are not affected and must be purged separately.',
		];
	}
}
