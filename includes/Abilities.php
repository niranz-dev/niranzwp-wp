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

	public static function register_category(): void {
		wp_register_ability_category(
			'niranzwp',
			[
				'label'       => __( 'NiranzWP', 'niranzwp' ),
				'description' => __( 'Site inspection and maintenance abilities.', 'niranzwp' ),
			]
		);
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
		Files::register();
		Runtime::register();
		Cli::register();

		wp_register_ability(
			'niranzwp/site-info',
			[
				'label'               => __( 'Site information', 'niranzwp' ),
				'description'         => __( 'Returns the site name, URL, WordPress and PHP versions, active theme and locale.', 'niranzwp' ),
				'category'            => 'niranzwp',
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
				'category'            => 'niranzwp',
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
				'category'            => 'niranzwp',
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
				'category'            => 'niranzwp',
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
