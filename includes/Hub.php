<?php
/**
 * Abilities Hub: every ability exposed on this site, from any plugin, with a
 * switch on each one.
 *
 * The Abilities API is a shared registry -- NiranzWP registers into it, and so
 * does anything else that speaks it. The site owner is the one accountable for
 * what an agent can reach, so the list has to cover all of it, not just ours,
 * and the switches have to survive the plugin that registered them.
 *
 * A disabled ability is unregistered late, after everything has had its chance
 * to register, so it disappears from discovery and cannot be executed.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Hub {

	private const OPTION = 'niranzwp_disabled_abilities';
	private const NONCE  = 'niranzwp_hub';

	public static function init(): void {
		// Late on the registration hook, so every provider has registered and
		// there is a complete list to remove from.
		add_action( 'wp_abilities_api_init', [ self::class, 'apply' ], 9999 );
		add_action( 'admin_post_niranzwp_hub_save', [ self::class, 'handle_save' ] );
	}

	/** @return array<int,string> */
	public static function disabled(): array {
		$v = get_option( self::OPTION, [] );
		return is_array( $v ) ? array_values( array_filter( array_map( 'strval', $v ) ) ) : [];
	}

	public static function is_disabled( string $name ): bool {
		return in_array( $name, self::disabled(), true );
	}

	/** Remove everything the owner switched off. */
	public static function apply(): void {
		foreach ( self::disabled() as $name ) {
			if ( function_exists( 'wp_unregister_ability' ) && wp_get_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
	}

	/* ----------------------------------------------------------- grouping */

	/**
	 * Everything currently registered, plus anything switched off, grouped by
	 * provider and then by category.
	 *
	 * Disabled abilities are not in the registry any more -- that is the point
	 * of disabling them -- so they are carried separately or they would vanish
	 * from the screen that is supposed to switch them back on.
	 *
	 * @return array<string,array<string,array<int,array<string,mixed>>>>
	 */
	public static function grouped(): array {
		$rows = [];

		foreach ( wp_get_abilities() as $ability ) {
			$rows[ $ability->get_name() ] = [
				'name'        => $ability->get_name(),
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'category'    => $ability->get_category(),
				'readonly'    => (bool) ( $ability->get_meta()['annotations']['readonly'] ?? false ),
				'destructive' => (bool) ( $ability->get_meta()['annotations']['destructive'] ?? false ),
				'enabled'     => true,
			];
		}

		foreach ( self::remembered() as $name => $row ) {
			if ( ! isset( $rows[ $name ] ) ) {
				$row['enabled'] = false;
				$rows[ $name ]  = $row;
			}
		}

		$out = [];
		foreach ( $rows as $row ) {
			$provider = strstr( $row['name'], '/', true ) ?: 'other';
			$category = '' !== $row['category'] ? $row['category'] : 'uncategorised';
			$out[ $provider ][ $category ][] = $row;
		}

		// Ours first, then everyone else's, alphabetically.
		uksort( $out, static function ( string $a, string $b ): int {
			if ( 'niranzwp' === $a ) return -1;
			if ( 'niranzwp' === $b ) return 1;
			return strcmp( $a, $b );
		} );

		foreach ( $out as &$categories ) {
			ksort( $categories );
			foreach ( $categories as &$list ) {
				usort( $list, static fn( array $x, array $y ): int => strcmp( $x['name'], $y['name'] ) );
			}
		}

		return $out;
	}

	/**
	 * Details of abilities that were switched off, kept so the screen can still
	 * describe them once they are gone from the registry.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function remembered(): array {
		$v = get_option( self::OPTION . '_meta', [] );
		return is_array( $v ) ? $v : [];
	}

	private static function remember( array $names ): void {
		$meta = self::remembered();
		foreach ( $names as $name ) {
			$ability = wp_get_ability( $name );
			if ( $ability ) {
				$meta[ $name ] = [
					'name'        => $ability->get_name(),
					'label'       => $ability->get_label(),
					'description' => $ability->get_description(),
					'category'    => $ability->get_category(),
					'readonly'    => (bool) ( $ability->get_meta()['annotations']['readonly'] ?? false ),
					'destructive' => (bool) ( $ability->get_meta()['annotations']['destructive'] ?? false ),
				];
			}
		}
		update_option( self::OPTION . '_meta', $meta, false );
	}

	/* --------------------------------------------------------------- save */

	public static function handle_save(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}

		$action   = isset( $_POST['bulk'] ) ? sanitize_key( (string) wp_unslash( $_POST['bulk'] ) ) : '';
		$selected = isset( $_POST['abilities'] ) && is_array( $_POST['abilities'] )
			? array_map( 'sanitize_text_field', array_map( 'strval', wp_unslash( $_POST['abilities'] ) ) )
			: [];

		if ( ! $selected || ! in_array( $action, [ 'enable', 'disable' ], true ) ) {
			wp_safe_redirect( self::url( [ 'nochange' => '1' ] ) );
			exit;
		}

		$disabled = self::disabled();

		if ( 'disable' === $action ) {
			self::remember( $selected );
			$disabled = array_values( array_unique( array_merge( $disabled, $selected ) ) );
		} else {
			$disabled = array_values( array_diff( $disabled, $selected ) );
		}

		update_option( self::OPTION, $disabled, false );

		wp_safe_redirect( self::url( [ 'saved' => count( $selected ), 'did' => $action ] ) );
		exit;
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'niranzwp-abilities' ], $args ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------- render */

	public static function render(): void {
		Admin::header( __( 'Abilities Hub', 'niranzwp' ) );

		if ( isset( $_GET['saved'] ) ) {
			$n   = (int) $_GET['saved'];
			$did = 'enable' === sanitize_key( (string) wp_unslash( $_GET['did'] ?? '' ) ) ? __( 'enabled', 'niranzwp' ) : __( 'disabled', 'niranzwp' );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( _n( '%1$d ability %2$s.', '%1$d abilities %2$s.', $n, 'niranzwp' ), $n, $did ) )
			);
		}
		if ( isset( $_GET['nochange'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Nothing selected, so nothing changed.', 'niranzwp' ) . '</p></div>';
		}

		$grouped  = self::grouped();
		$disabled = self::disabled();
		?>
		<style>
			.nzwp-hub details{border:1px solid #dcdcde;border-radius:6px;background:#fff;margin:0 0 12px}
			.nzwp-hub details[open]{box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.nzwp-hub summary{cursor:pointer;padding:14px 18px;font-weight:600;display:flex;align-items:center;gap:10px;list-style:none}
			.nzwp-hub summary::-webkit-details-marker{display:none}
			.nzwp-hub summary::after{content:"";margin-left:auto;width:7px;height:7px;border-right:2px solid #787c82;border-bottom:2px solid #787c82;transform:rotate(45deg);transition:transform .15s}
			.nzwp-hub details[open]>summary::after{transform:rotate(-135deg)}
			.nzwp-hub .count{color:#787c82;font-weight:400}
			.nzwp-cat{border-top:1px solid #f0f0f1}
			.nzwp-cat>summary{background:#f6f7f7;font-size:12px;letter-spacing:.09em;text-transform:uppercase;color:#50575e;padding:9px 18px}
			.nzwp-ab{display:flex;align-items:flex-start;gap:12px;padding:11px 18px;border-top:1px solid #f0f0f1}
			.nzwp-ab code{font-size:13px;background:none;padding:0;font-weight:600}
			.nzwp-ab p{margin:2px 0 0;color:#646970;font-size:13px}
			.nzwp-ab .state{margin-left:auto;white-space:nowrap;padding-left:12px}
			.nzwp-provider-head{display:flex;align-items:center;gap:10px}
			.nzwp-other{margin:26px 0 10px;font-size:12px;letter-spacing:.09em;text-transform:uppercase;color:#787c82;font-weight:600}
			.nzwp-bulk{display:flex;gap:8px;align-items:center;margin:0 0 14px}
		</style>

		<div class="nzwp-card" style="margin-bottom:16px">
			<p class="nzwp-desc" style="margin:0">
				<?php esc_html_e( 'Every ability a connected client can reach, from this plugin and from anything else on the site that uses the WordPress Abilities API. Switching one off removes it from discovery and it can no longer be run.', 'niranzwp' ); ?>
			</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nzwp-hub">
			<input type="hidden" name="action" value="niranzwp_hub_save">
			<?php wp_nonce_field( self::NONCE ); ?>

			<?php self::bulk_bar(); ?>

			<?php
			$first = true;
			foreach ( $grouped as $provider => $categories ) :
				$total = array_sum( array_map( 'count', $categories ) );

				if ( ! $first && 'niranzwp' !== $provider ) :
					static $printed_other = false;
					if ( ! $printed_other ) {
						echo '<p class="nzwp-other">' . esc_html__( 'Registered by other plugins', 'niranzwp' ) . '</p>';
						$printed_other = true;
					}
				endif;
				$first = false;
				?>
				<details <?php echo 'niranzwp' === $provider ? 'open' : ''; ?>>
					<summary>
						<span class="nzwp-provider-head"><?php echo esc_html( $provider ); ?>
							<span class="count"><?php echo esc_html( (string) $total ); ?></span>
						</span>
					</summary>

					<?php foreach ( $categories as $category => $abilities ) : ?>
						<details class="nzwp-cat">
							<summary><?php echo esc_html( self::category_label( $category ) ); ?>
								<span class="count"><?php echo esc_html( (string) count( $abilities ) ); ?></span>
							</summary>

							<?php foreach ( $abilities as $a ) : ?>
								<div class="nzwp-ab">
									<input type="checkbox" name="abilities[]" value="<?php echo esc_attr( $a['name'] ); ?>"
									       id="ab-<?php echo esc_attr( sanitize_html_class( $a['name'] ) ); ?>">
									<div>
										<label for="ab-<?php echo esc_attr( sanitize_html_class( $a['name'] ) ); ?>">
											<code><?php echo esc_html( substr( strrchr( $a['name'], '/' ) ?: $a['name'], 1 ) ); ?></code>
										</label>
										<p><?php echo esc_html( mb_strimwidth( $a['description'], 0, 130, '…' ) ); ?></p>
									</div>
									<span class="state">
										<?php if ( $a['enabled'] ) : ?>
											<span class="nzwp-badge nzwp-on"><?php esc_html_e( 'Enabled', 'niranzwp' ); ?></span>
										<?php else : ?>
											<span class="nzwp-badge nzwp-off"><?php esc_html_e( 'Disabled', 'niranzwp' ); ?></span>
										<?php endif; ?>
										<?php if ( $a['destructive'] ) : ?>
											<span class="nzwp-badge nzwp-warn"><?php esc_html_e( 'destructive', 'niranzwp' ); ?></span>
										<?php elseif ( ! $a['readonly'] ) : ?>
											<span class="nzwp-badge nzwp-off"><?php esc_html_e( 'writes', 'niranzwp' ); ?></span>
										<?php endif; ?>
									</span>
								</div>
							<?php endforeach; ?>
						</details>
					<?php endforeach; ?>
				</details>
			<?php endforeach; ?>

			<?php self::bulk_bar(); ?>
		</form>

		<?php if ( $disabled ) : ?>
			<div class="nzwp-card">
				<h2><?php esc_html_e( 'Currently switched off', 'niranzwp' ); ?></h2>
				<div class="nzwp-code"><?php echo esc_html( implode( "\n", $disabled ) ); ?></div>
			</div>
		<?php endif; ?>
		</div>
		<?php
	}

	/** Registered categories carry a human label; fall back to the slug. */
	private static function category_label( string $slug ): string {
		$cat = function_exists( 'wp_get_ability_category' ) ? wp_get_ability_category( $slug ) : null;
		if ( $cat && method_exists( $cat, 'get_label' ) ) {
			return $cat->get_label();
		}
		return ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );
	}

	private static function bulk_bar(): void {
		?>
		<div class="nzwp-bulk">
			<select name="bulk">
				<option value=""><?php esc_html_e( 'Bulk actions', 'niranzwp' ); ?></option>
				<option value="enable"><?php esc_html_e( 'Enable', 'niranzwp' ); ?></option>
				<option value="disable"><?php esc_html_e( 'Disable', 'niranzwp' ); ?></option>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Apply', 'niranzwp' ); ?></button>
		</div>
		<?php
	}
}
