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

	public static function written(): string {
		$v = get_option( self::OPTION, '' );
		return is_string( $v ) ? $v : '';
	}

	public static function save( string $text ): void {
		update_option( self::OPTION, mb_substr( $text, 0, self::MAX ), false );
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

	/**
	 * The generated brief. Facts first, then the rules that follow from them.
	 */
	private static function instructions(): string {
		$lines = [];

		$written = trim( self::written() );
		if ( '' !== $written ) {
			$lines[] = "## From the site owner\n\n" . $written;
		}

		/* ------------------------------------------------------- this site */

		$theme  = wp_get_theme();
		$facts  = [];
		$facts[] = sprintf( 'Theme: %s%s.', $theme->get( 'Name' ), $theme->parent() ? ' (child of ' . $theme->parent()->get( 'Name' ) . ')' : '' );
		$facts[] = sprintf( 'WordPress %s on PHP %s.', get_bloginfo( 'version' ), PHP_VERSION );

		$builder = defined( 'ELEMENTOR_VERSION' ) ? 'Elementor ' . ELEMENTOR_VERSION : null;
		$facts[] = $builder
			? $builder . ' is active. Pages built with it store their layout in _elementor_data, not in post_content, so editing post_content will not change what renders. Use the elementor abilities.'
			: 'No page builder detected. Content is Gutenberg blocks in post_content.';

		$seo = self::seo_plugin();
		$facts[] = $seo
			? $seo . ' owns the SEO fields on this site. Write meta through the seo abilities rather than to post meta directly.'
			: 'No SEO plugin is active, so the seo abilities that resolve meta fields will decline.';

		$lines[] = "## This installation\n\n- " . implode( "\n- ", $facts );

		/* --------------------------------------------------- what is open */

		$open = [];
		if ( Settings::active() ) {
			$open[] = 'content, SEO and audit abilities';
		}
		if ( Settings::files_enabled() ) {
			$open[] = 'filesystem abilities (read, write, delete inside ABSPATH)';
		}
		if ( Settings::runtime_enabled() ) {
			$open[] = 'PHP evaluation and WP-CLI';
		}

		$lines[] = "## What is switched on\n\n" . ( $open
			? '- ' . implode( "\n- ", $open )
			: '- Nothing. Abilities are off; switch them on under NiranzWP > Configuration.' );

		/* ------------------------------------------------------ how to work */

		$rules = [
			'Read a skill before doing the job it covers. `niranzwp/skill-list` shows what exists.',
			'Writes preview first. Call with dry_run true, read what would change, then call again with dry_run false.',
			'write-file, delete-file, block-write and elementor-update-setting snapshot before they change anything, and return a checkpoint_id. Keep it; `niranzwp/checkpoint-restore` puts it back.',
			'A checkpoint needs a working site to restore. If a change could fatal the site, say so before making it.',
			'This is a real installation. Prefer the narrowest ability that does the job over evaluate.',
		];

		if ( 'production' === wp_get_environment_type() ) {
			array_unshift( $rules, 'This site reports itself as **production**. Treat every write as affecting live traffic.' );
		}

		$lines[] = "## How to work here\n\n- " . implode( "\n- ", $rules );

		return implode( "\n\n", $lines );
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
