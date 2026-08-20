<?php
/**
 * Elementor abilities.
 *
 * Elementor keeps a page's whole layout in one `_elementor_data` post meta:
 * a recursive JSON tree of elements, each with an id, an elType
 * (container / section / column / widget), a settings object, and children
 * under `elements`. Widgets additionally carry a `widgetType`.
 *
 * Two things make writing it by hand dangerous, and both are handled here:
 *
 *   1. The meta is stored slashed. Writing it back without wp_slash() mangles
 *      every quote in the layout.
 *   2. Elementor renders from generated CSS files, not from the meta. Change
 *      the data without clearing that cache and the page keeps showing the
 *      old design.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Elementor {

	public static function available(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	public static function register( callable|array $gate ): void {
		$ro = [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => true, 'destructive' => false ] ];

		register_ability( 'niranzwp/elementor-status', [
			'label'               => __( 'Elementor status', 'niranzwp' ),
			'description'         => __( 'Reports whether Elementor is active, its version, how many pages use it, and which widget types this site actually uses.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'sample' => [ 'type' => 'integer', 'default' => 200, 'minimum' => 10, 'maximum' => 2000 ] ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'status' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/elementor-read', [
			'label'               => __( 'Read Elementor layout', 'niranzwp' ),
			'description'         => __( 'Returns a page\'s Elementor layout as a readable tree of elements, widget types and their ids, without the full settings payload.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'       => [ 'type' => 'integer' ],
					'depth'    => [ 'type' => 'integer', 'default' => 4, 'minimum' => 1, 'maximum' => 10 ],
					'settings' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Include full settings for each element.' ],
				],
				'required'   => [ 'id' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'read' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/elementor-write', [
			'label'               => __( 'Write Elementor layout', 'niranzwp' ),
			'description'         => __( 'Adds, replaces or removes elements in a page\'s Elementor layout. Every element is checked against the widget types this site actually has before anything is written, ids are assigned for you, and the previous layout is snapshotted so the change can be put back. Reports what would change unless dry_run is false. Call elementor-widgets and elementor-widget first: a widget type that does not exist here, or a setting key that does not, renders as an empty element rather than an error.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'       => [ 'type' => 'integer', 'description' => 'The post or page to write to.' ],
					'mode'     => [
						'type'        => 'string',
						'enum'        => [ 'append', 'prepend', 'after', 'before', 'replace-element', 'replace-page', 'delete' ],
						'default'     => 'append',
						'description' => 'append and prepend go at the top level. after, before, replace-element and delete act on target. replace-page discards the whole layout.',
					],
					'elements' => [
						'type'        => 'array',
						'description' => 'Element trees: each with elType (container or widget), widgetType for widgets, settings, and elements for children. Ids are assigned here - do not invent them.',
						'items'       => [ 'type' => 'object' ],
					],
					'target'   => [ 'type' => 'string', 'description' => 'Existing element id, for after, before, replace-element and delete.' ],
					'dry_run'  => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'id' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'write' ],
			'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
		] );

		register_ability( 'niranzwp/elementor-move', [
			'label'               => __( 'Move an Elementor element', 'niranzwp' ),
			'description'         => __( 'Moves one element, with everything inside it, to another place on the same page - before or after another element, or in at the start or end of a container. The element keeps its id, so its styling and any link to it survive the move. Reports what would change unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'         => [ 'type' => 'integer' ],
					'element_id' => [ 'type' => 'string', 'description' => 'The element to move.' ],
					'target'     => [ 'type' => 'string', 'description' => 'The element to move it relative to. Omit with where first or last to use the top level of the page.' ],
					'where'      => [
						'type'    => 'string',
						'enum'    => [ 'before', 'after', 'first', 'last' ],
						'default' => 'after',
						'description' => 'before and after sit beside target. first and last go inside it.',
					],
					'dry_run'    => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'id', 'element_id' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'move' ],
			'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
		] );

		register_ability( 'niranzwp/elementor-widgets', [
			'label'               => __( 'List Elementor widgets', 'niranzwp' ),
			'description'         => __( 'Lists the Elementor widget types registered on this site, so a layout is built only from widgets that actually exist here. Call this before writing any Elementor content, and elementor-widget for the settings a particular one accepts.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'search'   => [ 'type' => 'string', 'description' => 'Match against the widget name and title.' ],
					'category' => [ 'type' => 'string', 'description' => 'Only widgets in this editor category, e.g. basic, general, theme-elements.' ],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'widgets' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/elementor-widget', [
			'label'               => __( 'Describe an Elementor widget', 'niranzwp' ),
			'description'         => __( 'The settings one widget type accepts: each key, its control type, its default, and the values a select or choose control will take. Only the widget\'s own settings by default - the 274 layout, spacing, position and effect settings every widget shares are returned by asking for the widget named "common" instead, once, rather than repeated for each. Read this before setting anything on a widget; a guessed key is silently ignored and a guessed value can render an empty element.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'name'       => [ 'type' => 'string', 'description' => 'Widget type, e.g. heading. Use "common" for the shared settings.' ],
					'responsive' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Include the _tablet and _mobile variants. Off by default: they are the same control at another breakpoint.' ],
					'search'     => [ 'type' => 'string', 'description' => 'Only settings whose key or label matches.' ],
				],
				'required'   => [ 'name' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'widget' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/elementor-find', [
			'label'               => __( 'Find Elementor elements', 'niranzwp' ),
			'description'         => __( 'Finds elements on a page by widget type or by matching text in their settings, returning each element id so it can be edited precisely.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'          => [ 'type' => 'integer' ],
					'widget_type' => [ 'type' => 'string' ],
					'text'        => [ 'type' => 'string' ],
				],
				'required'   => [ 'id' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'find' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/elementor-update-setting', [
			'label'               => __( 'Update an Elementor setting', 'niranzwp' ),
			'description'         => __( 'Changes one setting on one element of an Elementor page, found by element id. Previews the before and after unless dry_run is false, and clears Elementor\'s CSS cache after writing.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'         => [ 'type' => 'integer' ],
					'element_id' => [ 'type' => 'string' ],
					'setting'    => [ 'type' => 'string' ],
					'value'      => [ 'description' => 'String, number, boolean or object, depending on the control.' ],
					'dry_run'    => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'id', 'element_id', 'setting' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'update_setting' ],
			'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
		] );

		register_ability( 'niranzwp/elementor-settings-read', [
			'label'               => __( 'Read Elementor settings', 'niranzwp' ),
			'description'         => __( 'Reads the settings that live outside a page\'s element tree: with scope "site", the active kit - global colours, global fonts, layout defaults, the whole Site Settings panel; with scope "page", one page\'s own settings - its template, page background, page-level custom CSS. Reports what is set now and every key that can be set, so a write can name a real one.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'scope'  => [ 'type' => 'string', 'enum' => [ 'site', 'page' ], 'default' => 'site' ],
					'id'     => [ 'type' => 'integer', 'description' => 'The page, for scope page. Ignored for scope site.' ],
					'search' => [ 'type' => 'string', 'description' => 'Narrow the key list to those matching this.' ],
					'keys_only' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Report what is set now and nothing else.' ],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'settings_read' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/elementor-settings-write', [
			'label'               => __( 'Write Elementor settings', 'niranzwp' ),
			'description'         => __( 'Changes the settings outside a page\'s element tree - the site kit with scope "site", one page\'s own settings with scope "page". Only the keys passed are changed; everything else is left as it is. A key the document does not have is refused rather than stored, because Elementor would drop it silently. Reports the before and after unless dry_run is false, snapshots first, and lets Elementor regenerate its CSS.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'scope'    => [ 'type' => 'string', 'enum' => [ 'site', 'page' ], 'default' => 'site' ],
					'id'       => [ 'type' => 'integer', 'description' => 'The page, for scope page. Ignored for scope site.' ],
					'settings' => [ 'type' => 'object', 'description' => 'The keys to change, as key => value. Call elementor-settings-read for the names.' ],
					'dry_run'  => [ 'type' => 'boolean', 'default' => true ],
				],
				'required'   => [ 'settings' ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'settings_write' ],
			'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
		] );

		register_ability( 'niranzwp/elementor-templates', [
			'label'               => __( 'List Elementor templates', 'niranzwp' ),
			'description'         => __( 'Lists the site\'s Elementor library templates - headers, footers, single and archive layouts, popups, saved sections - with the display conditions that decide where each one appears. Also reports which template types this site can create and which condition names it accepts, so a template can be made and placed without guessing.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'type' => [ 'type' => 'string', 'description' => 'Only templates of this type: header, footer, single-post, archive, popup and so on.' ],
					'id'   => [ 'type' => 'integer', 'description' => 'One template, in full.' ],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'templates' ],
			'meta'                => $ro,
		] );

		register_ability( 'niranzwp/elementor-template-write', [
			'label'               => __( 'Create or place an Elementor template', 'niranzwp' ),
			'description'         => __( 'Creates a template of a given type, or changes an existing one\'s title, status or display conditions. Creating one leaves it empty: write its layout with elementor-write, using the id reported here. Conditions decide where the template takes effect and are what makes a header the site\'s header rather than a page in the library. Reports what would change unless dry_run is false.', 'niranzwp' ),
			'category'            => 'niranzwp-elementor',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'         => [ 'type' => 'integer', 'description' => 'An existing template to change. Leave out to create one.' ],
					'type'       => [ 'type' => 'string', 'description' => 'Template type, when creating. Call elementor-templates for the list.' ],
					'title'      => [ 'type' => 'string' ],
					'status'     => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ] ],
					'conditions' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Where this template applies, as include/... or exclude/... - for example include/general, include/singular/post, exclude/singular/page/12. An empty array removes every condition.',
					],
					'dry_run'    => [ 'type' => 'boolean', 'default' => true ],
				],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'template_write' ],
			'meta'                => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false, 'destructive' => true ] ],
		] );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function data( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'niranzwp_not_found', 'No post with ID ' . $post_id );
		}

		// Elementor copies _elementor_data onto revisions, so a revision id
		// looks perfectly valid here -- and editing one silently changes
		// history while the caller believes they edited the page. Refuse and
		// name the real post instead.
		$parent = wp_is_post_revision( $post_id );
		if ( $parent ) {
			return new \WP_Error(
				'niranzwp_is_revision',
				sprintf( 'Post %d is a revision of post %d. Work on %d instead.', $post_id, $parent, $parent )
			);
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( '' === $raw || null === $raw ) {
			return new \WP_Error( 'niranzwp_not_elementor', 'Post ' . $post_id . ' has no Elementor layout.' );
		}

		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'niranzwp_bad_data', 'The Elementor layout on post ' . $post_id . ' is not valid JSON.' );
		}

		return $data;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function status( mixed $input = [] ): array {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		global $wpdb;

		if ( ! self::available() ) {
			return [ 'active' => false, 'note' => 'Elementor is not active on this site.' ];
		}

		$sample = max( 10, min( 2000, (int) ( $input['sample'] ?? 200 ) ) );

		$pages = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data'"
		);

		// Widget names are counted from the raw JSON rather than by decoding
		// every layout; on a large site that is the difference between a
		// second and a timeout.
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data' LIMIT %d",
			$sample
		) );

		$counts = [];
		foreach ( $rows as $json ) {
			if ( preg_match_all( '/"widgetType":"([^"]+)"/', (string) $json, $m ) ) {
				foreach ( $m[1] as $w ) {
					$counts[ $w ] = ( $counts[ $w ] ?? 0 ) + 1;
				}
			}
		}
		arsort( $counts );

		return [
			'active'        => true,
			'version'       => ELEMENTOR_VERSION,
			'pro'           => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'pages_using'   => $pages,
			'sampled'       => count( $rows ),
			'widgets_used'  => array_slice( $counts, 0, 25, true ),
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $els
	 * @return array<int,array<string,mixed>>
	 */
	private static function tree( array $els, int $depth, bool $with_settings, int $level = 0 ): array {
		$out = [];

		foreach ( $els as $e ) {
			$node = [
				'id'     => $e['id'] ?? null,
				'elType' => $e['elType'] ?? null,
			];
			if ( isset( $e['widgetType'] ) ) {
				$node['widgetType'] = $e['widgetType'];
			}
			if ( ! empty( $e['isInner'] ) ) {
				$node['isInner'] = true;
			}

			$settings = (array) ( $e['settings'] ?? [] );
			if ( $with_settings ) {
				$node['settings'] = $settings;
			} else {
				// A label is far more useful than 60 style keys.
				foreach ( [ 'title', 'editor', 'text', 'heading_title' ] as $key ) {
					if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
						$node['label'] = mb_substr( trim( wp_strip_all_tags( $settings[ $key ] ) ), 0, 80 );
						break;
					}
				}
				$node['setting_count'] = count( $settings );
			}

			$kids = (array) ( $e['elements'] ?? [] );
			if ( $kids ) {
				$node['child_count'] = count( $kids );
				if ( $level + 1 < $depth ) {
					$node['elements'] = self::tree( $kids, $depth, $with_settings, $level + 1 );
				}
			}

			$out[] = $node;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function read( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$id   = (int) ( $input['id'] ?? 0 );
		$data = self::data( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$depth = max( 1, min( 10, (int) ( $input['depth'] ?? 4 ) ) );

		return [
			'id'            => $id,
			'title'         => get_the_title( $id ),
			'edit_mode'     => get_post_meta( $id, '_elementor_edit_mode', true ),
			'template_type' => get_post_meta( $id, '_elementor_template_type', true ) ?: null,
			'version'       => get_post_meta( $id, '_elementor_version', true ),
			'top_level'     => count( $data ),
			'elements'      => self::tree( $data, $depth, (bool) ( $input['settings'] ?? false ) ),
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $els
	 * @param array<int,array<string,mixed>> $hits
	 */
	private static function walk( array $els, callable $match, array &$hits, string $path = '' ): void {
		foreach ( $els as $i => $e ) {
			$here = $path ? "{$path}.elements[{$i}]" : "[{$i}]";
			if ( $match( $e ) ) {
				$settings = (array) ( $e['settings'] ?? [] );
				$hits[]   = [
					'element_id' => $e['id'] ?? null,
					'elType'     => $e['elType'] ?? null,
					'widgetType' => $e['widgetType'] ?? null,
					'path'       => $here,
					'settings'   => array_slice( array_keys( $settings ), 0, 12 ),
				];
			}
			$kids = (array) ( $e['elements'] ?? [] );
			if ( $kids ) {
				self::walk( $kids, $match, $hits, $here );
			}
		}
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function find( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$id   = (int) ( $input['id'] ?? 0 );
		$data = self::data( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$widget = trim( (string) ( $input['widget_type'] ?? '' ) );
		$text   = trim( (string) ( $input['text'] ?? '' ) );

		if ( '' === $widget && '' === $text ) {
			return new \WP_Error( 'niranzwp_no_criteria', 'Pass widget_type, text, or both.' );
		}

		$match = static function ( array $e ) use ( $widget, $text ): bool {
			if ( '' !== $widget && ( $e['widgetType'] ?? '' ) !== $widget ) {
				return false;
			}
			if ( '' !== $text ) {
				$blob = wp_json_encode( $e['settings'] ?? [] );
				if ( false === stripos( (string) $blob, $text ) ) {
					return false;
				}
			}
			return true;
		};

		$hits = [];
		self::walk( $data, $match, $hits );

		return [
			'id'      => $id,
			'matches' => count( $hits ),
			'elements'=> $hits,
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $els
	 * @return array{0:array<int,array<string,mixed>>,1:mixed,2:bool}
	 */
	private static function set_in( array $els, string $element_id, string $setting, $value ): array {
		$before = null;
		$found  = false;

		foreach ( $els as $i => $e ) {
			if ( ( $e['id'] ?? '' ) === $element_id ) {
				$before = $e['settings'][ $setting ] ?? null;
				$els[ $i ]['settings'][ $setting ] = $value;
				return [ $els, $before, true ];
			}
			$kids = (array) ( $e['elements'] ?? [] );
			if ( $kids ) {
				[ $new_kids, $b, $f ] = self::set_in( $kids, $element_id, $setting, $value );
				if ( $f ) {
					$els[ $i ]['elements'] = $new_kids;
					return [ $els, $b, true ];
				}
			}
		}

		return [ $els, $before, $found ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function update_setting( mixed $input = [] ) {
		// Core hands the callback whatever arrived in the request, which is an
		// empty string when a GET ability is called with no input at all.
		$input = is_array( $input ) ? $input : [];
		$id         = (int) ( $input['id'] ?? 0 );
		$element_id = (string) ( $input['element_id'] ?? '' );
		$setting    = (string) ( $input['setting'] ?? '' );
		$value      = $input['value'] ?? null;
		$dry        = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];

		$data = self::data( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( '' === $element_id || '' === $setting ) {
			return new \WP_Error( 'niranzwp_missing', 'element_id and setting are both required.' );
		}

		[ $updated, $before, $found ] = self::set_in( $data, $element_id, $setting, $value );

		if ( ! $found ) {
			return new \WP_Error(
				'niranzwp_element_not_found',
				sprintf( 'No element with id "%s" on post %d. Use elementor-find to locate one.', $element_id, $id )
			);
		}

		$result = [
			'id'         => $id,
			'element_id' => $element_id,
			'setting'    => $setting,
			'before'     => $before,
			'after'      => $value,
			'dry_run'    => $dry,
		];

		if ( $dry ) {
			$result['status'] = 'would_update';
			$result['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $result;
		}

		$json = wp_json_encode( $updated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new \WP_Error( 'niranzwp_encode_failed', 'Could not re-encode the layout.' );
		}

		// Snapshot the layout before it is replaced, so the edit is undoable.
		$result['checkpoint_id'] = Checkpoint::before_post( $id, 'elementor-update-setting' );

		// Elementor reads this meta slashed; update_post_meta strips one level
		// of slashes, so it has to be added back or every quote is mangled.
		update_post_meta( $id, '_elementor_data', wp_slash( $json ) );

		// The page renders from generated CSS, not from this meta.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$result['css_cache_cleared'] = true;
		}
		clean_post_cache( $id );

		$result['status'] = 'updated';
		return $result;
	}

	/**
	 * Elementor's own name for the settings every widget carries.
	 *
	 * Measured on this codebase: the `common` widget's 274 controls are the
	 * same 274 that appear on the Advanced tab of heading, button, image,
	 * text-editor, icon-box and image-carousel - identical keys, none missing,
	 * none extra. So they are described once under that name rather than
	 * repeated 254 times, which is the difference between a catalogue a client
	 * can read and 6 MB of duplicated JSON.
	 */
	private const SHARED = 'common';

	/** Widget types with no title are Elementor's internal bases, not widgets. */
	private static function widget_manager(): ?object {
		return self::available() && isset( \Elementor\Plugin::$instance->widgets_manager )
			? \Elementor\Plugin::$instance->widgets_manager
			: null;
	}

	/**
	 * A container is not a widget, and Elementor keeps the two in different
	 * registries: widgets_manager for the 253 things you drop onto a page,
	 * elements_manager for container, section and column - the things you drop
	 * them into. Nothing here was checking the second, so a mistyped container
	 * setting went through silently, which is the failure this whole catalogue
	 * exists to prevent.
	 */
	/**
	 * The keys in a settings object that the element has no control for.
	 *
	 * Two kinds of key are legitimate without being controls. A responsive
	 * variant is its base key plus a breakpoint suffix. And Elementor's own
	 * reserved keys - __globals__, which binds a control to a global colour or
	 * font, and __dynamic__, which binds one to a dynamic tag - hold a map of
	 * control name to source rather than a value. Those are checked one level
	 * in, so a typo inside them is still caught.
	 *
	 * @param array<string,mixed> $settings Settings as given.
	 * @param string[]            $known    Control names the element has.
	 * @return string[] Keys to report, in the order they were given.
	 */
	private static function unknown_keys( array $settings, array $known ): array {
		$known   = array_flip( array_map( 'strval', $known ) );
		$unknown = [];

		foreach ( $settings as $key => $value ) {
			$key = (string) $key;

			if ( preg_match( '/^__.+__$/', $key ) ) {
				if ( is_array( $value ) ) {
					foreach ( array_keys( $value ) as $bound ) {
						if ( ! isset( $known[ (string) $bound ] ) ) {
							$unknown[] = $key . '.' . $bound;
						}
					}
				}
				continue;
			}

			$base = (string) preg_replace( '/_(tablet|mobile|laptop|widescreen|mobile_extra|tablet_extra)$/', '', $key );
			if ( ! isset( $known[ $key ] ) && ! isset( $known[ $base ] ) ) {
				$unknown[] = $key;
			}
		}

		return $unknown;
	}

	/**
	 * One control, as the catalogue reports it.
	 *
	 * @param array<string,mixed> $control     Elementor control definition.
	 * @param int                 $max_default How long a structured default may
	 *                                         be before it is reported by shape
	 *                                         rather than by value.
	 * @return array<string,mixed>
	 */
	private static function control_entry( array $control, int $max_default = 200 ): array {
		$entry = [ 'type' => (string) ( $control['type'] ?? '' ) ];

		$label = (string) ( $control['label'] ?? '' );
		if ( '' !== $label ) {
			$entry['label'] = $label;
		}

		if ( array_key_exists( 'default', $control ) ) {
			$default = $control['default'];
			// Scalars go as they are; a structured default is only useful
			// if it is small enough to copy, so long ones say their shape.
			if ( is_scalar( $default ) || null === $default ) {
				$entry['default'] = $default;
			} else {
				$encoded = wp_json_encode( $default );
				$entry['default'] = ( is_string( $encoded ) && strlen( $encoded ) <= $max_default )
					? json_decode( $encoded, true )
					: gettype( $default );
			}
		}

		if ( isset( $control['options'] ) && is_array( $control['options'] ) ) {
			$entry['options'] = array_map( 'strval', array_keys( $control['options'] ) );
		}

		return $entry;
	}

	/**
	 * The control keys every element already has, as a lookup.
	 *
	 * These are read from the shared widget itself rather than inferred from
	 * the control's tab. The tab is not a safe proxy: a container declares its
	 * own Layout section - margin, padding, width, z-index - under the
	 * Advanced tab, and treating the tab as "shared" hid those keys from the
	 * catalogue even though writing them works.
	 *
	 * @return array<string,true>
	 */
	private static function shared_keys(): array {
		static $keys = null;
		if ( null !== $keys ) {
			return $keys;
		}

		$keys   = [];
		$common = self::widget_manager() ? self::widget_manager()->get_widget_types( self::SHARED ) : null;
		if ( $common && method_exists( $common, 'get_controls' ) ) {
			foreach ( array_keys( (array) $common->get_controls() ) as $key ) {
				$keys[ $key ] = true;
			}
		}

		return $keys;
	}

	private static function element_type( string $name ): ?object {
		if ( ! self::available() ) {
			return null;
		}

		$wm = self::widget_manager();
		if ( $wm ) {
			$widget = $wm->get_widget_types( $name );
			if ( $widget ) {
				return $widget;
			}
		}

		if ( isset( \Elementor\Plugin::$instance->elements_manager ) ) {
			$element = \Elementor\Plugin::$instance->elements_manager->get_element_types( $name );
			if ( $element ) {
				return $element;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function widgets( array $input ) {
		$wm = self::widget_manager();
		if ( ! $wm ) {
			return new \WP_Error( 'niranzwp_no_elementor', 'Elementor is not active on this site.' );
		}

		$search   = strtolower( trim( (string) ( $input['search'] ?? '' ) ) );
		$category = strtolower( trim( (string) ( $input['category'] ?? '' ) ) );

		$out        = [];
		$categories = [];

		foreach ( $wm->get_widget_types() as $name => $widget ) {
			$title = (string) $widget->get_title();
			if ( '' === $title ) {
				continue;
			}

			$cats = array_map( 'strval', (array) $widget->get_categories() );
			foreach ( $cats as $c ) {
				$categories[ $c ] = ( $categories[ $c ] ?? 0 ) + 1;
			}

			if ( '' !== $category && ! in_array( $category, array_map( 'strtolower', $cats ), true ) ) {
				continue;
			}
			if ( '' !== $search && ! str_contains( strtolower( $name . ' ' . $title ), $search ) ) {
				continue;
			}

			$out[] = [
				'name'       => (string) $name,
				'title'      => $title,
				'categories' => $cats,
			];
		}

		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['name'], $b['name'] ) );
		ksort( $categories );

		return [
			'elementor'  => ELEMENTOR_VERSION,
			'count'      => count( $out ),
			'categories' => $categories,
			'widgets'    => $out,
			'shared'     => sprintf(
				/* translators: %s: the widget name that carries the shared settings. */
				__( 'Every widget also accepts the settings of "%s" - ask for that one to see them.', 'niranzwp' ),
				self::SHARED
			),
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function widget( array $input ) {
		$wm = self::widget_manager();
		if ( ! $wm ) {
			return new \WP_Error( 'niranzwp_no_elementor', 'Elementor is not active on this site.' );
		}

		$name = trim( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'niranzwp_bad_input', 'Pass the widget name, e.g. heading.' );
		}

		$widget = self::element_type( $name );
		if ( ! $widget ) {
			return new \WP_Error(
				'niranzwp_not_found',
				sprintf( 'No widget or element type "%s" on this site. Call elementor-widgets to see what there is; container, section and column are valid names here too.', $name )
			);
		}

		$shared     = self::SHARED === $name;
		$responsive = ! empty( $input['responsive'] );
		$search     = strtolower( trim( (string) ( $input['search'] ?? '' ) ) );

		$settings = [];
		$skipped  = [ 'responsive' => 0, 'shared' => 0 ];

		foreach ( $widget->get_controls() as $key => $control ) {
			$type = (string) ( $control['type'] ?? '' );

			// A section is a heading in the editor panel, not a setting.
			if ( 'section' === $type ) {
				continue;
			}

			/*
			 * The _tablet and _mobile keys are the same control at another
			 * breakpoint. Listing them triples the reply and teaches a client
			 * nothing it did not already know from the base key.
			 */
			if ( ! $responsive && preg_match( '/_(tablet|mobile|laptop|widescreen|mobile_extra|tablet_extra)$/', (string) $key ) ) {
				++$skipped['responsive'];
				continue;
			}

			if ( ! $shared ) {
				$common = self::shared_keys();
				// With the shared set in hand, membership in it is the answer.
				// Without it, fall back to the tab, which is what this used to
				// go on: wrong for a container, but better than replying with
				// every control on every widget.
				$is_shared = $common
					? isset( $common[ $key ] )
					: 'advanced' === (string) ( $control['tab'] ?? '' );
				if ( $is_shared ) {
					++$skipped['shared'];
					continue;
				}
			}

			$label = (string) ( $control['label'] ?? '' );
			if ( '' !== $search && ! str_contains( strtolower( $key . ' ' . $label ), $search ) ) {
				continue;
			}

			$settings[ (string) $key ] = self::control_entry( $control );
		}

		/*
		 * get_categories() is a widget method. A container has no editor
		 * category because you do not pick it out of the widget panel, and
		 * calling it there is a fatal - which is what happened the first time
		 * this accepted a container name.
		 */
		$result = [
			'name'     => $name,
			'title'    => method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : $name,
			'kind'     => method_exists( $widget, 'get_categories' ) ? 'widget' : 'element',
			'count'    => count( $settings ),
			'settings' => $settings,
		];
		if ( method_exists( $widget, 'get_categories' ) ) {
			$result['categories'] = array_map( 'strval', (array) $widget->get_categories() );
		}

		if ( $skipped['shared'] > 0 ) {
			$result['shared_settings'] = [
				'count' => $skipped['shared'],
				'where' => self::SHARED,
				'note'  => __( 'Layout, spacing, position, background, border and motion settings, the same on every widget. Ask for the widget named here to see them.', 'niranzwp' ),
			];
		}
		if ( $skipped['responsive'] > 0 ) {
			$result['responsive_variants'] = [
				'count' => $skipped['responsive'],
				'note'  => __( 'Each is its base key plus _tablet or _mobile. Pass responsive true to list them.', 'niranzwp' ),
			];
		}

		return $result;
	}


	/* ------------------------------------------------------------- writing */

	/**
	 * Every id already on the page, so a new one cannot collide with an old.
	 *
	 * @param array<int,array<string,mixed>> $nodes
	 * @param array<string,bool>             $seen
	 * @return array<string,bool>
	 */
	private static function ids( array $nodes, array $seen = [] ): array {
		foreach ( $nodes as $n ) {
			if ( is_array( $n ) && isset( $n['id'] ) ) {
				$seen[ (string) $n['id'] ] = true;
			}
			if ( is_array( $n ) && ! empty( $n['elements'] ) && is_array( $n['elements'] ) ) {
				$seen = self::ids( $n['elements'], $seen );
			}
		}
		return $seen;
	}

	/**
	 * Check a tree and give every element an id.
	 *
	 * Ids are assigned here rather than accepted from the caller. Elementor
	 * uses the id to address an element for editing and for its generated CSS
	 * selector, so two elements sharing one is not a cosmetic problem: the
	 * second becomes unaddressable and inherits the first's styling.
	 *
	 * Unknown widget types are refused. Unknown setting keys are not - a
	 * control can be added by a third-party plugin at render time, and
	 * refusing those would make the ability unusable on a real site - but they
	 * are collected and reported, because a mistyped key is dropped by
	 * Elementor without a word and the element comes out blank.
	 *
	 * @param array<string,mixed> $node
	 * @param array<string,bool>  $used
	 * @param array<string,mixed> $report
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function prepare( array $node, array &$used, array &$report ) {
		$type = (string) ( $node['elType'] ?? '' );
		if ( ! in_array( $type, [ 'container', 'section', 'column', 'widget' ], true ) ) {
			return new \WP_Error(
				'niranzwp_bad_element',
				sprintf( 'elType must be container, section, column or widget; got "%s".', $type )
			);
		}

		$out = [ 'elType' => $type ];

		if ( 'widget' === $type ) {
			$widget_type = (string) ( $node['widgetType'] ?? '' );
			$wm          = self::widget_manager();
			if ( '' === $widget_type ) {
				return new \WP_Error( 'niranzwp_bad_element', 'A widget needs a widgetType.' );
			}
			if ( $wm && ! $wm->get_widget_types( $widget_type ) ) {
				return new \WP_Error(
					'niranzwp_unknown_widget',
					sprintf( 'No widget type "%s" on this site. Call elementor-widgets for the list.', $widget_type )
				);
			}
			$out['widgetType'] = $widget_type;

			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
			if ( $wm && $settings ) {
				$widget = $wm->get_widget_types( $widget_type );
				$known  = $widget ? array_keys( $widget->get_controls() ) : [];
				foreach ( self::unknown_keys( $settings, $known ) as $key ) {
					$report['unknown_settings'][] = $widget_type . '.' . $key;
				}
			}
			$out['settings'] = (object) $settings;
		} else {
			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
			$element  = self::element_type( $type );
			if ( $element && $settings ) {
				$known = array_keys( $element->get_controls() );
				foreach ( self::unknown_keys( $settings, $known ) as $key ) {
					$report['unknown_settings'][] = $type . '.' . $key;
				}
			}
			$out['settings'] = (object) $settings;
		}

		// Seven lowercase hex characters, which is the shape Elementor's own
		// editor generates. Loop rather than trust randomness on a page that
		// may already hold hundreds of ids.
		do {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( isset( $used[ $id ] ) );
		$used[ $id ] = true;
		$out['id']   = $id;
		++$report['created'];

		$children = [];
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $child ) {
				if ( ! is_array( $child ) ) {
					return new \WP_Error( 'niranzwp_bad_element', 'Every entry in elements must be an object.' );
				}
				$prepared = self::prepare( $child, $used, $report );
				if ( is_wp_error( $prepared ) ) {
					return $prepared;
				}
				$children[] = $prepared;
			}
		}
		$out['elements'] = $children;

		return $out;
	}

	/**
	 * Place, replace or remove a subtree at the element with $target.
	 *
	 * Returns the tree and whether the target was reached, so the caller can
	 * tell "nothing matched" from "matched and removed" - which look the same
	 * in the returned tree and mean opposite things to the person who asked.
	 *
	 * @param array<int,array<string,mixed>> $nodes
	 * @param array<int,array<string,mixed>> $new
	 * @return array{0:array<int,array<string,mixed>>,1:bool}
	 */
	private static function splice( array $nodes, string $target, array $new, string $where ): array {
		$out   = [];
		$found = false;

		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && (string) ( $node['id'] ?? '' ) === $target ) {
				$found = true;
				switch ( $where ) {
					case 'before':
						foreach ( $new as $n ) {
							$out[] = $n;
						}
						$out[] = $node;
						break;
					case 'after':
						$out[] = $node;
						foreach ( $new as $n ) {
							$out[] = $n;
						}
						break;
					case 'replace-element':
						foreach ( $new as $n ) {
							$out[] = $n;
						}
						break;
					case 'delete':
						break;
				}
				continue;
			}

			if ( is_array( $node ) && ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				[ $kids, $hit ] = self::splice( $node['elements'], $target, $new, $where );
				if ( $hit ) {
					$found            = true;
					$node['elements'] = $kids;
				}
			}
			$out[] = $node;
		}

		return [ $out, $found ];
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function write( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];

		$id     = (int) ( $input['id'] ?? 0 );
		$mode   = (string) ( $input['mode'] ?? 'append' );
		$target = (string) ( $input['target'] ?? '' );
		$dry    = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$given  = isset( $input['elements'] ) && is_array( $input['elements'] ) ? $input['elements'] : [];

		if ( ! self::available() ) {
			return new \WP_Error( 'niranzwp_no_elementor', 'Elementor is not active on this site.' );
		}
		if ( ! get_post( $id ) ) {
			return new \WP_Error( 'niranzwp_not_found', 'No post with id ' . $id );
		}

		$needs_target = in_array( $mode, [ 'after', 'before', 'replace-element', 'delete' ], true );
		if ( $needs_target && '' === $target ) {
			return new \WP_Error( 'niranzwp_missing', sprintf( 'Mode "%s" needs a target element id.', $mode ) );
		}
		if ( 'delete' !== $mode && ! $given ) {
			return new \WP_Error( 'niranzwp_missing', 'Pass the elements to write.' );
		}

		/*
		 * An empty layout is a legitimate starting point - a page that has
		 * never been opened in Elementor has no meta at all - so a missing
		 * value is an empty tree rather than an error. Malformed JSON is
		 * different and must not be overwritten silently.
		 */
		$raw  = get_post_meta( $id, '_elementor_data', true );
		$data = [];
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return new \WP_Error( 'niranzwp_corrupt', sprintf( 'The Elementor data on post %d is not valid JSON. Refusing to overwrite it.', $id ) );
			}
			$data = $decoded;
		}

		$used   = self::ids( $data );
		$report = [ 'created' => 0, 'unknown_settings' => [] ];

		$prepared = [];
		foreach ( $given as $node ) {
			if ( ! is_array( $node ) ) {
				return new \WP_Error( 'niranzwp_bad_element', 'Every entry in elements must be an object.' );
			}
			$one = self::prepare( $node, $used, $report );
			if ( is_wp_error( $one ) ) {
				return $one;
			}
			$prepared[] = $one;
		}

		$before_count = count( self::ids( $data ) );

		switch ( $mode ) {
			case 'replace-page':
				$data = $prepared;
				break;
			case 'append':
				$data = array_merge( $data, $prepared );
				break;
			case 'prepend':
				$data = array_merge( $prepared, $data );
				break;
			default:
				[ $data, $found ] = self::splice( $data, $target, $prepared, $mode );
				if ( ! $found ) {
					return new \WP_Error(
						'niranzwp_element_not_found',
						sprintf( 'No element with id "%s" on post %d. Use elementor-find to locate one.', $target, $id )
					);
				}
		}

		$result = [
			'id'               => $id,
			'mode'             => $mode,
			'elements_added'   => $report['created'],
			'elements_before'  => $before_count,
			'elements_after'   => count( self::ids( $data ) ),
			'dry_run'          => $dry,
		];
		if ( '' !== $target ) {
			$result['target'] = $target;
		}
		if ( $report['unknown_settings'] ) {
			$result['unknown_settings'] = array_values( array_unique( $report['unknown_settings'] ) );
			$result['unknown_note']     = __( 'Elementor drops a setting key it does not know without saying so, and the element renders blank. Check these against elementor-widget.', 'niranzwp' );
		}

		if ( $dry ) {
			$result['status'] = 'would_write';
			$result['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $result;
		}

		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new \WP_Error( 'niranzwp_encode_failed', 'Could not encode the layout.' );
		}

		$result['checkpoint_id'] = Checkpoint::before_post( $id, 'elementor-write' );

		// Slashed on the way in, or every quote in the layout is mangled.
		update_post_meta( $id, '_elementor_data', wp_slash( $json ) );

		$adopted = self::adopt( $id );
		if ( $adopted ) {
			$result['now_an_elementor_page'] = $adopted;
		}

		// The page renders from generated CSS, not from this meta.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$result['css_cache_cleared'] = true;
		}
		clean_post_cache( $id );

		$result['status'] = 'written';
		return $result;
	}

	/**
	 * Make the post an Elementor page, if it is not one already.
	 *
	 * Writing _elementor_data is not enough. Elementor only renders the layout
	 * for a post whose edit mode says it was built with the builder; without
	 * that the layout is stored and the theme renders the (empty) post content
	 * instead, which looks exactly like a write that silently did nothing.
	 *
	 * Only the missing keys are set, so a page already opened in the editor
	 * keeps its own template type and the version that last saved it.
	 *
	 * @param int $id Post id.
	 * @return array Meta keys that were added.
	 */
	private static function adopt( int $id ): array {
		$added = [];

		if ( '' === (string) get_post_meta( $id, '_elementor_edit_mode', true ) ) {
			update_post_meta( $id, '_elementor_edit_mode', 'builder' );
			$added[] = '_elementor_edit_mode';
		}

		if ( '' === (string) get_post_meta( $id, '_elementor_template_type', true ) ) {
			$type = get_post_type( $id );
			update_post_meta( $id, '_elementor_template_type', 'wp-' . ( $type ?: 'post' ) );
			$added[] = '_elementor_template_type';
		}

		if ( '' === (string) get_post_meta( $id, '_elementor_version', true ) && defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
			$added[] = '_elementor_version';
		}

		return $added;
	}


	/* ---------------------------------------------------------------- move */

	/**
	 * Lift one element out of the tree and hand it back with what remains.
	 *
	 * @param array<int,array<string,mixed>> $nodes
	 * @return array{0:array<int,array<string,mixed>>,1:?array<string,mixed>}
	 */
	private static function extract( array $nodes, string $element_id ): array {
		$out  = [];
		$took = null;

		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && (string) ( $node['id'] ?? '' ) === $element_id ) {
				$took = $node;
				continue;
			}
			if ( is_array( $node ) && ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				[ $kids, $found ] = self::extract( $node['elements'], $element_id );
				if ( null !== $found ) {
					$took             = $found;
					$node['elements'] = $kids;
				}
			}
			$out[] = $node;
		}

		return [ $out, $took ];
	}

	/** Is $element_id anywhere inside this subtree, including at its root? */
	private static function holds( array $node, string $element_id ): bool {
		if ( (string) ( $node['id'] ?? '' ) === $element_id ) {
			return true;
		}
		foreach ( (array) ( $node['elements'] ?? [] ) as $child ) {
			if ( is_array( $child ) && self::holds( $child, $element_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Put a subtree inside a container, at one end or the other.
	 *
	 * @param array<int,array<string,mixed>> $nodes
	 * @param array<string,mixed>            $moving
	 * @return array{0:array<int,array<string,mixed>>,1:bool}
	 */
	private static function put_inside( array $nodes, string $target, array $moving, string $end ): array {
		$out   = [];
		$found = false;

		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && (string) ( $node['id'] ?? '' ) === $target ) {
				$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];
				$node['elements'] = 'first' === $end
					? array_merge( [ $moving ], $children )
					: array_merge( $children, [ $moving ] );
				$found = true;
				$out[] = $node;
				continue;
			}
			if ( is_array( $node ) && ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				[ $kids, $hit ] = self::put_inside( $node['elements'], $target, $moving, $end );
				if ( $hit ) {
					$found            = true;
					$node['elements'] = $kids;
				}
			}
			$out[] = $node;
		}

		return [ $out, $found ];
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function move( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];

		$id         = (int) ( $input['id'] ?? 0 );
		$element_id = (string) ( $input['element_id'] ?? '' );
		$target     = (string) ( $input['target'] ?? '' );
		$where      = (string) ( $input['where'] ?? 'after' );
		$dry        = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];

		if ( ! self::available() ) {
			return new \WP_Error( 'niranzwp_no_elementor', 'Elementor is not active on this site.' );
		}
		if ( '' === $element_id ) {
			return new \WP_Error( 'niranzwp_missing', 'Pass the element_id to move.' );
		}
		if ( '' === $target && ! in_array( $where, [ 'first', 'last' ], true ) ) {
			return new \WP_Error( 'niranzwp_missing', sprintf( 'Where "%s" needs a target element id.', $where ) );
		}
		if ( $target === $element_id ) {
			return new \WP_Error( 'niranzwp_bad_input', 'An element cannot be moved relative to itself.' );
		}

		$data = self::data( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		[ $remaining, $moving ] = self::extract( $data, $element_id );
		if ( null === $moving ) {
			return new \WP_Error(
				'niranzwp_element_not_found',
				sprintf( 'No element with id "%s" on post %d. Use elementor-find to locate one.', $element_id, $id )
			);
		}

		/*
		 * Moving something into its own subtree detaches that whole branch
		 * from the page: the element is no longer anywhere the renderer walks,
		 * and it takes its children with it. The tree that comes back looks
		 * valid, which is what makes it worth refusing rather than checking
		 * for afterwards.
		 */
		if ( '' !== $target && self::holds( $moving, $target ) ) {
			return new \WP_Error(
				'niranzwp_bad_input',
				sprintf( 'Element "%s" is inside "%s"; moving it there would detach both from the page.', $target, $element_id )
			);
		}

		if ( '' === $target ) {
			$data  = 'first' === $where ? array_merge( [ $moving ], $remaining ) : array_merge( $remaining, [ $moving ] );
			$found = true;
		} elseif ( in_array( $where, [ 'first', 'last' ], true ) ) {
			[ $data, $found ] = self::put_inside( $remaining, $target, $moving, $where );
		} else {
			[ $data, $found ] = self::splice( $remaining, $target, [ $moving ], 'before' === $where ? 'before' : 'after' );
		}

		if ( ! $found ) {
			return new \WP_Error(
				'niranzwp_element_not_found',
				sprintf( 'No element with id "%s" on post %d to move it next to.', $target, $id )
			);
		}

		$result = [
			'id'         => $id,
			'element_id' => $element_id,
			'where'      => $where,
			'moved'      => 1 + count( self::ids( (array) ( $moving['elements'] ?? [] ) ) ),
			'dry_run'    => $dry,
		];
		if ( '' !== $target ) {
			$result['target'] = $target;
		}

		if ( $dry ) {
			$result['status'] = 'would_move';
			$result['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $result;
		}

		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new \WP_Error( 'niranzwp_encode_failed', 'Could not encode the layout.' );
		}

		$result['checkpoint_id'] = Checkpoint::before_post( $id, 'elementor-move' );
		update_post_meta( $id, '_elementor_data', wp_slash( $json ) );

		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$result['css_cache_cleared'] = true;
		}
		clean_post_cache( $id );

		$result['status'] = 'moved';
		return $result;
	}

	/* ------------------------------------------ settings outside the tree */

	/**
	 * The document whose settings a scope means, and the post holding them.
	 *
	 * Elementor keeps two kinds of setting away from the element tree. A page
	 * has its own - template, page background, page-level custom CSS - and the
	 * site has a kit, which is the Site Settings panel: global colours, global
	 * fonts, layout defaults. Both are documents, both store their settings in
	 * the same meta key on their own post, and neither is reachable through
	 * _elementor_data, which is why elementor-write cannot touch them.
	 *
	 * @param string $scope site or page.
	 * @param int    $id    The page, for scope page.
	 * @return array{doc:object,post_id:int,what:string}|\WP_Error
	 */
	private static function settings_target( string $scope, int $id ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'niranzwp_no_elementor', 'Elementor is not active on this site.' );
		}

		if ( 'site' === $scope ) {
			if ( ! isset( \Elementor\Plugin::$instance->kits_manager ) ) {
				return new \WP_Error( 'niranzwp_no_kit', 'This Elementor build has no kits manager, so there are no site settings to read.' );
			}
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( ! $kit || ! $kit->get_main_id() ) {
				return new \WP_Error( 'niranzwp_no_kit', 'This site has no active Elementor kit.' );
			}
			return [ 'doc' => $kit, 'post_id' => (int) $kit->get_main_id(), 'what' => 'the active kit' ];
		}

		if ( $id <= 0 ) {
			return new \WP_Error( 'niranzwp_missing', 'Scope "page" needs the id of the page.' );
		}

		// A revision carries the same meta, so a revision id looks valid here
		// and writing to one changes history instead of the page.
		$parent = wp_is_post_revision( $id );
		if ( $parent ) {
			return new \WP_Error(
				'niranzwp_is_revision',
				sprintf( 'Post %d is a revision of %d. Use %d.', $id, (int) $parent, (int) $parent )
			);
		}

		if ( ! get_post( $id ) ) {
			return new \WP_Error( 'niranzwp_not_found', 'No post with ID ' . $id );
		}

		$doc = \Elementor\Plugin::$instance->documents->get( $id );
		if ( ! $doc ) {
			return new \WP_Error(
				'niranzwp_no_document',
				sprintf( 'Elementor has no document for post %d, so it has no page settings. A post gets one the first time an Elementor layout is written to it.', $id )
			);
		}

		return [ 'doc' => $doc, 'post_id' => $id, 'what' => sprintf( 'page %d', $id ) ];
	}

	/**
	 * What a document currently has stored, straight from the meta.
	 *
	 * @param int $post_id Post holding the settings.
	 * @return array<string,mixed>
	 */
	private static function stored_settings( int $post_id ): array {
		$stored = get_post_meta( $post_id, '_elementor_page_settings', true );
		return is_array( $stored ) ? $stored : [];
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function settings_read( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$scope = 'page' === (string) ( $input['scope'] ?? 'site' ) ? 'page' : 'site';

		$target = self::settings_target( $scope, (int) ( $input['id'] ?? 0 ) );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$current = self::stored_settings( $target['post_id'] );
		$result  = [
			'scope'   => $scope,
			'post_id' => $target['post_id'],
			'title'   => (string) get_the_title( $target['post_id'] ),
			'current' => $current,
		];

		if ( 'site' === $scope ) {
			$result['note'] = __( 'These are the site\'s Site Settings. A key absent from "current" is at Elementor\'s default, which "settings" reports.', 'niranzwp' );
		} else {
			$result['template'] = (string) get_post_meta( $target['post_id'], '_wp_page_template', true );
		}

		if ( ! empty( $input['keys_only'] ) ) {
			return $result;
		}

		$search   = strtolower( trim( (string) ( $input['search'] ?? '' ) ) );
		$settings = [];
		$skipped  = 0;

		foreach ( $target['doc']->get_controls() as $key => $control ) {
			// A section is a heading in the editor panel, not a setting.
			if ( 'section' === (string) ( $control['type'] ?? '' ) ) {
				continue;
			}
			if ( preg_match( '/_(tablet|mobile|laptop|widescreen|mobile_extra|tablet_extra)$/', (string) $key ) ) {
				++$skipped;
				continue;
			}
			$label = (string) ( $control['label'] ?? '' );
			if ( '' !== $search && ! str_contains( strtolower( $key . ' ' . $label ), $search ) ) {
				continue;
			}
			$entry = self::control_entry( (array) $control, 1200 );
			if ( isset( $control['fields'] ) && is_array( $control['fields'] ) ) {
				$entry['fields'] = array_map( 'strval', array_keys( $control['fields'] ) );
			}
			$settings[ (string) $key ] = $entry;
		}

		$result['count']    = count( $settings );
		$result['settings'] = $settings;

		if ( $skipped > 0 ) {
			$result['responsive_variants'] = [
				'count' => $skipped,
				'note'  => __( 'Each is its base key plus _tablet or _mobile, and can be written even though it is not listed.', 'niranzwp' ),
			];
		}

		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function settings_write( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$scope = 'page' === (string) ( $input['scope'] ?? 'site' ) ? 'page' : 'site';
		$patch = $input['settings'] ?? null;
		$dry   = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];

		if ( ! is_array( $patch ) || ! $patch ) {
			return new \WP_Error( 'niranzwp_missing', 'settings must be an object of key => value, and cannot be empty.' );
		}

		$target = self::settings_target( $scope, (int) ( $input['id'] ?? 0 ) );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$controls = (array) $target['doc']->get_controls();

		/*
		 * A key the document does not have is refused, not stored. Elementor
		 * ignores it on render, so it would look like a setting that quietly
		 * does nothing - and unlike a widget setting, a wrong key here is
		 * being written into the settings of the whole site.
		 */
		$unknown = [];
		foreach ( array_keys( $patch ) as $key ) {
			$base = preg_replace( '/_(tablet|mobile|laptop|widescreen|mobile_extra|tablet_extra)$/', '', (string) $key );
			if ( ! isset( $controls[ $key ] ) && ! isset( $controls[ $base ] ) ) {
				$unknown[] = (string) $key;
			}
		}
		if ( $unknown ) {
			return new \WP_Error(
				'niranzwp_unknown_settings',
				sprintf(
					'%s has no setting called %s. Call elementor-settings-read for the names it does have.',
					ucfirst( $target['what'] ),
					implode( ', ', array_map( static fn( $k ) => '"' . $k . '"', $unknown ) )
				)
			);
		}

		/*
		 * Elementor replaces the whole meta when it saves settings, so a patch
		 * has to be merged onto what is there or every key not mentioned is
		 * lost - which, for a kit, is the site's colours and fonts.
		 */
		$current = self::stored_settings( $target['post_id'] );
		$merged  = array_merge( $current, $patch );

		$changes = [];
		foreach ( $patch as $key => $value ) {
			$before = $current[ $key ] ?? null;
			if ( $before === $value ) {
				continue;
			}
			$changes[ (string) $key ] = [ 'before' => $before, 'after' => $value ];
		}

		$result = [
			'scope'      => $scope,
			'post_id'    => $target['post_id'],
			'changes'    => $changes,
			'unchanged'  => count( $patch ) - count( $changes ),
			'keys_kept'  => count( array_diff_key( $current, $patch ) ),
			'dry_run'    => $dry,
		];

		if ( ! $changes ) {
			$result['status'] = 'no_change';
			$result['note']   = 'Every key passed already holds that value.';
			return $result;
		}

		if ( $dry ) {
			$result['status'] = 'would_write';
			$result['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $result;
		}

		$result['checkpoint_id'] = Checkpoint::before_post( $target['post_id'], 'elementor-settings-write' );

		/*
		 * Saved through Elementor's own document rather than by writing the
		 * meta, because saving a kit is more than one meta row: it runs the
		 * kit's tabs, pushes site name and description back to WordPress, and
		 * rebuilds the global CSS that every page on the site loads.
		 */
		$saved = $target['doc']->save( [ 'settings' => $merged ] );
		if ( ! $saved ) {
			return new \WP_Error(
				'niranzwp_save_refused',
				sprintf( 'Elementor refused to save %s. The usual cause is that the current user cannot edit that post.', $target['what'] )
			);
		}

		if ( 'site' !== $scope && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			// A kit clears the whole site's CSS itself; a page does not.
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		$result['css_cache_cleared'] = true;
		clean_post_cache( $target['post_id'] );

		$result['status'] = 'written';
		return $result;
	}

	/* ------------------------------------------------- library templates */

	/** The library post type Elementor keeps templates in. */
	private const LIBRARY = 'elementor_library';

	/**
	 * Elementor Pro's theme builder, or null where it is not installed.
	 *
	 * Everything about display conditions lives in Pro. The library post type
	 * itself is free, so a template can be listed and created without Pro; it
	 * just cannot be placed.
	 */
	private static function theme_builder(): ?object {
		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			return null;
		}
		$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
		return method_exists( $module, 'get_conditions_manager' ) ? $module : null;
	}

	/**
	 * The document types that describe where a template goes.
	 *
	 * @return string[]
	 */
	private static function template_types(): array {
		if ( ! self::available() ) {
			return [];
		}
		$types = [];
		foreach ( \Elementor\Plugin::$instance->documents->get_document_types() as $name => $class ) {
			$cpt = is_callable( [ $class, 'get_property' ] ) ? (array) $class::get_property( 'cpt' ) : [];
			if ( in_array( self::LIBRARY, $cpt, true ) ) {
				$types[] = (string) $name;
			}
		}
		sort( $types );
		return $types;
	}

	/**
	 * One template, as reported.
	 *
	 * @param \WP_Post $post Library post.
	 * @return array<string,mixed>
	 */
	private static function template_row( \WP_Post $post ): array {
		$conditions = get_post_meta( $post->ID, '_elementor_conditions', true );

		return [
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'type'       => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
			'status'     => $post->post_status,
			'conditions' => is_array( $conditions ) ? array_values( array_map( 'strval', $conditions ) ) : [],
			'modified'   => $post->post_modified_gmt,
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function templates( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];

		if ( ! self::available() ) {
			return new \WP_Error( 'niranzwp_no_elementor', 'Elementor is not active on this site.' );
		}

		$one = (int) ( $input['id'] ?? 0 );
		if ( $one > 0 ) {
			$post = get_post( $one );
			if ( ! $post || self::LIBRARY !== $post->post_type ) {
				return new \WP_Error( 'niranzwp_not_found', sprintf( 'Post %d is not an Elementor library template.', $one ) );
			}
			$row              = self::template_row( $post );
			$row['elements']  = self::count_elements( $one );
			return $row;
		}

		$args = [
			'post_type'      => self::LIBRARY,
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];
		$type = (string) ( $input['type'] ?? '' );
		if ( '' !== $type ) {
			$args['meta_query'] = [ [ 'key' => '_elementor_template_type', 'value' => $type ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$templates = array_map( [ self::class, 'template_row' ], get_posts( $args ) );

		$result = [
			'count'      => count( $templates ),
			'templates'  => $templates,
			'types'      => self::template_types(),
		];

		$module = self::theme_builder();
		if ( ! $module ) {
			$result['conditions'] = [
				'available' => false,
				'note'      => __( 'Display conditions come from Elementor Pro\'s theme builder, which is not installed. Templates can be created and written here, but nothing will place them.', 'niranzwp' ),
			];
			return $result;
		}

		$names = array_keys( (array) $module->get_conditions_manager()->get_conditions_config() );
		sort( $names );
		$result['conditions'] = [
			'available' => true,
			'names'     => $names,
			'note'      => __( 'A condition is include or exclude, then a name, then anything that name narrows: include/general, include/singular/post, exclude/singular/page/12.', 'niranzwp' ),
		];

		return $result;
	}

	/**
	 * How many elements a template's layout holds, for the listing.
	 *
	 * @param int $id Template post id.
	 */
	private static function count_elements( int $id ): int {
		$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
		if ( ! is_array( $data ) ) {
			return 0;
		}
		$count = 0;
		$walk  = static function ( array $nodes ) use ( &$walk, &$count ): void {
			foreach ( $nodes as $node ) {
				++$count;
				if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
					$walk( $node['elements'] );
				}
			}
		};
		$walk( $data );
		return $count;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function template_write( mixed $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$dry   = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$id    = (int) ( $input['id'] ?? 0 );

		if ( ! self::available() ) {
			return new \WP_Error( 'niranzwp_no_elementor', 'Elementor is not active on this site.' );
		}

		$conditions = null;
		if ( array_key_exists( 'conditions', $input ) ) {
			if ( ! is_array( $input['conditions'] ) ) {
				return new \WP_Error( 'niranzwp_bad_conditions', 'conditions must be an array of strings.' );
			}
			$conditions = array_values( array_map( 'strval', $input['conditions'] ) );

			$module = self::theme_builder();
			if ( ! $module ) {
				return new \WP_Error(
					'niranzwp_no_theme_builder',
					'Display conditions need Elementor Pro\'s theme builder, which is not installed on this site.'
				);
			}

			$known = (array) $module->get_conditions_manager()->get_conditions_config();
			foreach ( $conditions as $condition ) {
				$parts = explode( '/', trim( $condition, '/' ) );
				if ( count( $parts ) < 2 || ! in_array( $parts[0], [ 'include', 'exclude' ], true ) ) {
					return new \WP_Error(
						'niranzwp_bad_condition',
						sprintf( '"%s" is not a condition. It has to start with include/ or exclude/ and then name one: include/general.', $condition )
					);
				}
				if ( ! isset( $known[ $parts[1] ] ) ) {
					return new \WP_Error(
						'niranzwp_bad_condition',
						sprintf( 'There is no condition called "%s". Call elementor-templates for the names this site accepts.', $parts[1] )
					);
				}
			}
		}

		/* ----------------------------------------------------- an existing */
		if ( $id > 0 ) {
			$post = get_post( $id );
			if ( ! $post || self::LIBRARY !== $post->post_type ) {
				return new \WP_Error( 'niranzwp_not_found', sprintf( 'Post %d is not an Elementor library template.', $id ) );
			}

			$before = self::template_row( $post );
			$after  = $before;
			if ( isset( $input['title'] ) ) {
				$after['title'] = (string) $input['title'];
			}
			if ( isset( $input['status'] ) ) {
				$after['status'] = (string) $input['status'];
			}
			if ( null !== $conditions ) {
				$after['conditions'] = $conditions;
			}

			$result = [
				'id'      => $id,
				'action'  => 'update',
				'before'  => $before,
				'after'   => $after,
				'dry_run' => $dry,
			];

			if ( $before === $after ) {
				$result['status'] = 'no_change';
				return $result;
			}
			if ( $dry ) {
				$result['status'] = 'would_update';
				$result['note']   = 'Nothing was written. Pass dry_run false to apply.';
				return $result;
			}

			$result['checkpoint_id'] = Checkpoint::before_post( $id, 'elementor-template-write' );

			if ( $after['title'] !== $before['title'] || $after['status'] !== $before['status'] ) {
				$updated = wp_update_post(
					[
						'ID'          => $id,
						'post_title'  => $after['title'],
						'post_status' => $after['status'],
					],
					true
				);
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}

			if ( null !== $conditions ) {
				$saved = self::save_conditions( $id, $conditions );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
			}

			clean_post_cache( $id );
			$result['status'] = 'updated';
			return $result;
		}

		/* --------------------------------------------------------- a new one */
		$type = (string) ( $input['type'] ?? '' );
		if ( '' === $type ) {
			return new \WP_Error( 'niranzwp_missing', 'Creating a template needs its type. Call elementor-templates for the list.' );
		}

		$types = self::template_types();
		if ( ! in_array( $type, $types, true ) ) {
			return new \WP_Error(
				'niranzwp_unknown_type',
				sprintf( 'This site has no template type "%s". It has: %s.', $type, implode( ', ', $types ) )
			);
		}

		$title  = (string) ( $input['title'] ?? '' );
		$status = (string) ( $input['status'] ?? 'publish' );

		$result = [
			'action'      => 'create',
			'type'        => $type,
			'title'       => $title,
			// The post's own status, kept apart from this reply's status,
			// which says what happened rather than what the template is.
			'post_status' => $status,
			'conditions'  => $conditions ?? [],
			'dry_run'     => $dry,
		];

		if ( $dry ) {
			$result['status'] = 'would_create';
			$result['note']   = 'Nothing was written. Pass dry_run false to apply.';
			return $result;
		}

		$document = \Elementor\Plugin::$instance->documents->create(
			$type,
			[
				'post_title'  => '' !== $title ? $title : null,
				'post_status' => $status,
			]
		);
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$new_id           = (int) $document->get_main_id();
		$result['id']     = $new_id;
		$result['edit']   = (string) $document->get_edit_url();

		if ( $conditions ) {
			$saved = self::save_conditions( $new_id, $conditions );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		$result['status'] = 'created';
		$result['note']   = sprintf(
			'The template is empty. Write its layout with elementor-write on id %d.',
			$new_id
		);
		return $result;
	}

	/**
	 * Store display conditions through Pro, not through the meta.
	 *
	 * The conditions are cached in an option that decides which template wins
	 * for a given request, and a template whose conditions are written straight
	 * to its meta is absent from that cache: saved, and never shown. Pro's own
	 * manager regenerates the cache, so it does the writing.
	 *
	 * @param int      $id         Template post id.
	 * @param string[] $conditions Conditions as include/... strings.
	 * @return true|\WP_Error
	 */
	private static function save_conditions( int $id, array $conditions ) {
		$module = self::theme_builder();
		if ( ! $module ) {
			return new \WP_Error( 'niranzwp_no_theme_builder', 'Elementor Pro\'s theme builder is not installed.' );
		}

		// The manager joins each condition's parts with a slash, so it wants
		// them apart rather than as the string they will be stored as.
		$split = array_map(
			static fn( string $c ): array => explode( '/', trim( $c, '/' ) ),
			$conditions
		);

		$module->get_conditions_manager()->save_conditions( $id, $split );
		return true;
	}

}
