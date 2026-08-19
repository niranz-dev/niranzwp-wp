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

	private const NONCE_OAUTH = 'niranzwp_revoke_oauth';

	public static function init(): void {
		add_action( 'admin_post_niranzwp_revoke', [ self::class, 'handle_revoke' ] );
		add_action( 'admin_post_niranzwp_revoke_oauth', [ self::class, 'handle_revoke_oauth' ] );
	}

	/**
	 * Tools that connected by approving a code, rather than by being handed a
	 * password. This is what the Connect screen creates, and until it was
	 * listed here approving a tool produced a grant with no way to see or
	 * withdraw it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function oauth(): array {
		return class_exists( '\NiranzWP\OAuth' )
			? OAuth::connections( get_current_user_id() )
			: [];
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

	public static function handle_revoke_oauth(): void {
		$client = isset( $_GET['client'] ) ? sanitize_text_field( wp_unslash( $_GET['client'] ) ) : '';

		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE_OAUTH . $client ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}

		if ( $client && class_exists( '\NiranzWP\OAuth' ) ) {
			OAuth::revoke_client( get_current_user_id(), $client );
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

		$grants = self::oauth();
		$items  = self::all();

		if ( ! $grants && ! $items ) {
			echo '<div class="nzwp-card"><p>' . esc_html__( 'Nothing is connected yet.', 'niranzwp' ) . '</p>';
			echo '<p class="nzwp-desc" style="margin-top:8px">'
				. esc_html__( 'Run the connect command in your terminal, then approve the code it shows you.', 'niranzwp' )
				. '</p></div></div>';
			return;
		}

		if ( $grants ) {
			echo '<div class="nzwp-card">';
			echo '<h2 style="margin:0 0 4px;font-size:15px">' . esc_html__( 'Approved tools', 'niranzwp' ) . '</h2>';
			echo '<p class="nzwp-desc">' . esc_html__( 'Connected by approving a code. Each holds a token that renews itself; revoking ends it immediately and the tool has to be approved again.', 'niranzwp' ) . '</p>';

			echo '<table class="widefat striped" style="margin-top:12px"><thead><tr>';
			echo '<th>' . esc_html__( 'Tool', 'niranzwp' ) . '</th>';
			echo '<th style="width:130px">' . esc_html__( 'Connected', 'niranzwp' ) . '</th>';
			echo '<th style="width:150px">' . esc_html__( 'Last used', 'niranzwp' ) . '</th>';
			echo '<th style="width:150px">' . esc_html__( 'Expires', 'niranzwp' ) . '</th>';
			echo '<th style="width:90px"></th>';
			echo '</tr></thead><tbody>';

			foreach ( $grants as $g ) {
				$client = (string) $g['client_id'];
				$revoke = wp_nonce_url(
					admin_url( 'admin-post.php?action=niranzwp_revoke_oauth&client=' . rawurlencode( $client ) ),
					self::NONCE_OAUTH . $client
				);

				printf(
					'<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td>'
					. '<td><a href="%s" class="button button-small nzwp-danger" onclick="return confirm(%s)">%s</a></td></tr>',
					esc_html( (string) $g['name'] ),
					esc_html( $g['created'] ? gmdate( 'j M Y', (int) $g['created'] ) : '-' ),
					esc_html(
						$g['last_used']
							? human_time_diff( (int) $g['last_used'] ) . ' ' . __( 'ago', 'niranzwp' )
							: __( 'never', 'niranzwp' )
					),
					esc_html(
						$g['expires']
							? __( 'in', 'niranzwp' ) . ' ' . human_time_diff( time(), (int) $g['expires'] )
							: '-'
					),
					esc_url( $revoke ),
					esc_attr( wp_json_encode( __( 'Revoke this connection? The tool using it will stop working immediately.', 'niranzwp' ) ) ),
					esc_html__( 'Revoke', 'niranzwp' )
				);
			}

			echo '</tbody></table></div>';
		}

		if ( ! $items ) {
			echo '</div>';
			return;
		}

		echo '<div class="nzwp-card">';
		echo '<h2 style="margin:0 0 4px;font-size:15px">' . esc_html__( 'Application passwords', 'niranzwp' ) . '</h2>';
		echo '<p class="nzwp-desc">' . esc_html__( 'The older way in: a password issued to one client, on your WordPress account. Revoking is immediate.', 'niranzwp' ) . '</p>';

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
