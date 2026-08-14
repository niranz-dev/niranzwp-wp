<?php
/**
 * MCP server registration.
 *
 * The WordPress MCP Adapter turns registered abilities into MCP tools, so any
 * MCP client -- Claude Desktop, Claude Code, Cursor -- can call them. We only
 * declare which abilities to expose; the adapter handles the protocol.
 *
 * The server is only created when abilities are switched on, so an installed
 * but disabled plugin exposes no MCP endpoint at all.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Mcp {

	public const SERVER_ID = 'niranzwp';
	public const NAMESPACE = 'mcp';
	public const ROUTE     = 'niranzwp';

	/** Abilities exposed as MCP tools. */
	private const TOOLS = [
		'niranzwp/site-info',
		'niranzwp/list-plugins',
		'niranzwp/autoload-report',
		'niranzwp/purge-cache',
		'niranzwp/seo-audit',
		'niranzwp/seo-list-missing',
		'niranzwp/seo-set-meta',
		'niranzwp/media-set-alt',
		'niranzwp/geo-check',
		'niranzwp/geo-llms-txt',
	];

	public static function init(): void {
		// Composer's platform check throws when the host PHP is older than the
		// dependencies were resolved for. A plugin must never take the whole
		// site down over that, so degrade to no MCP rather than fataling.
		$autoload = NIRANZWP_DIR . 'vendor/autoload.php';
		if ( is_readable( $autoload ) ) {
			try {
				require_once $autoload;
			} catch ( \Throwable $e ) {
				self::$load_error = $e->getMessage();
				add_action( 'admin_notices', [ self::class, 'render_load_notice' ] );
				return;
			}
		}

		add_action( 'mcp_adapter_init', [ self::class, 'register_server' ] );

		// Loading the autoloader is not enough: the adapter only fires
		// mcp_adapter_init once its singleton is constructed, so it has to be
		// booted explicitly or no MCP endpoint is ever registered.
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return;
		}
		try {
			\WP\MCP\Core\McpAdapter::instance();
		} catch ( \Throwable $e ) {
			self::$load_error = $e->getMessage();
			add_action( 'admin_notices', [ self::class, 'render_load_notice' ] );
		}
	}

	private static ?string $load_error = null;

	public static function render_load_notice(): void {
		if ( ! current_user_can( CAPABILITY ) || null === self::$load_error ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>NiranzWP:</strong> %s</p><p>%s</p></div>',
			esc_html__( 'The bundled dependencies could not load, so the MCP endpoint is unavailable. Every other ability still works.', 'niranzwp' ),
			esc_html( self::$load_error )
		);
	}

	public static function load_error(): ?string {
		return self::$load_error;
	}

	/**
	 * @param object $adapter The MCP adapter instance.
	 */
	public static function register_server( $adapter ): void {
		if ( ! Settings::active() ) {
			return;
		}
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$transport = '\WP\MCP\Transport\HttpTransport';
		if ( ! class_exists( $transport ) ) {
			return;
		}

		$errors = class_exists( '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler' )
			? '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler'
			: null;
		$observability = class_exists( '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler' )
			? '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler'
			: null;

		if ( ! $errors || ! $observability ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::NAMESPACE,
			self::ROUTE,
			'NiranzWP',
			'SEO, GEO and maintenance abilities for this WordPress site.',
			'v' . VERSION,
			[ $transport ],
			$errors,
			$observability,
			self::TOOLS,
			[],
			[]
		);
	}

	/** Where an MCP client should point, once abilities are enabled. */
	public static function endpoint(): string {
		return rest_url( self::NAMESPACE . '/' . self::ROUTE );
	}
}
