<?php
/**
 * Ask about the owner's content at the moment they are leaving, not months before.
 *
 * Whether deleting the plugin also destroys the site brief, the design notes,
 * the skills and every checkpoint used to be a checkbox on the settings screen.
 * That is the wrong place for it: it is answered while thinking about something
 * else entirely, and read back much later by an uninstaller nobody is watching.
 * Somebody could tick it in August and lose their work in November without ever
 * connecting the two.
 *
 * WordPress makes you deactivate before you can delete, so the click on
 * Deactivate is the last moment a person is present and paying attention. The
 * question belongs there, with the consequence written next to it.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Deactivate {

	private const ACTION = 'niranzwp_deactivate_choice';

	public static function init(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( NIRANZWP_FILE ), [ self::class, 'tag_link' ] );
		add_action( 'admin_footer-plugins.php', [ self::class, 'dialog' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'record' ] );
	}

	/**
	 * Mark our own Deactivate link so the script can find it.
	 *
	 * Found by attribute rather than by position: the array of links is filtered
	 * by other plugins too, and "the second one" stops being deactivate the day
	 * one of them adds a Settings link.
	 *
	 * @param array<string,string> $links Row action links.
	 * @return array<string,string>
	 */
	public static function tag_link( array $links ): array {
		if ( isset( $links['deactivate'] ) ) {
			$links['deactivate'] = str_replace(
				'<a ',
				'<a data-niranzwp-deactivate="1" ',
				$links['deactivate']
			);
		}
		return $links;
	}

	/** Record the answer, then continue to the deactivation WordPress was about to do. */
	public static function record(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to deactivate plugins.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );

		Settings::set_purge_on_delete( '1' === ( $_POST['purge'] ?? '0' ) );

		// Where to go next was signed by WordPress when it built the row action,
		// so it is trustworthy - but only as far as this site.
		$next = wp_validate_redirect(
			wp_unslash( (string) ( $_POST['next'] ?? '' ) ),
			admin_url( 'plugins.php' )
		);
		wp_safe_redirect( $next );
		exit;
	}

	/** The dialog itself, printed only on the Plugins screen. */
	public static function dialog(): void {
		$purge = Settings::purge_on_delete();
		?>
		<div id="nzwp-bye" hidden>
			<div class="nzwp-bye-sheet" role="dialog" aria-modal="true" aria-labelledby="nzwp-bye-title">
				<div class="nzwp-bye-head">
					<h2 id="nzwp-bye-title"><?php esc_html_e( 'Before NiranzWP goes', 'niranzwp' ); ?></h2>
				</div>
				<div class="nzwp-bye-body">
					<p><?php esc_html_e( 'Deactivating stops every connected tool and removes the recovery guard. Nothing of yours is touched.', 'niranzwp' ); ?></p>
					<p><?php esc_html_e( 'If you go on to delete the plugin, what should happen to the work that is yours - the site brief, the design notes, your skills and every snapshot?', 'niranzwp' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::ACTION ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<input type="hidden" name="next" id="nzwp-bye-next" value="">

						<label class="nzwp-bye-opt">
							<input type="radio" name="purge" value="0" <?php checked( ! $purge ); ?>>
							<span>
								<b><?php esc_html_e( 'Keep it', 'niranzwp' ); ?></b>
								<?php esc_html_e( 'A reinstall finds everything waiting.', 'niranzwp' ); ?>
							</span>
						</label>

						<label class="nzwp-bye-opt nzwp-bye-danger">
							<input type="radio" name="purge" value="1" <?php checked( $purge ); ?>>
							<span>
								<b><?php esc_html_e( 'Delete it with the plugin', 'niranzwp' ); ?></b>
								<?php esc_html_e( 'Gone for good on delete. There is no undo.', 'niranzwp' ); ?>
							</span>
						</label>

						<div class="nzwp-bye-foot">
							<button type="button" class="button" id="nzwp-bye-cancel"><?php esc_html_e( 'Cancel', 'niranzwp' ); ?></button>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Deactivate', 'niranzwp' ); ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>

		<style>
		#nzwp-bye{position:fixed;inset:0;z-index:160000;display:flex;align-items:center;justify-content:center;
			background:rgba(15,10,30,.55);backdrop-filter:saturate(140%) blur(3px)}
		#nzwp-bye[hidden]{display:none}
		.nzwp-bye-sheet{width:min(520px,calc(100vw - 40px));background:#fff;border-radius:10px;overflow:hidden;
			box-shadow:0 24px 60px rgba(20,10,50,.35)}
		.nzwp-bye-head{padding:18px 24px;color:#fff;
			background:linear-gradient(115deg,#2e1065 0%,#5b21b6 48%,#7c3aed 100%)}
		.nzwp-bye-head h2{margin:0;font-size:17px;font-weight:600;color:#fff}
		.nzwp-bye-body{padding:20px 24px 22px}
		.nzwp-bye-body p{margin:0 0 12px;color:#3c434a;font-size:13.5px;line-height:1.6}
		.nzwp-bye-opt{display:flex;gap:11px;align-items:flex-start;padding:12px 14px;margin:10px 0;
			border:1px solid #dcdcde;border-radius:8px;cursor:pointer;transition:border-color .12s,background .12s}
		.nzwp-bye-opt:hover{border-color:#7c3aed;background:#faf8ff}
		.nzwp-bye-opt input{margin:2px 0 0}
		.nzwp-bye-opt b{display:block;font-size:13.5px;color:#1d2327}
		.nzwp-bye-opt span{font-size:12.5px;color:#646970;line-height:1.5}
		.nzwp-bye-danger:hover{border-color:#d63638;background:#fff6f6}
		.nzwp-bye-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}
		</style>

		<script>
		( function () {
			var box  = document.getElementById( 'nzwp-bye' );
			var next = document.getElementById( 'nzwp-bye-next' );
			var link = document.querySelector( 'a[data-niranzwp-deactivate]' );
			if ( ! box || ! link ) { return; }

			function close() { box.hidden = true; }

			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				// Carry WordPress's own nonced deactivation URL through, so the
				// answer is recorded and the deactivation still happens exactly
				// as it would have.
				next.value = link.getAttribute( 'href' );
				box.hidden = false;
			} );

			document.getElementById( 'nzwp-bye-cancel' ).addEventListener( 'click', close );
			box.addEventListener( 'click', function ( e ) { if ( e.target === box ) { close(); } } );
			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && ! box.hidden ) { close(); }
			} );
		} )();
		</script>
		<?php
	}
}
