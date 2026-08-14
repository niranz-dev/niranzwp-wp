<?php
/**
 * The Skills screen.
 *
 * The point of keeping skills on the site rather than in one operator's CLI is
 * that an editor can write them without a terminal, so this screen has to be
 * usable on its own -- list, edit, delete, no command line anywhere.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class SkillsAdmin {

	private const NONCE = 'niranzwp_skill';

	public static function init(): void {
		add_action( 'admin_post_niranzwp_skill_save', [ self::class, 'handle_save' ] );
		add_action( 'admin_post_niranzwp_skill_delete', [ self::class, 'handle_delete' ] );
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'niranzwp-skills' ], $args ), admin_url( 'admin.php' ) );
	}

	/* ---------------------------------------------------------------- write */

	public static function handle_save(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}

		// Skill bodies are prose written by an administrator and are read back
		// by machines, so the newlines and punctuation have to survive intact.
		// sanitize_textarea_field would strip them; unslashing plus a capability
		// check is the right level here.
		$body = isset( $_POST['body'] ) ? (string) wp_unslash( $_POST['body'] ) : '';

		$result = Skills::put(
			isset( $_POST['slug'] ) ? sanitize_title( (string) wp_unslash( $_POST['slug'] ) ) : '',
			isset( $_POST['title'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['title'] ) ) : '',
			isset( $_POST['description'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['description'] ) ) : '',
			$body
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::url( [ 'error' => rawurlencode( $result->get_error_message() ) ] ) );
			exit;
		}

		wp_safe_redirect( self::url( [ 'saved' => $result['status'], 'slug' => $result['slug'] ] ) );
		exit;
	}

	public static function handle_delete(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}

		$slug   = isset( $_GET['slug'] ) ? sanitize_title( (string) wp_unslash( $_GET['slug'] ) ) : '';
		$result = Skills::forget( $slug );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::url( [ 'error' => rawurlencode( $result->get_error_message() ) ] ) );
			exit;
		}

		wp_safe_redirect( self::url( [ 'saved' => 'deleted' ] ) );
		exit;
	}

	/* --------------------------------------------------------------- render */

	public static function render(): void {
		Admin::header( __( 'Skills', 'niranzwp' ) );

		$editing = isset( $_GET['edit'] ) ? sanitize_title( (string) wp_unslash( $_GET['edit'] ) ) : '';
		$post    = '' !== $editing ? Skills::find( $editing ) : null;
		$skills  = Skills::all();

		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) wp_unslash( $_GET['error'] ) ) . '</p></div>';
		}
		if ( isset( $_GET['saved'] ) ) {
			$what = sanitize_key( (string) wp_unslash( $_GET['saved'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				'deleted' === $what ? __( 'Skill deleted.', 'niranzwp' ) : ( 'created' === $what ? __( 'Skill created.', 'niranzwp' ) : __( 'Skill updated.', 'niranzwp' ) )
			) . '</p></div>';
		}
		?>
		<style>
			.nzwp-skill{border:1px solid #dcdcde;border-radius:6px;background:#fff;padding:14px 18px;margin:0 0 10px;display:flex;gap:16px;align-items:flex-start}
			.nzwp-skill-main{flex:1;min-width:0}
			.nzwp-skill h3{margin:0 0 3px;font-size:14px}
			.nzwp-skill code{font-size:12px;background:#f0f0f1;padding:1px 6px;border-radius:3px}
			.nzwp-skill p{margin:4px 0 0;color:#646970;font-size:13px}
			.nzwp-skill-meta{color:#8c8f94;font-size:12px;white-space:nowrap}
			.nzwp-empty{border:1px dashed #c3c4c7;border-radius:6px;padding:26px;text-align:center;color:#646970;background:#fff}
			.nzwp-form textarea{width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.7}
			.nzwp-form input[type=text]{width:100%}
			.nzwp-form .row{margin:0 0 14px}
			.nzwp-form label{display:block;font-weight:600;margin:0 0 4px}
		</style>

		<div class="nzwp-card">
			<h2><?php esc_html_e( 'What these are', 'niranzwp' ); ?></h2>
			<p class="nzwp-desc">
				<?php esc_html_e( 'Written instructions that stay with this site. Anything that connects -- a CLI, an AI assistant, a teammate -- reads them and works to the same rules, so nobody has to be told twice.', 'niranzwp' ); ?>
			</p>
			<p class="nzwp-desc">
				<?php esc_html_e( 'Write them the way you would brief a new editor: what to do, what not to do, and why when it is not obvious.', 'niranzwp' ); ?>
			</p>
		</div>

		<?php if ( $skills ) : ?>
			<?php foreach ( $skills as $s ) : ?>
				<div class="nzwp-skill">
					<div class="nzwp-skill-main">
						<h3><?php echo esc_html( $s['title'] ); ?> <code><?php echo esc_html( $s['slug'] ); ?></code></h3>
						<?php if ( '' !== $s['description'] ) : ?>
							<p><?php echo esc_html( $s['description'] ); ?></p>
						<?php endif; ?>
					</div>
					<div class="nzwp-skill-meta">
						<?php echo esc_html( size_format( $s['bytes'] ) ); ?><br>
						<a href="<?php echo esc_url( self::url( [ 'edit' => $s['slug'] ] ) ); ?>"><?php esc_html_e( 'Edit', 'niranzwp' ); ?></a>
						&nbsp;
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=niranzwp_skill_delete&slug=' . rawurlencode( $s['slug'] ) ), self::NONCE ) ); ?>"
						   onclick="return confirm('<?php echo esc_js( __( 'Delete this skill? A snapshot is kept and can be restored.', 'niranzwp' ) ); ?>')"
						   style="color:#b32d2e"><?php esc_html_e( 'Delete', 'niranzwp' ); ?></a>
					</div>
				</div>
			<?php endforeach; ?>
		<?php else : ?>
			<div class="nzwp-empty">
				<p style="margin:0 0 4px"><strong><?php esc_html_e( 'No skills yet.', 'niranzwp' ); ?></strong></p>
				<p style="margin:0"><?php esc_html_e( 'Write the first one below. A good starting point is whatever you find yourself repeating.', 'niranzwp' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="nzwp-card nzwp-form" style="margin-top:16px">
			<h2><?php echo $post ? esc_html__( 'Edit skill', 'niranzwp' ) : esc_html__( 'New skill', 'niranzwp' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="niranzwp_skill_save">
				<?php wp_nonce_field( self::NONCE ); ?>

				<div class="row">
					<label for="nzwp-title"><?php esc_html_e( 'Name', 'niranzwp' ); ?></label>
					<input type="text" id="nzwp-title" name="title" required
					       value="<?php echo esc_attr( $post ? $post->post_title : '' ); ?>"
					       placeholder="<?php esc_attr_e( 'Alt text', 'niranzwp' ); ?>">
				</div>

				<div class="row">
					<label for="nzwp-slug"><?php esc_html_e( 'Slug', 'niranzwp' ); ?></label>
					<input type="text" id="nzwp-slug" name="slug" required
					       value="<?php echo esc_attr( $post ? $post->post_name : '' ); ?>"
					       placeholder="alt-text">
					<p class="nzwp-desc"><?php esc_html_e( 'How a client asks for this skill. Reusing an existing slug replaces that skill.', 'niranzwp' ); ?></p>
				</div>

				<div class="row">
					<label for="nzwp-description"><?php esc_html_e( 'One-line summary', 'niranzwp' ); ?></label>
					<input type="text" id="nzwp-description" name="description"
					       value="<?php echo esc_attr( $post ? (string) get_post_meta( $post->ID, '_niranzwp_description', true ) : '' ); ?>"
					       placeholder="<?php esc_attr_e( 'How to write alt text for photographs on this site', 'niranzwp' ); ?>">
					<p class="nzwp-desc"><?php esc_html_e( 'This is what a client reads to decide whether the skill is relevant, so say what it covers rather than that it exists.', 'niranzwp' ); ?></p>
				</div>

				<div class="row">
					<label for="nzwp-body"><?php esc_html_e( 'Instructions', 'niranzwp' ); ?></label>
					<textarea id="nzwp-body" name="body" rows="16" required placeholder="<?php esc_attr_e( "- Do not start with \"image of\" or \"photo of\"\n- Under 125 characters\n- Name people when they are recognisable\n- Leave decorative images empty", 'niranzwp' ); ?>"><?php echo esc_textarea( $post ? $post->post_content : '' ); ?></textarea>
				</div>

				<p>
					<button type="submit" class="button button-primary"><?php echo $post ? esc_html__( 'Save skill', 'niranzwp' ) : esc_html__( 'Create skill', 'niranzwp' ); ?></button>
					<?php if ( $post ) : ?>
						<a href="<?php echo esc_url( self::url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'niranzwp' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		</div>
		<?php
	}
}
