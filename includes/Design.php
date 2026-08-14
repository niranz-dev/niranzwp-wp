<?php
/**
 * Design: what this site looks like, and a check that says whether something
 * built for it actually belongs.
 *
 * Tokens are read, not stored. A block theme already declares its palette and
 * typography in theme.json, and the Site Editor already writes user changes on
 * top; keeping a second copy here would mean deciding which one wins every
 * time somebody edits either. So the theme stays the source of truth and this
 * reads the merged result. Classic themes have nowhere to declare a palette,
 * so for those -- and only those -- one can be written down here.
 *
 * What is stored either way is the part WordPress has no place for: the name
 * of the direction, and the rules that go with it.
 *
 * The check is the useful half. Generated design converges on a handful of
 * looks, and left alone an agent will produce them here too -- so the rules
 * are enforced against the output rather than merely written down and hoped
 * for.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Design {

	private const OPTION = 'niranzwp_design';

	/* ---------------------------------------------------------------- read */

	/** @return array<string,mixed> */
	public static function stored(): array {
		$v = get_option( self::OPTION, [] );
		return is_array( $v ) ? $v : [];
	}

	/**
	 * The palette and type this site actually renders with.
	 *
	 * @return array<string,mixed>
	 */
	public static function tokens(): array {
		$stored = self::stored();

		if ( function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json() ) {
			$settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : [];

			$colors = [];
			foreach ( [ 'theme', 'custom', 'default' ] as $origin ) {
				foreach ( (array) ( $settings['color']['palette'][ $origin ] ?? [] ) as $c ) {
					if ( ! empty( $c['color'] ) ) {
						$colors[ strtolower( (string) $c['color'] ) ] = (string) ( $c['name'] ?? $c['slug'] ?? '' );
					}
				}
			}

			$fonts = [];
			foreach ( (array) ( $settings['typography']['fontFamilies'] ?? [] ) as $origin ) {
				foreach ( (array) $origin as $f ) {
					if ( ! empty( $f['fontFamily'] ) ) {
						$fonts[] = (string) $f['fontFamily'];
					}
				}
			}

			return [
				'source'     => 'theme.json',
				'editable'   => false,
				'note'       => 'These come from the theme and the Site Editor. Change them there, not here.',
				'colors'     => $colors,
				'fonts'      => array_values( array_unique( $fonts ) ),
				'font_sizes' => array_column( (array) ( $settings['typography']['fontSizes']['theme'] ?? [] ), 'size', 'slug' ),
			];
		}

		return [
			'source'     => 'declared',
			'editable'   => true,
			'note'       => 'This theme has no theme.json, so the palette is whatever was written down here.',
			'colors'     => (array) ( $stored['colors'] ?? [] ),
			'fonts'      => (array) ( $stored['fonts'] ?? [] ),
			'font_sizes' => (array) ( $stored['font_sizes'] ?? [] ),
		];
	}

	/** @return array<string,mixed> */
	public static function read(): array {
		$stored = self::stored();

		return [
			'name'   => (string) ( $stored['name'] ?? '' ),
			'notes'  => (string) ( $stored['notes'] ?? '' ),
			'dos'    => array_values( (array) ( $stored['dos'] ?? [] ) ),
			'donts'  => array_values( (array) ( $stored['donts'] ?? [] ) ),
			'tokens' => self::tokens(),
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function write( mixed $input = [] ) {
		$input  = is_array( $input ) ? $input : [];
		$stored = self::stored();
		$tokens = self::tokens();

		foreach ( [ 'name', 'notes' ] as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$stored[ $k ] = sanitize_text_field( (string) $input[ $k ] );
			}
		}
		foreach ( [ 'dos', 'donts' ] as $k ) {
			if ( isset( $input[ $k ] ) && is_array( $input[ $k ] ) ) {
				$stored[ $k ] = array_values( array_filter( array_map(
					static fn( $v ): string => sanitize_text_field( (string) $v ),
					$input[ $k ]
				) ) );
			}
		}

		if ( isset( $input['colors'] ) || isset( $input['fonts'] ) ) {
			if ( ! $tokens['editable'] ) {
				return new \WP_Error(
					'niranzwp_tokens_readonly',
					'This theme declares its palette in theme.json, so it is edited in the Site Editor rather than here. Only the name and the rules are stored by this plugin.'
				);
			}
			if ( isset( $input['colors'] ) && is_array( $input['colors'] ) ) {
				$stored['colors'] = array_filter(
					array_map( 'sanitize_hex_color', array_map( 'strval', array_keys( $input['colors'] ) ) )
				);
				$stored['colors'] = array_combine( $stored['colors'], array_values( (array) $input['colors'] ) ) ?: [];
			}
			if ( isset( $input['fonts'] ) && is_array( $input['fonts'] ) ) {
				$stored['fonts'] = array_map( 'sanitize_text_field', array_map( 'strval', $input['fonts'] ) );
			}
		}

		update_option( self::OPTION, $stored, false );
		return self::read();
	}

	/* --------------------------------------------------------------- check */

	/**
	 * Look for the tells.
	 *
	 * Two kinds. Off-palette is specific to this site: a colour or a face that
	 * is not in what the theme declares. The rest are the shapes generated
	 * design keeps landing on regardless of the brief, which is why they are
	 * checked everywhere rather than being somebody's taste.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function check( mixed $input = [] ): array {
		$input  = is_array( $input ) ? $input : [];
		$out    = (string) ( $input['output'] ?? '' );
		$tokens = self::tokens();
		$stored = self::stored();

		$findings = [];

		$fail = static function ( string $rule, string $what, string $why ) use ( &$findings ): void {
			$findings[] = [ 'severity' => 'fail', 'rule' => $rule, 'found' => $what, 'why' => $why ];
		};
		$warn = static function ( string $rule, string $what, string $why ) use ( &$findings ): void {
			$findings[] = [ 'severity' => 'warn', 'rule' => $rule, 'found' => $what, 'why' => $why ];
		};

		/* ------------------------------------------------- this site's palette */

		$palette = array_map( 'strtolower', array_keys( (array) $tokens['colors'] ) );
		$used    = [];
		if ( preg_match_all( '/#([0-9a-f]{3}|[0-9a-f]{6})\b/i', $out, $m ) ) {
			foreach ( $m[0] as $hex ) {
				$used[ strtolower( self::expand_hex( $hex ) ) ] = true;
			}
		}

		if ( $palette ) {
			$off = array_diff( array_keys( $used ), array_map( [ self::class, 'expand_hex' ], $palette ) );
			// Black, white and pure greys are structural rather than brand, so
			// flagging them produces noise on every single check.
			$off = array_filter( $off, static fn( string $h ): bool => ! self::is_neutral( $h ) );
			if ( $off ) {
				$fail(
					'off-palette-colour',
					implode( ', ', array_slice( $off, 0, 8 ) ),
					'Not in this site\'s palette. Use a declared colour, or add this one to the theme deliberately.'
				);
			}
		}

		$fonts = array_map( 'strtolower', (array) $tokens['fonts'] );
		if ( preg_match_all( '/font-family\s*:\s*([^;{}]+)/i', $out, $m ) ) {
			foreach ( $m[1] as $stack ) {
				$first = strtolower( trim( explode( ',', $stack )[0], " \"'\t\n" ) );
				if ( '' === $first || str_starts_with( $first, 'var(' ) || in_array( $first, [ 'inherit', 'initial', 'unset' ], true ) ) {
					continue;
				}
				$known = false;
				foreach ( $fonts as $f ) {
					if ( str_contains( $f, $first ) ) {
						$known = true;
						break;
					}
				}
				if ( ! $known && $fonts ) {
					$warn( 'off-palette-font', $first, 'Not one of the faces this theme declares.' );
				}
			}
		}

		/* --------------------------------------------------- the usual tells */

		$lower = strtolower( $out );

		if ( preg_match( '/font-family\s*:\s*["\']?(inter|space grotesk)\b/i', $out, $m ) ) {
			$warn( 'default-typeface', $m[1], 'Inter and Space Grotesk are the faces generated design reaches for by default. Pick one because it suits the subject.' );
		}

		if ( preg_match( '/linear-gradient\([^)]*(#[68][0-9a-f]{2}[0-9a-f]{3}|purple|violet|indigo|rgba?\(\s*1[0-4][0-9])/i', $out ) ) {
			$warn( 'purple-gradient', 'purple-to-blue gradient', 'The purple gradient hero is the single most recognisable generated-design tell.' );
		}

		if ( preg_match( '/#f[3-5]f[0-2]e[8-a]\b/i', $out ) && preg_match( '/#[cd][4-9][3-6][0-9a-f]{3}\b/i', $out ) ) {
			$warn( 'warm-craft-palette', 'cream ground with terracotta accent', 'Warm cream plus terracotta plus a serif is a look, and it is the one that turns up unprompted.' );
		}

		foreach ( [ 'lorem ipsum', 'your text here', 'placeholder text', 'sample text', 'dolor sit amet' ] as $filler ) {
			if ( str_contains( $lower, $filler ) ) {
				$fail( 'filler-copy', $filler, 'Placeholder copy shipped. Write the real words, or leave the section out.' );
			}
		}

		if ( preg_match_all( '/>\s*(Section\s*\d|Card\s*\d|Feature\s*\d|Item\s*\d|Heading\s*\d|Title\s*\d)\s*</i', $out, $m ) ) {
			$fail( 'generic-names', implode( ', ', array_unique( $m[1] ) ), 'Numbered generic labels are scaffolding, not content.' );
		}

		if ( preg_match_all( '/<(h[1-6]|p|li)[^>]*>\s*[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $out, $m ) ) {
			$warn( 'emoji-markers', count( $m[0] ) . ' headings or list items begin with an emoji', 'Emoji as section markers reads as generated. Use type and spacing instead.' );
		}

		$centred = preg_match_all( '/text-align\s*:\s*center/i', $out );
		$aligned = preg_match_all( '/text-align\s*:/i', $out );
		if ( $centred >= 4 && $aligned > 0 && ( $centred / $aligned ) > 0.8 ) {
			$warn( 'everything-centred', $centred . ' of ' . $aligned . ' text-align rules are center', 'Centring everything removes the hierarchy that alignment would otherwise carry.' );
		}

		$radii = [];
		if ( preg_match_all( '/border-radius\s*:\s*([^;{}]+)/i', $out, $m ) ) {
			foreach ( $m[1] as $r ) {
				$radii[ trim( $r ) ] = true;
			}
		}
		if ( count( $radii ) === 1 && $m && count( $m[1] ) >= 5 ) {
			$warn( 'uniform-radius', 'one radius on ' . count( $m[1] ) . ' elements', 'A single rounded corner everywhere is the default, not a decision.' );
		}

		/* ------------------------------------------------ this site's own rules */

		foreach ( (array) ( $stored['donts'] ?? [] ) as $dont ) {
			$needle = strtolower( trim( (string) $dont ) );
			if ( '' !== $needle && str_contains( $lower, $needle ) ) {
				$fail( 'site-rule', (string) $dont, 'This site says not to.' );
			}
		}

		$fails = array_values( array_filter( $findings, static fn( array $f ): bool => 'fail' === $f['severity'] ) );

		return [
			'checked_against' => $tokens['source'],
			'passed'          => ! $fails,
			'fails'           => count( $fails ),
			'warnings'        => count( $findings ) - count( $fails ),
			'findings'        => $findings,
			'note'            => $findings
				? 'Fix every fail before shipping. A warning is a question, not a verdict -- if the answer is that it suits this subject, it suits it.'
				: 'Nothing caught. That is not the same as good; it means none of the usual tells are present.',
		];
	}

	private static function expand_hex( string $hex ): string {
		$hex = ltrim( strtolower( trim( $hex ) ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return '#' . $hex;
	}

	/** Black, white and near-greys carry no brand, so they are never off-palette. */
	private static function is_neutral( string $hex ): bool {
		$hex = ltrim( $hex, '#' );
		if ( 6 !== strlen( $hex ) ) {
			return false;
		}
		[ $r, $g, $b ] = [ hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ];
		return ( max( $r, $g, $b ) - min( $r, $g, $b ) ) <= 12;
	}

	/* ------------------------------------------------------------ abilities */

	/** @param callable|array $gate */
	public static function register( callable|array $gate ): void {
		$ro = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ];
		$rw = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => false ] ];

		wp_register_ability( 'niranzwp/design-read', [
			'label'               => __( 'Read design', 'niranzwp' ),
			'description'         => __( 'The palette, typefaces and rules this site works to. Read this before building or restyling anything visual.', 'niranzwp' ),
			'category'            => 'niranzwp-design',
			'input_schema'        => [ 'type' => 'object', 'properties' => (object) [] ],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'read' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/design-check', [
			'label'               => __( 'Check design', 'niranzwp' ),
			'description'         => __( 'Checks HTML or CSS you have built against this site\'s palette and rules, and against the shapes generated design keeps landing on. Call it before shipping any visual work and fix every fail.', 'niranzwp' ),
			'category'            => 'niranzwp-design',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'output' => [ 'type' => 'string', 'description' => 'The HTML or CSS to check.' ],
				],
				'required'   => [ 'output' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'check' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/design-write', [
			'label'               => __( 'Write design', 'niranzwp' ),
			'description'         => __( 'Sets the name, notes and rules for this site\'s design. Palette and typefaces are only writable on a theme with no theme.json; otherwise they belong to the theme.', 'niranzwp' ),
			'category'            => 'niranzwp-design',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'name'   => [ 'type' => 'string' ],
					'notes'  => [ 'type' => 'string' ],
					'dos'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'donts'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'colors' => [ 'type' => 'object', 'description' => 'hex => name. Classic themes only.' ],
					'fonts'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'write' ],
			'meta'                => $rw,
		] );
	}
}
