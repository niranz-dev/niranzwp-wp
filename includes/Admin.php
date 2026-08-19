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
/* NiranzWP admin surface — printed from Admin::styles().
   One inline style element. No build step, no external CSS, no JS for visuals.

   THESIS. "Basic" here was a spacing-and-typography problem, not a
   missing-gradient problem. The page is re-set as a well-typeset document:
   small-caps labels over tabular figures, hairline rules, one 900px measure
   that the masthead and every card align to. Gradient is then spent in
   exactly three places where it does work — the masthead panel, the 2px
   brand rule under it, and the "Abilities on" pill — and each of the three
   was picked from its LIGHTEST stop backwards, so the top half of a fill is
   as legible as the bottom.

   TWO RULES THE WHOLE FILE OBEYS.
   1. Every painted surface states background AND colour. Admin colour
      schemes (Midnight, Ectoplasm, Coffee, third-party dark-mode plugins)
      repaint what sits underneath us; a surface that sets only one of the
      pair can end up with its own text invisible on someone else's ground.
   2. No blue is borrowed for state. In wp-admin blue belongs to the user's
      colour scheme and changes under us, so selection is ink and focus is
      ink. Links stay blue, because they are links.

   PALETTE. violet #2e1065 -> #7c3aed (masthead), navy #12326b (step markers) · brand red #ff2424→#e10000
   (one 2px rule, nothing else) · green #0e7a53 / #0a5c36 ("on" right now) ·
   amber #8a5700 ("this can write") · core greys #1d2327 / #50575e / #dcdcde.
*/

/* wp-admin does not guarantee border-box on plugin markup and every box
   below is sized with padding. Scoped, so core markup is untouched. */
.nzwp-bar,.nzwp-bar *,.nzwp-wrap,.nzwp-wrap *{box-sizing:border-box}

/* ============================================================ masthead == */

/* The bar spans the content column, so it escapes the padding WordPress
   puts on #wpcontent: 20px on desktop, 10px at ≤782px (see the responsive
   block). Those two numbers are WP's, not a guess — keep them paired.

   Two background layers, one flourish. The navy ramp is diagonal and light
   at the LEFT, so the wordmark sits in the light and the green state pill
   sits on the deep end where it separates best. ~10% lightness travel: a
   panel catching light, not a decorative sweep that will date. White holds
   9.0:1 even on the lightest stop. */
