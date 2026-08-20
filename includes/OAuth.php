<?php
/**
 * OAuth 2.0 for the CLI and MCP clients, device grant first.
 *
 * The application-password path works and is not going away, but it hands a
 * client a credential that never expires and lives in a keychain until someone
 * remembers to revoke it. A token expires on its own, refreshes without asking
 * anyone, and can be killed from this side. That is the whole argument.
 *
 * Device grant rather than a redirect flow, because the redirect flow needs the
 * browser to reach the CLI's loopback listener, and on this site it did not:
 * WordPress refused the authorization with "Invalid URL format" for a
 * success_url its own validator accepts when called directly, which means
 * something between the browser and PHP rewrote it. The device grant carries no
 * redirect at all - a code is read off the terminal and typed into wp-admin -
 * so there is nothing in the URL for anything to object to. It is also the only
 * flow that works over SSH, in a container, or from a phone.
 *
 * RFC 8628 for the device grant, RFC 7591 for registration, RFC 8414 for the
 * metadata document. The client half already speaks all three; this is the half
 * that was missing.
 *
 * Nothing here is reachable until AI abilities are switched on.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class OAuth {

	private const NS           = 'niranzwp/v1';
	private const CLIENTS      = 'niranzwp_oauth_clients';
	private const TOKEN_META   = '_niranzwp_oauth_tokens';
	private const DEVICE_TTL   = 600;    // ten minutes to type a code
	private const AUTHZ_TTL    = 600;    // ten minutes to approve in the browser
	private const CODE_TTL     = 60;     // one minute to exchange the code
	private const ACCESS_TTL   = 3600;   // one hour
	private const REFRESH_TTL  = 2592000; // thirty days
	private const INTERVAL     = 5;
	private const MAX_CLIENTS  = 50;
	private const MAX_TOKENS   = 20;

	/*
	 * Rotation needs two different answers to the same question - somebody is
	 * presenting a refresh token that has already been used.
	 *
	 * Almost always that is the honest client asking again because it never
	 * received the reply: the connection dropped, a proxy ate the response, the
	 * process was killed between the write and the read. The server rotated,
	 * the client still holds the old token, and destroying it on arrival locks
	 * that client out permanently over one lost packet. GRACE is how long a
	 * second attempt is read that way.
	 *
	 * After that it is read as theft, which is the whole reason to rotate: two
	 * parties hold one token and only one of them should. REUSE_WINDOW is how
	 * long a spent token is remembered so that can be noticed at all - past it
	 * the record is gone and the token is merely unknown.
	 */
	private const ROTATION_GRACE = 120;   // two minutes
	private const REUSE_WINDOW   = 86400; // one day
	private const LAST_USED_RESOLUTION = 60;

	/*
	 * Two endpoints have to be open to strangers - a client cannot register
	 * itself or ask for a code while holding a credential it does not have yet.
	 * Open and unmetered are different things. Each device request writes two
	 * transients that live ten minutes, so a script left running fills the
	 * options table of a site whose owner never connected anything.
	 *
	 * Counted per address per hour. Generous enough that a person retrying, or
	 * an office behind one address, never meets it.
	 */
	private const THROTTLE_WINDOW = 3600;
	private const THROTTLE_MAX    = 30;

	/**
	 * The user code alphabet.
	 *
	 * No 0/O, no 1/I/L: this is read off a terminal and typed into a browser,
	 * and every one of those pairs is a support conversation.
	 */
	private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
		add_action( 'parse_request', [ self::class, 'metadata_document' ] );
		add_action( 'parse_request', [ self::class, 'protected_resource_document' ] );

		/*
		 * A 401 with no WWW-Authenticate is a dead end: the client is told no
		 * and not told where to ask. This is the header that turns the refusal
		 * into the first step of connecting.
		 */
		add_filter( 'rest_post_dispatch', [ self::class, 'challenge_header' ], 10, 3 );
		add_filter( 'determine_current_user', [ self::class, 'authenticate' ], 30 );
	}

	/* ------------------------------------------------------------- metadata */

	/**
	 * Whether this request is for one of the discovery documents.
	 *
	 * Both RFCs build the URL by putting the well-known segment in front of
	 * the resource's own path rather than at the root, so a client asks for
	 * /.well-known/oauth-protected-resource/wp-json/mcp/niranzwp - and every
	 * current connector does. Matching only the bare path answered 404 to all
	 * of them, and a connector that cannot find the document cannot start.
	 *
	 * The bare form is still answered, because some clients ask for that.
	 *
	 * @param string $document oauth-authorization-server or oauth-protected-resource.
	 */
	private static function asked_for( string $document ): bool {
		$path = untrailingslashit( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );
		$base = '/.well-known/' . $document;

		if ( $base === $path ) {
			return true;
		}
		if ( ! str_starts_with( $path, $base . '/' ) ) {
			return false;
		}

		/*
		 * A suffix names the resource it is asking about. Only this site's own
		 * MCP endpoint is answered for; claiming to be the authorization server
		 * for some other path would be answering a question nobody asked.
		 */
		$suffix   = substr( $path, strlen( $base ) );
		$endpoint = untrailingslashit( (string) wp_parse_url( Mcp::endpoint(), PHP_URL_PATH ) );

		return $suffix === $endpoint;
	}

	/**
	 * RFC 8414 asks for this at the site root, which is outside the REST
	 * namespace, so it is answered before WordPress decides what the request
	 * was for. Public on purpose: a client has to be able to read it before it
	 * has any credential at all.
	 */
	public static function metadata_document(): void {
		if ( ! self::asked_for( 'oauth-authorization-server' ) ) {
			return;
		}

		wp_send_json(
			[
				'issuer'                                => untrailingslashit( home_url() ),
				'registration_endpoint'                 => rest_url( self::NS . '/oauth/register' ),
				'authorization_endpoint'                => rest_url( self::NS . '/oauth/authorize' ),
				'device_authorization_endpoint'         => rest_url( self::NS . '/oauth/device' ),
				'token_endpoint'                        => rest_url( self::NS . '/oauth/token' ),
				'response_types_supported'              => [ 'code' ],
				'grant_types_supported'                 => [
					'authorization_code',
					'urn:ietf:params:oauth:grant-type:device_code',
					'refresh_token',
				],
				// S256 only. The spec also allows "plain", which sends the
				// verifier in the same redirect as the code it is meant to
				// protect and so protects nothing.
				'code_challenge_methods_supported'      => [ 'S256' ],
				'token_endpoint_auth_methods_supported' => [ 'none' ],
				'scopes_supported'                      => [ 'abilities' ],
				'service_documentation'                 => 'https://niranz.dev',
			]
		);
	}

	/**
	 * Point an unauthenticated MCP caller at the discovery document.
	 *
	 * @param \WP_HTTP_Response $response Response about to be sent.
	 * @param \WP_REST_Server   $server   Unused.
	 * @param \WP_REST_Request  $request  The request being answered.
	 * @return \WP_HTTP_Response
	 */
	public static function challenge_header( $response, $server, $request ) {
		if ( ! $response instanceof \WP_HTTP_Response || 401 !== $response->get_status() ) {
			return $response;
		}
		if ( ! str_starts_with( (string) $request->get_route(), '/' . Mcp::NAMESPACE . '/' ) ) {
			return $response;
		}

		/*
		 * Points at the path-inserted form, which is the canonical URL for
		 * this resource's metadata under RFC 9728 - the bare one is answered
		 * too, but a client should be sent to the document that names the
		 * resource it just asked about.
		 */
		$resource = (string) wp_parse_url( Mcp::endpoint(), PHP_URL_PATH );

		$response->header(
			'WWW-Authenticate',
			sprintf(
				'Bearer realm="%s", resource_metadata="%s"',
				esc_url_raw( home_url() ),
				esc_url_raw( home_url( '/.well-known/oauth-protected-resource' . $resource ) )
			)
		);

		return $response;
	}

	/**
	 * RFC 9728, the other half of discovery.
	 *
	 * The authorization server document says how to get a token. This one says
	 * which server to ask for this particular resource, and it is what a client
	 * looks for after a 401 from the MCP endpoint.
	 */
	public static function protected_resource_document(): void {
		if ( ! self::asked_for( 'oauth-protected-resource' ) ) {
			return;
		}

		wp_send_json(
			[
				'resource'                 => Mcp::endpoint(),
				'authorization_servers'    => [ untrailingslashit( home_url() ) ],
				'scopes_supported'         => [ 'abilities' ],
				'bearer_methods_supported' => [ 'header' ],
				'resource_documentation'   => 'https://niranz.dev',
			]
		);
	}

	public static function routes(): void {
		$open = [ 'permission_callback' => '__return_true' ];

		register_rest_route( self::NS, '/oauth/register', array_merge( $open, [
			'methods'  => 'POST',
			'callback' => [ self::class, 'register_client' ],
		] ) );

		register_rest_route( self::NS, '/oauth/device', array_merge( $open, [
			'methods'  => 'POST',
			'callback' => [ self::class, 'device_authorize' ],
		] ) );

		register_rest_route( self::NS, '/oauth/token', array_merge( $open, [
			'methods'  => 'POST',
			'callback' => [ self::class, 'token' ],
		] ) );

		register_rest_route( self::NS, '/oauth/authorize', array_merge( $open, [
			'methods'  => 'GET',
			'callback' => [ self::class, 'authorize' ],
		] ) );
	}

	/* ------------------------------------------------------------- clients */

	/**
	 * RFC 7591. A public client with no secret, because a CLI on someone's
	 * laptop cannot keep one - the device grant does not need it, and pretending
	 * otherwise would only add a value to leak.
	 *
	 * Registering grants nothing. A client_id is a name to poll under; every
	 * grant of access still goes through a person approving a code in wp-admin.
	 */
	public static function register_client( \WP_REST_Request $r ) {
		if ( ! self::transport_is_safe() ) {
			return self::insecure_transport();
		}
		if ( self::throttled() ) {
			return self::error( 'slow_down', 'Too many requests from this address. Try again later.', 429 );
		}
		if ( ! Settings::active() ) {
			return self::error( 'access_denied', 'Abilities are switched off on this site.', 403 );
		}

		$name = sanitize_text_field( (string) ( $r->get_param( 'client_name' ) ?: 'Unnamed client' ) );
		$name = mb_substr( $name, 0, 80 );

		$clients = self::clients();

		/*
		 * A registration endpoint anyone can post to is a row-creation endpoint
		 * anyone can post to, so it is capped. Dropping the oldest to make room
		 * was the wrong end to drop from: a stranger could register fifty times
		 * and push out the registration a working client was still polling
		 * under, breaking a connection nobody touched.
		 *
		 * Registrations that no longer have a token behind them go first, newest
		 * of those before oldest, so what is discarded is whatever was least
		 * likely to be in use. If everything is in use, the endpoint refuses
		 * rather than choosing a victim.
		 */
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			$in_use = self::clients_with_tokens();
			$idle   = array_reverse(
				array_filter(
					$clients,
					static fn( string $id ): bool => ! isset( $in_use[ $id ] ),
					ARRAY_FILTER_USE_KEY
				),
				true
			);
			if ( ! $idle ) {
				return self::error(
					'temporarily_unavailable',
					'This site has as many registered clients as it will hold. Revoke one on the Connections screen.',
					503
				);
			}
			foreach ( array_slice( array_keys( $idle ), 0, 10 ) as $drop ) {
				unset( $clients[ $drop ] );
			}
		}

		$client_id = 'nzwp_' . bin2hex( random_bytes( 16 ) );

		/*
		 * The browser grant hands the authorization code to whatever address
		 * the request names, so the addresses a client may name are fixed here,
		 * at registration, and matched exactly later. Without that, anyone who
		 * learns a client_id can point the redirect at their own server and
		 * collect codes meant for someone else.
		 */
		$redirects = self::clean_redirect_uris( $r->get_param( 'redirect_uris' ) );
		if ( is_wp_error( $redirects ) ) {
			return self::error( 'invalid_redirect_uri', $redirects->get_error_message(), 400 );
		}

		$clients[ $client_id ] = [
			'name'          => $name,
			'created'       => time(),
			'redirect_uris' => $redirects,
		];
		update_option( self::CLIENTS, $clients, false );

		$body = [
			'client_id'                  => $client_id,
			'client_name'                => $name,
			'client_id_issued_at'        => time(),
			'grant_types'                => [
				'authorization_code',
				'urn:ietf:params:oauth:grant-type:device_code',
				'refresh_token',
			],
			'response_types'             => [ 'code' ],
			'token_endpoint_auth_method' => 'none',
		];
		if ( $redirects ) {
			$body['redirect_uris'] = $redirects;
		}

		return new \WP_REST_Response( $body, 201 );
	}

	/* -------------------------------------------------------- device grant */

	/**
	 * RFC 8628 step one: mint a code the person will type, and a secret the
	 * client will poll with.
	 *
	 * Both are stored hashed. A device code sitting in the options table in
	 * plaintext would be a token anyone with database read access could redeem
	 * during its ten minutes.
	 */
	public static function device_authorize( \WP_REST_Request $r ) {
		if ( ! self::transport_is_safe() ) {
			return self::insecure_transport();
		}
		if ( self::throttled() ) {
			return self::error( 'slow_down', 'Too many requests from this address. Try again later.', 429 );
		}
		if ( ! Settings::active() ) {
			return self::error( 'access_denied', 'Abilities are switched off on this site.', 403 );
		}

		$client_id = (string) $r->get_param( 'client_id' );
		if ( ! isset( self::clients()[ $client_id ] ) ) {
			return self::error( 'invalid_client', 'Unknown client_id. Register first.', 400 );
		}

		$device_code = bin2hex( random_bytes( 32 ) );
		$user_code   = self::user_code();

		$record = [
			'client_id' => $client_id,
			'user_code' => $user_code,
			'approved'  => 0,
			'user_id'   => 0,
			'created'   => time(),
		];

		set_transient( self::device_key( $device_code ), $record, self::DEVICE_TTL );
		/*
		 * The typed code has to find the request - it is the only thing the
		 * person carries between the terminal and the browser - but it must not
		 * carry the device code itself. Storing that here put the plaintext of
		 * the credential the client polls with into the options table, where a
		 * database read through somebody else's plugin, or an old backup, would
		 * hand it over.
		 *
		 * What is stored is the hash. approve() only needs to find the record,
		 * and it can find it by that; redeeming still requires the plaintext,
		 * which only the client that asked for it ever had.
		 */
		set_transient( self::code_key( $user_code ), self::device_key( $device_code ), self::DEVICE_TTL );

		return new \WP_REST_Response(
			[
				'device_code'              => $device_code,
				'user_code'                => $user_code,
				'verification_uri'         => admin_url( 'admin.php?page=niranzwp-connect' ),
				'verification_uri_complete' => admin_url( 'admin.php?page=niranzwp-connect&code=' . rawurlencode( $user_code ) ),
				'expires_in'               => self::DEVICE_TTL,
				'interval'                 => self::INTERVAL,
			],
			200
		);
	}

	/**
	 * Called from the admin screen once a person has read the code and said yes.
	 *
	 * Approval is the whole security boundary: everything before it is
	 * unauthenticated bookkeeping, and everything after it acts as this user.
	 */
	public static function approve( string $user_code, int $user_id ): bool {
		$user_code = self::normalise_code( $user_code );
		$key       = get_transient( self::code_key( $user_code ) );
		if ( ! is_string( $key ) || '' === $key ) {
			return false;
		}

		$record = get_transient( $key );
		if ( ! is_array( $record ) ) {
			return false;
		}

		$record['approved'] = 1;
		$record['user_id']  = $user_id;

		// Whatever is left of the original ten minutes, not a fresh ten: the
		// window is for the person to approve in, and it has just been used.
		$left = max( 30, self::DEVICE_TTL - ( time() - (int) $record['created'] ) );
		set_transient( $key, $record, $left );
		delete_transient( self::code_key( $user_code ) );

		return true;
	}

	/** What the admin screen shows before asking anyone to approve anything. */
	public static function pending( string $user_code ): ?array {
		$key = get_transient( self::code_key( self::normalise_code( $user_code ) ) );
		if ( ! is_string( $key ) ) {
			return null;
		}
		$record = get_transient( $key );
		if ( ! is_array( $record ) ) {
			return null;
		}
		$clients = self::clients();
		return [
			'client_name' => $clients[ $record['client_id'] ]['name'] ?? 'Unknown client',
			'requested'   => (int) $record['created'],
		];
	}

	/* ---------------------------------------------------------------- token */

	public static function token( \WP_REST_Request $r ) {
		if ( ! self::transport_is_safe() ) {
			return self::insecure_transport();
		}
		$grant = (string) $r->get_param( 'grant_type' );

		if ( 'urn:ietf:params:oauth:grant-type:device_code' === $grant ) {
			return self::token_from_device( $r );
		}
		if ( 'authorization_code' === $grant ) {
			return self::token_from_code( $r );
		}
		if ( 'refresh_token' === $grant ) {
			return self::token_from_refresh( $r );
		}
		return self::error( 'unsupported_grant_type', 'Supported: authorization_code, device_code, refresh_token.', 400 );
	}

	private static function token_from_device( \WP_REST_Request $r ) {
		$device_code = (string) $r->get_param( 'device_code' );
		$client_id   = (string) $r->get_param( 'client_id' );

		$record = $device_code ? get_transient( self::device_key( $device_code ) ) : false;

		// An expired device code and a fabricated one are the same answer on
		// purpose: this endpoint is polled by anyone who can reach the site,
		// and it should not become an oracle for which codes existed.
		if ( ! is_array( $record ) || $record['client_id'] !== $client_id ) {
			return self::error( 'expired_token', 'That device code is unknown or has expired. Start again.', 400 );
		}

		if ( empty( $record['approved'] ) ) {
			return self::error( 'authorization_pending', 'Waiting for someone to approve the code.', 400 );
		}

		$user_id = (int) $record['user_id'];
		if ( ! $user_id || ! user_can( $user_id, CAPABILITY ) ) {
			delete_transient( self::device_key( $device_code ) );
			return self::error( 'access_denied', 'The approving user can no longer manage this site.', 403 );
		}

		// One device code, one exchange.
		delete_transient( self::device_key( $device_code ) );

		return self::issue( $user_id, $client_id );
	}

	private static function token_from_refresh( \WP_REST_Request $r ) {
		$refresh   = (string) $r->get_param( 'refresh_token' );
		$client_id = (string) $r->get_param( 'client_id' );

		$found = self::find_token( $refresh, 'refresh' );
		if ( ! $found ) {
			return self::error( 'invalid_grant', 'That refresh token is unknown or has expired.', 400 );
		}
		[ $user_id, $index, $record ] = $found;

		if ( $record['client_id'] !== $client_id ) {
			return self::error( 'invalid_grant', 'That refresh token belongs to a different client.', 400 );
		}
		if ( ! user_can( $user_id, CAPABILITY ) ) {
			self::forget_token( $user_id, $index );
			return self::error( 'invalid_grant', 'That user can no longer manage this site.', 403 );
		}

		$family  = (string) ( $record['family'] ?? '' );
		$retired = (int) ( $record['retired'] ?? 0 );

		if ( $retired > 0 ) {
			if ( $retired + self::ROTATION_GRACE < time() ) {
				// Long after any honest retry would have given up. Two parties
				// hold this token; the site cannot tell which is the client, so
				// it trusts neither and takes the whole chain down. The user
				// logs in again, and whoever stole it gets nothing.
				self::forget_family( $user_id, $family );
				return self::error(
					'invalid_grant',
					'That refresh token was already used. Every token from this connection has been revoked - sign in again.',
					400
				);
			}
			// Inside the window: the client never saw the last reply. Answer it.
		}

		// Rotate. A refresh token that survives its own use is a credential with
		// no expiry wearing a different hat - so it is spent here, and only
		// remembered long enough to recognise it coming back.
		self::retire_token( $user_id, $index );

		return self::issue( $user_id, $client_id, $family );
	}

	/**
	 * Mint a pair and keep only their hashes.
	 *
	 * Same reasoning as WordPress's own application passwords: the site never
	 * needs the plaintext again, so it should not be able to hand it over.
	 */
	private static function issue( int $user_id, string $client_id, string $family = '' ) {
		if ( '' === $family ) {
			$family = bin2hex( random_bytes( 16 ) );
		}

		$access  = bin2hex( random_bytes( 32 ) );
		$refresh = bin2hex( random_bytes( 32 ) );

		$tokens = self::tokens( $user_id );
		$now    = time();

		/*
		 * A rotation mints a new record, so its own 'created' is the moment the
		 * refresh happened - not the moment the person approved the tool. The
		 * screen wants the second one, or every connection reads as if it were
		 * made an hour ago. Read it off any surviving record in the family,
		 * before the filter below removes the retired one it may be sitting on.
		 */
		$family_created = $now;
		if ( '' !== $family ) {
			foreach ( $tokens as $t ) {
				if ( (string) ( $t['family'] ?? '' ) === $family ) {
					$family_created = (int) ( $t['family_created'] ?? $t['created'] ?? $now );
					break;
				}
			}
		}

		/*
		 * One live refresh token per family, enforced here rather than trusted
		 * to each path that mints one.
		 *
		 * The retry case is why this is needed. When a reply is lost the client
		 * presents the same token again, and the site cannot send the same
		 * answer back - it kept only hashes, so it no longer has the token it
		 * issued. It has to mint another. That left the first one live and
		 * unclaimed for thirty days, and a connection used for two days had
		 * collected five of them.
		 *
		 * Retired rather than deleted, so a client that did receive the earlier
		 * reply is told its token was superseded instead of that it never
		 * existed - and so a real theft still trips the reuse check.
		 */
		if ( '' !== $family ) {
			foreach ( $tokens as $i => $t ) {
				if ( (string) ( $t['family'] ?? '' ) !== $family || ! empty( $t['retired'] ) ) {
					continue;
				}
				$tokens[ $i ]['retired']         = $now;
				$tokens[ $i ]['refresh_expires'] = $now + self::REUSE_WINDOW;
				$tokens[ $i ]['access_expires']  = min(
					(int) ( $t['access_expires'] ?? 0 ),
					$now + self::ROTATION_GRACE
				);
			}
		}

		// Drop anything already dead, then cap. Without this the meta row grows
		// for the life of the site.
		$tokens = array_values( array_filter(
			$tokens,
			static fn( array $t ): bool => (int) ( $t['refresh_expires'] ?? 0 ) > $now
		) );
		if ( count( $tokens ) >= self::MAX_TOKENS ) {
			$tokens = array_slice( $tokens, -( self::MAX_TOKENS - 1 ) );
		}

		$tokens[] = [
			'client_id'       => $client_id,
			'family'          => $family,
			'family_created'  => $family_created,
			'access'          => hash( 'sha256', $access ),
			'refresh'         => hash( 'sha256', $refresh ),
			'access_expires'  => $now + self::ACCESS_TTL,
			'refresh_expires' => $now + self::REFRESH_TTL,
			'created'         => $now,
			'last_used'       => 0,
		];
		update_user_meta( $user_id, self::TOKEN_META, $tokens );

		return new \WP_REST_Response(
			[
				'access_token'  => $access,
				'refresh_token' => $refresh,
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'scope'         => 'abilities',
			],
			200
		);
	}

	/* ------------------------------------------------------ authentication */

	/**
	 * Turn a Bearer token into a logged-in user.
	 *
	 * Runs on determine_current_user, so everything downstream - the abilities
	 * API, the REST permission callbacks, current_user_can - sees an ordinary
	 * authenticated request and needs to know nothing about OAuth.
	 *
	 * @param int|false $user_id
	 * @return int|false
	 */
	public static function authenticate( $user_id ) {
		if ( $user_id ) {
			return $user_id; // Something already authenticated this request.
		}

		/*
		 * The switch has to hold here, not only over the abilities.
		 *
		 * A bearer token does not merely reach this plugin - it makes the
		 * request an administrator, and everything WordPress exposes answers to
		 * that: posts, users, media, plugins. Gating the abilities alone would
		 * leave "off" meaning "our features are off, the credential still opens
		 * the site", which is not what anyone reads it as.
		 *
		 * active() also covers the domain lock, so a copy of this database
		 * restored somewhere else does not arrive with working credentials.
		 */
		if ( ! Settings::active() ) {
			return $user_id;
		}

		$header = '';
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$header = (string) $_SERVER[ $key ];
				break;
			}
		}
		if ( '' === $header && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'authorization' ) ) {
					$header = (string) $value;
					break;
				}
			}
		}

		if ( ! preg_match( '/^Bearer\s+([0-9a-f]{64})$/i', trim( $header ), $m ) ) {
			return $user_id;
		}

		$found = self::find_token( strtolower( $m[1] ), 'access' );
		if ( ! $found ) {
			return $user_id;
		}
		[ $owner, $index, $record ] = $found;

		if ( ! user_can( $owner, CAPABILITY ) ) {
			return $user_id;
		}

		/*
		 * Worth having on the Connections screen - it is the only way to tell a
		 * live client from an abandoned one - but not worth a database write per
		 * request. An agent working through a site makes hundreds, and every one
		 * of them read this whole array, changed a number and wrote it back over
		 * the same row that rotation uses. Two overlapping requests could put
		 * back a copy taken before the other's change, which for a refresh
		 * meant a spent token returning to life.
		 *
		 * A minute's resolution is all the screen shows, so a minute is all it
		 * costs.
		 */
		$now = time();
		if ( $now - (int) ( $record['last_used'] ?? 0 ) >= self::LAST_USED_RESOLUTION ) {
			$tokens = self::tokens( $owner );
			if ( isset( $tokens[ $index ] ) && hash_equals( (string) ( $tokens[ $index ]['access'] ?? '' ), (string) $record['access'] ) ) {
				$tokens[ $index ]['last_used'] = $now;
				update_user_meta( $owner, self::TOKEN_META, $tokens );
			}
		}

		return $owner;
	}

	/* ---------------------------------------------------------- revocation */

	/** @return array<int,array<string,mixed>> */
	public static function list_tokens( int $user_id ): array {
		$now = time();
		return array_values( array_filter(
			self::tokens( $user_id ),
			static fn( array $t ): bool => (int) ( $t['refresh_expires'] ?? 0 ) > $now
		) );
	}

	public static function revoke_all( int $user_id ): void {
		delete_user_meta( $user_id, self::TOKEN_META );
	}

	/**
	 * Everything the owner needs to recognise a connection, and decide about it.
	 *
	 * The token record stores only the client id, because that is all the grant
	 * needs. A person looking at a list of connections needs the name the tool
	 * gave when it registered, so join the two here rather than making the
	 * screen understand how either is stored.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function connections( int $user_id ): array {
		$clients = self::clients();
		$rows    = [];

		foreach ( self::list_tokens( $user_id ) as $t ) {
			/*
			 * A retired record is a spent refresh token, kept for a day only so
			 * that a second presentation can be told from a token the site never
			 * issued. It is not something anyone is connected with, and listing
			 * it says the opposite.
			 */
			if ( ! empty( $t['retired'] ) ) {
				continue;
			}

			/*
			 * One row per family, not per token. A family is one approval; every
			 * refresh after it mints a fresh pair and retires the old, so a tool
			 * that has been in use for a week has left a trail of records behind
			 * one connection. Listing them per token turned a single CLI into
			 * eleven entries, all named the same, none of which the reader could
			 * tell apart or act on separately.
			 *
			 * Records from before families were stored fall back to their own
			 * created time, which keeps them visible and separate rather than
			 * silently merging them into one another.
			 */
			$family    = (string) ( $t['family'] ?? '' );
			$key       = '' !== $family ? 'f:' . $family : 't:' . (int) ( $t['created'] ?? 0 );
			$client_id = (string) ( $t['client_id'] ?? '' );

			$created   = (int) ( $t['family_created'] ?? $t['created'] ?? 0 );
			$last_used = (int) ( $t['last_used'] ?? 0 );
			$expires   = (int) ( $t['refresh_expires'] ?? 0 );

			if ( isset( $rows[ $key ] ) ) {
				// Oldest approval, newest use, latest expiry: what is true of the
				// connection rather than of whichever record happened to be last.
				$created   = min( $rows[ $key ]['created'] ?: $created, $created ?: $rows[ $key ]['created'] );
				$last_used = max( $rows[ $key ]['last_used'], $last_used );
				$expires   = max( $rows[ $key ]['expires'], $expires );
			}

			$rows[ $key ] = [
				'client_id' => $client_id,
				'family'    => $family,
				'name'      => (string) ( $clients[ $client_id ]['name'] ?? 'Unnamed client' ),
				'created'   => $created,
				'last_used' => $last_used,
				'expires'   => $expires,
			];
		}

		$out = array_values( $rows );
		usort( $out, static fn( array $a, array $b ): int => $b['last_used'] <=> $a['last_used'] );
		return $out;
	}

	/**
	 * Revoke every connection belonging to one client.
	 *
	 * Matched on the client id rather than on a position in the array, because
	 * the list is filtered and re-sorted before it reaches the screen and a row
	 * index there means nothing here. Returns how many records went.
	 *
	 * This is the blunt one, and it is only right when the reader means "this
	 * tool, entirely". A screen showing one row per connection wants
	 * revoke_family() instead, or revoking the row a person pointed at takes
	 * every other connection of the same tool down with it.
	 */
	public static function revoke_client( int $user_id, string $client_id ): int {
		$tokens = self::tokens( $user_id );
		$kept   = array_values( array_filter(
			$tokens,
			static fn( array $t ): bool => (string) ( $t['client_id'] ?? '' ) !== $client_id
		) );

		$gone = count( $tokens ) - count( $kept );
		if ( $gone > 0 ) {
			update_user_meta( $user_id, self::TOKEN_META, $kept );
		}
		return $gone;
	}

	/**
	 * Revoke one connection: every token descended from a single approval.
	 *
	 * Retired records go too. They are spent and cannot be exchanged, but the
	 * person asked for this connection to end, and leaving anything behind that
	 * carries its family is not that.
	 */
	public static function revoke_family( int $user_id, string $family ): int {
		if ( '' === $family ) {
			return 0;
		}

		$tokens = self::tokens( $user_id );
		$kept   = array_values( array_filter(
			$tokens,
			static fn( array $t ): bool => (string) ( $t['family'] ?? '' ) !== $family
		) );

		$gone = count( $tokens ) - count( $kept );
		if ( $gone > 0 ) {
			update_user_meta( $user_id, self::TOKEN_META, $kept );
		}
		return $gone;
	}

	/* -------------------------------------------------------------- plumbing */

	/** @return array<string,array<string,mixed>> */
	private static function clients(): array {
		$c = get_option( self::CLIENTS, [] );
		return is_array( $c ) ? $c : [];
	}

	/** @return array<int,array<string,mixed>> */
	private static function tokens( int $user_id ): array {
		$t = get_user_meta( $user_id, self::TOKEN_META, true );
		return is_array( $t ) ? array_values( $t ) : [];
	}

	/**
	 * Find a token by its plaintext, across every user who has any.
	 *
	 * hash_equals rather than ===: this compares a secret, and a timing
	 * difference on a value an attacker can vary is a way to learn it.
	 *
	 * @return array{0:int,1:int,2:array<string,mixed>}|null
	 */
	private static function find_token( string $plain, string $kind ): ?array {
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $plain ) ) {
			return null;
		}
		$hash = hash( 'sha256', $plain );
		$now  = time();

		$users = get_users( [
			'meta_key'     => self::TOKEN_META,
			'meta_compare' => 'EXISTS',
			'fields'       => 'ID',
			'number'       => 100,
		] );

		foreach ( $users as $uid ) {
			foreach ( self::tokens( (int) $uid ) as $i => $t ) {
				$expiry = 'access' === $kind ? 'access_expires' : 'refresh_expires';
				if ( (int) ( $t[ $expiry ] ?? 0 ) <= $now ) {
					continue;
				}
				if ( isset( $t[ $kind ] ) && hash_equals( (string) $t[ $kind ], $hash ) ) {
					return [ (int) $uid, (int) $i, $t ];
				}
			}
		}
		return null;
	}

	/**
	 * Spend a refresh token without forgetting it.
	 *
	 * The record stays so a second presentation can be told apart from a token
	 * the site never issued, which is the difference between a dropped response
	 * and a stolen credential. Shortening its expiry rather than adding another
	 * sweep means the existing cleanup in issue() removes it on its own.
	 */
	private static function retire_token( int $user_id, int $index ): void {
		$tokens = self::tokens( $user_id );
		if ( ! isset( $tokens[ $index ] ) ) {
			return;
		}
		$now                                   = time();
		$tokens[ $index ]['retired']           = $now;
		$tokens[ $index ]['refresh_expires']   = $now + self::REUSE_WINDOW;
		$tokens[ $index ]['access_expires']    = min(
			(int) ( $tokens[ $index ]['access_expires'] ?? 0 ),
			$now + self::ROTATION_GRACE
		);
		update_user_meta( $user_id, self::TOKEN_META, array_values( $tokens ) );
	}

	/**
	 * Revoke every token descended from one approval.
	 *
	 * Rotation only detects a theft; it does not end it. The thief holds a pair
	 * minted from the same grant, so cutting the presented token alone leaves
	 * them connected.
	 */
	private static function forget_family( int $user_id, string $family ): void {
		if ( '' === $family ) {
			return;
		}
		$tokens = array_values( array_filter(
			self::tokens( $user_id ),
			static fn( array $t ): bool => ( $t['family'] ?? '' ) !== $family
		) );
		update_user_meta( $user_id, self::TOKEN_META, $tokens );
	}

	private static function forget_token( int $user_id, int $index ): void {
		$tokens = self::tokens( $user_id );
		unset( $tokens[ $index ] );
		update_user_meta( $user_id, self::TOKEN_META, array_values( $tokens ) );
	}

	/** Four and four, which is short enough to read aloud and long enough not to guess. */
	private static function user_code(): string {
		$out = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$out .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
			if ( 3 === $i ) {
				$out .= '-';
			}
		}
		return $out;
	}

	/** Accept what a person actually types: lower case, spaces, a missing dash. */
	public static function normalise_code( string $code ): string {
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) ?? '' );
		return 8 === strlen( $code ) ? substr( $code, 0, 4 ) . '-' . substr( $code, 4 ) : $code;
	}

	/**
	 * Has this address had enough for now?
	 *
	 * Deliberately not an authoritative counter: transients can be evicted and
	 * a determined attacker has more than one address. It is here to stop the
	 * cheap, loud case - one script, one address, no reason - without ever
	 * standing between a real person and their own site.
	 */
	/** client_ids that still have a token behind them, so they are not evicted. */
	/**
	 * Is this connection safe to put a credential on?
	 *
	 * Everything here - the device code, the access token, the refresh token -
	 * is a bearer credential, which is to say whoever reads it off the wire owns
	 * it. Over plain HTTP that is anyone between the client and the site, and no
	 * amount of care at either end changes it.
	 *
	 * Loopback and the usual local development hostnames are allowed, because a
	 * request that never leaves the machine has no wire to read, and refusing
	 * would mean nobody could develop against this.
	 */
	private static function transport_is_safe(): bool {
		if ( is_ssl() ) {
			return true;
		}

		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
			return true;
		}
		foreach ( [ '.local', '.test', '.localhost', '.internal' ] as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	private static function insecure_transport(): \WP_REST_Response {
		return self::error(
			'invalid_request',
			'This site is served over plain HTTP. Tokens are bearer credentials and would be readable by anything on the network, so none will be issued. Serve the site over HTTPS.',
			400
		);
	}

	private static function clients_with_tokens(): array {
		$in_use = [];
		$users  = get_users( [
			'meta_key'     => self::TOKEN_META,
			'meta_compare' => 'EXISTS',
			'fields'       => 'ID',
			'number'       => 100,
		] );
		$now = time();
		foreach ( $users as $uid ) {
			foreach ( self::tokens( (int) $uid ) as $t ) {
				if ( (int) ( $t['refresh_expires'] ?? 0 ) > $now && ! empty( $t['client_id'] ) ) {
					$in_use[ (string) $t['client_id'] ] = true;
				}
			}
		}
		return $in_use;
	}

	private static function throttled(): bool {
		$ip = '';
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$candidate = trim( explode( ',', (string) $_SERVER[ $k ] )[0] );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}
		if ( '' === $ip ) {
			return false; // Nothing to count against; refusing everyone is worse.
		}

		$key   = 'nzwp_thr_' . hash( 'sha256', $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::THROTTLE_MAX ) {
			return true;
		}
		set_transient( $key, $count + 1, self::THROTTLE_WINDOW );
		return false;
	}

	private static function device_key( string $device_code ): string {
		return 'nzwp_dev_' . hash( 'sha256', $device_code );
	}

	private static function code_key( string $user_code ): string {
		return 'nzwp_uc_' . hash( 'sha256', $user_code );
	}

	/** The error shape RFC 6749 asks for, which is what the client parses. */
	private static function error( string $code, string $description, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			[ 'error' => $code, 'error_description' => $description ],
			$status
		);
	}
	/* --------------------------------------------------- browser grant */

	/*
	 * The device grant exists because a terminal has no browser: a code is read
	 * off the screen and typed into wp-admin. A connector is the other case -
	 * it is already in a browser and has nowhere to show a code - so it needs
	 * the redirect grant instead. Same approval, same tokens, different way of
	 * getting the person in front of the question.
	 */

	/**
	 * The redirect addresses a client is allowed to name, checked and cleaned.
	 *
	 * @param mixed $raw Whatever arrived as redirect_uris.
	 * @return string[]|\WP_Error
	 */
	private static function clean_redirect_uris( mixed $raw ) {
		if ( null === $raw || '' === $raw ) {
			return [];
		}
		if ( ! is_array( $raw ) ) {
			$raw = [ $raw ];
		}
		if ( count( $raw ) > 5 ) {
			return new \WP_Error( 'too_many', 'At most five redirect_uris.' );
		}

		$out = [];
		foreach ( $raw as $uri ) {
			$uri   = trim( (string) $uri );
			$parts = wp_parse_url( $uri );

			if ( ! $uri || ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return new \WP_Error( 'bad_uri', sprintf( '"%s" is not an absolute URL.', $uri ) );
			}
			if ( ! empty( $parts['fragment'] ) ) {
				return new \WP_Error( 'bad_uri', 'A redirect_uri may not carry a fragment.' );
			}

			/*
			 * https everywhere, with one exception: a client running on the
			 * same machine as the browser, which cannot have a certificate.
			 *
			 * "localhost" is accepted alongside the IP literals. RFC 8252
			 * prefers the literals, because a name can be pointed elsewhere by
			 * DNS or a hosts file - but every client in practice registers
			 * http://localhost:PORT/callback, and refusing it means refusing
			 * them all. Anyone who can rewrite the hosts file on the machine
			 * the browser is running on has already won.
			 */
			$loopback = in_array( strtolower( (string) $parts['host'] ), [ 'localhost', '127.0.0.1', '[::1]', '::1' ], true );
			if ( 'https' !== strtolower( (string) $parts['scheme'] ) && ! ( 'http' === strtolower( (string) $parts['scheme'] ) && $loopback ) ) {
				return new \WP_Error( 'bad_uri', sprintf( '"%s" must be https, or http on 127.0.0.1.', $uri ) );
			}

			$out[] = $uri;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * RFC 6749 step one for the browser grant.
	 *
	 * Sends the person to wp-admin to answer the question, having first checked
	 * everything that can be checked without them. What cannot be answered
	 * safely is answered here rather than at the redirect address: an unknown
	 * client or an unregistered redirect_uri must never be reported by
	 * redirecting to it, or the endpoint becomes a way to bounce a browser
	 * anywhere.
	 */
	public static function authorize( \WP_REST_Request $r ) {
		if ( ! self::transport_is_safe() ) {
			return self::insecure_transport();
		}
		if ( ! Settings::active() ) {
			return self::error( 'access_denied', 'Abilities are switched off on this site.', 403 );
		}

		$client_id = (string) $r->get_param( 'client_id' );
		$client    = self::clients()[ $client_id ] ?? null;
		if ( ! $client ) {
			return self::error( 'invalid_client', 'Unknown client_id. Register first.', 400 );
		}

		$redirect_uri = (string) $r->get_param( 'redirect_uri' );
		$registered   = (array) ( $client['redirect_uris'] ?? [] );
		if ( ! $registered ) {
			return self::error( 'invalid_request', 'This client registered no redirect_uris, so it cannot use the browser grant.', 400 );
		}
		if ( ! in_array( $redirect_uri, $registered, true ) ) {
			return self::error( 'invalid_request', 'That redirect_uri is not one this client registered.', 400 );
		}

		// From here on the client and its address are known, so a problem can
		// safely be reported by redirecting - which is what a client expects.
		$state = (string) $r->get_param( 'state' );

		if ( 'code' !== (string) $r->get_param( 'response_type' ) ) {
			return self::bounce( $redirect_uri, [ 'error' => 'unsupported_response_type' ], $state );
		}

		$challenge = (string) $r->get_param( 'code_challenge' );
		$method    = (string) ( $r->get_param( 'code_challenge_method' ) ?: 'plain' );
		if ( '' === $challenge || 'S256' !== $method ) {
			return self::bounce(
				$redirect_uri,
				[ 'error' => 'invalid_request', 'error_description' => 'code_challenge with code_challenge_method=S256 is required.' ],
				$state
			);
		}

		$request_id = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::authz_key( $request_id ),
			[
				'client_id'    => $client_id,
				'redirect_uri' => $redirect_uri,
				'challenge'    => $challenge,
				'state'        => $state,
				'created'      => time(),
			],
			self::AUTHZ_TTL
		);

		$screen = add_query_arg( 'authorize', $request_id, admin_url( 'admin.php?page=niranzwp-connect' ) );

		// Not logged in, or logged in as someone who cannot manage the site:
		// let WordPress ask, and come back here afterwards.
		if ( ! is_user_logged_in() || ! current_user_can( CAPABILITY ) ) {
			$screen = wp_login_url( $screen );
		}

		return self::redirect( $screen );
	}

	/** What the approval screen needs to describe a pending browser request. */
	public static function pending_authorization( string $request_id ): ?array {
		$record = get_transient( self::authz_key( $request_id ) );
		if ( ! is_array( $record ) ) {
			return null;
		}
		$host = (string) wp_parse_url( (string) $record['redirect_uri'], PHP_URL_HOST );
		return [
			'client_name' => self::clients()[ $record['client_id'] ]['name'] ?? 'Unknown client',
			'returns_to'  => $host,
			'requested'   => (int) $record['created'],
		];
	}

	/**
	 * Called from the admin screen once a person has said yes or no.
	 *
	 * Returns the address to send the browser to, which is the client's own
	 * redirect_uri either way: a refusal is an answer the client is waiting
	 * for, not a dead end.
	 *
	 * @param string $request_id From the authorize redirect.
	 * @param int    $user_id    Who approved.
	 * @param bool   $allow      Whether they said yes.
	 * @return string|null Null when the request is unknown or expired.
	 */
	public static function settle_authorization( string $request_id, int $user_id, bool $allow ): ?string {
		$record = get_transient( self::authz_key( $request_id ) );
		if ( ! is_array( $record ) ) {
			return null;
		}
		delete_transient( self::authz_key( $request_id ) );

		$state = (string) $record['state'];

		if ( ! $allow ) {
			return self::redirect_url( $record['redirect_uri'], [ 'error' => 'access_denied' ], $state );
		}
		if ( ! user_can( $user_id, CAPABILITY ) ) {
			return self::redirect_url( $record['redirect_uri'], [ 'error' => 'access_denied' ], $state );
		}

		$code = bin2hex( random_bytes( 32 ) );
		set_transient(
			self::code_exchange_key( $code ),
			[
				'client_id'    => $record['client_id'],
				'redirect_uri' => $record['redirect_uri'],
				'challenge'    => $record['challenge'],
				'user_id'      => $user_id,
			],
			self::CODE_TTL
		);

		return self::redirect_url( $record['redirect_uri'], [ 'code' => $code ], $state );
	}

	/** RFC 6749 step two: the code, plus proof the caller is who asked for it. */
	private static function token_from_code( \WP_REST_Request $r ) {
		$code      = (string) $r->get_param( 'code' );
		$client_id = (string) $r->get_param( 'client_id' );
		$verifier  = (string) $r->get_param( 'code_verifier' );

		$record = $code ? get_transient( self::code_exchange_key( $code ) ) : false;

		// One answer for unknown, expired, and belonging to another client, so
		// this cannot be used to learn which codes existed.
		if ( ! is_array( $record ) || $record['client_id'] !== $client_id ) {
			return self::error( 'invalid_grant', 'That code is unknown or has expired. Start again.', 400 );
		}

		// One code, one exchange - whatever happens next.
		delete_transient( self::code_exchange_key( $code ) );

		if ( (string) $r->get_param( 'redirect_uri' ) !== $record['redirect_uri'] ) {
			return self::error( 'invalid_grant', 'redirect_uri does not match the one the code was issued for.', 400 );
		}

		/*
		 * PKCE. The code travelled in a URL - through the address bar, the
		 * browser's history, and anything watching either - so the code alone
		 * is not proof. The verifier never left the client.
		 */
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		if ( '' === $verifier || ! hash_equals( (string) $record['challenge'], $expected ) ) {
			return self::error( 'invalid_grant', 'code_verifier does not match the challenge this code was issued for.', 400 );
		}

		$user_id = (int) $record['user_id'];
		if ( ! $user_id || ! user_can( $user_id, CAPABILITY ) ) {
			return self::error( 'invalid_grant', 'The approving user can no longer manage this site.', 403 );
		}

		return self::issue( $user_id, $client_id );
	}

	/* ------------------------------------------------------ small helpers */

	/**
	 * A redirect back to a client, as a URL.
	 *
	 * @param string               $base  The client's registered redirect_uri.
	 * @param array<string,string> $args  What to report.
	 * @param string               $state Echoed back untouched, per the spec.
	 */
	private static function redirect_url( string $base, array $args, string $state ): string {
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		return add_query_arg( array_map( 'rawurlencode', $args ), $base );
	}

	/** The same, as a response. */
	private static function bounce( string $base, array $args, string $state ): \WP_REST_Response {
		return self::redirect( self::redirect_url( $base, $args, $state ) );
	}

	private static function redirect( string $to ): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $to );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function authz_key( string $request_id ): string {
		return 'nzwp_authz_' . hash( 'sha256', $request_id );
	}

	private static function code_exchange_key( string $code ): string {
		return 'nzwp_code_' . hash( 'sha256', $code );
	}
}
