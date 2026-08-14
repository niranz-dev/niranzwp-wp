<?php
/**
 * Self-hosted updates.
 *
 * The plugin is not on wordpress.org, so nothing tells a site that a new
 * version exists. Without this, every install silently stays on whatever
 * version it was downloaded at -- including installs sitting on a release with
 * a bug in it.
 *
 * A manifest is fetched from a URL, cached, and compared to the running
 * version. When it is newer, WordPress shows the update in the usual place and
 * installs it through the usual machinery.
 *
 * An updater is also a supply chain: whoever controls the manifest controls
 * what runs on every install. So the manifest must be HTTPS, and the ZIP is
 * verified against a SHA-256 in the manifest before WordPress is allowed to
 * unpack it. That does not defend against the manifest itself being replaced
 * -- that would need a signing key, which is a bigger piece of work than this
 * -- but it does mean a tampered download is caught.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Updater {

	private const MANIFEST  = 'https://niranz.dev/niranzwp/plugin.json';
	private const TRANSIENT = 'niranzwp_update_manifest';
	private const TTL       = 12 * HOUR_IN_SECONDS;

	public static function init(): void {
		add_filter( 'site_transient_update_plugins', [ self::class, 'offer' ] );
		add_filter( 'upgrader_pre_download', [ self::class, 'verified_download' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ self::class, 'forget' ], 10, 0 );
		add_action( 'niranzwp_force_update_check', [ self::class, 'forget' ] );
	}

	public static function forget(): void {
		delete_site_transient( self::TRANSIENT );
	}

	private static function url(): string {
		/**
		 * Filter the manifest URL, for anyone hosting their own build.
		 *
		 * @param string $url
		 */
		return (string) apply_filters( 'niranzwp_update_manifest_url', self::MANIFEST );
	}

	/**
	 * The manifest, cached. Returns null on anything unexpected -- a failed
	 * update check must never be visible as a broken admin screen.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function manifest( bool $fresh = false ): ?array {
		if ( ! $fresh ) {
			$cached = get_site_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached['ok'] ? $cached['data'] : null;
			}
		}

		$url = self::url();
		if ( ! str_starts_with( strtolower( $url ), 'https://' ) ) {
			return null;
		}

		$response = wp_remote_get( $url, [
			'timeout' => 8,
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		$data = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) && ! empty( $decoded['version'] ) && ! empty( $decoded['download_url'] ) ) {
				$data = $decoded;
			}
		}

		// Cache the failure too, at a shorter life, so an unreachable host is
		// not retried on every single admin page load.
		set_site_transient(
			self::TRANSIENT,
			[ 'ok' => null !== $data, 'data' => $data ],
			null !== $data ? self::TTL : HOUR_IN_SECONDS
		);

		return $data;
	}

	/**
	 * Tell WordPress an update is available.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public static function offer( $transient ) {
		if ( ! is_object( $transient ) || ! isset( $transient->response ) ) {
			return $transient;
		}

		$m = self::manifest();
		if ( ! $m || version_compare( (string) $m['version'], VERSION, '<=' ) ) {
			return $transient;
		}

		$download = (string) $m['download_url'];
		if ( ! str_starts_with( strtolower( $download ), 'https://' ) ) {
			return $transient;
		}

		$file = plugin_basename( NIRANZWP_FILE );

		$transient->response[ $file ] = (object) [
			'slug'         => 'niranzwp',
			'plugin'       => $file,
			'new_version'  => (string) $m['version'],
			'package'      => $download,
			'url'          => (string) ( $m['homepage'] ?? 'https://niranz.dev' ),
			'requires'     => (string) ( $m['requires'] ?? '6.9' ),
			'requires_php' => (string) ( $m['requires_php'] ?? '8.0' ),
			'tested'       => (string) ( $m['tested'] ?? '' ),
		];

		return $transient;
	}

	/**
	 * Download the package ourselves so the bytes can be checked before
	 * WordPress unpacks them.
	 *
	 * Returning a path short-circuits WP_Upgrader's own download. Returning
	 * false lets it proceed as normal, which is what happens for every other
	 * plugin's update.
	 *
	 * @param mixed  $reply
	 * @param string $package
	 * @param mixed  $upgrader
	 * @return mixed
	 */
	public static function verified_download( $reply, $package, $upgrader ) {
		$m = self::manifest();
		if ( ! $m || ! isset( $m['download_url'] ) || $package !== $m['download_url'] ) {
			return $reply;
		}

		$expected = strtolower( trim( (string) ( $m['sha256'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
			return new \WP_Error(
				'niranzwp_no_checksum',
				__( 'The update manifest has no SHA-256 for this release, so the download cannot be verified. Update refused.', 'niranzwp' )
			);
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$actual = hash_file( 'sha256', $file );
		if ( ! hash_equals( $expected, (string) $actual ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error(
				'niranzwp_checksum_mismatch',
				sprintf(
					/* translators: 1: expected hash, 2: actual hash */
					__( 'The downloaded update does not match the checksum in the manifest (expected %1$s, got %2$s). Update refused.', 'niranzwp' ),
					substr( $expected, 0, 12 ) . '…',
					substr( (string) $actual, 0, 12 ) . '…'
				)
			);
		}

		return $file;
	}

	/* ---------------------------------------------------------- diagnostics */

	/**
	 * What the update check currently knows, for the Troubleshoot screen.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$m = self::manifest();

		return [
			'manifest_url' => self::url(),
			'reachable'    => null !== $m,
			'installed'    => VERSION,
			'available'    => $m['version'] ?? null,
			'update_ready' => $m && version_compare( (string) $m['version'], VERSION, '>' ),
			'verified'     => $m && preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) ( $m['sha256'] ?? '' ) ) ) === 1,
		];
	}
}