.nzwp-bar{
	margin:0 0 0 -20px;padding:0 20px;
	background-color:#5b21b6;
	background-image:linear-gradient(115deg,#2e1065 0%,#5b21b6 48%,#7c3aed 100%);
	/* Light-on-dark only. Applied to the light half of the page it thins
	   body copy; here it stops white 800-weight caps blooming. */
	-webkit-font-smoothing:antialiased;
	-moz-osx-font-smoothing:grayscale;
	/* Keeps Chrome's auto-dark heuristics from inverting the one dark band
	   on a page whose surroundings stay light. */
	color-scheme:light;
}
.nzwp-bar-in{
	display:flex;align-items:center;justify-content:space-between;
	gap:16px;height:64px;
	/* Was 940px against a 900px page: the version number floated 40px past
	   the right edge of every card below it. Same measure, one alignment. */
	max-width:900px;
	font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
}
.nzwp-bar-name{display:flex;align-items:center;gap:12px;min-width:0;overflow:hidden}
.nzwp-mark{
	flex:none;color:#fff;font-weight:800;font-size:18px;letter-spacing:.16em;
	line-height:1;white-space:nowrap;
	/* letter-spacing hangs a full space off the final P; pull it back so the
	   divider sits optically centred in the 12px gap, not 12px + .16em. */
	margin-right:-.16em;
}
/* .58 white measured 4.48:1 against the light end of this ramp — under the
   line by a hair. .68 and .74 are the smallest values that hold 4.5:1
   across the whole bar (5.1:1 and 5.9:1 at the worst point). min-width:0 +
   ellipsis so a long translation truncates instead of pushing the state
   pill off the measure. */
.nzwp-by{
	min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
	color:rgba(255,255,255,.68);font-size:12px;line-height:1;text-decoration:none;
	border-left:1px solid rgba(255,255,255,.24);padding-left:12px;margin-left:2px;
	transition:color .12s ease;
}
.nzwp-by:hover,.nzwp-by:focus{color:#fff}
/* Same divider treatment as the attribution, so the three items read as one
   run of small print rather than three separate claims. */
.nzwp-free{
	flex:none;color:rgba(255,255,255,.68);font-size:12px;line-height:1;white-space:nowrap;
	border-left:1px solid rgba(255,255,255,.24);padding-left:12px;
}
/* The mark is a link with no text, so it carries its label in aria-label and
   title rather than relying on the icon to say what it is. */
.nzwp-gh{
	flex:none;width:17px;height:17px;
	background-color:rgba(255,255,255,.72);
	-webkit-mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12'/%3E%3C/svg%3E");
	-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;
	-webkit-mask-position:center;mask-position:center;
	-webkit-mask-size:contain;mask-size:contain;
	transition:background-color .12s ease;
}
.nzwp-gh:hover,.nzwp-gh:focus{background-color:#fff}
@supports not ((-webkit-mask-image:none) or (mask-image:none)){.nzwp-gh{display:none}}
/* The bar has a fixed height and the wordmark and state pill are the two
   things that must survive any width, so the small print goes first. */
@media(max-width:1100px){.nzwp-free{display:none}}
/* flex:none so the state and version never compress; they are the two
   things in the bar that must stay readable at any width. */
.nzwp-bar-meta{display:flex;align-items:center;gap:12px;flex:none}
.nzwp-ver{color:rgba(255,255,255,.74);font-size:12px;letter-spacing:.02em;font-variant-numeric:tabular-nums}

/* In the bar only, the status chip echoes the toolbar pill: full round, and
   the one shallow gradient on the page that carries white text. #0f815a is
   the lightest green that still holds 4.5:1 under white 12px bold (4.88:1);
   the bottom stop is 5.9:1. That is the whole trick — pick the top stop
   first, then ramp down. */
.nzwp-bar .nzwp-badge{border-radius:999px;padding:3px 11px}
.nzwp-bar .nzwp-on{
	background-color:#0e7a53;
	background-image:linear-gradient(180deg,#0f815a,#0c6a49);
	color:#fff;box-shadow:inset 0 1px 0 rgba(255,255,255,.20);
}
.nzwp-bar .nzwp-off{
	background-color:rgba(255,255,255,.14);
	background-image:none;
	color:rgba(255,255,255,.92);box-shadow:inset 0 0 0 1px rgba(255,255,255,.22);
}

/* =========================================================== page head == */

.nzwp-wrap{
	max-width:900px;margin-top:24px;
	color-scheme:light;
}
.nzwp-head{display:flex;align-items:baseline;flex-wrap:wrap;gap:12px;margin:0 0 8px}
/* With the heading hidden the row collapses, and its bottom margin would
   still push the subtitle down by 8px of nothing. */
.nzwp-head:has(> .screen-reader-text){margin:0}
/* Core prints .wrap h1 at 23px/400 with 9px of top padding. Same ballpark,
   one weight up and the padding taken back so the spacing below is ours. */
.nzwp-head h1{
	margin:0;padding:0;font-size:24px;font-weight:600;line-height:1.25;
	letter-spacing:-.012em;color:#1d2327;text-wrap:balance;
}
/* A measure, not a full-bleed line: 900px of 14px text runs to ~110
   characters, which is a genuinely hard read. */
.nzwp-sub{color:#50575e;font-size:14px;line-height:1.6;margin:0 0 22px;max-width:66ch;text-wrap:pretty}

/* ======================================================= the figures ==== */
/* Set as a table of figures rather than four little boxes: label above in
   tracked caps, figure below in tabular lining numerals, hairlines between.
   This is the part that actually answers "looks basic", and it does it with
   alignment and type instead of colour.

   The hairlines are the 1px gap plus a 1px spread shadow on every CELL —
   not a background on the container. Both draw the same grid when the row
   is full, but the container trick paints a solid grey slab into the empty
   tracks of a ragged last row (4 items in a 3-column layout), and this one
   leaves them white. Edge cells' shadows are clipped by overflow:hidden, so
   the outer frame stays exactly 1px. */
/* A violet hairline along the top edge ties the strip to the masthead
   without tinting the surface people read numbers off. Drawn as a background
   layer rather than a border-top so the 1px cell shadows below still meet the
   frame cleanly. */
.nzwp-grid{
	display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
	gap:1px;
	background-color:#fff;
	background-image:linear-gradient(90deg,#5b21b6,#7c3aed);
	background-repeat:no-repeat;
	background-size:100% 2px;
	background-position:left top;
	border:1px solid #dcdcde;border-radius:6px;overflow:hidden;
	margin:18px 0 4px;
}
.nzwp-stat{
	background:#fff;color:#1d2327;border:0;border-radius:0;padding:15px 16px;
	box-shadow:0 0 0 1px #dcdcde;
	display:flex;flex-direction:column;align-items:flex-start;gap:7px;
	min-width:0;overflow-wrap:break-word;
}
/* Financial-table reading order is label-then-figure; DOM order is
   figure-then-label. Explicit `order` rather than column-reverse, so any
   third node someone adds later keeps order 0 and lands at the top instead
   of being silently flipped to the bottom. Screen-reader order is unchanged
   ("92, abilities registered"), which is the natural one. */
/* #5b21b6 on white is 8.9:1, so the label can carry the colour at 11px
   without dropping below the small-text floor. The figure stays ink: colour
   on the label says which column, colour on the number would say something
   about its value. */
.nzwp-stat span{
	order:1;color:#5b21b6;font-size:11px;font-weight:600;line-height:1.4;
	letter-spacing:.06em;text-transform:uppercase;
}
.nzwp-stat b{
	order:2;display:block;font-size:26px;font-weight:600;line-height:1.15;
	letter-spacing:-.02em;color:#1d2327;
	/* Tabular + lining so 92 and 30 occupy identical width and sit on one
	   optical baseline across the row. 26px rather than 30px because these
	   cells are not always numbers — Context prints "Yours + system" and
	   "production" into the same slot, and 30px wraps those to three lines. */
	font-variant-numeric:tabular-nums lining-nums;
	font-feature-settings:"tnum" 1,"lnum" 1;
}
/* Stated, not inherited: an admin colour scheme owns bare `a` at (0,1,0)
   and would otherwise repaint this link and kill its hover. */
/* The plugin's own violet rather than the admin scheme's blue, which changes
   under us. 8.9:1 on white, and underlined so colour is not the only signal. */
.nzwp-stat span a{color:#5b21b6;text-decoration:underline;text-underline-offset:2px}
.nzwp-stat span a:hover{color:#4c1d95}
.nzwp-stat span a:hover{color:#135e96}

/* ==================================================== dashboard tiles === */
/* Not tiles any more. Four boxes on a grey page spend most of their area on
   nothing and read as widgets; set as a plain row of figures with hairline
   dividers, the same four facts read as a masthead for the page. The markup
   still says a/div and b/span, so this is entirely a matter of taking the
   containers away.

   dashboard() prints its own style element after Admin::styles(), so these
   rules carry the .nzwp-wrap ancestor and win on specificity rather than on
   source order. */
.nzwp-wrap .nzwp-dash{
	display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
	gap:0;margin:2px 0 26px;
}
.nzwp-wrap .nzwp-dash a,
.nzwp-wrap .nzwp-dash div.tile{
	display:block;padding:2px 22px;text-decoration:none;
	background:none;border:0;border-radius:0;box-shadow:none;
	color:#1d2327;
	/* The divider sits on the left of every cell and is removed from the
	   first, so a row that wraps to two lines still divides correctly - a
	   right-hand border would leave a stray line at the end of each row. */
	border-left:1px solid #e0e0e4;
	transition:none;
}
.nzwp-wrap .nzwp-dash a:first-child,
.nzwp-wrap .nzwp-dash div.tile:first-child{border-left:0;padding-left:0}
/* Nothing to lift, so hover speaks in the only two things left: the figure
   takes the brand violet and the label darkens. */
.nzwp-wrap .nzwp-dash a:hover b{color:#5b21b6}
.nzwp-wrap .nzwp-dash a:hover span{color:#1d2327}
.nzwp-wrap .nzwp-dash a:hover b.on{color:#0a5c36}
.nzwp-wrap .nzwp-dash div.tile{cursor:default}
.nzwp-wrap .nzwp-dash b{
	display:block;font-size:38px;font-weight:600;line-height:1.05;
	letter-spacing:-.03em;color:#1d2327;
	font-variant-numeric:tabular-nums lining-nums;
}
/* Green survives only where it means something: this thing is on now. */
.nzwp-wrap .nzwp-dash b.on{color:#0a5c36}
.nzwp-wrap .nzwp-dash b.off{color:#6b7075}
/* Caps below the figure, the way a column of figures is labelled. These carry
   clauses - "abilities, 3 switched off" - so they stay small rather than being
   set in the same tracked caps as the figure strip further down. */
.nzwp-wrap .nzwp-dash span{
	display:block;margin-top:7px;color:#50575e;
	font-size:12px;font-weight:500;line-height:1.4;letter-spacing:.01em;
}
@media(max-width:782px){
	.nzwp-wrap .nzwp-dash{grid-template-columns:repeat(auto-fit,minmax(140px,1fr));row-gap:20px}
	.nzwp-wrap .nzwp-dash a,
	.nzwp-wrap .nzwp-dash div.tile{padding:2px 16px}
	.nzwp-wrap .nzwp-dash b{font-size:30px}
}

/* ============================================================== cards === */

.nzwp-card{
	background:#fff;color:#1d2327;
	border:1px solid #dcdcde;border-radius:6px;
	padding:22px 24px 24px;margin:0 0 16px;
	box-shadow:0 1px 1px rgba(0,0,0,.035);
}
/* Kills the phantom gutters that make padding look inconsistent card to
   card — a first paragraph's top margin, a last table's bottom margin. */
.nzwp-card>:first-child{margin-top:0}
.nzwp-card>:last-child{margin-bottom:0}
.nzwp-card h2{
	display:flex;align-items:center;gap:10px;
	margin:0 0 16px;padding:0 0 12px;
	font-size:15px;font-weight:600;line-height:1.35;letter-spacing:-.005em;color:#1d2327;
	/* One step lighter than the card border, so the card still reads as the
	   outer boundary and the heading rule reads as an internal division. */
	border-bottom:1px solid #ebebed;
}
/* A status badge that closes a heading goes to the rule's right end.
   :last-child so a badge sitting mid-heading stays where the markup put it. */
.nzwp-card h2>.nzwp-badge:last-child{margin-left:auto}
/* Ring, not a filled dot: the numeral is a wayfinding mark, not a second
   heading, and a black disc out-weighed the title beside it. Navy rather
   than grey ties the step markers to the masthead without inventing a hue
   — and navy is the one place on this page it is safe to spend colour,
   because red in wp-admin means destructive and these are just steps. */
.nzwp-num{
	display:inline-flex;align-items:center;justify-content:center;
	width:22px;height:22px;border-radius:50%;flex:none;
	background:transparent;border:1px solid #cfc4ea;color:#4c1d95;
	font-size:11px;font-weight:700;line-height:1;font-variant-numeric:tabular-nums;
}
.nzwp-desc{color:#50575e;margin:6px 0 0;font-size:13px;line-height:1.6;max-width:72ch}
.nzwp-desc code{font-size:12.5px}

/* ============================================================= badges === */
/* Tags, not pills, in content: 3px radius, sentence case. Uppercase was
   tempting and rejected — Abilities Hub prints up to three badges on each
   of ~90 rows, and caps there shout. Tint carries the state; an inset
   hairline carries the edge, which is cheaper than a border and keeps the
   badge's exact height inside a baseline-aligned row. All three clear
   5.6:1 against their own fill; nowrap so a translated label cannot break
   inside the lozenge. */
.nzwp-badge{
	display:inline-flex;align-items:center;white-space:nowrap;
	padding:3px 9px;border-radius:3px;
	font-size:12px;font-weight:600;line-height:1.45;
}
.nzwp-on{background:#e6f4ec;color:#0a5c36;box-shadow:inset 0 0 0 1px rgba(10,92,54,.18)}   /* 6.6:1 */
.nzwp-off{background:#f6f7f7;color:#50575e;box-shadow:inset 0 0 0 1px rgba(30,35,40,.13)}  /* 7.0:1 */
.nzwp-warn{background:#fcf3e4;color:#8a5700;box-shadow:inset 0 0 0 1px rgba(138,87,0,.20)} /* 5.6:1 */

/* =============================================== code and copy button === */

/* white-space:pre is a bug fix, not a style. .nzwp-code is a <div> fed
   implode("\n", …) (Admin::clients() and Hub::render()), and with no
   white-space set every one of those snippets — the Claude Desktop JSON
   especially — collapses to a single line. The Copy button reads
   .innerText, which is layout-aware, so it has been copying that collapsed
   line too. This fixes what people paste, not just what they see. */
/* Dressed as a terminal window, because that is what it is: every block on
   this page is something you paste into a shell. The chrome is drawn with
   pseudo-elements, since the markup is a bare div and cannot gain a title bar
   of its own.
   ::before is the bar; ::after is the left traffic light, with the other two
   hung off it as box-shadows so three dots cost one element. Both are pinned
   to the block rather than scrolling with it, so they stay put when a long
   command scrolls sideways. */
.nzwp-code{
	position:relative;
	background:#1e2227;color:#e6e8ea;
	white-space:pre;overflow:auto;
	/* 38px of the top padding is the title bar the ::before paints over. */
	padding:52px 17px 16px;border-radius:8px;margin:12px 0 6px;
	border:1px solid #0d0f11;
	box-shadow:0 1px 2px rgba(0,0,0,.16),0 6px 18px rgba(0,0,0,.10);
	font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;
	font-size:12.5px;line-height:1.75;
	/* -> and != must stay two glyphs in a command someone may retype. */
	font-variant-ligatures:none;
	box-shadow:inset 0 0 0 1px rgba(255,255,255,.07);
	/* Shell commands and JSON are LTR even on an RTL admin. */
	direction:ltr;text-align:left;
}
.nzwp-code::selection,.nzwp-code ::selection{background:#6d28d9;color:#fff}
/* The title bar. position:sticky rather than absolute so it stays across the
   full scroll width of a long line instead of ending at the visible edge. */
.nzwp-code::before{
	content:"";
	position:sticky;left:0;
	display:block;
	margin:-52px -17px 14px;
	height:38px;
	background:linear-gradient(180deg,#2b3138,#252a30);
	border-bottom:1px solid #0d0f11;
	border-radius:7px 7px 0 0;
}
/* One dot, and two more hung off it. Sizes and colours are the ones macOS
   actually uses, which is the entire point of the reference. */
.nzwp-code::after{
	content:"";
	position:absolute;top:14px;left:16px;
	width:11px;height:11px;border-radius:50%;
	background:#ff5f57;
	box-shadow:19px 0 0 #febc2e,38px 0 0 #28c840;
}
/* Load-bearing: .nzwp-copy is absolutely positioned against this wrapper.
   Remove it and the button escapes to the initial containing block. */
.nzwp-copywrap{position:relative}
/* The shelf the button stands on, scoped to blocks that actually have a
   button — Abilities Hub prints a .nzwp-code with no Copy, and giving that
   one an 84px dead gutter would be a bug of its own. */
.nzwp-copywrap .nzwp-code{padding-right:17px}
.nzwp-copy{
	position:absolute;top:8px;right:10px;z-index:1;
	-webkit-appearance:none;appearance:none;
	/* Opaque, never translucent: code showing through a button is unreadable. */
	background:#39404a;color:#dcdcde;border:1px solid rgba(255,255,255,.14);
	border-radius:3px;padding:5px 10px;
	font-family:inherit;font-size:11px;font-weight:600;letter-spacing:.05em;
	text-transform:uppercase;line-height:1.5;cursor:pointer;
	transition:background-color .12s ease,color .12s ease,border-color .12s ease;
}
.nzwp-copy:hover{background:#3c434a;color:#fff;border-color:rgba(255,255,255,.34)}
/* The JS adds .done for 1.6s. Colour AND text colour, so the inherited
   #dcdcde does not ride a green fill at 5.5:1 when white is available. */
.nzwp-copy.done{background:#0a5c36;color:#fff;border-color:rgba(255,255,255,.28)}

/* =============================================================== tabs === */

.nzwp-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:16px 0 0;padding:0}
.nzwp-tab{
	-webkit-appearance:none;appearance:none;
	font:inherit;font-size:12.5px;font-weight:500;line-height:1.5;
	padding:6px 13px;border:1px solid #dcdcde;border-radius:3px;
	background:#fff;color:#3c434a;cursor:pointer;
	transition:background-color .12s ease,border-color .12s ease,color .12s ease;
}
.nzwp-tab:hover{border-color:#8c8f94;color:#1d2327}
/* Selection carries the masthead violet, not admin blue. Blue is the user's
   colour scheme and changes under us; this is the plugin's own and is already
   at the top of every page, so the selected chip reads as belonging to it.
   #5b21b6 under white 12.5px is 8.9:1. Listed after :hover at equal
   specificity so the selected chip keeps its fill while the pointer is over
   it. */
.nzwp-tab[aria-selected="true"]{
	background-color:#5b21b6;
	background-image:linear-gradient(180deg,#6d28d9,#5b21b6);
	border-color:#4c1d95;color:#fff;font-weight:600;
	box-shadow:inset 0 1px 0 rgba(255,255,255,.16);
}
.nzwp-tab[aria-selected="true"]:hover{
	background-color:#4c1d95;
	background-image:linear-gradient(180deg,#5b21b6,#4c1d95);
	border-color:#3b1580;color:#fff;
}
/* Load-bearing: the tab script toggles .is-on. Without these two rules
   every client pane renders at once. */
.nzwp-pane{display:none}
.nzwp-pane.is-on{display:block}


/* Client marks. Official brand SVGs from simple-icons (CC0); the brands
   themselves are their owners' trademarks and are used here only to name
   which client each tab configures. Two have no published mark in that
   set - the CLI and the bare endpoint - so those carry a glyph
   saying what kind of thing it is rather than an invented logo.

   mask-image rather than background-image, so one file works in both states:
   the mark takes the tab's own colour, which is ink normally and white when
   the tab is selected. */
.nzwp-tab{display:inline-flex;align-items:center;gap:7px}
.nzwp-tab::before{
	content:"";
	width:14px;height:14px;flex:none;
	background-color:currentColor;
	-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;
	-webkit-mask-position:center;mask-position:center;
	-webkit-mask-size:contain;mask-size:contain;
}
.nzwp-tab.c-claudecode::before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z'/%3E%3C/svg%3E")}
.nzwp-tab.c-claudedesktop::before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z'/%3E%3C/svg%3E")}
.nzwp-tab.c-cursor::before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M11.503.131 1.891 5.678a.84.84 0 0 0-.42.726v11.188c0 .3.162.575.42.724l9.609 5.55a1 1 0 0 0 .998 0l9.61-5.55a.84.84 0 0 0 .42-.724V6.404a.84.84 0 0 0-.42-.726L12.497.131a1.01 1.01 0 0 0-.996 0M2.657 6.338h18.55c.263 0 .43.287.297.515L12.23 22.918c-.062.107-.229.064-.229-.06V12.335a.59.59 0 0 0-.295-.51l-9.11-5.257c-.109-.063-.064-.23.061-.23'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M11.503.131 1.891 5.678a.84.84 0 0 0-.42.726v11.188c0 .3.162.575.42.724l9.609 5.55a1 1 0 0 0 .998 0l9.61-5.55a.84.84 0 0 0 .42-.724V6.404a.84.84 0 0 0-.42-.726L12.497.131a1.01 1.01 0 0 0-.996 0M2.657 6.338h18.55c.263 0 .43.287.297.515L12.23 22.918c-.062.107-.229.064-.229-.06V12.335a.59.59 0 0 0-.295-.51l-9.11-5.257c-.109-.063-.064-.23.061-.23'/%3E%3C/svg%3E")}
.nzwp-tab.c-codex::before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z'/%3E%3C/svg%3E")}
.nzwp-tab.c-opencode::before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M22 24H2V0h20zM17 4.8H7v14.4h10z'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M22 24H2V0h20zM17 4.8H7v14.4h10z'/%3E%3C/svg%3E")}
.nzwp-tab.c-windsurf::before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M23.55 5.067c-1.2038-.002-2.1806.973-2.1806 2.1765v4.8676c0 .972-.8035 1.7594-1.7597 1.7594-.568 0-1.1352-.286-1.4718-.7659l-4.9713-7.1003c-.4125-.5896-1.0837-.941-1.8103-.941-1.1334 0-2.1533.9635-2.1533 2.153v4.8957c0 .972-.7969 1.7594-1.7596 1.7594-.57 0-1.1363-.286-1.4728-.7658L.4076 5.1598C.2822 4.9798 0 5.0688 0 5.2882v4.2452c0 .2147.0656.4228.1884.599l5.4748 7.8183c.3234.462.8006.8052 1.3509.9298 1.3771.313 2.6446-.747 2.6446-2.0977v-4.893c0-.972.7875-1.7593 1.7596-1.7593h.003a1.798 1.798 0 0 1 1.4718.7658l4.9723 7.0994c.4135.5905 1.05.941 1.8093.941 1.1587 0 2.1515-.9645 2.1515-2.153v-4.8948c0-.972.7875-1.7594 1.7596-1.7594h.194a.22.22 0 0 0 .2204-.2202v-4.622a.22.22 0 0 0-.2203-.2203Z'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M23.55 5.067c-1.2038-.002-2.1806.973-2.1806 2.1765v4.8676c0 .972-.8035 1.7594-1.7597 1.7594-.568 0-1.1352-.286-1.4718-.7659l-4.9713-7.1003c-.4125-.5896-1.0837-.941-1.8103-.941-1.1334 0-2.1533.9635-2.1533 2.153v4.8957c0 .972-.7969 1.7594-1.7596 1.7594-.57 0-1.1363-.286-1.4728-.7658L.4076 5.1598C.2822 4.9798 0 5.0688 0 5.2882v4.2452c0 .2147.0656.4228.1884.599l5.4748 7.8183c.3234.462.8006.8052 1.3509.9298 1.3771.313 2.6446-.747 2.6446-2.0977v-4.893c0-.972.7875-1.7593 1.7596-1.7593h.003a1.798 1.798 0 0 1 1.4718.7658l4.9723 7.0994c.4135.5905 1.05.941 1.8093.941 1.1587 0 2.1515-.9645 2.1515-2.153v-4.8948c0-.972.7875-1.7594 1.7596-1.7594h.194a.22.22 0 0 0 .2204-.2202v-4.622a.22.22 0 0 0-.2203-.2203Z'/%3E%3C/svg%3E")}
.nzwp-tab.c-zed::before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M2.25 1.5a.75.75 0 0 0-.75.75v16.5H0V2.25A2.25 2.25 0 0 1 2.25 0h20.095c1.002 0 1.504 1.212.795 1.92L10.764 14.298h3.486V12.75h1.5v1.922a1.125 1.125 0 0 1-1.125 1.125H9.264l-2.578 2.578h11.689V9h1.5v9.375a1.5 1.5 0 0 1-1.5 1.5H5.185L2.562 22.5H21.75a.75.75 0 0 0 .75-.75V5.25H24v16.5A2.25 2.25 0 0 1 21.75 24H1.655C.653 24 .151 22.788.86 22.08L13.19 9.75H9.75v1.5h-1.5V9.375A1.125 1.125 0 0 1 9.375 8.25h5.314l2.625-2.625H5.625V15h-1.5V5.625a1.5 1.5 0 0 1 1.5-1.5h13.19L21.438 1.5z'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M2.25 1.5a.75.75 0 0 0-.75.75v16.5H0V2.25A2.25 2.25 0 0 1 2.25 0h20.095c1.002 0 1.504 1.212.795 1.92L10.764 14.298h3.486V12.75h1.5v1.922a1.125 1.125 0 0 1-1.125 1.125H9.264l-2.578 2.578h11.689V9h1.5v9.375a1.5 1.5 0 0 1-1.5 1.5H5.185L2.562 22.5H21.75a.75.75 0 0 0 .75-.75V5.25H24v16.5A2.25 2.25 0 0 1 21.75 24H1.655C.653 24 .151 22.788.86 22.08L13.19 9.75H9.75v1.5h-1.5V9.375A1.125 1.125 0 0 1 9.375 8.25h5.314l2.625-2.625H5.625V15h-1.5V5.625a1.5 1.5 0 0 1 1.5-1.5h13.19L21.438 1.5z'/%3E%3C/svg%3E")}
.nzwp-tab.c-cline::before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='m23.365 13.556-1.442-2.895V8.994c0-2.764-2.218-5.002-4.954-5.002h-2.464c.178-.367.276-.779.276-1.213A2.77 2.77 0 0 0 12.018 0a2.77 2.77 0 0 0-2.763 2.779c0 .434.098.846.276 1.213H7.067c-2.736 0-4.954 2.238-4.954 5.002v1.667L.64 13.549c-.149.29-.149.636 0 .927l1.472 2.855v1.667C2.113 21.762 4.33 24 7.067 24h9.902c2.736 0 4.954-2.238 4.954-5.002V17.33l1.44-2.865c.143-.286.143-.622.002-.91m-12.854 2.36a2.27 2.27 0 0 1-2.261 2.273 2.27 2.27 0 0 1-2.261-2.273v-4.042A2.27 2.27 0 0 1 8.249 9.6a2.267 2.267 0 0 1 2.262 2.274zm7.285 0a2.27 2.27 0 0 1-2.26 2.273 2.27 2.27 0 0 1-2.262-2.273v-4.042A2.267 2.267 0 0 1 15.535 9.6a2.267 2.267 0 0 1 2.261 2.274z'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='m23.365 13.556-1.442-2.895V8.994c0-2.764-2.218-5.002-4.954-5.002h-2.464c.178-.367.276-.779.276-1.213A2.77 2.77 0 0 0 12.018 0a2.77 2.77 0 0 0-2.763 2.779c0 .434.098.846.276 1.213H7.067c-2.736 0-4.954 2.238-4.954 5.002v1.667L.64 13.549c-.149.29-.149.636 0 .927l1.472 2.855v1.667C2.113 21.762 4.33 24 7.067 24h9.902c2.736 0 4.954-2.238 4.954-5.002V17.33l1.44-2.865c.143-.286.143-.622.002-.91m-12.854 2.36a2.27 2.27 0 0 1-2.261 2.273 2.27 2.27 0 0 1-2.261-2.273v-4.042A2.27 2.27 0 0 1 8.249 9.6a2.267 2.267 0 0 1 2.262 2.274zm7.285 0a2.27 2.27 0 0 1-2.26 2.273 2.27 2.27 0 0 1-2.262-2.273v-4.042A2.267 2.267 0 0 1 15.535 9.6a2.267 2.267 0 0 1 2.261 2.274z'/%3E%3C/svg%3E")}
.nzwp-tab.c-niranzwp::before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='1.9' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='4 17 10 11 4 5'/%3E%3Cline x1='12' y1='19' x2='20' y2='19'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='1.9' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='4 17 10 11 4 5'/%3E%3Cline x1='12' y1='19' x2='20' y2='19'/%3E%3C/svg%3E")}
.nzwp-tab.c-mcp::before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='1.9' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M9 7V4a3 3 0 0 1 6 0v3'/%3E%3Crect x='5' y='7' width='14' height='6' rx='2'/%3E%3Cpath d='M12 13v4'/%3E%3Cpath d='M9 21h6'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='1.9' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M9 7V4a3 3 0 0 1 6 0v3'/%3E%3Crect x='5' y='7' width='14' height='6' rx='2'/%3E%3Cpath d='M12 13v4'/%3E%3Cpath d='M9 21h6'/%3E%3C/svg%3E")}
/* Safari before 15.4 and Firefox before 53 ignore mask-image and would show a
   solid currentColor square. Both are far older than anything running the
   Abilities API, but the cost of being wrong is a row of blocks, so the mark
   only appears where masking is understood. */
@supports not ((-webkit-mask-image:none) or (mask-image:none)){
	.nzwp-tab::before{display:none}
}

/* ============================================================== focus === */
/* Ink, not var(--wp-admin-theme-color). That variable is published by the
   active colour scheme and lands at ~2:1 in Ectoplasm and Coffee — a focus
   ring that disappears for two of the eight bundled schemes is not a focus
   ring. Ink is ≥12:1 on every light surface here; white is the same bet on
   the two dark ones. None of these controls had a visible ring before. */
.nzwp-tab:focus-visible,
.nzwp-stat span a:focus-visible,
.nzwp-wrap .nzwp-dash a:focus-visible{outline:2px solid #1d2327;outline-offset:2px}
.nzwp-by:focus-visible,.nzwp-copy:focus-visible{outline:2px solid #fff;outline-offset:2px;border-radius:3px}

@media (prefers-reduced-motion:reduce){
	.nzwp-tab,.nzwp-copy,.nzwp-by,.nzwp-wrap .nzwp-dash a{transition:none}
}

/* ===================================================== forced colours === */
/* Windows High Contrast drops background-images and box-shadows, which is
   where every hairline on this page lives — the figure-table grid, the
   badge edges, the code block's rim. Redraw them as real borders, and give
   the selected tab a cue that is not a fill. */
@media (forced-colors:active){
	/* The brand rule is a background layer, so it vanishes here; restate it
	   as a real border or the bar loses its edge against the page. */
	.nzwp-bar{background-image:none;border-bottom:2px solid CanvasText}
	.nzwp-stat{box-shadow:none;border:1px solid CanvasText}
	.nzwp-grid{gap:0}
	.nzwp-badge,.nzwp-code,.nzwp-copy{border:1px solid CanvasText;box-shadow:none}
	.nzwp-card h2{border-bottom-color:CanvasText}
	.nzwp-wrap .nzwp-dash a,.nzwp-wrap .nzwp-dash div.tile{box-shadow:none;border:1px solid CanvasText}
	.nzwp-tab[aria-selected="true"]{border-width:2px;padding:5px 12px}
}

/* ================================================= 782px and below ===== */
/* WordPress switches to its touch layout here and #wpcontent's left padding
   drops from 20px to 10px, so the bar's negative margin follows it. Those
   two numbers are a pair — change one and the bar either insets or forces
   a horizontal scrollbar on the whole admin page. */
@media screen and (max-width:782px){
	.nzwp-bar{margin-left:-10px;padding:0 10px}
	/* min-height rather than height: normally one 56px row, but a long
	   translated status label wraps to a second row instead of overflowing. */
	.nzwp-bar-in{height:auto;min-height:56px;flex-wrap:wrap;row-gap:8px;column-gap:12px;padding:8px 0}
	.nzwp-mark{font-size:16px;letter-spacing:.13em}
	.nzwp-by{font-size:11px}

	.nzwp-wrap{margin-top:16px}
	.nzwp-head{gap:8px;margin-bottom:6px}
	.nzwp-head h1{font-size:21px}
	.nzwp-sub{font-size:13px;margin-bottom:18px}

	/* Two columns, not one: these are short readouts and a single column
	   turns four of them into a column of mostly empty card. */
	.nzwp-grid{grid-template-columns:repeat(2,minmax(0,1fr));margin:14px 0 2px}
	.nzwp-stat{padding:13px 14px;gap:6px}
	.nzwp-stat b{font-size:21px}
	.nzwp-stat span{font-size:10px;letter-spacing:.055em}

	.nzwp-wrap .nzwp-dash{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:18px}
	.nzwp-wrap .nzwp-dash a,.nzwp-wrap .nzwp-dash div.tile{padding:14px 15px}
	.nzwp-wrap .nzwp-dash b{font-size:23px}

	.nzwp-card{padding:18px 16px 20px}
	.nzwp-card h2{font-size:14px;margin-bottom:14px;padding-bottom:11px}

	/* Absolutely positioned over the block, Copy is a 24px tap target
	   sitting on the first line of a command. Out of the flow it goes; the
	   markup already prints the button before .nzwp-code, so it stacks
	   above the block and the block no longer needs its right-hand shelf. */
	.nzwp-copywrap{display:flex;flex-direction:column}
	.nzwp-copy{position:static;align-self:flex-start;margin:0 0 8px;padding:8px 13px;font-size:12px}
	.nzwp-copywrap .nzwp-code{padding-right:14px}
	/* Out of the well and onto the white card, so the white ring that worked
	   against #1d2327 would now be invisible. */
	.nzwp-copy:focus-visible{outline-color:#1d2327}
	.nzwp-code{font-size:12px;line-height:1.7;padding:13px 14px}

	/* Buttons, not inputs, so wp-admin's 16px anti-zoom rule never reaches
	   them — but the tap target still has to clear 32px. */
	.nzwp-tabs{gap:8px}
	.nzwp-tab{padding:8px 13px;font-size:13px}
}

@media screen and (max-width:600px){
	/* The wordmark and the state pill are what the bar is for; the credit
	   line is the first thing to go. */
	.nzwp-by{display:none}
	.nzwp-bar-meta{gap:8px}
}
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
					<span class="nzwp-free"><?php esc_html_e( 'Open source, free forever', 'niranzwp' ); ?></span>
					<a class="nzwp-gh" href="<?php echo esc_url( GITHUB_URL ); ?>" target="_blank" rel="noopener noreferrer"
						aria-label="<?php esc_attr_e( 'NiranzWP on GitHub', 'niranzwp' ); ?>"
						title="<?php esc_attr_e( 'NiranzWP on GitHub', 'niranzwp' ); ?>"></a>
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

		/*
		 * On the Configuration screen the heading repeats the wordmark two
		 * inches above it, so it is hidden rather than removed: the wordmark
		 * is a span, and dropping the h1 would leave the page with no heading
		 * at all for anything reading the document rather than looking at it.
		 * Every other screen has a heading that says something the masthead
		 * does not - Abilities Hub, Checkpoints, Troubleshoot - and keeps it.
		 */
		$duplicate = $title === __( 'NiranzWP', 'niranzwp' );
		echo '<div class="nzwp-head"><h1' . ( $duplicate ? ' class="screen-reader-text"' : '' ) . '>'
			. esc_html( $title ) . '</h1></div>';

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
				'key'   => 'abilities',
				'value' => (string) $abilities,
				'label' => 0 === $disabled
					? __( 'abilities available', 'niranzwp' )
					: sprintf( __( 'available, %d switched off', 'niranzwp' ), $disabled ),
				'href'  => admin_url( 'admin.php?page=niranzwp-abilities' ),
				'tone'  => Settings::active() ? 'on' : 'off',
			],
			[
				'key'   => 'skills',
				'value' => (string) $skills,
				'label' => __( 'skills', 'niranzwp' ),
				'href'  => admin_url( 'admin.php?page=niranzwp-skills' ),
				'tone'  => '',
			],
			[
				'key'   => 'checkpoints',
				'value' => (string) $points,
				'label' => __( 'checkpoints kept', 'niranzwp' ),
				'href'  => admin_url( 'admin.php?page=niranzwp-checkpoints' ),
				'tone'  => '',
			],
			[
				'key'   => 'guard',
				'value' => $guard ? __( 'Armed', 'niranzwp' ) : __( 'Off', 'niranzwp' ),
				'label' => $guard
					? __( 'recovery guard', 'niranzwp' )
					: __( 'recovery guard -- on with filesystem', 'niranzwp' ),
				'href'  => '',
				'tone'  => $guard ? 'on' : 'off',
			],
		];
		?>
		<div class="nzwp-dash">
			<?php foreach ( $tiles as $t ) : ?>
				<?php if ( '' !== $t['href'] ) : ?>
					<a class="t-<?php echo esc_attr( $t['key'] ); ?>" href="<?php echo esc_url( $t['href'] ); ?>">
						<b class="<?php echo esc_attr( $t['tone'] ); ?>"><?php echo esc_html( $t['value'] ); ?></b>
						<span><?php echo esc_html( $t['label'] ); ?></span>
					</a>
				<?php else : ?>
					<div class="tile t-<?php echo esc_attr( $t['key'] ); ?>">
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
					<button type="button" class="nzwp-tab c-<?php echo esc_attr( $c['key'] ); ?>" role="tab"
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
				'key'   => 'niranzwp',
				'note'  => __( 'No config file. Install it, approve once in the browser, done.', 'niranzwp' ),
				'code'  => "npm install -g niranzwp\nniranzwp auth login {$host}\n\nniranzwp seo audit\nniranzwp geo check",
				'where' => '',
			],
			[
				'label' => 'Claude Code',
				'key'   => 'claudecode',
				'note'  => __( 'Run this in your terminal.', 'niranzwp' ),
				'code'  => "claude mcp add --transport http {$slug} \\\n  {$endpoint}",
				'where' => '',
			],
			[
				'label' => 'Claude Desktop',
				'key'   => 'claudedesktop',
				'note'  => __( 'Add this inside the mcpServers block.', 'niranzwp' ),
				'code'  => "{\n  \"mcpServers\": {\n    \"{$slug}\": {\n      \"type\": \"http\",\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
				'where' => '~/Library/Application Support/Claude/claude_desktop_config.json',
			],
			[
				'label' => 'Cursor',
				'key'   => 'cursor',
				'note'  => __( 'Add this inside the mcpServers block.', 'niranzwp' ),
				'code'  => "{\n  \"mcpServers\": {\n    \"{$slug}\": {\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
				'where' => '~/.cursor/mcp.json',
			],
			[
				'label' => 'Codex',
				'key'   => 'codex',
				'note'  => __( 'Add this to your Codex config.', 'niranzwp' ),
				'code'  => "[mcp_servers.{$slug}]\nurl = \"{$endpoint}\"",
				'where' => '~/.codex/config.toml',
			],
			[
				'label' => 'OpenCode',
				'key'   => 'opencode',
				'note'  => __( 'Add this inside the mcp block. type must be "remote".', 'niranzwp' ),
				'code'  => "{\n  \"mcp\": {\n    \"{$slug}\": {\n      \"type\": \"remote\",\n      \"url\": \"{$endpoint}\",\n      \"enabled\": true\n    }\n  }\n}",
				'where' => '~/.config/opencode/opencode.json',
			],
			[
				'label' => 'Windsurf',
				'key'   => 'windsurf',
				'note'  => __( 'Add this inside the mcpServers block.', 'niranzwp' ),
				'code'  => "{\n  \"mcpServers\": {\n    \"{$slug}\": {\n      \"serverUrl\": \"{$endpoint}\"\n    }\n  }\n}",
				'where' => '~/.codeium/windsurf/mcp_config.json',
			],
			[
				'label' => 'Zed',
				'key'   => 'zed',
				'note'  => __( 'Add this inside context_servers in your Zed settings.', 'niranzwp' ),
				'code'  => "{\n  \"context_servers\": {\n    \"{$slug}\": {\n      \"source\": \"custom\",\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
				'where' => '~/.config/zed/settings.json',
			],
			[
				'label' => 'Cline',
				'key'   => 'cline',
				'note'  => __( 'Add this inside the mcpServers block. Cline reads it from the VS Code extension directory.', 'niranzwp' ),
				'code'  => "{\n  \"mcpServers\": {\n    \"{$slug}\": {\n      \"type\": \"streamableHttp\",\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
				'where' => 'cline_mcp_settings.json',
			],
			[
				'label' => 'Any MCP client',
				'key'   => 'mcp',
				'note'  => __( 'The endpoint itself, for anything that speaks MCP over HTTP and is not listed above.', 'niranzwp' ),
				'code'  => "{$endpoint}",
				'where' => '',
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
