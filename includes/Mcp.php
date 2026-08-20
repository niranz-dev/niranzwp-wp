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

	/**
	 * Abilities never offered over MCP, whatever else is switched on.
	 *
	 * Not because they are dangerous - the file and runtime abilities are
	 * already behind their own toggles, and a site that has turned those on
	 * has said what it meant. These are left out because they are about this
	 * plugin rather than about the site: an MCP client has its own way to undo
	 * work and its own instructions, and does not need ours.
	 */
	private const PRIVATE_TO_THE_PLUGIN = [
		'niranzwp/checkpoint-create',
		'niranzwp/checkpoint-list',
		'niranzwp/checkpoint-restore',
		'niranzwp/checkpoint-verify',
		'niranzwp/checkpoint-delete',
		'niranzwp/skill-write',
		'niranzwp/skill-delete',
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
			'Read and change this WordPress site: SEO, content, page design, maintenance.',
			'v' . VERSION,
			[ $transport ],
			$errors,
			$observability,
			self::tools(),
			[],
			[]
		);
	}

	/**
	 * The abilities to offer as MCP tools.
	 *
	 * This was a hand-written list, and a hand-written list goes stale: every
	 * ability added after it was written was reachable over the CLI and
	 * invisible over MCP, which is how a client ended up seeing ten tools on a
	 * site that had sixty-five. It is now read from what is actually
	 * registered, so an ability is exposed by existing rather than by being
	 * remembered.
	 *
	 * @return string[]
	 */
	private static function tools(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}

		$names = array_filter(
			array_keys( (array) wp_get_abilities() ),
			static fn( string $name ): bool => str_starts_with( $name, 'niranzwp/' )
				&& ! in_array( $name, self::PRIVATE_TO_THE_PLUGIN, true )
		);

		sort( $names );
		return array_values( $names );
	}

	/** Where an MCP client should point, once abilities are enabled. */
	public static function endpoint(): string {
		return rest_url( self::NAMESPACE . '/' . self::ROUTE );
	}
}
