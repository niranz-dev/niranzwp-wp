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
   9.0:1 even on the lightest stop.

   The 2px brand rule is the second background layer, painted first so it
   sits on top. It is a background rather than a border because a border
   cannot hold a gradient and there is no spare element. It lands on the
   boundary with the grey admin page, which is where it can actually be
   seen — 4.2:1 against #f0f0f1 below it. */
.nzwp-bar{
	margin:0 0 0 -20px;padding:0 20px;
	background-color:#5b21b6;
	background-image:
		linear-gradient(90deg,#ff2424,#e10000),
		linear-gradient(115deg,#2e1065 0%,#5b21b6 48%,#7c3aed 100%);
	background-repeat:no-repeat;
	background-size:100% 2px,100% 100%;
	background-position:left bottom,left top;
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
.nzwp-grid{
	display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
	gap:1px;background:#fff;
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
.nzwp-stat span{
	order:1;color:#50575e;font-size:11px;font-weight:600;line-height:1.4;
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
.nzwp-stat span a{color:#2271b1;text-decoration:underline;text-underline-offset:2px}
.nzwp-stat span a:hover{color:#135e96}

/* ==================================================== dashboard tiles === */
/* These are the four cards in the screenshot. dashboard() prints its own
   its own style element AFTER Admin::styles(), so these rules carry the .nzwp-wrap
   ancestor and win on specificity (0,2,1 vs 0,1,1) rather than on source
   order — .nzwp-dash genuinely sits inside .nzwp-wrap, so the extra
   ancestor is real and not a superstition. The right fix is to delete that
   second style element and drop this prefix; this is the version that does
   not need two methods edited in one commit.

   Labels here stay sentence case at 12.5px while the figure table above
   uses caps: these carry clauses ("exposed, 3 switched off", "recovery
   guard -- on with filesystem") and caps at 11px turns those into a wall. */
.nzwp-wrap .nzwp-dash{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:0 0 22px}
.nzwp-wrap .nzwp-dash a,
.nzwp-wrap .nzwp-dash div.tile{
	display:block;padding:16px 18px;text-decoration:none;
	background:#fff;color:#1d2327;
	border:1px solid #dcdcde;border-radius:6px;
	/* One pixel of lift, matching wp-admin's own postbox. Any more and the
	   tile starts competing with the number it holds. */
	box-shadow:0 1px 1px rgba(0,0,0,.035);
	transition:border-color .12s ease,box-shadow .12s ease;
}
/* Hover changes edge and lift only — no colour, no transform. Blue here
   would be the colour scheme's, and a 1px translate needs a reduced-motion
   escape hatch to buy nothing. The guard tile is a div, not a link: nothing
   on it may imply a click. */
.nzwp-wrap .nzwp-dash a:hover{border-color:#8c8f94;box-shadow:0 2px 5px rgba(0,0,0,.07)}
.nzwp-wrap .nzwp-dash div.tile{cursor:default}
.nzwp-wrap .nzwp-dash b{
	display:block;font-size:28px;font-weight:600;line-height:1.12;
	letter-spacing:-.02em;color:#1d2327;
	font-variant-numeric:tabular-nums lining-nums;
}
/* Green survives only where it means something: this thing is on now. */
.nzwp-wrap .nzwp-dash b.on{color:#0a5c36}
/* Was #8c8f94 — 2.9:1 on white, under even the 3:1 large-text floor on a
   number the owner is meant to read. #6f747a is 4.7:1 and still clearly
   the quiet state. */
.nzwp-wrap .nzwp-dash b.off{color:#6f747a}
.nzwp-wrap .nzwp-dash span{display:block;margin-top:6px;color:#50575e;font-size:12.5px;line-height:1.45}

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
.nzwp-code{
	background:#1d2327;color:#f0f0f1;
	white-space:pre;overflow:auto;
	padding:15px 17px;border-radius:5px;margin:12px 0 6px;
	font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;
	font-size:12.5px;line-height:1.75;
	/* -> and != must stay two glyphs in a command someone may retype. */
	font-variant-ligatures:none;
	box-shadow:inset 0 0 0 1px rgba(255,255,255,.07);
	/* Shell commands and JSON are LTR even on an RTL admin. */
	direction:ltr;text-align:left;
}
.nzwp-code::selection,.nzwp-code ::selection{background:#3858e9;color:#fff}
/* Load-bearing: .nzwp-copy is absolutely positioned against this wrapper.
   Remove it and the button escapes to the initial containing block. */
.nzwp-copywrap{position:relative}
/* The shelf the button stands on, scoped to blocks that actually have a
   button — Abilities Hub prints a .nzwp-code with no Copy, and giving that
   one an 84px dead gutter would be a bug of its own. */
.nzwp-copywrap .nzwp-code{padding-right:88px}
.nzwp-copy{
	position:absolute;top:9px;right:9px;
	-webkit-appearance:none;appearance:none;
	/* Opaque, never translucent: it sits over the first line of the block,
	   and code showing through a button is unreadable. */
	background:#2c3338;color:#dcdcde;border:1px solid rgba(255,255,255,.18);
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
/* Selection is ink, not admin blue. Blue is the user's colour scheme and
   changes under us; ink is 15.9:1 in every scheme and never becomes the
   fourth hue on a page that already carries navy, green and red. Listed
   after :hover at equal specificity so the selected chip keeps its fill
   while the pointer is over it. */
.nzwp-tab[aria-selected="true"]{background:#1d2327;border-color:#1d2327;color:#fff;font-weight:600}
.nzwp-tab[aria-selected="true"]:hover{background:#101517;border-color:#101517;color:#fff}
/* Load-bearing: the tab script toggles .is-on. Without these two rules
   every client pane renders at once. */
.nzwp-pane{display:none}
.nzwp-pane.is-on{display:block}

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
