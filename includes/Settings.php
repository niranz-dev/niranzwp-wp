<?php
/**
 * Stored settings for NiranzWP.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/** @return array{enabled:bool,files:bool,runtime:bool} */
	public static function all(): array {
		$saved = is_array( get_option( OPTION_KEY, [] ) ) ? get_option( OPTION_KEY, [] ) : [];
		return [
			'enabled' => (bool) ( $saved['enabled'] ?? false ),
			// Filesystem access is a separate decision from the read-mostly
			// abilities, so it never rides along with the main switch.
			'files'   => (bool) ( $saved['files'] ?? false ),
			// Evaluating PHP is a bigger decision again, so it gets its own
			// switch rather than riding along with filesystem access.
			'runtime' => (bool) ( $saved['runtime'] ?? false ),
		];
	}

	public static function files_enabled(): bool {
		return self::all()['files'];
	}

	public static function runtime_enabled(): bool {
		return self::all()['runtime'];
	}

	/**
	 * Whether deleting the plugin should also take the owner's own content -
	 * the site brief, the design notes, the skills, the checkpoints.
	 *
	 * Off unless asked. Somebody wrote those, and removing a plugin is not
	 * consent to destroy them; a reinstall should find them waiting.
	 */
	public static function purge_on_delete(): bool {
		$saved = is_array( get_option( OPTION_KEY, [] ) ) ? get_option( OPTION_KEY, [] ) : [];
		return (bool) ( $saved['purge_on_delete'] ?? false );
	}

	public static function set_purge_on_delete( bool $on ): void {
		$s                    = get_option( OPTION_KEY, [] );
		$s                    = is_array( $s ) ? $s : [];
		$s['purge_on_delete'] = $on;
		update_option( OPTION_KEY, $s );
	}

	public static function set_runtime( bool $on ): void {
		$s            = get_option( OPTION_KEY, [] );
		$s            = is_array( $s ) ? $s : [];
		$s['runtime'] = $on;
		update_option( OPTION_KEY, $s );
	}

	public static function set_files( bool $on ): void {
		$s          = get_option( OPTION_KEY, [] );
		$s          = is_array( $s ) ? $s : [];
		$s['files'] = $on;
		update_option( OPTION_KEY, $s );
	}

	public static function enabled(): bool {
		return self::all()['enabled'];
	}

	public static function set_enabled( bool $on ): void {
		$settings            = self::all();
		$settings['enabled'] = $on;
		update_option( OPTION_KEY, $settings );
	}

	/**
	 * Abilities are locked to the host they were switched on for. Restoring a
	 * database onto another domain must not silently carry access with it.
	 */
	public static function domain_matches(): bool {
		$saved = get_option( OPTION_KEY . '_domain' );
		return ! $saved || $saved === self::current_domain();
	}

	public static function remember_domain(): void {
		update_option( OPTION_KEY . '_domain', self::current_domain() );
	}

	public static function current_domain(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/** Abilities only run when switched on, on the right domain, for an admin. */
	public static function active(): bool {
		return self::enabled() && self::domain_matches();
	}
}
