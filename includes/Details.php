<?php
/**
 * The "View details" modal, for a plugin that is not on wordpress.org.
 *
 * WordPress builds that link from the wordpress.org plugin API, so a
 * self-hosted plugin simply has no link -- which reads, on the Plugins screen,
 * as a plugin with nothing to say for itself.
 *
 * The link is added by hand and the data served through the plugins_api
 * filter, so it opens the same modal every other plugin uses. Nothing is
 * fetched: everything shown comes from the plugin header and this file.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Details {

	private const SLUG = 'niranzwp';

	public static function init(): void {
		add_filter( 'plugin_row_meta', [ self::class, 'row_meta' ], 10, 2 );
		add_filter( 'plugins_api', [ self::class, 'information' ], 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( NIRANZWP_FILE ), [ self::class, 'action_links' ] );
	}

	/** Configuration first, since that is the only thing a new install needs. */
	public static function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=niranzwp' ) ),
				esc_html__( 'Configuration', 'niranzwp' )
			)
		);
		return $links;
	}

	/**
	 * Add the View details link. It points at the same thickbox modal core
	 * uses; the plugins_api filter below fills it.
	 *
	 * @param array<int,string> $meta
	 * @return array<int,string>
	 */
	public static function row_meta( array $meta, string $file ): array {
		if ( plugin_basename( NIRANZWP_FILE ) !== $file ) {
			return $meta;
		}

		$meta[] = sprintf(
			'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="NiranzWP">%s</a>',
			esc_url( add_query_arg(
				[
					'tab'       => 'plugin-information',
					'plugin'    => self::SLUG,
					'TB_iframe' => 'true',
					'width'     => 700,
					'height'    => 800,
				],
				self_admin_url( 'plugin-install.php' )
			) ),
			esc_attr__( 'More information about NiranzWP', 'niranzwp' ),
			esc_html__( 'View details', 'niranzwp' )
		);

		return $meta;
	}

	/**
	 * Serve the modal's contents.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public static function information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}

		$abilities = function_exists( 'wp_get_abilities' )
			? count( array_filter(
				array_keys( wp_get_abilities() ),
				static fn( string $n ): bool => str_starts_with( $n, 'niranzwp/' )
			) )
			: 0;

		return (object) [
			'name'          => 'NiranzWP',
			'slug'          => self::SLUG,
			'version'       => VERSION,
			'author'        => '<a href="https://niranz.dev">Niranjan</a>',
			'homepage'      => 'https://niranz.dev',
			'requires'      => '6.9',
			'requires_php'  => '8.0',
			'tested'        => '7.0',
			'last_updated'  => gmdate( 'Y-m-d', (int) filemtime( NIRANZWP_FILE ) ),
			'download_link' => '',
			'sections'      => [
				'description' => self::description( $abilities ),
				'safety'      => self::safety(),
				'changelog'   => self::changelog(),
			],
		];
	}

	private static function description( int $abilities ): string {
		return '
			<p>NiranzWP exposes purpose-built abilities through the WordPress Abilities API, so a
			command-line tool or an AI client can inspect and maintain this site without anyone
			pasting a password into a config file.</p>

			<p>It registers <strong>' . (int) $abilities . ' abilities</strong> on this install, in nine groups:
			site, SEO and GEO, content, Gutenberg, Elementor, context and skills, checkpoints,
			filesystem, and code execution. <strong>NiranzWP &rsaquo; Abilities Hub</strong> lists every one of
			them, along with anything registered by other plugins, with a switch on each.</p>

			<h4>Pair it with the CLI</h4>
			<pre>npm install -g niranzwp
niranzwp auth login ' . esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</pre>
			<p>WordPress asks you to approve the connection in your own browser, and the credential
			goes to your OS keychain. Nothing is typed or pasted.</p>

			<h4>What it is for</h4>
			<ul>
				<li>SEO and GEO audits, and fixing what they find in batches</li>
				<li>Reading and writing Gutenberg blocks and Elementor layouts</li>
				<li>Content audits &mdash; thin, duplicated, orphaned and stale</li>
				<li>Reading and writing files inside the install, behind their own switch</li>
			</ul>';
	}

	private static function safety(): string {
		return '
			<p>Nothing is exposed after activation. Three switches, off by default and independent
			of each other, decide what is available: abilities, filesystem, and code execution.
			Access is locked to the domain it was enabled on, so restoring the database elsewhere
			does not carry it over.</p>

			<h4>Undo</h4>
			<p>write-file, delete-file, block-write, elementor-update-setting and skill edits take a
			snapshot before they change anything, and return its id with the result. Anything in a
			snapshot can be put back from <strong>NiranzWP &rsaquo; Checkpoints</strong>, dry run first.</p>

			<h4>When a write breaks the site</h4>
			<p>A checkpoint needs a working site to restore through, so switching on the filesystem
			abilities also installs a must-use plugin that runs before everything else. If a request
			that loads a newly written file dies, it puts the previous contents back on the spot.
			PHP that does not parse is refused before it is ever written.</p>

			<h4>Code execution</h4>
			<p>The third switch runs arbitrary PHP and WP-CLI. That is full control of this site,
			which is the point of it &mdash; and why it is off by default, separate from the others,
			and best left off on anything serving real traffic.</p>';
	}

	private static function changelog(): string {
		return '
			<h4>1.0.0</h4>
			<ul>
				<li>Checkpoints, taken automatically before every destructive write, with a timeline
					screen to restore from.</li>
				<li>Recovery guard: puts a file back when the request that loaded it dies.</li>
				<li>PHP that does not parse is refused rather than written.</li>
				<li>Context and skills: the standing brief for this site, and per-job instructions,
					both editable from wp-admin.</li>
				<li>Abilities Hub: every ability on the site, from any plugin, with a switch on each.</li>
				<li>Elementor: read, search and update layouts.</li>
				<li>Gutenberg: read and write blocks, validated against the block registry.</li>
				<li>SEO and GEO audits, content audits, batch meta and alt text.</li>
			</ul>';
	}
}
