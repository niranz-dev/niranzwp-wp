<?php
/**
 * Context: the standing brief every connected client should read first.
 *
 * Two halves. The generated half describes this specific installation -- what
 * is installed, which page builder is in use, which SEO plugin owns the meta
 * fields, what is switched on -- so a client does not have to discover it by
 * trial and error. The written half is whatever the site owner wants applied
 * on every job, and it comes first, because a rule someone took the trouble to
 * write outranks anything derived from the plugin list.
 *
 * Context is not a skill. A skill is fetched when a particular job calls for
 * it; context applies to every job.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Context {

	private const OPTION = 'niranzwp_context';

	private const MAX = 16384; // 16 KiB of prose is already more than anyone reads.

	private const OPTION_ON = 'niranzwp_context_enabled';

	public static function written(): string {
		$v = get_option( self::OPTION, '' );
		return is_string( $v ) ? $v : '';
	}

	public static function save( string $text ): void {
		update_option( self::OPTION, mb_substr( $text, 0, self::MAX ), false );
	}

	/**
	 * Whether the owner's own instructions are sent.
	 *
	 * Separate from whether they are written, so they can be taken out of
	 * circulation for a moment without being deleted -- useful when working out
	 * whether a rule is the thing causing an agent to behave oddly.
	 */
	public static function user_enabled(): bool {
		$v = get_option( self::OPTION_ON, null );
		return null === $v ? true : (bool) $v;
	}

	public static function set_user_enabled( bool $on ): void {
		update_option( self::OPTION_ON, $on ? 1 : 0, false );
	}

	/* -------------------------------------------------------------- build */

	/** @return array<string,mixed> */
	public static function build(): array {
		return [
			'site'         => [
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => home_url(),
				'locale'      => get_locale(),
			],
			'environment'  => [
				'wordpress'   => get_bloginfo( 'version' ),
				'php'         => PHP_VERSION,
				'environment' => wp_get_environment_type(),
				'multisite'   => is_multisite(),
			],
			'instructions' => self::instructions(),
			'skills'       => array_map(
				static fn( array $s ): array => [
					'slug'        => $s['slug'],
					'description' => $s['description'],
					'source'      => $s['source'] ?? 'site',
				],
				Skills::catalogue()
			),
		];
	}

	private static bool $suppress_written = false;

	/**
	 * The generated half on its own, for the screen that shows the two
	 * separately. The owner needs to see what is derived from the installation
	 * without their own words mixed into it.
	 */
	public static function generated(): string {
		self::$suppress_written = true;
		try {
			return self::instructions();
		} finally {
			self::$suppress_written = false;
		}
	}

	/**
	 * The full brief.
	 *
	 * Written as prose rather than a data dump because it is read by something
	 * that has to act on it. Order matters: the owner's own rules first, then
	 * what would break the connection, then the facts, then how those facts
	 * change what you should do.
	 */
	private static function instructions(): string {
		$lines = [];

		$written = trim( self::written() );
		if ( '' !== $written && self::user_enabled() && ! self::$suppress_written ) {
			$lines[] = "## From the site owner\n\n" . $written;
		}

		$lines[] = self::connection_safety();
		$lines[] = self::environment();
		$lines[] = self::data_modelling();
		$lines[] = self::layout();
		$lines[] = self::switched_on();
		$lines[] = self::skills_section();
		$lines[] = self::how_to_work();

		return implode( "\n\n", array_filter( $lines ) );
	}

	/**
	 * The two ways an agent can lock itself out: removing the plugin it is
	 * talking through, and revoking the credential it is talking with.
	 */
	private static function connection_safety(): string {
		$user = wp_get_current_user();
		$who  = $user && $user->ID
			? sprintf( 'You are connected as WordPress user %d (%s).', $user->ID, $user->user_login )
			: 'You are connected with an administrator credential.';

		return "## Connection safety\n\n"
			. "- Do not deactivate, delete, replace or rewrite the NiranzWP plugin. It is what you are talking through; removing it ends the conversation mid-task.\n"
			. "- {$who} Do not revoke or delete that user's application passwords, and do not change its role or password.\n"
			. '- If a task genuinely requires one of those, say so and let the site owner do it by hand.';
	}

	/** What is actually installed. An agent guessing at this gets it wrong. */
	private static function environment(): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$rows = [];
		foreach ( get_plugins() as $file => $data ) {
			$rows[] = sprintf(
				'- %s v%s (%s)',
				$data['Name'],
				$data['Version'],
				is_plugin_active( $file ) ? 'active' : 'inactive'
			);
		}
		sort( $rows );

		$theme  = wp_get_theme();
		$parent = $theme->parent();

		return "## Environment\n\n"
			. sprintf(
				"WordPress %s -- PHP %s -- Locale: %s -- Environment: %s\n\n",
				get_bloginfo( 'version' ),
				PHP_VERSION,
				get_locale(),
				wp_get_environment_type()
			)
			. sprintf(
				"Active theme: %s%s.\n\n",
				$theme->get( 'Name' ),
				$parent ? sprintf( ' (child theme of %s -- edit the child, never the parent, or an update will overwrite the work)', $parent->get( 'Name' ) ) : ''
			)
			. "Installed plugins:\n" . implode( "\n", $rows );
	}

	/**
	 * Where structured content should live. Writing a register_post_type() call
	 * in PHP for something a modelling plugin already owns splits the source of
	 * truth, and whoever touches the plugin's UI next silently breaks it.
	 */
	private static function data_modelling(): string {
		$owners = [
			'ACF'                  => class_exists( 'ACF' ) || defined( 'ACF_VERSION' ),
			'Custom Post Type UI'  => defined( 'CPTUI_VERSION' ) || function_exists( 'cptui_init' ),
			'Pods'                 => defined( 'PODS_VERSION' ),
			'JetEngine'            => defined( 'JET_ENGINE_VERSION' ),
			'Meta Box'             => defined( 'RWMB_VER' ),
			'Toolset Types'        => defined( 'TYPES_VERSION' ),
			'WooCommerce'          => class_exists( 'WooCommerce' ),
		];
		$active = array_keys( array_filter( $owners ) );

		$out = "## Where data belongs\n\n"
			. "Use what WordPress already provides rather than inventing storage: custom post types for structured content, taxonomies for grouping, post meta for per-post fields, the options API for settings. Reach for a custom table only when none of those fit.\n";

		if ( 1 === count( $active ) ) {
			$out .= "\n" . sprintf(
				'%s is active and owns content modelling here. Register post types, taxonomies and fields through it, not with a hand-written register_post_type() call -- a split source of truth breaks the moment someone edits the other side.',
				$active[0]
			);
		} elseif ( count( $active ) > 1 ) {
			$out .= "\n" . sprintf(
				'More than one modelling plugin is active: %s. Ask the site owner which one owns a given model before creating anything, and do not mix them.',
				implode( ', ', $active )
			);
		} else {
			$out .= "\nNo content-modelling plugin is active, so registering post types and fields in a child theme or a small plugin is the right approach here.";
		}

		return $out;
	}

	/** How pages are actually built here, and what that rules out. */
	private static function layout(): string {
		$builders = [];
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$builders[] = 'Elementor ' . ELEMENTOR_VERSION . ( defined( 'ELEMENTOR_PRO_VERSION' ) ? ' with Pro' : '' );
		}
		if ( defined( 'FL_BUILDER_VERSION' ) ) {
			$builders[] = 'Beaver Builder';
		}
		if ( defined( 'BRICKS_VERSION' ) ) {
			$builders[] = 'Bricks';
		}
		if ( defined( 'ET_BUILDER_VERSION' ) ) {
			$builders[] = 'Divi';
		}
		if ( defined( 'WPB_VC_VERSION' ) ) {
			$builders[] = 'WPBakery';
		}

		$out = "## Building pages\n\n";

		if ( $builders ) {
			$out .= implode( ' and ', $builders ) . " is active.\n\n"
				. "A page built with a builder stores its layout in post meta, not in post_content. Editing post_content on such a page changes nothing that renders, and writing Gutenberg blocks into it produces a page that is half one system and half the other. Check which a page uses before editing it: `niranzwp/elementor-status` and `niranzwp/block-read` will tell you.\n\n"
				. 'For a new page, ask which approach the owner wants -- builder, Gutenberg, or a theme template -- and then stay in it.';
		} else {
			$out .= "No page builder is active. Content is Gutenberg blocks in post_content, so `niranzwp/block-read` and `niranzwp/block-write` are the way in.\n\n"
				. 'Validate block names against `niranzwp/block-types` before writing: a block that is not registered here renders as "this block contains unexpected or invalid content".';
		}

		$seo = self::seo_plugin();
		$out .= "\n\n" . ( $seo
			? $seo . ' owns the SEO fields. Write meta through the seo abilities rather than to post meta directly, or its own UI will disagree with what is stored.'
			: 'No SEO plugin is active, so the seo abilities that resolve meta fields will decline rather than guess at a meta key.' );

		return $out;
	}

	private static function switched_on(): string {
		$open = [];
		if ( Settings::active() ) {
			$open[] = 'content, SEO, content-audit and checkpoint abilities';
		}
		if ( Settings::files_enabled() ) {
			$open[] = 'filesystem abilities -- read, write and delete inside ABSPATH';
		}
		if ( Settings::runtime_enabled() ) {
			$open[] = 'PHP evaluation and WP-CLI, which is full control of this site';
		}

		return "## What is switched on\n\n" . ( $open
			? '- ' . implode( "\n- ", $open )
			: '- Nothing. Abilities are off; the site owner switches them on under NiranzWP > Configuration.' );
	}

	private static function skills_section(): string {
		$skills = Skills::catalogue();
		if ( ! $skills ) {
			return '';
		}

		$rows = [];
		foreach ( $skills as $s ) {
			$rows[] = sprintf(
				'- `%s` *(%s)* -- %s',
				$s['slug'],
				'built-in' === ( $s['source'] ?? 'site' ) ? 'built-in' : 'written here',
				'' !== $s['description'] ? $s['description'] : $s['title']
			);
		}

		return "## Skills available\n\n"
			. "If one of these covers the job in front of you, load it with `niranzwp/skill-get` **before** starting the work, not after.\n\n"
			. implode( "\n", $rows );
	}

	private static function how_to_work(): string {
		$rules = [
			'Writes preview first. Call with dry_run true, read what would actually change, then call again with dry_run false.',
			'write-file, delete-file, block-write and elementor-update-setting snapshot before they change anything and return a checkpoint_id. Keep it; `niranzwp/checkpoint-restore` puts it back.',
			'A checkpoint needs a working site to restore. If a change could fatal the site -- editing a theme PHP file, evaluating code that runs on every request -- say so before making it.',
			'Prefer the narrowest ability that does the job. `niranzwp/evaluate` will do anything, which is exactly why it should not be the first choice.',
			'Purging caches does not reach an external CDN. If this site sits behind Cloudflare, changes may still look stale after a purge.',
		];

		if ( 'production' === wp_get_environment_type() ) {
			array_unshift( $rules, 'This site reports itself as **production**. Every write affects live traffic and can be seen by readers and crawlers before anyone reviews it.' );
		}

		return "## How to work here\n\n- " . implode( "\n- ", $rules );
	}

	private static function seo_plugin(): ?string {
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'Rank Math';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'SEOPress';
		}
		return null;
	}

	/* ---------------------------------------------------------- ability */

	/** @param callable|array $gate */
	public static function register( callable|array $gate ): void {
		wp_register_ability( 'niranzwp/context', [
			'label'               => __( 'Site context', 'niranzwp' ),
			'description'         => __( 'The standing brief for this site: what is installed, which plugins own which fields, what is switched on, the rules the owner set, and the skills available. Call this first, before doing anything else on this site.', 'niranzwp' ),
			'category'            => 'niranzwp-skills',
			'input_schema'        => [ 'type' => 'object', 'properties' => (object) [] ],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'build' ],
			'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ],
		] );
	}
}
