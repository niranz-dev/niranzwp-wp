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

	/*
	 * The endpoint's public address, at the site root.
	 *
	 * The real route lives under /wp-json, but claude.ai's connector backend
	 * completes the whole OAuth flow and then never sends the token unless
	 * the endpoint path is exactly /mcp - isolated by varying only the path
	 * against one server (anthropics/claude-ai-mcp#878, confirmed fixes in
	 * #690). So /mcp is the advertised address, served by internal dispatch
	 * to the same route, and the REST path keeps answering for every client
	 * already pointed at it.
	 */
	public const ALIAS = '/mcp';
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

		// Before REST would 404 anything, same slot as the well-knowns.
		add_action( 'parse_request', [ self::class, 'serve_alias' ], 5 );

		/*
		 * A record of what actually arrives at this endpoint. When a client
		 * says it cannot reach the server there are two very different causes
		 * - the request never got here, or it got here and was refused - and
		 * from outside they look identical. This is how to tell them apart.
		 */
		add_filter( 'rest_post_dispatch', [ self::class, 'record' ], 20, 3 );

		/*
		 * A client reads the session id off the initialize response and sends
		 * it back on every call after that. WordPress exposes three headers to
		 * a cross-origin caller and this is not one of them, so anything
		 * running in a browser gets the header sent and is not allowed to read
		 * it - and fails on its second request, which looks from the outside
		 * like the server not being an MCP server at all.
		 */
		add_filter( 'rest_pre_serve_request', [ self::class, 'expose_headers' ], 10, 4 );

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

	/**
	 * Let a cross-origin caller read the headers this protocol needs.
	 *
	 * @param bool              $served  Whether the request has been served.
	 * @param \WP_HTTP_Response $result  Response about to be sent.
	 * @param \WP_REST_Request  $request The request being answered.
	 * @param \WP_REST_Server   $server  Unused.
	 * @return bool
	 */
	public static function expose_headers( $served, $result, $request, $server ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $served;
		}
		if ( ! str_starts_with( (string) $request->get_route(), '/' . self::NAMESPACE . '/' . self::ROUTE ) ) {
			return $served;
		}
		if ( headers_sent() ) {
			return $served;
		}

		$exposed = [ 'Mcp-Session-Id', 'MCP-Protocol-Version', 'WWW-Authenticate' ];

		// Added to whatever core already exposes rather than replacing it.
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'access-control-expose-headers:' ) ) {
				$existing = array_map( 'trim', explode( ',', substr( $header, strlen( 'access-control-expose-headers:' ) ) ) );
				$exposed  = array_merge( $existing, $exposed );
				break;
			}
		}

		header( 'Access-Control-Expose-Headers: ' . implode( ', ', array_unique( array_filter( $exposed ) ) ) );

		return $served;
	}

	/** How many recent requests to keep. */
	private const LOG      = 'niranzwp_mcp_log';
	private const LOG_KEEP = 25;

	/**
	 * Note one request to this endpoint, and what it was answered with.
	 *
	 * @param \WP_HTTP_Response $response Response about to be sent.
	 * @param \WP_REST_Server   $server   Unused.
	 * @param \WP_REST_Request  $request  The request being answered.
	 * @return \WP_HTTP_Response
	 */
	public static function record( $response, $server, $request ) {
		if ( ! $response instanceof \WP_HTTP_Response ) {
			return $response;
		}
		if ( ! str_starts_with( (string) $request->get_route(), '/' . self::NAMESPACE . '/' . self::ROUTE ) ) {
			return $response;
		}

		$auth = (string) $request->get_header( 'authorization' );
		$body = $request->get_json_params();

		$log   = get_option( self::LOG, [] );
		$log   = is_array( $log ) ? $log : [];
		$log[] = [
			'at'     => time(),
			// The forwarded address first: behind a CDN the connecting address
			// is the CDN, and the caller is the thing worth knowing.
			'ip'     => sanitize_text_field( (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'method' => (string) $request->get_method(),
			// Never the credential itself - only whether one was sent, and of
			// what kind, which is all that is ever in question here.
			'auth'   => '' === $auth ? 'none' : strtok( $auth, ' ' ),
			'call'   => is_array( $body ) ? sanitize_text_field( (string) ( $body['method'] ?? '' ) ) : '',
			'status' => (int) $response->get_status(),
			'agent'  => mb_substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 60 ),
		];

		update_option( self::LOG, array_slice( $log, -self::LOG_KEEP ), false );

		return $response;
	}

	/**
	 * The recent requests, newest first, for the Troubleshoot screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent(): array {
		$log = get_option( self::LOG, [] );
		return is_array( $log ) ? array_reverse( $log ) : [];
	}

	/** Where an MCP client should point, once abilities are enabled. */
	public static function endpoint(): string {
		return home_url( self::ALIAS );
	}

	/** The same handler at its REST address, which older clients still use. */
	public static function rest_endpoint(): string {
		return rest_url( self::NAMESPACE . '/' . self::ROUTE );
	}

	/**
	 * Serve the alias by dispatching into the REST route it stands for.
	 *
	 * A redirect would be simpler and wrong: a client following one is
	 * entitled to drop its Authorization header, and POST bodies do not
	 * reliably survive the hop. Internal dispatch keeps method, body,
	 * headers and authentication exactly as they arrived.
	 */
	public static function serve_alias(): void {
		$path = untrailingslashit( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );
		if ( self::ALIAS !== $path ) {
			return;
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

		if ( 'OPTIONS' === $method ) {
			status_header( 204 );
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID' );
			header( 'Access-Control-Expose-Headers: Mcp-Session-Id, MCP-Protocol-Version, WWW-Authenticate' );
			exit;
		}

		$request = new \WP_REST_Request( $method, '/' . self::NAMESPACE . '/' . self::ROUTE );
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				$request->set_header( (string) $name, (string) $value );
			}
		}
		$request->set_query_params( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$request->set_body( (string) file_get_contents( 'php://input' ) );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );
		// serve_request() would apply this; an internal dispatch has to do it
		// itself or the request is neither logged nor given its challenge
		// header on a 401.
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), $server, $request );

		status_header( $response->get_status() );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Access-Control-Expose-Headers: Mcp-Session-Id, MCP-Protocol-Version, WWW-Authenticate' );
		foreach ( $response->get_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo wp_json_encode( $server->response_to_data( $response, false ) );
		exit;
	}
}
