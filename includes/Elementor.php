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

		$widget = $wm->get_widget_types( $name );
		if ( ! $widget ) {
			return new \WP_Error(
				'niranzwp_not_found',
				sprintf( 'No widget type "%s" on this site. Call elementor-widgets to see what there is.', $name )
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

			if ( ! $shared && 'advanced' === (string) ( $control['tab'] ?? '' ) ) {
				++$skipped['shared'];
				continue;
			}

			$label = (string) ( $control['label'] ?? '' );
			if ( '' !== $search && ! str_contains( strtolower( $key . ' ' . $label ), $search ) ) {
				continue;
			}

			$entry = [ 'type' => $type ];
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
					$entry['default'] = ( is_string( $encoded ) && strlen( $encoded ) <= 200 )
						? json_decode( $encoded, true )
						: gettype( $default );
				}
			}

			if ( isset( $control['options'] ) && is_array( $control['options'] ) ) {
				$entry['options'] = array_map( 'strval', array_keys( $control['options'] ) );
			}

			$settings[ (string) $key ] = $entry;
		}

		$result = [
			'name'       => $name,
			'title'      => (string) $widget->get_title(),
			'categories' => array_map( 'strval', (array) $widget->get_categories() ),
			'count'      => count( $settings ),
			'settings'   => $settings,
		];

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

}
