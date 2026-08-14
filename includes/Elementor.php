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

		wp_register_ability( 'niranzwp/elementor-status', [
			'label'               => __( 'Elementor status', 'niranzwp' ),
			'description'         => __( 'Reports whether Elementor is active, its version, how many pages use it, and which widget types this site actually uses.', 'niranzwp' ),
			'category'            => 'niranzwp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [ 'sample' => [ 'type' => 'integer', 'default' => 200, 'minimum' => 10, 'maximum' => 2000 ] ],
			],
			'output_schema'       => [ 'type' => 'object' ],
			'permission_callback' => $gate,
			'execute_callback'    => [ self::class, 'status' ],
			'meta'                => $ro,
		] );

		wp_register_ability( 'niranzwp/elementor-read', [
			'label'               => __( 'Read Elementor layout', 'niranzwp' ),
			'description'         => __( 'Returns a page\'s Elementor layout as a readable tree of elements, widget types and their ids, without the full settings payload.', 'niranzwp' ),
			'category'            => 'niranzwp',
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

		wp_register_ability( 'niranzwp/elementor-find', [
			'label'               => __( 'Find Elementor elements', 'niranzwp' ),
			'description'         => __( 'Finds elements on a page by widget type or by matching text in their settings, returning each element id so it can be edited precisely.', 'niranzwp' ),
			'category'            => 'niranzwp',
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

		wp_register_ability( 'niranzwp/elementor-update-setting', [
			'label'               => __( 'Update an Elementor setting', 'niranzwp' ),
			'description'         => __( 'Changes one setting on one element of an Elementor page, found by element id. Previews the before and after unless dry_run is false, and clears Elementor\'s CSS cache after writing.', 'niranzwp' ),
			'category'            => 'niranzwp',
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
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'niranzwp_not_found', 'No post with ID ' . $post_id );
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
}
