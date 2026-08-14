<?php
/**
 * The Skills screen.
 *
 * The point of keeping skills on the site rather than in one operator's config
 * is that an editor can write them without a terminal, so this screen has to
 * stand on its own: list, upload, edit, delete, no command line anywhere.
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
		add_action( 'admin_post_niranzwp_skill_upload', [ self::class, 'handle_upload' ] );
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'niranzwp-skills' ], $args ), admin_url( 'admin.php' ) );
	}

	/* ---------------------------------------------------------------- write */

	public static function handle_save(): void {
		self::guard();

		// Skill bodies are prose written by an administrator and read back by
		// machines, so newlines and punctuation have to survive intact.
		// sanitize_textarea_field would strip them; unslashing plus the
		// capability check is the right level here.
		$body = isset( $_POST['body'] ) ? (string) wp_unslash( $_POST['body'] ) : '';

		$result = Skills::put(
			isset( $_POST['slug'] ) ? sanitize_title( (string) wp_unslash( $_POST['slug'] ) ) : '',
			isset( $_POST['title'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['title'] ) ) : '',
			isset( $_POST['description'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['description'] ) ) : '',
			$body
		);

		self::finish( $result );
	}

	public static function handle_upload(): void {
		self::guard();

		if ( ! isset( $_FILES['skill'] ) || ! is_array( $_FILES['skill'] ) || UPLOAD_ERR_OK !== ( $_FILES['skill']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			self::fail( __( 'No file was uploaded.', 'niranzwp' ) );
		}

		$name = sanitize_file_name( (string) ( $_FILES['skill']['name'] ?? '' ) );
		if ( ! str_ends_with( strtolower( $name ), '.md' ) ) {
			self::fail( __( 'Only .md files can be uploaded.', 'niranzwp' ) );
		}

		$tmp = (string) ( $_FILES['skill']['tmp_name'] ?? '' );
		if ( ! is_uploaded_file( $tmp ) ) {
			self::fail( __( 'That upload could not be read.', 'niranzwp' ) );
		}

		$raw = (string) file_get_contents( $tmp );

		// The filename is the slug unless the frontmatter names the skill,
		// which Skills::put() resolves.
		self::finish( Skills::put( basename( $name, '.md' ), '', '', $raw ) );
	}

	public static function handle_delete(): void {
		self::guard();
		$slug = isset( $_GET['slug'] ) ? sanitize_title( (string) wp_unslash( $_GET['slug'] ) ) : '';
		self::finish( Skills::forget( $slug ), 'deleted' );
	}

	private static function guard(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}
	}

	private static function fail( string $message ): void {
		wp_safe_redirect( self::url( [ 'error' => rawurlencode( $message ) ] ) );
		exit;
	}

	/** @param array<string,mixed>|\WP_Error $result */
	private static function finish( $result, string $status = '' ): void {
		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message() );
		}
		wp_safe_redirect( self::url( [
			'saved' => '' !== $status ? $status : (string) ( $result['status'] ?? 'updated' ),
			'slug'  => (string) ( $result['slug'] ?? '' ),
		] ) );
		exit;
	}

	/* --------------------------------------------------------------- render */

	public static function render(): void {
		Admin::header( __( 'Skills', 'niranzwp' ) );

		$editing = isset( $_GET['edit'] ) ? sanitize_title( (string) wp_unslash( $_GET['edit'] ) ) : '';
		$adding  = isset( $_GET['new'] );
		$post    = '' !== $editing ? Skills::find( $editing ) : null;
		$written = Skills::all();
		$builtin = Skills::built_in();

		self::notices();
		?>
		<style>
			.nzwp-actions{display:flex;gap:8px;align-items:center;margin:-8px 0 18px}
			.nzwp-sec{background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:0 0 16px;overflow:hidden}
			.nzwp-sec-head{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid #f0f0f1}
			.nzwp-sec-head h2{margin:0;font-size:14px;display:flex;align-items:center;gap:8px}
			.nzwp-sec-head .count{color:#787c82;font-weight:400}
			.nzwp-sec-head .tag{margin-left:auto;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#787c82;background:#f0f0f1;padding:3px 9px;border-radius:3px}
			.nzwp-row{display:flex;align-items:flex-start;gap:14px;padding:13px 18px;border-top:1px solid #f6f7f7}
			.nzwp-row:first-of-type{border-top:0}
			.nzwp-row code{font-size:13px;font-weight:600;background:none;padding:0}
			.nzwp-row p{margin:3px 0 0;color:#646970;font-size:13px;max-width:74ch}
			.nzwp-row .right{margin-left:auto;white-space:nowrap;padding-left:14px;text-align:right}
			.nzwp-row .right small{display:block;color:#8c8f94;font-size:11px;margin-bottom:2px}
			.nzwp-empty{padding:26px 18px;text-align:center;color:#646970}
			.nzwp-form textarea{width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.7}
			.nzwp-form input[type=text]{width:100%}
			.nzwp-form .row{margin:0 0 14px}
			.nzwp-form label{display:block;font-weight:600;margin:0 0 4px}
			.nzwp-warn-note{border-left:4px solid #dba617;background:#fcf9e8;padding:11px 16px;margin:0 0 16px;font-size:13px}
		</style>

		<div class="nzwp-actions">
			<a class="button" href="<?php echo esc_url( self::url( [ 'new' => '1' ] ) ); ?>"><?php esc_html_e( 'Add new', 'niranzwp' ); ?></a>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center">
				<input type="hidden" name="action" value="niranzwp_skill_upload">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="file" name="skill" accept=".md,text/markdown" required style="max-width:230px">
				<button type="submit" class="button"><?php esc_html_e( 'Upload .md', 'niranzwp' ); ?></button>
			</form>
		</div>

		<div class="nzwp-warn-note">
			<?php esc_html_e( 'A skill is instructions that everything connected to this site will follow. Only upload one from a source you trust.', 'niranzwp' ); ?>
		</div>

		<!-- ------------------------------------------------------ written -->
		<div class="nzwp-sec">
			<div class="nzwp-sec-head">
				<h2><?php esc_html_e( 'Written on this site', 'niranzwp' ); ?>
					<span class="count"><?php echo esc_html( (string) count( $written ) ); ?></span>
				</h2>
			</div>

			<?php if ( $written ) : ?>
				<?php foreach ( $written as $s ) : ?>
					<div class="nzwp-row">
						<div>
							<code><?php echo esc_html( $s['slug'] ); ?></code>
							<?php if ( $s['title'] !== $s['slug'] ) : ?>
								<span style="color:#787c82">&mdash; <?php echo esc_html( $s['title'] ); ?></span>
							<?php endif; ?>
							<p><?php echo esc_html( '' !== $s['description'] ? $s['description'] : __( 'No summary. Clients use this to decide whether the skill applies, so it is worth adding one.', 'niranzwp' ) ); ?></p>
						</div>
						<div class="right">
							<small><?php echo esc_html( size_format( $s['bytes'] ) ); ?></small>
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
					<p style="margin:0 0 4px"><strong><?php esc_html_e( 'Nothing written yet.', 'niranzwp' ); ?></strong></p>
					<p style="margin:0"><?php esc_html_e( 'A good first one is whatever you find yourself repeating.', 'niranzwp' ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<!-- ----------------------------------------------------- built in -->
		<?php if ( $builtin ) : ?>
			<div class="nzwp-sec">
				<div class="nzwp-sec-head">
					<h2><?php esc_html_e( 'Shipped with the plugin', 'niranzwp' ); ?>
						<span class="count"><?php echo esc_html( (string) count( $builtin ) ); ?></span>
					</h2>
					<span class="tag"><?php esc_html_e( 'not editable', 'niranzwp' ); ?></span>
				</div>

				<?php foreach ( $builtin as $s ) : ?>
					<div class="nzwp-row">
						<div>
							<code><?php echo esc_html( $s['slug'] ); ?></code>
							<span style="color:#787c82">&mdash; <?php echo esc_html( $s['title'] ); ?></span>
							<p><?php echo esc_html( $s['description'] ); ?></p>
						</div>
						<div class="right">
							<small><?php echo esc_html( size_format( $s['bytes'] ) ); ?></small>
							<a href="<?php echo esc_url( self::url( [ 'new' => '1', 'from' => $s['slug'] ] ) ); ?>"><?php esc_html_e( 'Override', 'niranzwp' ); ?></a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<!-- --------------------------------------------------------- form -->
		<?php if ( $post || $adding ) : ?>
			<?php
			$from = isset( $_GET['from'] ) ? sanitize_title( (string) wp_unslash( $_GET['from'] ) ) : '';
			$seed = [ 'slug' => '', 'title' => '', 'description' => '', 'body' => '' ];

			if ( $post ) {
				$seed = [
					'slug'        => $post->post_name,
					'title'       => $post->post_title,
					'description' => (string) get_post_meta( $post->ID, '_niranzwp_description', true ),
					'body'        => $post->post_content,
				];
			} elseif ( '' !== $from ) {
				foreach ( Skills::built_in( true ) as $b ) {
					if ( $b['slug'] === $from ) {
						$seed = [ 'slug' => $b['slug'], 'title' => $b['title'], 'description' => $b['description'], 'body' => $b['body'] ];
					}
				}
			}
			?>
			<div class="nzwp-card nzwp-form">
				<h2><?php echo $post ? esc_html__( 'Edit skill', 'niranzwp' ) : esc_html__( 'New skill', 'niranzwp' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="niranzwp_skill_save">
					<?php wp_nonce_field( self::NONCE ); ?>

					<div class="row">
						<label for="nzwp-title"><?php esc_html_e( 'Name', 'niranzwp' ); ?></label>
						<input type="text" id="nzwp-title" name="title" required value="<?php echo esc_attr( $seed['title'] ); ?>" placeholder="<?php esc_attr_e( 'Alt text', 'niranzwp' ); ?>">
					</div>

					<div class="row">
						<label for="nzwp-slug"><?php esc_html_e( 'Slug', 'niranzwp' ); ?></label>
						<input type="text" id="nzwp-slug" name="slug" required value="<?php echo esc_attr( $seed['slug'] ); ?>" placeholder="alt-text">
						<p class="nzwp-desc"><?php esc_html_e( 'How a client asks for this skill. Reusing an existing slug replaces that skill; reusing a built-in slug overrides it.', 'niranzwp' ); ?></p>
					</div>

					<div class="row">
						<label for="nzwp-description"><?php esc_html_e( 'One-line summary', 'niranzwp' ); ?></label>
						<input type="text" id="nzwp-description" name="description" value="<?php echo esc_attr( $seed['description'] ); ?>" placeholder="<?php esc_attr_e( 'How to write alt text for images on this site', 'niranzwp' ); ?>">
						<p class="nzwp-desc"><?php esc_html_e( 'Read to decide whether the skill applies, so say what it covers and when to load it.', 'niranzwp' ); ?></p>
					</div>

					<div class="row">
						<label for="nzwp-body"><?php esc_html_e( 'Instructions', 'niranzwp' ); ?></label>
						<textarea id="nzwp-body" name="body" rows="18" required><?php echo esc_textarea( $seed['body'] ); ?></textarea>
						<p class="nzwp-desc"><?php esc_html_e( 'Markdown. A frontmatter block at the top overrides the name and summary above, so a .md written elsewhere pastes in unchanged.', 'niranzwp' ); ?></p>
					</div>

					<p>
						<button type="submit" class="button button-primary"><?php echo $post ? esc_html__( 'Save skill', 'niranzwp' ) : esc_html__( 'Create skill', 'niranzwp' ); ?></button>
						<a href="<?php echo esc_url( self::url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'niranzwp' ); ?></a>
					</p>
				</form>
			</div>
		<?php endif; ?>
		</div>
		<?php
	}

	private static function notices(): void {
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) wp_unslash( $_GET['error'] ) ) . '</p></div>';
		}
		if ( isset( $_GET['saved'] ) ) {
			$what = sanitize_key( (string) wp_unslash( $_GET['saved'] ) );
			$map  = [
				'deleted' => __( 'Skill deleted.', 'niranzwp' ),
				'created' => __( 'Skill created.', 'niranzwp' ),
			];
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $what ] ?? __( 'Skill updated.', 'niranzwp' ) ) . '</p></div>';
		}
	}
}
