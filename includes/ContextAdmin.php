<?php
/**
 * The Context screen.
 *
 * Shows the generated brief so the owner can see exactly what connected
 * clients are told about this site, and takes the written half above it.
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
	}

	public static function handle_save(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}

		// Prose written by an administrator and read back by machines, so the
		// line breaks have to survive. Unslashing plus the capability check is
		// the right level; sanitize_textarea_field would flatten it.
		Context::save( isset( $_POST['context'] ) ? (string) wp_unslash( $_POST['context'] ) : '' );

		wp_safe_redirect( add_query_arg( [ 'page' => 'niranzwp-context', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		Admin::header( __( 'Context', 'niranzwp' ) );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Context saved.', 'niranzwp' ) . '</p></div>';
		}

		$generated = Context::build();
		?>
		<div class="nzwp-card">
			<h2><?php esc_html_e( 'Your instructions', 'niranzwp' ); ?></h2>
			<p class="nzwp-desc">
				<?php esc_html_e( 'Applied on every job, by anything that connects. Put the standing rules here -- what this site is, what it never does, who the audience is. Things that only matter for one kind of task belong in a skill instead.', 'niranzwp' ); ?>
			</p>
			<p class="nzwp-desc">
				<strong><?php esc_html_e( 'No passwords, API keys or private data.', 'niranzwp' ); ?></strong>
				<?php esc_html_e( 'Everything here is sent to every client that asks for context.', 'niranzwp' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="niranzwp_context_save">
				<?php wp_nonce_field( self::NONCE ); ?>
				<textarea name="context" rows="12" style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.7"
				          placeholder="<?php esc_attr_e( "UAE Stories is a people-centric magazine. Every story is about a person.\n\n- Never cover property listings or press releases.\n- Name people before their job title.\n- British spelling.", 'niranzwp' ); ?>"><?php echo esc_textarea( Context::written() ); ?></textarea>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save context', 'niranzwp' ); ?></button></p>
			</form>
		</div>

		<div class="nzwp-card">
			<h2><?php esc_html_e( 'What clients actually receive', 'niranzwp' ); ?></h2>
			<p class="nzwp-desc">
				<?php esc_html_e( 'Generated from this installation and regenerated on every call, so it stays true when you change theme, page builder or SEO plugin. Your instructions appear at the top of it.', 'niranzwp' ); ?>
			</p>
			<div class="nzwp-code" style="white-space:pre-wrap;max-height:420px"><?php echo esc_html( (string) $generated['instructions'] ); ?></div>

			<div class="nzwp-grid">
				<div class="nzwp-stat">
					<b><?php echo esc_html( (string) count( (array) $generated['skills'] ) ); ?></b>
					<span><?php esc_html_e( 'skills offered', 'niranzwp' ); ?></span>
				</div>
				<div class="nzwp-stat">
					<b><?php echo esc_html( size_format( strlen( (string) $generated['instructions'] ) ) ); ?></b>
					<span><?php esc_html_e( 'brief size', 'niranzwp' ); ?></span>
				</div>
				<div class="nzwp-stat">
					<b><?php echo esc_html( (string) $generated['environment']['environment'] ); ?></b>
					<span><?php esc_html_e( 'environment type', 'niranzwp' ); ?></span>
				</div>
			</div>
		</div>
		</div>
		<?php
	}
}
