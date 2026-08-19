<?php
/**
 * Connections screen.
 *
 * Lists the Application Passwords on the current user's account and lets them
 * be revoked. WordPress core owns this data -- there is no separate store --
 * so anything revoked here is revoked everywhere, immediately.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Connections {

	private const NONCE = 'niranzwp_revoke';

	public static function init(): void {
		add_action( 'admin_post_niranzwp_revoke', [ self::class, 'handle_revoke' ] );
	}

	/** @return array<int,array<string,mixed>> */
	public static function all(): array {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return [];
		}
		$items = \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() );
		return is_array( $items ) ? $items : [];
	}

	public static function handle_revoke(): void {
		$uuid = isset( $_GET['uuid'] ) ? sanitize_text_field( wp_unslash( $_GET['uuid'] ) ) : '';

		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE . $uuid ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}

		if ( $uuid && class_exists( '\WP_Application_Passwords' ) ) {
			\WP_Application_Passwords::delete_application_password( get_current_user_id(), $uuid );
		}

		wp_safe_redirect( add_query_arg( 'revoked', '1', admin_url( 'admin.php?page=niranzwp-connections' ) ) );
		exit;
	}

	/**
	 * Revoking cuts off whatever is using that connection, immediately. A
	 * button that looks like every other button says nothing about that, so
	 * this one is red. Core has no destructive button style -- only
	 * .button-link-delete, which is a text link.
	 */
	private static function styles(): void {
		?>
		<style>
			.nzwp-danger{color:#b32d2e!important;border-color:#b32d2e!important}
			.nzwp-danger:hover,.nzwp-danger:focus{
				background:#b32d2e!important;color:#fff!important;border-color:#b32d2e!important
			}
		</style>
		<?php
	}

	public static function render(): void {
		self::styles();
		Admin::header( __( 'Connections', 'niranzwp' ) );

		if ( isset( $_GET['revoked'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Connection revoked.', 'niranzwp' ) . '</p></div>';
		}

		$items = self::all();

		echo '<div class="nzwp-card">';
		echo '<p class="nzwp-desc">' . esc_html__( 'Application Passwords on your WordPress account. Each connected client holds one. Revoking is immediate.', 'niranzwp' ) . '</p>';

		if ( ! $items ) {
			echo '<p style="margin-top:14px">' . esc_html__( 'Nothing is connected yet.', 'niranzwp' ) . '</p></div></div>';
			return;
		}

		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr>';
		echo '<th>' . esc_html__( 'Application', 'niranzwp' ) . '</th>';
		echo '<th style="width:130px">' . esc_html__( 'Created', 'niranzwp' ) . '</th>';
		echo '<th style="width:150px">' . esc_html__( 'Last used', 'niranzwp' ) . '</th>';
		echo '<th style="width:130px">' . esc_html__( 'Last IP', 'niranzwp' ) . '</th>';
		echo '<th style="width:90px"></th>';
		echo '</tr></thead><tbody>';

		foreach ( $items as $item ) {
			$uuid = (string) ( $item['uuid'] ?? '' );
			$used = ! empty( $item['last_used'] )
				? human_time_diff( (int) $item['last_used'] ) . ' ' . __( 'ago', 'niranzwp' )
				: __( 'never', 'niranzwp' );

			$revoke = wp_nonce_url(
				admin_url( 'admin-post.php?action=niranzwp_revoke&uuid=' . rawurlencode( $uuid ) ),
				self::NONCE . $uuid
			);

			printf(
				'<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td>'
				. '<td><a href="%s" class="button button-small nzwp-danger" onclick="return confirm(%s)">%s</a></td></tr>',
				esc_html( (string) ( $item['name'] ?? '(unnamed)' ) ),
				esc_html( ! empty( $item['created'] ) ? gmdate( 'j M Y', (int) $item['created'] ) : '-' ),
				esc_html( $used ),
				esc_html( (string) ( $item['last_ip'] ?? '-' ) ),
				esc_url( $revoke ),
				esc_attr( wp_json_encode( __( 'Revoke this connection? The client using it will stop working immediately.', 'niranzwp' ) ) ),
				esc_html__( 'Revoke', 'niranzwp' )
			);
		}

		echo '</tbody></table>';
		echo '<p class="nzwp-desc" style="margin-top:12px">'
			. esc_html__( 'These live on your WordPress user, so they also appear under Users - Profile - Application Passwords.', 'niranzwp' )
			. '</p>';
		echo '</div></div>';
	}
}
