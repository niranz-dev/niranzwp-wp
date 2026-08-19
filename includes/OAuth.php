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
	private const ACCESS_TTL   = 3600;   // one hour
	private const REFRESH_TTL  = 2592000; // thirty days
	private const INTERVAL     = 5;
	private const MAX_CLIENTS  = 50;
	private const MAX_TOKENS   = 20;

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
		add_filter( 'determine_current_user', [ self::class, 'authenticate' ], 30 );
	}

	/* ------------------------------------------------------------- metadata */

	/**
	 * RFC 8414 asks for this at the site root, which is outside the REST
	 * namespace, so it is answered before WordPress decides what the request
	 * was for. Public on purpose: a client has to be able to read it before it
	 * has any credential at all.
	 */
	public static function metadata_document(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '/.well-known/oauth-authorization-server' !== untrailingslashit( $path ) ) {
			return;
		}

		wp_send_json(
			[
				'issuer'                                => untrailingslashit( home_url() ),
				'registration_endpoint'                 => rest_url( self::NS . '/oauth/register' ),
				'device_authorization_endpoint'         => rest_url( self::NS . '/oauth/device' ),
				'token_endpoint'                        => rest_url( self::NS . '/oauth/token' ),
				'grant_types_supported'                 => [
					'urn:ietf:params:oauth:grant-type:device_code',
					'refresh_token',
				],
				'token_endpoint_auth_methods_supported' => [ 'none' ],
				'scopes_supported'                      => [ 'abilities' ],
				'service_documentation'                 => 'https://niranz.dev',
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
		if ( ! Settings::active() ) {
			return self::error( 'access_denied', 'Abilities are switched off on this site.', 403 );
		}

		$name = sanitize_text_field( (string) ( $r->get_param( 'client_name' ) ?: 'Unnamed client' ) );
		$name = mb_substr( $name, 0, 80 );

		$clients = self::clients();

		// A registration endpoint anyone can post to is a row-creation endpoint
		// anyone can post to. The cap is the backstop; the oldest go first so a
		// real client is never locked out by junk.
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			$clients = array_slice( $clients, -( self::MAX_CLIENTS - 10 ), null, true );
		}

		$client_id = 'nzwp_' . bin2hex( random_bytes( 16 ) );

		$clients[ $client_id ] = [
			'name'    => $name,
			'created' => time(),
		];
		update_option( self::CLIENTS, $clients, false );

		return new \WP_REST_Response(
			[
				'client_id'                 => $client_id,
				'client_name'               => $name,
				'client_id_issued_at'       => time(),
				'grant_types'               => [
					'urn:ietf:params:oauth:grant-type:device_code',
					'refresh_token',
				],
				'token_endpoint_auth_method' => 'none',
			],
			201
		);
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
		// The typed code has to find the request; it is the only thing the
		// person carries between the terminal and the browser.
		set_transient( self::code_key( $user_code ), $device_code, self::DEVICE_TTL );

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
		$user_code   = self::normalise_code( $user_code );
		$device_code = get_transient( self::code_key( $user_code ) );
		if ( ! is_string( $device_code ) || '' === $device_code ) {
			return false;
		}

		$record = get_transient( self::device_key( $device_code ) );
		if ( ! is_array( $record ) ) {
			return false;
		}

		$record['approved'] = 1;
		$record['user_id']  = $user_id;

		// Whatever is left of the original ten minutes, not a fresh ten: the
		// window is for the person to approve in, and it has just been used.
		$left = max( 30, self::DEVICE_TTL - ( time() - (int) $record['created'] ) );
		set_transient( self::device_key( $device_code ), $record, $left );
		delete_transient( self::code_key( $user_code ) );

		return true;
	}

	/** What the admin screen shows before asking anyone to approve anything. */
	public static function pending( string $user_code ): ?array {
		$device_code = get_transient( self::code_key( self::normalise_code( $user_code ) ) );
		if ( ! is_string( $device_code ) ) {
			return null;
		}
		$record = get_transient( self::device_key( $device_code ) );
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
		$grant = (string) $r->get_param( 'grant_type' );

		if ( 'urn:ietf:params:oauth:grant-type:device_code' === $grant ) {
			return self::token_from_device( $r );
		}
		if ( 'refresh_token' === $grant ) {
			return self::token_from_refresh( $r );
		}
		return self::error( 'unsupported_grant_type', 'Supported: device_code, refresh_token.', 400 );
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

		// Rotate. A refresh token that survives its own use is a credential with
		// no expiry wearing a different hat.
		self::forget_token( $user_id, $index );

		return self::issue( $user_id, $client_id );
	}

	/**
	 * Mint a pair and keep only their hashes.
	 *
	 * Same reasoning as WordPress's own application passwords: the site never
	 * needs the plaintext again, so it should not be able to hand it over.
	 */
	private static function issue( int $user_id, string $client_id ) {
		$access  = bin2hex( random_bytes( 32 ) );
		$refresh = bin2hex( random_bytes( 32 ) );

		$tokens = self::tokens( $user_id );

		// Drop anything already dead, then cap. Without this the meta row grows
		// for the life of the site.
		$now    = time();
		$tokens = array_values( array_filter(
			$tokens,
			static fn( array $t ): bool => (int) ( $t['refresh_expires'] ?? 0 ) > $now
		) );
		if ( count( $tokens ) >= self::MAX_TOKENS ) {
			$tokens = array_slice( $tokens, -( self::MAX_TOKENS - 1 ) );
		}

		$tokens[] = [
			'client_id'       => $client_id,
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

		// Cheap enough to be worth having on the Connections screen, and the
		// only way to tell a live client from an abandoned one.
		$tokens = self::tokens( $owner );
		if ( isset( $tokens[ $index ] ) ) {
			$tokens[ $index ]['last_used'] = time();
			update_user_meta( $owner, self::TOKEN_META, $tokens );
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
		$out     = [];

		foreach ( self::list_tokens( $user_id ) as $t ) {
			$client_id = (string) ( $t['client_id'] ?? '' );
			$out[]     = [
				'client_id' => $client_id,
				'name'      => (string) ( $clients[ $client_id ]['name'] ?? 'Unnamed client' ),
				'created'   => (int) ( $t['created'] ?? 0 ),
				'last_used' => (int) ( $t['last_used'] ?? 0 ),
				'expires'   => (int) ( $t['refresh_expires'] ?? 0 ),
			];
		}

		usort( $out, static fn( array $a, array $b ): int => $b['created'] <=> $a['created'] );
		return $out;
	}

	/**
	 * Revoke one connection.
	 *
	 * Matched on the client id rather than on a position in the array, because
	 * the list is filtered and re-sorted before it reaches the screen and a row
	 * index there means nothing here. Returns how many grants went, since one
	 * client that connected twice holds two.
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
}
