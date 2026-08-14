<?php
/**
 * The Context screen.
 *
 * Two halves shown separately, because the owner needs to answer two different
 * questions: what is this site already telling agents, and what do I want to
 * add. Mixing them into one box hides which half a given line came from.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class ContextAdmin {

	private const NONCE = 'niranzwp_context';

	public static function init(): void {
		add_action( 'admin_post_niranzwp_context_save', [ self::class, 'handle_save' ] );
		add_action( 'admin_post_niranzwp_context_toggle', [ self::class, 'handle_toggle' ] );
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'niranzwp-context' ], $args ), admin_url( 'admin.php' ) );
	}

	private static function guard(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}
	}

	public static function handle_save(): void {
		self::guard();

		// Prose written by an administrator and read back by machines, so the
		// line breaks have to survive. Unslashing plus the capability check is
		// the right level; sanitize_textarea_field would flatten it.
		Context::save( isset( $_POST['context'] ) ? (string) wp_unslash( $_POST['context'] ) : '' );

		wp_safe_redirect( self::url( [ 'saved' => '1' ] ) );
		exit;
	}

	public static function handle_toggle(): void {
		self::guard();
		Context::set_user_enabled( ! Context::user_enabled() );
		wp_safe_redirect( self::url( [ 'toggled' => Context::user_enabled() ? 'on' : 'off' ] ) );
		exit;
	}

	/* --------------------------------------------------------------- render */

	public static function render(): void {
		Admin::header( __( 'Context', 'niranzwp' ) );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Context saved.', 'niranzwp' ) . '</p></div>';
		}
		if ( isset( $_GET['toggled'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				'on' === sanitize_key( (string) wp_unslash( $_GET['toggled'] ) )
					? __( 'Your instructions are being sent again.', 'niranzwp' )
					: __( 'Your instructions are no longer sent. They are still saved.', 'niranzwp' )
			) . '</p></div>';
		}

		$generated = Context::generated();
		$written   = Context::written();
		$on        = Context::user_enabled();
		$payload   = Context::build();
		?>
		<style>
			.nzwp-ctx h2.sec{font-size:15px;margin:26px 0 4px}
			.nzwp-ctx p.lede{color:#646970;margin:0 0 12px;font-size:13px;max-width:80ch}
			.nzwp-ctx details{background:#fff;border:1px solid #dcdcde;border-radius:6px}
			.nzwp-ctx summary{cursor:pointer;padding:13px 18px;font-weight:600;font-size:13px}
			.nzwp-ctx .body{border-top:1px solid #f0f0f1;padding:4px 18px 18px}
			.nzwp-ctx pre{background:#1d2327;color:#f0f0f1;padding:14px 16px;border-radius:5px;overflow:auto;
				font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;line-height:1.7;
				white-space:pre-wrap;max-height:460px;margin:12px 0 0}
			.nzwp-ctx .switchrow{display:flex;align-items:center;gap:12px;padding:16px 20px;background:#fff;
				border:1px solid #dcdcde;border-radius:6px;margin:0 0 12px}
			.nzwp-ctx .switchrow .grow{flex:1}
			.nzwp-ctx .switchrow p{margin:3px 0 0;color:#646970;font-size:13px}
			.nzwp-ctx textarea{width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.7}
		</style>

		<div class="nzwp-ctx">

			<!-- ------------------------------------------------- generated -->
			<h2 class="sec"><?php esc_html_e( 'System context', 'niranzwp' ); ?></h2>
			<p class="lede">
				<?php esc_html_e( 'Written by the plugin from this installation and rebuilt on every call, so it stays true when you change theme, page builder or SEO plugin. Shown here so you can see it; not editable.', 'niranzwp' ); ?>
			</p>

			<details>
				<summary><?php esc_html_e( 'Show full system context', 'niranzwp' ); ?></summary>
				<div class="body"><pre><?php echo esc_html( $generated ); ?></pre></div>
			</details>

			<!-- --------------------------------------------------- written -->
			<h2 class="sec"><?php esc_html_e( 'Your instructions', 'niranzwp' ); ?></h2>
			<p class="lede">
				<?php esc_html_e( 'Added by you, and placed above the system context. Standing rules only: what this site is, what it never does, who it is for. Anything that matters for one kind of job belongs in a skill instead.', 'niranzwp' ); ?>
			</p>

			<div class="switchrow">
				<div class="grow">
					<strong><?php esc_html_e( 'Sending your instructions', 'niranzwp' ); ?></strong>
					<span class="nzwp-badge <?php echo $on ? 'nzwp-on' : 'nzwp-off'; ?>" style="margin-left:8px">
						<?php echo $on ? esc_html__( 'On', 'niranzwp' ) : esc_html__( 'Off', 'niranzwp' ); ?>
					</span>
					<p><?php esc_html_e( 'Switching this off stops them being sent without deleting them, which is the quick way to find out whether a rule of yours is what is making an agent behave oddly.', 'niranzwp' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="niranzwp_context_toggle">
					<?php wp_nonce_field( self::NONCE ); ?>
					<button type="submit" class="button">
						<?php echo $on ? esc_html__( 'Stop sending', 'niranzwp' ) : esc_html__( 'Send again', 'niranzwp' ); ?>
					</button>
				</form>
			</div>

			<div class="nzwp-card">
				<p class="nzwp-desc" style="margin:0 0 6px">
					<?php esc_html_e( 'Things that stay true, so nobody has to be asked again:', 'niranzwp' ); ?>
				</p>
				<ul class="nzwp-desc" style="margin:0 0 12px;padding-left:18px;list-style:disc">
					<li><?php esc_html_e( 'What the site is for, who reads it, how it sounds, what things are called.', 'niranzwp' ); ?></li>
					<li><?php esc_html_e( 'What to avoid, what needs your approval first, and how you prefer work to be done.', 'niranzwp' ); ?></li>
				</ul>
				<p class="nzwp-desc" style="margin:0 0 10px">
					<strong><?php esc_html_e( 'No passwords, API keys or private data.', 'niranzwp' ); ?></strong>
					<?php esc_html_e( 'Everything here goes to every client that asks for context. Keep it stable and site-wide -- a one-off instruction belongs in the request, and anything that only applies to one kind of job belongs in a skill.', 'niranzwp' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="niranzwp_context_save">
					<?php wp_nonce_field( self::NONCE ); ?>
					<textarea name="context" rows="12" placeholder="<?php esc_attr_e( "UAE Stories is a people-centric magazine. Every story is about a person.\n\n- Never cover property listings or press releases.\n- Name people before their job title.\n- British spelling.", 'niranzwp' ); ?>"><?php echo esc_textarea( $written ); ?></textarea>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'niranzwp' ); ?></button></p>
				</form>
			</div>

			<!-- ---------------------------------------------------- what goes -->
			<h2 class="sec"><?php esc_html_e( 'What a client actually receives', 'niranzwp' ); ?></h2>
			<p class="lede">
				<?php esc_html_e( 'One call to niranzwp/context returns the brief, the skill catalogue and the environment, so a client can orient itself before touching anything.', 'niranzwp' ); ?>
			</p>

			<div class="nzwp-grid">
				<div class="nzwp-stat">
					<b><?php echo esc_html( size_format( strlen( (string) $payload['instructions'] ) ) ); ?></b>
					<span><?php esc_html_e( 'brief', 'niranzwp' ); ?></span>
				</div>
				<div class="nzwp-stat">
					<b><?php echo esc_html( (string) count( (array) $payload['skills'] ) ); ?></b>
					<span><?php esc_html_e( 'skills offered', 'niranzwp' ); ?></span>
				</div>
				<div class="nzwp-stat">
					<b><?php echo esc_html( (string) $payload['environment']['environment'] ); ?></b>
					<span><?php esc_html_e( 'environment', 'niranzwp' ); ?></span>
				</div>
				<div class="nzwp-stat">
					<b><?php echo $on && '' !== trim( $written ) ? esc_html__( 'Yours + system', 'niranzwp' ) : esc_html__( 'System only', 'niranzwp' ); ?></b>
					<span><?php esc_html_e( 'currently sending', 'niranzwp' ); ?></span>
				</div>
			</div>
		</div>
		</div>
		<?php
	}
}
