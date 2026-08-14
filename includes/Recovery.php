<?php
/**
 * Fatal-error recovery.
 *
 * A checkpoint can only be restored through a working site. If a write leaves
 * the site unable to boot, every ability -- including checkpoint-restore -- is
 * unreachable, and the undo that was supposed to make writing safe is exactly
 * the thing that no longer works.
 *
 * So the guard has to run before the plugin does. It is a must-use plugin: WP
 * loads mu-plugins before regular plugins and before the theme, so this is in
 * place no matter what broke afterwards.
 *
 * WordPress 5.2's own fatal-error protection pauses a plugin or theme that
 * fatals during load and emails a recovery link. That covers a lot, but not a
 * fatal in a page template, which only breaks the pages using it, and it does
 * not put the previous file contents back. This does.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Recovery {

	private const MU_FILE = 'niranzwp-guard.php';
	private const OPTION  = 'niranzwp_guard_installed';

	public static function init(): void {
		add_action( 'admin_notices', [ self::class, 'notice' ] );
		add_action( 'admin_post_niranzwp_guard_clear', [ self::class, 'handle_clear' ] );
	}

	private static function mu_dir(): string {
		return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	}

	public static function mu_path(): string {
		return self::mu_dir() . '/' . self::MU_FILE;
	}

	public static function installed(): bool {
		return file_exists( self::mu_path() );
	}

	/* ------------------------------------------------------------- install */

	/**
	 * Write the guard. Called when the filesystem abilities are switched on,
	 * because that is the moment this plugin gains the ability to break the
	 * site in a way it cannot then undo.
	 *
	 * @return true|\WP_Error
	 */
	public static function install() {
		$dir = self::mu_dir();

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'niranzwp_mu_dir', 'Could not create the mu-plugins directory.' );
		}
		if ( false === file_put_contents( self::mu_path(), self::guard_source() ) ) {
			return new \WP_Error( 'niranzwp_mu_write', 'Could not write the recovery guard.' );
		}

		update_option( self::OPTION, VERSION, false );
		return true;
	}

	public static function uninstall(): void {
		if ( self::installed() ) {
			@unlink( self::mu_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		delete_option( self::OPTION );
	}

	/**
	 * The guard itself.
	 *
	 * Deliberately standalone: it runs when the site is already broken, so it
	 * cannot depend on this plugin having loaded, and it does the smallest
	 * possible amount of work on a healthy request.
	 */
	private static function guard_source(): string {
		return <<<'PHP'
<?php
/**
 * Plugin Name: NiranzWP recovery guard
 * Description: Detects a fatal error caused by a NiranzWP file write and puts the previous contents back. Written automatically; safe to delete when NiranzWP is not installed.
 *
 * Loaded as a must-use plugin, so it is in place before the plugins and the
 * theme that a bad write may have broken.
 */

defined( 'ABSPATH' ) || exit;

/*
 * A write leaves a marker here naming the file and its previous contents.
 *
 * The request that does the writing proves nothing: a REST call can write a
 * broken theme file and return 200, because it never loads what it just
 * wrote. The next request that does load it is the one that finds out.
 *
 * That verdict has to come from PHP, not from WordPress. The `shutdown` action
 * still fires after a fatal error -- core registers it through
 * register_shutdown_function, and PHP runs those even when the request died --
 * so reaching it proves nothing either. error_get_last() is what actually
 * distinguishes a request that finished from one that was killed.
 */
$niranzwp_pending = WP_CONTENT_DIR . '/niranzwp-pending.json';

if ( file_exists( $niranzwp_pending ) ) {
	$niranzwp_record = json_decode( (string) file_get_contents( $niranzwp_pending ), true );
	$niranzwp_path   = is_array( $niranzwp_record ) ? (string) ( $niranzwp_record['path'] ?? '' ) : '';
	$niranzwp_base   = (string) realpath( ABSPATH );
	$niranzwp_dir    = '' !== $niranzwp_path ? realpath( dirname( $niranzwp_path ) ) : false;

	if ( ! $niranzwp_dir || ! str_starts_with( $niranzwp_dir . '/', $niranzwp_base . '/' ) || ! array_key_exists( 'before', $niranzwp_record ) ) {
		@unlink( $niranzwp_pending );
	} else {
		register_shutdown_function( static function () use ( $niranzwp_pending, $niranzwp_record, $niranzwp_path ): void {
			$error = error_get_last();
			$fatal = null !== $error && ( $error['type'] & ( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR ) );

			if ( ! $fatal ) {
				// The request finished. Whatever was written is at least
				// loadable, so stop watching it.
				@unlink( $niranzwp_pending );
				return;
			}

			// Put the previous contents back before the next request loads the
			// same file and dies the same way.
			if ( null === $niranzwp_record['before'] ) {
				@unlink( $niranzwp_path );
			} else {
				@file_put_contents( $niranzwp_path, (string) $niranzwp_record['before'] );
			}

			@file_put_contents(
				WP_CONTENT_DIR . '/niranzwp-recovered.json',
				(string) json_encode( [
					'path'      => $niranzwp_path,
					'recovered' => gmdate( 'c' ),
					'error'     => $error['message'] ?? '',
					'line'      => $error['line'] ?? 0,
					'reason'    => 'A request that loaded this file died, so its previous contents were put back.',
				] )
			);

			@unlink( $niranzwp_pending );
		} );
	}
}
PHP;
	}

	/* -------------------------------------------------------------- record */

	/**
	 * Note what is about to be overwritten, so the guard can put it back if
	 * this request is the last one the site manages.
	 */
	public static function arm( string $abs_path, ?string $before ): void {
		if ( ! self::installed() ) {
			return;
		}
		@file_put_contents( // phpcs:ignore WordPress.PHP.NoSilencedErrors
			WP_CONTENT_DIR . '/niranzwp-pending.json',
			(string) wp_json_encode( [
				'path'     => $abs_path,
				'before'   => $before,
				'at'       => gmdate( 'c' ),
				// The request doing the writing never loads what it wrote, so
				// it does not count as an attempt.
				'attempts' => 0,
			] )
		);
	}

	/** The write went through and the request is still alive. */
	public static function disarm(): void {
		$pending = WP_CONTENT_DIR . '/niranzwp-pending.json';
		if ( file_exists( $pending ) ) {
			@unlink( $pending ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/* -------------------------------------------------------------- notice */

	/** @return array<string,mixed>|null */
	public static function last_recovery(): ?array {
		$file = WP_CONTENT_DIR . '/niranzwp-recovered.json';
		if ( ! file_exists( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	public static function notice(): void {
		$recovery = self::last_recovery();
		if ( ! $recovery || ! current_user_can( CAPABILITY ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'NiranzWP put a file back.', 'niranzwp' ); ?></strong>
				<?php
				printf(
					/* translators: 1: file path, 2: time */
					esc_html__( 'The site did not survive the request that wrote %1$s, so its previous contents were restored on the next load (%2$s).', 'niranzwp' ),
					'<code>' . esc_html( str_replace( ABSPATH, '', (string) ( $recovery['path'] ?? '' ) ) ) . '</code>',
					esc_html( (string) ( $recovery['recovered'] ?? '' ) )
				);
				?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=niranzwp_guard_clear' ), 'niranzwp_guard' ) ); ?>">
					<?php esc_html_e( 'Dismiss', 'niranzwp' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public static function handle_clear(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( 'niranzwp_guard' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}
		@unlink( WP_CONTENT_DIR . '/niranzwp-recovered.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
