<?php
/**
 * The Checkpoints screen.
 *
 * A checkpoint is only useful if the person who needs it can find it. On the
 * command line that means remembering an id from a response you may have
 * scrolled past; here it is a list in time order with a button on each row.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class CheckpointAdmin {

	private const NONCE = 'niranzwp_ckpt';

	public static function init(): void {
		add_action( 'admin_post_niranzwp_ckpt_restore', [ self::class, 'handle_restore' ] );
		add_action( 'admin_post_niranzwp_ckpt_delete', [ self::class, 'handle_delete' ] );
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'niranzwp-checkpoints' ], $args ), admin_url( 'admin.php' ) );
	}

	private static function guard(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}
	}

	public static function handle_restore(): void {
		self::guard();
		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$r   = Checkpoint::restore( $id, false );

		if ( is_wp_error( $r ) ) {
			wp_safe_redirect( self::url( [ 'error' => rawurlencode( $r->get_error_message() ) ] ) );
			exit;
		}
		wp_safe_redirect( self::url( [ 'restored' => (string) ( $r['changed'] ?? 0 ) ] ) );
		exit;
	}

	public static function handle_delete(): void {
		self::guard();
		Checkpoint::forget( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
		wp_safe_redirect( self::url( [ 'deleted' => '1' ] ) );
		exit;
	}

	/* --------------------------------------------------------------- render */

	public static function render(): void {
		Admin::header( __( 'Snapshots', 'niranzwp' ) );

		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) wp_unslash( $_GET['error'] ) ) . '</p></div>';
		}
		if ( isset( $_GET['restored'] ) ) {
			$n = (int) $_GET['restored'];
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( 0 === $n
					? __( 'Nothing needed changing; everything already matched the snapshot.', 'niranzwp' )
					: sprintf( _n( '%d thing put back.', '%d things put back.', $n, 'niranzwp' ), $n ) )
			);
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Snapshot deleted.', 'niranzwp' ) . '</p></div>';
		}

		$rows = Checkpoint::all( 50 );
		?>
		<style>
			.nzwp-tl{position:relative;margin:0}
			.nzwp-tl::before{content:"";position:absolute;left:9px;top:14px;bottom:14px;width:2px;background:#e0e0e2}
			.nzwp-ck{position:relative;padding:0 0 0 34px;margin:0 0 10px}
			.nzwp-ck::before{content:"";position:absolute;left:4px;top:20px;width:12px;height:12px;border-radius:50%;
				background:#fff;border:2px solid #2271b1}
			.nzwp-ck.auto::before{border-color:#8c8f94}
			.nzwp-ck-in{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:13px 16px;display:flex;gap:14px;align-items:flex-start}
			.nzwp-ck h3{margin:0;font-size:13.5px;font-weight:600}
			.nzwp-ck .meta{margin:3px 0 0;color:#646970;font-size:12px;font-variant-numeric:tabular-nums}
			.nzwp-ck .right{margin-left:auto;white-space:nowrap;text-align:right;padding-left:12px}
			.nzwp-ck .right small{display:block;color:#8c8f94;font-size:11px;margin-bottom:3px}
			.nzwp-chip{display:inline-block;background:#f0f0f1;color:#50575e;border-radius:3px;padding:1px 7px;font-size:11px;margin-right:4px}
		</style>

		<div class="nzwp-card">
			<p class="nzwp-desc" style="margin:0">
				<?php esc_html_e( 'Taken automatically before write-file, delete-file, block-write, elementor-update-setting and skill edits, and on request. The newest thirty are kept.', 'niranzwp' ); ?>
			</p>
			<p class="nzwp-desc">
				<strong><?php esc_html_e( 'This is not a backup.', 'niranzwp' ); ?></strong>
				<?php esc_html_e( 'It covers what this plugin itself touched, and it needs a working site to restore through.', 'niranzwp' ); ?>
			</p>
		</div>

		<?php if ( ! $rows ) : ?>
			<div class="nzwp-card" style="text-align:center;padding:32px">
				<p style="margin:0 0 4px"><strong><?php esc_html_e( 'No snapshots yet.', 'niranzwp' ); ?></strong></p>
				<p class="nzwp-desc" style="margin:0"><?php esc_html_e( 'One appears here the first time something writes a file or rewrites a post.', 'niranzwp' ); ?></p>
			</div>
			<?php
			echo '</div>';
			return;
		endif;
		?>

		<div class="nzwp-tl">
			<?php foreach ( $rows as $c ) :
				$auto = str_starts_with( (string) $c['label'], 'Before ' );
				?>
				<div class="nzwp-ck <?php echo $auto ? 'auto' : ''; ?>">
					<div class="nzwp-ck-in">
						<div>
							<h3><?php echo esc_html( $c['label'] ); ?></h3>
							<p class="meta">
								<?php
								$parts = [];
								if ( $c['files'] ) {
									$parts[] = sprintf( _n( '%d file', '%d files', $c['files'], 'niranzwp' ), $c['files'] );
								}
								if ( $c['posts'] ) {
									$parts[] = sprintf( _n( '%d post', '%d posts', $c['posts'], 'niranzwp' ), $c['posts'] );
								}
								if ( $c['options'] ) {
									$parts[] = sprintf( _n( '%d option', '%d options', $c['options'], 'niranzwp' ), $c['options'] );
								}
								echo esc_html( implode( ' &middot; ', $parts ) . ' &middot; ' . size_format( $c['bytes'] ) );
								?>
								&middot;
								<?php
								echo esc_html( sprintf(
									/* translators: %s: human time difference */
									__( '%s ago', 'niranzwp' ),
									human_time_diff( (int) strtotime( (string) $c['created'] ), time() )
								) );
								?>
								<span class="nzwp-chip" style="margin-left:6px">#<?php echo esc_html( (string) $c['checkpoint_id'] ); ?></span>
								<?php if ( $auto ) : ?>
									<span class="nzwp-chip"><?php esc_html_e( 'automatic', 'niranzwp' ); ?></span>
								<?php endif; ?>
							</p>
						</div>
						<div class="right">
							<a class="button button-small"
							   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=niranzwp_ckpt_restore&id=' . (int) $c['checkpoint_id'] ), self::NONCE ) ); ?>"
							   onclick="return confirm('<?php echo esc_js( __( 'Put everything in this snapshot back the way it was?', 'niranzwp' ) ); ?>')">
								<?php esc_html_e( 'Restore', 'niranzwp' ); ?>
							</a>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=niranzwp_ckpt_delete&id=' . (int) $c['checkpoint_id'] ), self::NONCE ) ); ?>"
							   onclick="return confirm('<?php echo esc_js( __( 'Delete this snapshot? It cannot be restored afterwards.', 'niranzwp' ) ); ?>')"
							   style="color:#b32d2e;font-size:12px;margin-left:8px"><?php esc_html_e( 'Delete', 'niranzwp' ); ?></a>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		</div>
		<?php
	}
}
