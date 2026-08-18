<?php
/**
 * Ability registration, with the schema tightened on the way through.
 *
 * JSON Schema treats an object as open unless told otherwise, so a key the
 * schema never mentions is validated by nobody and then dropped by the
 * callback that reads only the keys it knows. The call succeeds. Asking
 * list-directory for a recursive listing returned a flat one and exit 0;
 * asking write-file for mode append overwrote the file and reported
 * "written". Neither said no, and a caller cannot tell the difference between
 * a request that was honoured and one that was quietly discarded.
 *
 * A refusal is the only honest answer to a key nobody implements, so every
 * object schema registered through here closes itself. Doing it in one place
 * rather than in forty-eight literals also means an ability added next year
 * inherits it without anyone remembering.
 *
 * A schema that deliberately wants to stay open can say so: an explicit
 * additionalProperties is never overwritten.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

/**
 * @param array<string,mixed> $args
 */
function register_ability( string $name, array $args ): void {
	if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
		$args['input_schema'] = close_schema( $args['input_schema'] );
	}

	wp_register_ability( $name, $args );
}

/**
 * Close this object and every object nested inside it.
 *
 * Nested properties matter as much as the top level: an unknown key inside a
 * settings object is exactly as invisible as one beside it.
 *
 * @param array<string,mixed> $schema
 * @return array<string,mixed>
 */
function close_schema( array $schema ): array {
	$is_object = ( $schema['type'] ?? null ) === 'object' || isset( $schema['properties'] );

	if ( $is_object && ! array_key_exists( 'additionalProperties', $schema ) ) {
		$schema['additionalProperties'] = false;
	}

	if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
		foreach ( $schema['properties'] as $key => $child ) {
			if ( is_array( $child ) ) {
				$schema['properties'][ $key ] = close_schema( $child );
			}
		}
	}

	if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
		$schema['items'] = close_schema( $schema['items'] );
	}

	return $schema;
}
