<?php
/**
 * Which edition this install is running, and what to show for it.
 *
 * There is no licence server yet. This exists so that the day there is one,
 * the only thing that changes is what state() reads - every screen, badge and
 * feature gate already asks this class and none of them need touching.
 *
 * The states are written out in full rather than a boolean, because a licence
 * is not on or off. Expired and unreachable are different answers and must
 * stay different: a server that is merely down must never look like a licence
 * that has run out, or one bad afternoon at the host turns into every
 * customer losing their features at once.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class License {

	public const FREE      = 'free';
	public const PRO       = 'pro';
	public const EXPIRED   = 'expired';
	public const REVOKED   = 'revoked';

	/** Where the key and the last verdict live. */
	private const OPTION = 'niranzwp_license';

	/**
	 * The current state.
	 *
	 * Until there is a server to ask, this is Pro. The reading order below is
	 * the order it will keep once there is one, so nothing moves later.
	 */
	public static function state(): string {
		$saved = get_option( self::OPTION, [] );
		if ( is_array( $saved ) && ! empty( $saved['state'] ) ) {
			$known = [ self::FREE, self::PRO, self::EXPIRED, self::REVOKED ];
			if ( in_array( $saved['state'], $known, true ) ) {
				return (string) $saved['state'];
			}
		}
		return self::PRO;
	}

	/** Whether paid features should run. */
	public static function is_pro(): bool {
		return self::PRO === self::state();
	}

	/**
	 * The word on the badge, and the class that colours it.
	 *
	 * @return array{label:string,class:string,title:string}
	 */
	public static function badge(): array {
		switch ( self::state() ) {
			case self::EXPIRED:
				return [
					'label' => __( 'Pro expired', 'niranzwp' ),
					'class' => 'nzwp-lic-lapsed',
					'title' => __( 'This licence has run out. Renew to switch the paid features back on.', 'niranzwp' ),
				];
			case self::REVOKED:
				return [
					'label' => __( 'Free', 'niranzwp' ),
					'class' => 'nzwp-lic-free',
					'title' => __( 'This licence was withdrawn.', 'niranzwp' ),
				];
			case self::FREE:
				return [
					'label' => __( 'Free', 'niranzwp' ),
					'class' => 'nzwp-lic-free',
					'title' => __( 'Running the free edition.', 'niranzwp' ),
				];
			default:
				return [
					'label' => __( 'Pro', 'niranzwp' ),
					'class' => 'nzwp-lic-pro',
					'title' => __( 'Running the Pro edition.', 'niranzwp' ),
				];
		}
	}
}
