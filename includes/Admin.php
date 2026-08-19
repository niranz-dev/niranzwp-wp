<?php
/**
 * Admin screens: Configuration, Abilities, Troubleshoot.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const SLUG  = 'niranzwp';
	private const NONCE = 'niranzwp_save';
	private const TOGGLE_NONCE = 'niranzwp_toggle';

	/** Set when the toolbar badge is added, read when its styles are printed. */
	private static bool $badge_rendered = false;

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_niranzwp_save', [ self::class, 'handle_save' ] );
		add_action( 'admin_post_niranzwp_toggle', [ self::class, 'handle_toggle' ] );
		add_action( 'admin_bar_menu', [ self::class, 'admin_bar' ], 100 );
		/*
		 * Printed immediately before the toolbar itself rather than in either
		 * head. admin_bar_menu fires from inside wp_admin_bar_render(), which
		 * runs on in_admin_header and wp_body_open -- both after admin_head
		 * and wp_head, so anything hooked there decides before the badge
		 * exists. This hook fires in the same pass, just earlier, so it runs
		 * exactly when the bar is about to render and never otherwise.
		 */
		add_action( 'wp_before_admin_bar_render', [ self::class, 'bar_styles' ] );
	}

	public static function menu(): void {
		add_menu_page( 'NiranzWP', 'NiranzWP', CAPABILITY, self::SLUG, [ self::class, 'render_configuration' ], 'dashicons-rest-api', 76 );
		add_submenu_page( self::SLUG, __( 'Configuration', 'niranzwp' ), __( 'Configuration', 'niranzwp' ), CAPABILITY, self::SLUG, [ self::class, 'render_configuration' ] );
		add_submenu_page( self::SLUG, __( 'Connections', 'niranzwp' ), __( 'Connections', 'niranzwp' ), CAPABILITY, self::SLUG . '-connections', [ Connections::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Abilities Hub', 'niranzwp' ), __( 'Abilities Hub', 'niranzwp' ), CAPABILITY, self::SLUG . '-abilities', [ Hub::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Context', 'niranzwp' ), __( 'Context', 'niranzwp' ), CAPABILITY, self::SLUG . '-context', [ ContextAdmin::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Skills', 'niranzwp' ), __( 'Skills', 'niranzwp' ), CAPABILITY, self::SLUG . '-skills', [ SkillsAdmin::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Checkpoints', 'niranzwp' ), __( 'Checkpoints', 'niranzwp' ), CAPABILITY, self::SLUG . '-checkpoints', [ CheckpointAdmin::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Troubleshoot', 'niranzwp' ), __( 'Troubleshoot', 'niranzwp' ), CAPABILITY, self::SLUG . '-troubleshoot', [ self::class, 'render_troubleshoot' ] );
	}

	/**
	 * Styles for the toolbar badge.
	 *
	 * Printed rather than enqueued because it is a dozen lines and the badge
	 * appears on every screen the toolbar does. Inter is fetched only for
	 * users who can see the badge, which is administrators, and only the two
	 * weights it uses.
	 */
	public static function bar_styles(): void {
		if ( ! self::$badge_rendered ) {
			return;
		}
		?>
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500&amp;display=swap">
		<style>
			#wpadminbar .nzwp-pill{
				display:inline-block;
				font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
				font-size:12px;
				font-weight:500;
				letter-spacing:.02em;
				line-height:20px;
				color:#fff;
				padding:0 12px;
				border-radius:999px;
				background:linear-gradient(180deg,#ff2424 0%,#e10000 100%);
				/* A thin light edge for the raised surface and a tight shadow
				   for the lift. No outer glow: at 12px in a toolbar it bled
				   into everything around it. */
				box-shadow:
					inset 0 1px 0 rgba(255,255,255,.28),
					0 1px 3px rgba(0,0,0,.35);
				transition:box-shadow .18s ease;
				/* The gloss below is absolutely placed, and clipped to the pill
				   so it sweeps the shape rather than past it. */
				position:relative;
				overflow:hidden;
				isolation:isolate;
			}
			/* A blurred white gloss that springs left to right on hover and
			   settles. Nothing moves until pointed at, which is the difference
			   between this and a badge that keeps asking for attention. */
			#wpadminbar .nzwp-pill::before{
				content:"";
				position:absolute;
				top:-14px;
				bottom:-14px;
				left:0;
				width:30px;
				z-index:2;
				pointer-events:none;
				/* Soft-edged rather than a hard bar, so it reads as light
				   crossing the surface instead of a rectangle sliding over it. */
				background:linear-gradient(90deg,
					rgba(255,255,255,0) 0%,
					rgba(255,255,255,.95) 50%,
					rgba(255,255,255,0) 100%);
				filter:blur(5px);
				opacity:0;
				transform:translateX(-34px) rotate(18deg);
				transition:transform .5s cubic-bezier(.2,.7,.25,1),
				           opacity .18s ease;
			}
			#wpadminbar .nzwp-pill:hover::before{
				opacity:.75;
				transform:translateX(140px) rotate(18deg);
			}
			@media (prefers-reduced-motion: reduce){
				#wpadminbar .nzwp-pill::before{transition-duration:.01ms}
				#wpadminbar .nzwp-pill:hover::before{opacity:.18}
			}
			#wpadminbar .nzwp-pill:hover{
				box-shadow:
					inset 0 1px 0 rgba(255,255,255,.34),
					0 2px 5px rgba(0,0,0,.42);
			}
			/* The toolbar sets its own line-height on links; without this the
			   pill sits low against the 32px bar. */
			#wpadminbar #wp-admin-bar-niranzwp-on > .ab-item{display:flex;align-items:center}
		</style>
		<?php
	}

	/**
	 * The admin bar badge, and what sits under it.
	 *
	 * Red rather than blue on purpose. This is not a status light saying
	 * things are fine - it says something with full write access to the site
	 * is switched on right now, which is worth noticing every time you load a
	 * page rather than only when you go looking.
	 *
	 * The menu reports the three switches separately, because "on" is not one
	 * thing: abilities can be live while file writes are off, and the recovery
	 * guard only exists while file writes are on. A site with file writes
	 * enabled and no guard is the one combination worth shouting about, so it
	 * gets its own line.
	 */
	public static function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! Settings::active() || ! current_user_can( CAPABILITY ) ) {
			return;
		}

		$settings = get_option( OPTION_KEY, [] );
		$files    = is_array( $settings ) && ! empty( $settings['files'] );
		$runtime  = is_array( $settings ) && ! empty( $settings['runtime'] );
		$guard    = Recovery::installed();

		self::$badge_rendered = true;

		$bar->add_node( [
			'id'    => 'niranzwp-on',
			'title' => '<span class="nzwp-pill">NiranzWP ON</span>',
			'href'  => admin_url( 'admin.php?page=' . self::SLUG ),
			'meta'  => [ 'title' => __( 'NiranzWP has write access to this site', 'niranzwp' ) ],
		] );

		$rows = [
			'abilities' => [ __( 'AI Abilities', 'niranzwp' ), true ],
			'files'     => [ __( 'File writes', 'niranzwp' ), $files ],
			'runtime'   => [ __( 'PHP runtime', 'niranzwp' ), $runtime ],
		];

		foreach ( $rows as $key => [ $label, $on ] ) {
			$bar->add_node( [
				'id'     => 'niranzwp-state-' . $key,
				'parent' => 'niranzwp-on',
				'title'  => sprintf(
					'%s: <strong>%s</strong>',
					esc_html( $label ),
					$on ? esc_html__( 'On', 'niranzwp' ) : esc_html__( 'Off', 'niranzwp' )
				),
				'href'   => admin_url( 'admin.php?page=' . self::SLUG ),
			] );
		}

		// Only worth a line when file writes are on, since that is the switch
		// the guard is tied to.
		if ( $files ) {
			$bar->add_node( [
				'id'     => 'niranzwp-state-guard',
				'parent' => 'niranzwp-on',
				'title'  => $guard
					? esc_html__( 'Recovery guard: Installed', 'niranzwp' )
					: '<span style="color:#ffb900">' . esc_html__( 'Recovery guard: MISSING', 'niranzwp' ) . '</span>',
				'href'   => admin_url( 'admin.php?page=' . self::SLUG ),
			] );
		}

		$checkpoints = count( Checkpoint::all( 100 ) );
		$bar->add_node( [
			'id'     => 'niranzwp-checkpoints',
			'parent' => 'niranzwp-on',
			'title'  => sprintf(
				/* translators: %d: number of checkpoints kept. */
				_n( '%d checkpoint kept', '%d checkpoints kept', $checkpoints, 'niranzwp' ),
				$checkpoints
			),
			'href'   => admin_url( 'admin.php?page=' . self::SLUG . '-checkpoints' ),
		] );

		$bar->add_node( [
			'id'     => 'niranzwp-toggle',
			'parent' => 'niranzwp-on',
			'title'  => esc_html__( 'Turn off AI Abilities', 'niranzwp' ),
			'href'   => wp_nonce_url(
				admin_url( 'admin-post.php?action=niranzwp_toggle' ),
				self::TOGGLE_NONCE
			),
		] );

		$bar->add_node( [
			'id'     => 'niranzwp-config',
			'parent' => 'niranzwp-on',
			'title'  => esc_html__( 'Configuration', 'niranzwp' ),
			'href'   => admin_url( 'admin.php?page=' . self::SLUG ),
		] );
	}

	/**
	 * One-click off from the admin bar.
	 *
	 * Only ever switches off. Turning this back on means going to the settings
	 * page and seeing what else is being enabled alongside it, which is not
	 * something a single click from a toolbar should do.
	 */
	public static function handle_toggle(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::TOGGLE_NONCE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}

		Settings::set_enabled( false );

		wp_safe_redirect( add_query_arg( 'niranzwp_off', '1', wp_get_referer() ?: admin_url() ) );
		exit;
	}

	public static function handle_save(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}

		$enabled = isset( $_POST['enabled'] );
		Settings::set_enabled( $enabled );
		$files = isset( $_POST['files'] );
		Settings::set_files( $files );

		// The guard only matters once this plugin can write files, so it is
		// installed and removed with that switch rather than on activation.
		if ( $files ) {
			Recovery::install();
		} else {
			Recovery::uninstall();
		}
		Settings::set_runtime( isset( $_POST['runtime'] ) );
		if ( $enabled ) {
			Settings::remember_domain();
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	private static function styles(): void {
		?>
		<style>
			/* The bar spans the content column, so it has to escape the
			   padding WordPress puts on #wpbody-content. */
			/* Deep violet into a lighter one, angled so the lift runs across the
			   bar rather than straight down. The inner top line is the light
			   catching the edge; the bottom one separates it from the grey
			   admin background without needing a border. */
			.nzwp-bar{
				background:linear-gradient(115deg,#2e1065 0%,#5b21b6 48%,#7c3aed 100%);
				margin:0 0 0 -20px;
				padding:0 20px;
				box-shadow:
					inset 0 1px 0 rgba(255,255,255,.14),
					inset 0 -1px 0 rgba(0,0,0,.22);
			}
			.nzwp-bar-in{display:flex;align-items:center;justify-content:space-between;gap:16px;height:64px;max-width:940px}
			.nzwp-mark{color:#fff;font-weight:800;font-size:19px;letter-spacing:.14em;line-height:1}
			/* Grouped with the wordmark so the bar still has two children and
			   space-between keeps the name left and the state right. Reads as
			   part of the name rather than as another status field. */
			.nzwp-bar-name{display:flex;align-items:center;gap:12px;min-width:0}
			.nzwp-by{color:rgba(255,255,255,.66);font-size:12px;text-decoration:none;line-height:1;border-left:1px solid rgba(255,255,255,.22);padding-left:12px;margin-left:2px}
			.nzwp-by:hover,.nzwp-by:focus{color:rgba(255,255,255,.9)}
			@media(max-width:600px){.nzwp-by{display:none}}
			.nzwp-bar-meta{display:flex;align-items:center;gap:12px}
			.nzwp-ver{color:rgba(255,255,255,.72);font-size:12px;font-variant-numeric:tabular-nums}
			.nzwp-bar .nzwp-on{background:#0e7a53;color:#fff}
			.nzwp-bar .nzwp-off{background:rgba(255,255,255,.16);color:rgba(255,255,255,.85)}
			@media(max-width:782px){.nzwp-bar{margin-left:-10px;padding:0 10px}.nzwp-bar-in{height:56px}}

			.nzwp-wrap{max-width:900px;margin-top:22px}
			.nzwp-head{display:flex;align-items:baseline;gap:12px;margin:0 0 4px}
			.nzwp-head h1{margin:0;font-size:23px}
			.nzwp-sub{color:#646970;margin:0 0 20px}
			.nzwp-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px 24px;margin:0 0 16px}
			.nzwp-card h2{display:flex;align-items:center;gap:10px;margin:0 0 12px;font-size:15px}
			.nzwp-num{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#1d2327;color:#fff;font-size:12px;font-weight:600;flex:none}
			.nzwp-badge{display:inline-block;padding:2px 9px;border-radius:11px;font-size:12px;font-weight:600;line-height:18px}
			.nzwp-on{background:#d5f5e3;color:#0a5c36}
			.nzwp-off{background:#f0f0f1;color:#646970}
			.nzwp-warn{background:#fcf0e0;color:#8a5700}
			.nzwp-code{background:#1d2327;color:#f0f0f1;padding:14px 16px;border-radius:5px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.7;margin:10px 0 6px}
			.nzwp-desc{color:#646970;margin:4px 0 0;font-size:13px}
			.nzwp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:12px}
			.nzwp-stat{border:1px solid #dcdcde;border-radius:5px;padding:12px 14px}
			.nzwp-stat b{display:block;font-size:20px;line-height:1.3}
			.nzwp-stat span{color:#646970;font-size:12px}
			.nzwp-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0 0}
			.nzwp-tab{padding:5px 13px;border:1px solid #dcdcde;border-radius:14px;background:#fff;cursor:pointer;font-size:13px;line-height:1.5}
			.nzwp-tab[aria-selected="true"]{background:#2271b1;border-color:#2271b1;color:#fff;font-weight:600}
			.nzwp-pane{display:none}
			.nzwp-pane.is-on{display:block}
			.nzwp-copywrap{position:relative}
			.nzwp-copy{position:absolute;top:8px;right:8px;background:#3c434a;color:#f0f0f1;border:0;border-radius:4px;padding:4px 10px;font-size:12px;cursor:pointer}
			.nzwp-copy:hover{background:#50575e}
			.nzwp-copy.done{background:#0a5c36}
		</style>
		<?php
	}

	/**
	 * A full-width bar carrying the wordmark, then the page title beneath it.
	 *
	 * The bar sits outside .wrap so it can run the width of the screen the way
	 * WordPress's own admin headers do; .wrap's own top margin is cancelled to
	 * stop a gap opening between the two.
	 */
	public static function header( string $title ): void {
		self::styles();
		?>
		<div class="nzwp-bar">
			<div class="nzwp-bar-in">
				<span class="nzwp-bar-name">
					<span class="nzwp-mark">NIRANZWP</span>
					<a class="nzwp-by" href="https://niranz.dev" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Developed by Niranjan', 'niranzwp' ); ?>
					</a>
				</span>
				<span class="nzwp-bar-meta">
					<span class="nzwp-badge <?php echo Settings::active() ? 'nzwp-on' : 'nzwp-off'; ?>">
						<?php echo Settings::active() ? esc_html__( 'Abilities on', 'niranzwp' ) : esc_html__( 'Abilities off', 'niranzwp' ); ?>
					</span>
					<span class="nzwp-ver"><?php echo esc_html( VERSION ); ?></span>
				</span>
			</div>
		</div>
		<?php
		echo '<div class="wrap nzwp-wrap">';
		echo '<div class="nzwp-head"><h1>' . esc_html( $title ) . '</h1></div>';

		// The bar above already carries the name, version and state. Repeating
		// the site's own name under every heading was noise.
		$blurb = self::blurb( $title );
		if ( '' !== $blurb ) {
			echo '<p class="nzwp-sub">' . esc_html( $blurb ) . '</p>';
		}
	}

	/**
	 * The state of the site at a glance, above the settings.
	 *
	 * Each tile answers a question the owner would otherwise have to go to
	 * another screen to answer, and links to that screen. The counts are read
	 * live rather than cached, because a stale number here is worse than no
	 * number at all -- it is a claim about what is exposed right now.
	 */
	private static function dashboard(): void {
		$abilities = function_exists( 'wp_get_abilities' ) ? count( wp_get_abilities() ) : 0;
		$disabled  = count( Hub::disabled() );
		$skills    = count( Skills::catalogue() );
		$points    = count( Checkpoint::all( 100 ) );
		$guard     = Recovery::installed();

		$tiles = [
			[
				'value' => (string) $abilities,
				'label' => 0 === $disabled
					? __( 'abilities exposed', 'niranzwp' )
					: sprintf( __( 'exposed, %d switched off', 'niranzwp' ), $disabled ),
				'href'  => admin_url( 'admin.php?page=niranzwp-abilities' ),
				'tone'  => Settings::active() ? 'on' : 'off',
			],
			[
				'value' => (string) $skills,
				'label' => __( 'skills available', 'niranzwp' ),
				'href'  => admin_url( 'admin.php?page=niranzwp-skills' ),
				'tone'  => '',
			],
			[
				'value' => (string) $points,
				'label' => __( 'checkpoints kept', 'niranzwp' ),
				'href'  => admin_url( 'admin.php?page=niranzwp-checkpoints' ),
				'tone'  => '',
			],
			[
				'value' => $guard ? __( 'Armed', 'niranzwp' ) : __( 'Off', 'niranzwp' ),
				'label' => $guard
					? __( 'recovery guard', 'niranzwp' )
					: __( 'recovery guard -- on with filesystem', 'niranzwp' ),
				'href'  => '',
				'tone'  => $guard ? 'on' : 'off',
			],
		];
		?>
		<style>
			.nzwp-dash{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:0 0 22px}
			/* A shallow gradient rather than flat white: enough for the tile to
			   sit on the grey background instead of dissolving into it, not so
			   much that four of them start competing with the page. */
			.nzwp-dash a,.nzwp-dash div.tile{
				display:flex;
				flex-direction:column;
				justify-content:center;
				min-height:82px;
				background:linear-gradient(180deg,#fff 0%,#fbfbfc 100%);
				border:1px solid #e0e0e4;
				border-radius:8px;
				padding:16px 18px;
				text-decoration:none;
				color:inherit;
				box-shadow:0 1px 2px rgba(16,24,40,.04);
				transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease;
			}
			/* Only the linked tiles lift. The recovery guard tile goes nowhere,
			   so pretending it is clickable would be a lie. */
			.nzwp-dash a:hover{
				border-color:#c4b5fd;
				box-shadow:0 3px 10px rgba(76,29,149,.10);
				transform:translateY(-1px);
			}
			.nzwp-dash a:focus-visible{outline:2px solid #7c3aed;outline-offset:2px}
			.nzwp-dash b{
				display:block;
				font-size:27px;
				font-weight:600;
				line-height:1.1;
				letter-spacing:-.02em;
				font-variant-numeric:tabular-nums lining-nums;
				color:#1d2327;
			}
			.nzwp-dash b.on{color:#0a5c36}
			.nzwp-dash b.off{color:#8c8f94}
			/* Small caps under the figure, the way a table of figures labels a
			   column. Keeps the number the thing the eye lands on. */
			.nzwp-dash span{
				display:block;
				margin-top:7px;
				color:#646970;
				font-size:11px;
				font-weight:500;
				letter-spacing:.045em;
				text-transform:uppercase;
			}
			@media(max-width:782px){
				.nzwp-dash{gap:10px}
				.nzwp-dash a,.nzwp-dash div.tile{min-height:0;padding:14px 16px}
				.nzwp-dash b{font-size:23px}
			}
		</style>
		<div class="nzwp-dash">
			<?php foreach ( $tiles as $t ) : ?>
				<?php if ( '' !== $t['href'] ) : ?>
					<a href="<?php echo esc_url( $t['href'] ); ?>">
						<b class="<?php echo esc_attr( $t['tone'] ); ?>"><?php echo esc_html( $t['value'] ); ?></b>
						<span><?php echo esc_html( $t['label'] ); ?></span>
					</a>
				<?php else : ?>
					<div class="tile">
						<b class="<?php echo esc_attr( $t['tone'] ); ?>"><?php echo esc_html( $t['value'] ); ?></b>
						<span><?php echo esc_html( $t['label'] ); ?></span>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/** One line saying what this screen is for. */
	private static function blurb( string $title ): string {
		switch ( $title ) {
			case __( 'Abilities Hub', 'niranzwp' ):
				return __( 'Everything a connected client can reach, and a switch on each one.', 'niranzwp' );
			case __( 'Context', 'niranzwp' ):
				return __( 'The standing brief every connected client reads before it does anything.', 'niranzwp' );
			case __( 'Skills', 'niranzwp' ):
				return __( 'Instructions for a particular job, loaded when that job comes up.', 'niranzwp' );
			case __( 'Checkpoints', 'niranzwp' ):
				return __( 'What was here before the last few changes, and how to put it back.', 'niranzwp' );
			case __( 'Connections', 'niranzwp' ):
				return __( 'What is currently connected to this site, and how to disconnect it.', 'niranzwp' );
			case __( 'Troubleshoot', 'niranzwp' ):
				return __( 'What is wrong, and what to do about it.', 'niranzwp' );
			default:
				return __( 'What this site exposes to command-line tools and AI clients.', 'niranzwp' );
		}
	}

	public static function render_configuration(): void {
		self::header( __( 'NiranzWP', 'niranzwp' ) );

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'niranzwp' ) . '</p></div>';
		}
		if ( ! Settings::domain_matches() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Abilities were enabled for a different domain, so they are inactive here. Save again to enable them on this domain.', 'niranzwp' ) . '</p></div>';
		}

		$enabled  = Settings::enabled();
		$host     = Settings::current_domain();
		$mcp_up   = class_exists( '\WP\MCP\Core\McpAdapter' );
		$count    = count( self::own_abilities() );

		self::dashboard();
		?>

		<div class="nzwp-card">
			<h2><span class="nzwp-num">1</span><?php esc_html_e( 'Enable abilities', 'niranzwp' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="niranzwp_save">
				<?php wp_nonce_field( self::NONCE ); ?>
				<p>
					<input type="checkbox" name="enabled" id="nzwp-enabled" value="1" autocomplete="off" style="position:relative;z-index:1" <?php checked( $enabled ); ?>>
					<label for="nzwp-enabled" style="cursor:pointer;font-weight:600">
						<?php esc_html_e( 'Allow connected clients to use this site\'s abilities', 'niranzwp' ); ?>
					</label>
				</p>
				<p class="nzwp-desc">
					<?php esc_html_e( 'Abilities are admin-only, and the write ones preview before they change anything. They are still real access to this site, so leave this off unless something is connecting.', 'niranzwp' ); ?>
				</p>
				<p class="nzwp-desc">
					<?php esc_html_e( 'Access is locked to this domain. Restoring the database elsewhere will not carry it over.', 'niranzwp' ); ?>
				</p>

				<p style="margin-top:16px">
					<input type="checkbox" name="files" id="nzwp-files" value="1" autocomplete="off" style="position:relative;z-index:1" <?php checked( Settings::files_enabled() ); ?>>
					<label for="nzwp-files" style="cursor:pointer;font-weight:600">
						<?php esc_html_e( 'Also allow filesystem abilities', 'niranzwp' ); ?>
					</label>
				</p>
				<p class="nzwp-desc">
					<?php esc_html_e( 'Read, write and delete files inside the WordPress install. Writes and deletes preview first; wp-config.php and the core directories are always refused. Leave this off on a production site.', 'niranzwp' ); ?>
				</p>

				<p style="margin-top:16px">
					<input type="checkbox" name="runtime" id="nzwp-runtime" value="1" autocomplete="off" style="position:relative;z-index:1" <?php checked( Settings::runtime_enabled() ); ?>>
					<label for="nzwp-runtime" style="cursor:pointer;font-weight:600">
						<?php esc_html_e( 'Also allow PHP evaluation and WP-CLI', 'niranzwp' ); ?>
						<span class="nzwp-badge nzwp-warn"><?php esc_html_e( 'full control', 'niranzwp' ); ?></span>
					</label>
				</p>
				<p class="nzwp-desc">
					<?php esc_html_e( 'Lets a connected client evaluate PHP inside this site and run WP-CLI commands against it. Whoever holds the credential can do anything WordPress can do. Development and staging only.', 'niranzwp' ); ?>
				</p>
				<?php submit_button( __( 'Save settings', 'niranzwp' ), 'primary', 'submit', false ); ?>
			</form>

			<div class="nzwp-grid">
				<div class="nzwp-stat"><b><?php echo (int) $count; ?></b><span><?php esc_html_e( 'abilities registered', 'niranzwp' ); ?></span></div>
				<div class="nzwp-stat"><b><?php echo $enabled ? '&#10003;' : '&mdash;'; ?></b><span><?php esc_html_e( 'clients allowed', 'niranzwp' ); ?></span></div>
				<div class="nzwp-stat"><b><?php echo $mcp_up ? '&#10003;' : '&mdash;'; ?></b><span><?php esc_html_e( 'MCP adapter loaded', 'niranzwp' ); ?></span></div>
				<div class="nzwp-stat"><b><?php echo count( Connections::all() ); ?></b><span><a href="<?php echo esc_url( admin_url( 'admin.php?page=niranzwp-connections' ) ); ?>"><?php esc_html_e( 'connected clients', 'niranzwp' ); ?></a></span></div>
			</div>
		</div>

		<div class="nzwp-card">
			<h2><span class="nzwp-num">2</span><?php esc_html_e( 'Connect a client', 'niranzwp' ); ?></h2>
			<p class="nzwp-desc"><?php esc_html_e( 'Pick what you use. Copy the block, paste it where it says.', 'niranzwp' ); ?></p>

			<div class="nzwp-tabs" role="tablist">
				<?php foreach ( self::clients( $host ) as $i => $c ) : ?>
					<button type="button" class="nzwp-tab" role="tab"
						aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"
						data-pane="nzwp-pane-<?php echo (int) $i; ?>"><?php echo esc_html( $c['label'] ); ?></button>
				<?php endforeach; ?>
			</div>

			<?php foreach ( self::clients( $host ) as $i => $c ) : ?>
				<div class="nzwp-pane <?php echo 0 === $i ? 'is-on' : ''; ?>" id="nzwp-pane-<?php echo (int) $i; ?>">
					<p class="nzwp-desc" style="margin-top:14px"><?php echo esc_html( $c['note'] ); ?></p>
					<div class="nzwp-copywrap">
						<button type="button" class="nzwp-copy"><?php esc_html_e( 'Copy', 'niranzwp' ); ?></button>
						<div class="nzwp-code"><?php echo esc_html( $c['code'] ); ?></div>
					</div>
					<?php if ( ! empty( $c['where'] ) ) : ?>
						<p class="nzwp-desc"><strong><?php esc_html_e( 'Paste into:', 'niranzwp' ); ?></strong> <code><?php echo esc_html( $c['where'] ); ?></code></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<script>
		(function () {
			var wrap = document.querySelectorAll('.nzwp-tab');
			wrap.forEach(function (tab) {
				tab.addEventListener('click', function () {
					wrap.forEach(function (t) {
						t.setAttribute('aria-selected', String(t === tab));
					});
					document.querySelectorAll('.nzwp-pane').forEach(function (p) {
						p.classList.toggle('is-on', p.id === tab.dataset.pane);
					});
				});
			});
			document.querySelectorAll('.nzwp-copy').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var text = btn.parentNode.querySelector('.nzwp-code').innerText;
					navigator.clipboard.writeText(text).then(function () {
						var was = btn.textContent;
						btn.textContent = 'Copied';
						btn.classList.add('done');
						setTimeout(function () { btn.textContent = was; btn.classList.remove('done'); }, 1600);
					});
				});
			});
		}());
		</script>
		</div>
		<?php
	}

	/**
	 * Connection snippets per client. The CLI needs no configuration file at
	 * all, so it comes first; the MCP clients each want their own JSON in
	 * their own location.
	 *
	 * @return array<int,array{label:string,note:string,code:string,where:string}>
	 */
	private static function clients( string $host ): array {
		$endpoint = Mcp::endpoint();
		$slug     = 'niranzwp-' . str_replace( '.', '-', $host );

		return [
			[
				'label' => 'NiranzWP CLI',
				'note'  => __( 'No config file. Install it, approve once in the browser, done.', 'niranzwp' ),
				'code'  => "npm install -g niranzwp\nniranzwp auth login {$host}\n\nniranzwp seo audit\nniranzwp geo check",
				'where' => '',
			],
			[
				'label' => 'Claude Code',
				'note'  => __( 'Run this in your terminal.', 'niranzwp' ),
				'code'  => "claude mcp add --transport http {$slug} \\\n  {$endpoint}",
				'where' => '',
			],
			[
				'label' => 'Claude Desktop',
				'note'  => __( 'Add this inside the mcpServers block.', 'niranzwp' ),
				'code'  => "{\n  \"mcpServers\": {\n    \"{$slug}\": {\n      \"type\": \"http\",\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
				'where' => '~/Library/Application Support/Claude/claude_desktop_config.json',
			],
			[
				'label' => 'Cursor',
				'note'  => __( 'Add this inside the mcpServers block.', 'niranzwp' ),
				'code'  => "{\n  \"mcpServers\": {\n    \"{$slug}\": {\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
				'where' => '~/.cursor/mcp.json',
			],
			[
				'label' => 'Codex',
				'note'  => __( 'Add this to your Codex config.', 'niranzwp' ),
				'code'  => "[mcp_servers.{$slug}]\nurl = \"{$endpoint}\"",
				'where' => '~/.codex/config.toml',
			],
		];
	}

	/** @return array<int,\WP_Ability> */
	private static function own_abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}
		$out = [];
		foreach ( wp_get_abilities() as $key => $a ) {
			$name = is_object( $a ) && method_exists( $a, 'get_name' ) ? $a->get_name() : (string) $key;
			if ( str_starts_with( $name, 'niranzwp/' ) ) {
				$out[ $name ] = $a;
			}
		}
		ksort( $out );
		return $out;
	}

	public static function render_abilities(): void {
		self::header( __( 'Abilities', 'niranzwp' ) );

		$mine = self::own_abilities();
		echo '<div class="nzwp-card">';
		echo '<p class="nzwp-desc">' . esc_html( sprintf(
			/* translators: %d: number of abilities */
			__( '%d abilities registered by NiranzWP. Read-only ones are safe to run at any time; write ones preview before changing anything.', 'niranzwp' ),
			count( $mine )
		) ) . '</p>';

		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr>';
		echo '<th>' . esc_html__( 'Ability', 'niranzwp' ) . '</th><th style="width:90px">' . esc_html__( 'Type', 'niranzwp' ) . '</th><th>' . esc_html__( 'What it does', 'niranzwp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $mine as $name => $a ) {
			$meta     = is_object( $a ) && method_exists( $a, 'get_meta' ) ? (array) $a->get_meta() : [];
			$readonly = ! empty( $meta['annotations']['readonly'] );
			$desc     = is_object( $a ) && method_exists( $a, 'get_description' ) ? $a->get_description() : '';

			printf(
				'<tr><td><code>%s</code></td><td><span class="nzwp-badge %s">%s</span></td><td>%s</td></tr>',
				esc_html( $name ),
				$readonly ? 'nzwp-on' : 'nzwp-warn',
				$readonly ? esc_html__( 'read', 'niranzwp' ) : esc_html__( 'write', 'niranzwp' ),
				esc_html( $desc )
			);
		}

		echo '</tbody></table></div></div>';
	}

	public static function render_troubleshoot(): void {
		self::header( __( 'Troubleshoot', 'niranzwp' ) );

		echo '<div class="nzwp-card"><table class="widefat striped"><thead><tr>';
		echo '<th style="width:70px">' . esc_html__( 'Status', 'niranzwp' ) . '</th><th style="width:230px">' . esc_html__( 'Check', 'niranzwp' ) . '</th><th>' . esc_html__( 'Detail', 'niranzwp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( self::checks() as $check ) {
			$icon = 'pass' === $check['status'] ? '&#9989;' : ( 'warn' === $check['status'] ? '&#9888;&#65039;' : '&#10060;' );
			printf(
				'<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td></tr>',
				$icon,
				esc_html( $check['label'] ),
				esc_html( $check['detail'] )
			);
		}

		echo '</tbody></table></div></div>';
	}

	/** @return array<int,array{status:string,label:string,detail:string}> */
	private static function checks(): array {
		$wp    = get_bloginfo( 'version' );
		$https = str_starts_with( home_url(), 'https://' );
		$local = 'local' === wp_get_environment_type();
		$env   = wp_get_environment_type();

		$out = [
			[
				'status' => version_compare( $wp, '6.9', '>=' ) ? 'pass' : 'fail',
				'label'  => 'WordPress 6.9+',
				'detail' => 'Running ' . $wp . '. The Abilities API needs 6.9.',
			],
			[
				'status' => version_compare( PHP_VERSION, '8.0', '>=' ) ? 'pass' : 'fail',
				'label'  => 'PHP 8.0+',
				'detail' => 'Running ' . PHP_VERSION . '.',
			],
			[
				'status' => function_exists( 'wp_register_ability' ) ? 'pass' : 'fail',
				'label'  => 'Abilities API',
				'detail' => function_exists( 'wp_register_ability' ) ? 'Available.' : 'Missing - abilities cannot register.',
			],
			[
				'status' => count( self::own_abilities() ) > 0 ? 'pass' : 'fail',
				'label'  => 'Abilities registered',
				'detail' => count( self::own_abilities() ) . ' registered by NiranzWP.',
			],
			[
				'status' => class_exists( '\WP\MCP\Core\McpAdapter' ) ? 'pass' : 'warn',
				'label'  => 'MCP adapter',
				'detail' => class_exists( '\WP\MCP\Core\McpAdapter' )
					? 'Loaded. Endpoint: ' . Mcp::endpoint()
					: 'Not loaded. Install from the release ZIP, which bundles vendor dependencies.',
			],
			[
				'status' => ( $https || $local ) ? 'pass' : 'fail',
				'label'  => 'HTTPS for credentials',
				'detail' => $https ? 'Site is served over HTTPS.' : ( $local ? 'Not HTTPS, but the environment is marked local.' : 'WordPress disables Application Passwords without HTTPS.' ),
			],
			[
				'status' => wp_is_application_passwords_available() ? 'pass' : 'fail',
				'label'  => 'Application Passwords',
				'detail' => wp_is_application_passwords_available() ? 'Available.' : 'Disabled - a security plugin may be blocking them.',
			],
			[
				'status' => Settings::active() ? 'pass' : 'warn',
				'label'  => 'Abilities enabled',
				'detail' => Settings::active() ? 'On for ' . Settings::current_domain() . '.' : 'Off. Enable them under Configuration.',
			],
			[
				'status' => 'production' === $env ? 'warn' : 'pass',
				'label'  => 'Environment',
				'detail' => 'Reported as "' . $env . '".' . ( 'production' === $env ? ' Prefer staging for agent work.' : '' ),
			],
		];

		return $out;
	}
}
