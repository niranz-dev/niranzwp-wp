<?php
/**
 * Plugin Name:       NiranzWP
 * Plugin URI:        https://niranz.dev
 * Description:       Exposes safe, purpose-built abilities so CLIs and AI agents can work with this site through the WordPress Abilities API.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Author:            Niranjan
 * Author URI:        https://niranz.dev
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       niranzwp
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const OPTION_KEY  = 'niranzwp_settings';
const CAPABILITY  = 'manage_options';

define( 'NIRANZWP_FILE', __FILE__ );
define( 'NIRANZWP_DIR', plugin_dir_path( __FILE__ ) );

require_once NIRANZWP_DIR . 'includes/Settings.php';
require_once NIRANZWP_DIR . 'includes/Abilities.php';
require_once NIRANZWP_DIR . 'includes/Seo.php';
require_once NIRANZWP_DIR . 'includes/SeoFix.php';
require_once NIRANZWP_DIR . 'includes/Content.php';
require_once NIRANZWP_DIR . 'includes/Blocks.php';
require_once NIRANZWP_DIR . 'includes/Elementor.php';
require_once NIRANZWP_DIR . 'includes/Files.php';
require_once NIRANZWP_DIR . 'includes/Checkpoint.php';
require_once NIRANZWP_DIR . 'includes/Runtime.php';
require_once NIRANZWP_DIR . 'includes/Cli.php';
require_once NIRANZWP_DIR . 'includes/Mcp.php';
require_once NIRANZWP_DIR . 'includes/Connections.php';
require_once NIRANZWP_DIR . 'includes/Admin.php';

/**
 * The Abilities API landed in WordPress 6.9. Without it there is nothing to
 * register against, so fail loudly in the admin rather than silently doing
 * nothing.
 */
function boot(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', static function (): void {
			if ( ! current_user_can( CAPABILITY ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p><strong>NiranzWP:</strong> %s</p></div>',
				esc_html__( 'This plugin needs the WordPress Abilities API, added in WordPress 6.9. Update WordPress to use it.', 'niranzwp' )
			);
		} );
		return;
	}

	Checkpoint::init();
	Abilities::init();
	Mcp::init();
	Connections::init();
	Admin::init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );

/**
 * Abilities are off on activation. Turning them on has to be a deliberate act,
 * not a side effect of installing a plugin.
 */
register_activation_hook( __FILE__, static function (): void {
	if ( false === get_option( OPTION_KEY ) ) {
		add_option( OPTION_KEY, [ 'enabled' => false ] );
	}
} );
