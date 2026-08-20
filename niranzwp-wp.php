<?php
/**
 * Plugin Name:       NiranzWP
 * Plugin URI:        https://niranz.dev
 * Description:       MCP server that gives AI agents control of WordPress through purpose-built abilities - SEO, content, blocks, the database and files. Every write is previewed, snapshotted, and reverted automatically if the site breaks.
 * Version:           5.3.17
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

const VERSION     = '5.3.17';
/* Placeholder until the repository is public. Whatever this points at is what
   the masthead's GitHub mark opens, so it is one line to change and nothing
   else references it. */
const GITHUB_URL  = 'https://github.com/niranz-dev/niranzwp-wp';
const OPTION_KEY  = 'niranzwp_settings';
const CAPABILITY  = 'manage_options';

define( 'NIRANZWP_FILE', __FILE__ );
define( 'NIRANZWP_DIR', plugin_dir_path( __FILE__ ) );

require_once NIRANZWP_DIR . 'includes/Settings.php';
require_once NIRANZWP_DIR . 'includes/License.php';
require_once NIRANZWP_DIR . 'includes/Register.php';
require_once NIRANZWP_DIR . 'includes/Abilities.php';
require_once NIRANZWP_DIR . 'includes/Seo.php';
require_once NIRANZWP_DIR . 'includes/Redirects.php';
require_once NIRANZWP_DIR . 'includes/SeoFix.php';
require_once NIRANZWP_DIR . 'includes/Content.php';
require_once NIRANZWP_DIR . 'includes/Blocks.php';
require_once NIRANZWP_DIR . 'includes/Elementor.php';
require_once NIRANZWP_DIR . 'includes/Files.php';
require_once NIRANZWP_DIR . 'includes/Upload.php';
require_once NIRANZWP_DIR . 'includes/Checkpoint.php';
require_once NIRANZWP_DIR . 'includes/Skills.php';
require_once NIRANZWP_DIR . 'includes/SkillsAdmin.php';
require_once NIRANZWP_DIR . 'includes/Context.php';
require_once NIRANZWP_DIR . 'includes/Hub.php';
require_once NIRANZWP_DIR . 'includes/Recovery.php';
require_once NIRANZWP_DIR . 'includes/CheckpointAdmin.php';
require_once NIRANZWP_DIR . 'includes/ContextAdmin.php';
require_once NIRANZWP_DIR . 'includes/Runtime.php';
require_once NIRANZWP_DIR . 'includes/Cli.php';
require_once NIRANZWP_DIR . 'includes/Deactivate.php';
require_once NIRANZWP_DIR . 'includes/Mcp.php';
require_once NIRANZWP_DIR . 'includes/Connections.php';
require_once NIRANZWP_DIR . 'includes/OAuth.php';
require_once NIRANZWP_DIR . 'includes/Admin.php';
require_once NIRANZWP_DIR . 'includes/Details.php';
require_once NIRANZWP_DIR . 'includes/Updater.php';
require_once NIRANZWP_DIR . 'includes/Design.php';
require_once NIRANZWP_DIR . 'includes/Performance.php';
require_once NIRANZWP_DIR . 'includes/SeoPlan.php';

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
	Skills::init();
	SkillsAdmin::init();
	ContextAdmin::init();
	Hub::init();
	Recovery::init();
	Upload::init();
	CheckpointAdmin::init();
	Abilities::init();
	Mcp::init();
	Connections::init();
	OAuth::init();
	Deactivate::init();
	Admin::init();
	Details::init();
	Updater::init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );

/**
 * Abilities are off on activation. Turning them on has to be a deliberate act,
 * not a side effect of installing a plugin.
 */
/*
 * Everything off on a fresh install.
 *
 * Activating a plugin is not the same act as granting a tool access to the
 * site, and this one grants a lot: abilities on their own are admin-only but
 * real, files reach the install, and the runtime switch is WordPress itself.
 * The copy under each checkbox says to leave them off unless something is
 * connecting, and the defaults should agree with it rather than quietly
 * disagreeing.
 *
 * All three keys are written rather than just the one, so what is stored says
 * what the state is instead of leaving two of them to be inferred from their
 * absence.
 */
/*
 * The recovery guard is a mu-plugin, and deactivating a plugin does not touch
 * mu-plugins. Left alone it keeps loading on every request, watching for a
 * marker that nothing writes any more - harmless, since it is self-contained
 * and calls nothing from this plugin, but it is a file the owner did not put
 * there and did not ask to keep. Its own header says it is safe to delete when
 * NiranzWP is not installed; this is that deletion.
 *
 * Nothing else is removed here. Deactivating is not deleting, and settings,
 * skills and checkpoints should survive being switched off and on again.
 */
register_deactivation_hook( __FILE__, static function (): void {
	Recovery::uninstall();
} );

register_activation_hook( __FILE__, static function (): void {
	if ( false === get_option( OPTION_KEY ) ) {
		add_option(
			OPTION_KEY,
			[
				'enabled' => false,
				'files'   => false,
				'runtime' => false,
			]
		);
	}

	// Read once by the notice below, then deleted. A transient rather than an
	// option so an install that never sees wp-admin does not leave a row
	// behind for good.
	set_transient( 'niranzwp_just_activated', 1, 60 );
} );
