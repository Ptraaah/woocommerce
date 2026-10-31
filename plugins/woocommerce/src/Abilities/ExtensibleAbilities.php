<?php
/**
 * Extensible abilities class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Abilities;

use Automattic\WooCommerce\Internal\Utilities\StagedMethod;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * The entry point for extensions in WooCommerce's abilities.
 *
 * An extension registers a resource inside the `woocommerce_extensible_abilities_resources_init`
 * action. It registers a field on a resource, such as `product`, inside the
 * `woocommerce_extensible_abilities_init` action, which fires after. An ability that formats an
 * object of that resource returns the field values under
 * `extensions.<namespace>.<field>`, and lists the fields in its output schema.
 * An ActionableAbility writes the same `extensions` input to the fields that
 * have a `set_callback`, runs the validators of the resource, and saves
 * the object one time.
 * An extension also registers a query filter, which the list ability of the
 * resource accepts under `filters.extensions.<namespace>.<filter>`.
 *
 * The API is experimental while the `extensible_abilities` feature exists.
 *
 * @since 11.3.0
 */
class ExtensibleAbilities {

	public const FEATURE_ID = 'extensible_abilities';

	/**
	 * Key next to the values of a namespace that lists the fields the extension failed to provide, with the error for each one.
	 */
	public const ERRORS_KEY = '_errors';

	/**
	 * Fields keyed by resource, then namespace, then field name.
	 *
	 * @var array<string, array<string, array<string, array<string, mixed>>>>
	 */
	private static array $fields = array();

	/**
	 * Registered resources, keyed by resource name.
	 *
	 * @var array<string, bool>
	 */
	private static array $resources = array();

	/**
	 * Validators keyed by resource.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private static array $validators = array();

	/**
	 * Syncs keyed by resource.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private static array $syncs = array();

	/**
	 * Fields already reported for a value that does not match the schema, keyed by resource, namespace and field.
	 *
	 * @var array<string, bool>
	 */
	private static array $reported = array();

	/**
	 * Whether the store enabled the experimental feature.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return FeaturesUtil::feature_is_enabled( self::FEATURE_ID );
	}

	/**
	 * Query filters keyed by resource, then namespace, then filter name.
	 *
	 * @var array<string, array<string, array<string, array<string, mixed>>>>
	 */
	private static array $filters = array();

	/**
	 * Query arguments that a filter callback can return.
	 */
	private const QUERY_ARG_KEYS = array( 'meta_query', 'tax_query', 'include_ids', 'exclude_ids' );

	/**
	 * JSON schema types, with the PHP types of the `enum` values that each one allows.
	 */
	private const SCHEMA_TYPES = array(
		'array'   => array( 'array' ),
		'object'  => array( 'array' ),
		'string'  => array( 'string' ),
		'number'  => array( 'integer', 'double' ),
		'integer' => array( 'integer' ),
		'boolean' => array( 'boolean' ),
		'null'    => array( 'NULL' ),
	);

	/**
	 * Schema keywords that hold a list of schemas.
	 */
	private const SCHEMA_COMBINATORS = array( 'anyOf', 'oneOf', 'allOf' );

	/**
	 * A date-time that strict JSON schema clients accept, as RFC 3339 defines it.
	 */
	private const DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

	/**
	 * Register a resource that abilities format, so extensions can register fields on it.
	 *
	 * Call it inside the `woocommerce_extensible_abilities_resources_init` action. A call after that action is refused.
	 * Core registers `product`, `order` and `order_item` there. An
	 * extension registers a resource that its own abilities format, and then calls `add_fields_to_object()`
	 * and `add_fields_schema()` from those abilities, exactly as Core's abilities do.
	 * Registering the same resource again does nothing.
	 *
	 * @since 11.3.0
	 *
	 * @param string $resource_name Slug of the resource, such as `ticket`.
	 */
	public static function register_resource( string $resource_name ): void {
		if ( did_action( 'woocommerce_extensible_abilities_resources_init' ) && ! doing_action( 'woocommerce_extensible_abilities_resources_init' ) ) {
			wc_doing_it_wrong( __METHOD__, 'Register a resource inside the woocommerce_extensible_abilities_resources_init action, before the fields are registered.', '11.3.0' );
			return;
		}
		if ( '' === $resource_name || sanitize_key( $resource_name ) !== $resource_name || ctype_digit( $resource_name ) ) {
			wc_doing_it_wrong( __METHOD__, 'The resource name must be a slug of lowercase letters, numbers, dashes and underscores, not only numbers.', '11.3.0' );
			return;
		}

		self::$resources[ $resource_name ] = true;
	}

	/**
	 * Register a field that the abilities of a resource return under `extensions.<namespace>.<field>`.
	 *
	 * Call it inside the `woocommerce_extensible_abilities_init` action. A call after that action is refused.
	 * The resource must be registered with `register_resource()` in the earlier `woocommerce_extensible_abilities_resources_init` action.
	 * A field declares `get_callback` and `output_schema`, `set_callback` and `input_schema`, or both pairs. The
	 * first pair makes it readable and the second makes it writable. An incomplete pair is refused.
	 * A malformed `output_schema` or `input_schema` is refused. At every depth a schema declares a `type`, `required`
	 * is a list, and `properties` is a map of schemas with at least one key.
	 *
	 * @since 11.3.0
	 *
	 * @param array $args {
	 *     Field arguments.
	 *
	 *     @type string   $resource     Resource that an ability formats, for example `product`, `order` or `order_item`.
	 *     @type string   $namespace    Slug of the extension that owns the field, such as `subscriptions`.
	 *     @type string   $field        Field name inside the namespace, such as `trial_length`. It cannot be `_errors`.
	 *     @type array    $output_schema Optional. JSON schema of the value that `get_callback` returns. Required
	 *                                  with `get_callback`. Core publishes it as declared. At every depth it declares one `type` that is not null, so it
	 *                                  cannot use a type list, `nullable`, `anyOf`, `oneOf` or `allOf`, or an
	 *                                  `enum` with a value of another type. Its `title` is the label clients show.
	 *     @type string[] $object_types Optional. The object types the field applies to, as `get_type()` of the
	 *                                  object returns them, such as `simple` and `subscription` for a product or
	 *                                  `line_item` for an order item. The abilities leave the field out of the
	 *                                  other objects and refuse a write to them. Omit it for every type.
	 *     @type callable $get_callback Optional. Receives the object and returns the value. A field without it
	 *                                  is not in the output. Required with `output_schema`. Return null when the
	 *                                  object has no value, and the field is left out with no error. Any other
	 *                                  value that `output_schema` does not allow is left out and logged. It reads
	 *                                  the object it is given, not the database, because an ability can format
	 *                                  an object before it is saved.
	 *                                  Strict clients refuse some values that the schema check accepts, so
	 *                                  an empty array for an `object` is sent as `{}`, and a list for an
	 *                                  `object` or a `date-time` without a UTC offset is left out.
	 *     @type array    $input_schema Optional. JSON schema of the value that `set_callback` receives. Core
	 *                                  publishes it as declared. A value can be null only where it declares null.
	 *                                  Required with `set_callback`.
	 *     @type callable $set_callback Optional. Receives the object and the value, and changes the object
	 *                                  in memory, when an ability creates or updates the object. It returns a
	 *                                  WP_Error to reject the value, and then nothing is saved. A field without
	 *                                  it cannot be written. Required with `input_schema`. Rules:
	 *                                  1. It only sets values in memory. It does not save, send email
	 *                                     or make HTTP requests.
	 *                                  2. It receives the value already cast to `input_schema`.
	 *                                  3. A null value, where `input_schema` declares null, deletes the value
	 *                                     of the field.
	 *                                  4. It writes one object: the one it receives. Data that a
	 *                                     write must keep on another object, such as a price range
	 *                                     that a variable parent derives from its variations, is
	 *                                     written by a sync that register_sync() adds. A sync runs
	 *                                     once per call after every object is saved and inside the
	 *                                     transaction of the ability.
	 * }
	 */
	public static function register_field( array $args ): void {
		if ( did_action( 'woocommerce_extensible_abilities_init' ) && ! doing_action( 'woocommerce_extensible_abilities_init' ) ) {
			wc_doing_it_wrong( __METHOD__, 'Register a field inside the woocommerce_extensible_abilities_init action, before the abilities build their schemas.', '11.3.0' );
			return;
		}
		foreach ( array( 'resource', 'namespace', 'field' ) as $key ) {
			if ( ! is_string( $args[ $key ] ?? null ) || '' === $args[ $key ] || sanitize_key( $args[ $key ] ) !== $args[ $key ] || ctype_digit( $args[ $key ] ) ) {
				wc_doing_it_wrong(
					__METHOD__,
					sprintf(
						'The "%s" argument must be a slug of lowercase letters, numbers, dashes and underscores, not only numbers.',
						$key
					),
					'11.3.0'
				);
				return;
			}
		}
		if ( self::ERRORS_KEY === $args['field'] ) {
			wc_doing_it_wrong( __METHOD__, 'The field name "_errors" is reserved.', '11.4.0' );
			return;
		}
		if ( ! isset( self::$resources[ $args['resource'] ] ) && ! did_action( 'woocommerce_extensible_abilities_resources_init' ) ) {
			wc_doing_it_wrong( __METHOD__, 'Register a field inside the woocommerce_extensible_abilities_init action. The resources are not registered yet.', '11.3.0' );
			return;
		}
		if ( ! isset( self::$resources[ $args['resource'] ] ) ) {
			wc_doing_it_wrong(
				__METHOD__,
				sprintf(
					'The "%s" resource is not registered. Call ExtensibleAbilities::register_resource() first.',
					$args['resource']
				),
				'11.3.0'
			);
			return;
		}
		$readable = isset( $args['get_callback'] ) || isset( $args['output_schema'] );
		$writable = isset( $args['set_callback'] ) || isset( $args['input_schema'] );
		if ( ! $readable && ! $writable ) {
			wc_doing_it_wrong( __METHOD__, 'A field needs "get_callback" and "output_schema", "set_callback" and "input_schema", or both pairs.', '11.4.0' );
			return;
		}
		if ( $readable ) {
			if ( ! is_callable( $args['get_callback'] ?? null ) ) {
				wc_doing_it_wrong( __METHOD__, 'The "get_callback" argument must be callable, and a field with an "output_schema" needs it.', '11.3.0' );
				return;
			}
			$error = self::get_schema_error( $args['output_schema'] ?? null, 'output_schema', true );
			if ( null !== $error ) {
				wc_doing_it_wrong( __METHOD__, $error, '11.4.0' );
				return;
			}
		}
		if ( $writable ) {
			if ( ! is_callable( $args['set_callback'] ?? null ) ) {
				wc_doing_it_wrong( __METHOD__, 'The "set_callback" argument must be callable, and a field with an "input_schema" needs it.', '11.4.0' );
				return;
			}
			$error = self::get_schema_error( $args['input_schema'] ?? null, 'input_schema', false );
			if ( null !== $error ) {
				wc_doing_it_wrong( __METHOD__, $error, '11.4.0' );
				return;
			}
		}
		if ( isset( $args['object_types'] ) && ( ! is_array( $args['object_types'] ) || empty( $args['object_types'] ) || array_filter( $args['object_types'], static fn( $type ) => ! is_string( $type ) ) ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "object_types" argument must be a list of object types.', '11.4.0' );
			return;
		}
		if ( isset( self::$fields[ $args['resource'] ][ $args['namespace'] ][ $args['field'] ] ) ) {
			wc_doing_it_wrong(
				__METHOD__,
				sprintf(
					'The field "%1$s.%2$s" of "%3$s" is already registered.',
					$args['namespace'],
					$args['field'],
					$args['resource']
				),
				'11.3.0'
			);
			return;
		}

		$args['needs_json_check'] = self::needs_json_check( $args['output_schema'] ?? array() );

		self::$fields[ $args['resource'] ][ $args['namespace'] ][ $args['field'] ] = $args;
	}

	/**
	 * Find the first problem in a schema and in the schemas inside it. A `type`
	 * is required at every depth, because rest_validate_value_from_schema() needs
	 * it. A branch of a combinator can inherit it from its parent, as that function
	 * allows.
	 *
	 * @param mixed  $schema    Schema to check, of any type until this method checks it.
	 * @param string $path      Path of the schema, used in the message.
	 * @param bool   $is_output Whether the schema must declare one type that is not null.
	 * @return string|null The problem, or null when the schema is valid.
	 */
	private static function get_schema_error( $schema, string $path, bool $is_output ): ?string {
		if ( ! is_array( $schema ) ) {
			/* translators: %s: path of the schema. */
			return sprintf( __( '"%s" must be a schema, as an array.', 'woocommerce' ), $path );
		}

		$combinators = array_values( array_intersect( self::SCHEMA_COMBINATORS, array_keys( $schema ) ) );
		if ( $is_output && ( ! empty( $combinators ) || ! empty( $schema['nullable'] ) || ! is_string( $schema['type'] ?? null ) || 'null' === $schema['type'] ) ) {
			/* translators: %s: path of the schema. */
			return sprintf( __( '"%s" must declare one "type" that is not null, with no "nullable", "anyOf", "oneOf" or "allOf". Return null from the get_callback to leave the field out.', 'woocommerce' ), $path );
		}
		if ( ! isset( $schema['type'] ) && empty( $combinators ) ) {
			/* translators: %s: path of the schema. */
			return sprintf( __( '"%s" must declare a "type".', 'woocommerce' ), $path );
		}

		$types = (array) ( $schema['type'] ?? array() );
		if ( isset( $schema['type'] ) && ( empty( $types ) || ! wp_is_numeric_array( $types ) || ! empty( array_filter( $types, static fn( $type ) => ! in_array( $type, array_keys( self::SCHEMA_TYPES ), true ) ) ) ) ) {
			/* translators: %s: path of the schema. */
			return sprintf( __( '"%s" declares an unknown "type".', 'woocommerce' ), $path );
		}
		if ( array_key_exists( 'required', $schema ) && ! is_array( $schema['required'] ) ) {
			/* translators: %s: path of the schema. */
			return sprintf( __( 'The "required" of "%s" must be a list of property names.', 'woocommerce' ), $path );
		}
		if ( array_key_exists( 'enum', $schema ) ) {
			if ( ! is_array( $schema['enum'] ) || empty( $schema['enum'] ) || ! wp_is_numeric_array( $schema['enum'] ) ) {
				/* translators: %s: path of the schema. */
				return sprintf( __( 'The "enum" of "%s" must be a list of values.', 'woocommerce' ), $path );
			}
			$enum_types = array_merge( array(), ...array_map( static fn( $type ) => self::SCHEMA_TYPES[ $type ], $types ) );
			if ( ! empty( $types ) && ! empty( array_diff( array_map( 'gettype', $schema['enum'] ), $enum_types ) ) ) {
				/* translators: %s: path of the schema. */
				return sprintf( __( 'The "enum" of "%s" has a value that its "type" does not allow.', 'woocommerce' ), $path );
			}
		}

		$children = array();
		foreach ( array( 'properties', 'patternProperties' ) as $keyword ) {
			if ( array_key_exists( $keyword, $schema ) ) {
				if ( ! is_array( $schema[ $keyword ] ) || wp_is_numeric_array( $schema[ $keyword ] ) ) {
					/* translators: 1: schema keyword, 2: path of the schema. */
					return sprintf( __( 'The "%1$s" of "%2$s" must be a map of schemas with at least one key.', 'woocommerce' ), $keyword, $path );
				}
				foreach ( $schema[ $keyword ] as $key => $child ) {
					$children[ $path . '.' . $keyword . '.' . $key ] = $child;
				}
			}
		}
		if ( array_key_exists( 'items', $schema ) ) {
			$children[ $path . '.items' ] = $schema['items'];
		}
		if ( array_key_exists( 'additionalProperties', $schema ) && ! is_bool( $schema['additionalProperties'] ) ) {
			$children[ $path . '.additionalProperties' ] = $schema['additionalProperties'];
		}
		foreach ( $combinators as $keyword ) {
			if ( ! is_array( $schema[ $keyword ] ) || empty( $schema[ $keyword ] ) || ! wp_is_numeric_array( $schema[ $keyword ] ) ) {
				/* translators: 1: schema keyword, 2: path of the schema. */
				return sprintf( __( 'The "%1$s" of "%2$s" must be a list of schemas.', 'woocommerce' ), $keyword, $path );
			}
			foreach ( $schema[ $keyword ] as $index => $child ) {
				$children[ $path . '.' . $keyword . '.' . $index ] = is_array( $child ) && isset( $schema['type'] ) ? $child + array( 'type' => $schema['type'] ) : $child;
			}
		}

		foreach ( $children as $child_path => $child ) {
			$error = self::get_schema_error( $child, $child_path, $is_output );
			if ( null !== $error ) {
				return $error;
			}
		}
		return null;
	}

	/**
	 * Register a filter that the list ability of a resource accepts under `filters.extensions.<namespace>.<filter>`.
	 *
	 * Call it inside the `woocommerce_extensible_abilities_init` action. A call after that action is refused.
	 * The resource must be registered with `register_resource()`.
	 *
	 * @since 11.3.0
	 *
	 * @param array $args {
	 *     Filter arguments.
	 *
	 *     @type string   $resource  Resource whose list ability applies the filters. Core's `products-query` and
	 *                               `orders-query` apply the filters of `product` and `order`. The list ability
	 *                               of another resource must call `get_query_args()` and `add_query_filters_schema()` itself.
	 *     @type string   $namespace Slug of the extension that owns the filter, such as `subscriptions`.
	 *     @type string   $filter    Filter name inside the namespace, such as `subscribable`.
	 *     @type array    $schema    JSON schema of the value. Its `description` tells agents how to use the filter.
	 *                               A malformed schema is refused, with the rules of `register_field()`.
	 *     @type callable $callback  Receives the value, sanitized with the schema, and returns query arguments:
	 *                               a `meta_query`, for products also a `tax_query`, `include_ids` or
	 *                               `exclude_ids`. Core adds each one to the query with AND. Return a
	 *                               WP_Error to refuse the value. A `tax_query` is accepted only for
	 *                               `product`. Any other key, or a `tax_query` for another resource, fails the call.
	 *                               `include_ids` and `exclude_ids` are lists of object IDs. Use them for
	 *                               data that is not meta, such as order items or a table of the extension:
	 *                               query that data and return the IDs that match. Core keeps the IDs that
	 *                               every `include_ids` filter returns, then drops the IDs that any
	 *                               `exclude_ids` filter returns. An empty `include_ids` matches nothing.
	 * }
	 */
	public static function register_query_filter( array $args ): void {
		if ( did_action( 'woocommerce_extensible_abilities_init' ) && ! doing_action( 'woocommerce_extensible_abilities_init' ) ) {
			wc_doing_it_wrong( __METHOD__, 'Register a filter inside the woocommerce_extensible_abilities_init action, before the abilities build their schemas.', '11.3.0' );
			return;
		}
		foreach ( array( 'resource', 'namespace', 'filter' ) as $key ) {
			if ( ! is_string( $args[ $key ] ?? null ) || '' === $args[ $key ] || sanitize_key( $args[ $key ] ) !== $args[ $key ] || ctype_digit( $args[ $key ] ) ) {
				wc_doing_it_wrong(
					__METHOD__,
					sprintf(
						'The "%s" argument must be a slug of lowercase letters, numbers, dashes and underscores, not only numbers.',
						$key
					),
					'11.3.0'
				);
				return;
			}
		}
		if ( ! isset( self::$resources[ $args['resource'] ] ) ) {
			wc_doing_it_wrong(
				__METHOD__,
				sprintf(
					'The "%s" resource is not registered. Call ExtensibleAbilities::register_resource() first.',
					$args['resource']
				),
				'11.3.0'
			);
			return;
		}
		if ( ! is_array( $args['schema'] ?? null ) || ! isset( $args['schema']['type'] ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "schema" argument must be an array with a "type".', '11.3.0' );
			return;
		}
		$error = self::get_schema_error( $args['schema'], 'schema', false );
		if ( null !== $error ) {
			wc_doing_it_wrong( __METHOD__, $error, '11.4.0' );
			return;
		}
		if ( ! is_callable( $args['callback'] ?? null ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "callback" argument must be callable.', '11.3.0' );
			return;
		}
		if ( isset( self::$filters[ $args['resource'] ][ $args['namespace'] ][ $args['filter'] ] ) ) {
			wc_doing_it_wrong(
				__METHOD__,
				sprintf(
					'The filter "%1$s.%2$s" of "%3$s" is already registered.',
					$args['namespace'],
					$args['filter'],
					$args['resource']
				),
				'11.3.0'
			);
			return;
		}

		self::$filters[ $args['resource'] ][ $args['namespace'] ][ $args['filter'] ] = $args;
	}

	/**
	 * Add the `filters.extensions` property to the input schema of a list
	 * ability, when the feature is on and the resource has filters. It does
	 * not allow other filters, because a filter that the query ignores
	 * returns wrong results.
	 *
	 * @since 11.3.0
	 *
	 * @param array<string, mixed> $schema        Input schema.
	 * @param string               $resource_name Resource.
	 * @return array<string, mixed>
	 */
	public static function add_query_filters_schema( array $schema, string $resource_name ): array {
		if ( empty( self::$filters[ $resource_name ] ) || ! self::is_enabled() ) {
			return $schema;
		}

		$extensions = array(
			'type'                 => 'object',
			'description'          => __( 'Filters that extensions add, keyed by extension namespace, then filter.', 'woocommerce' ),
			'properties'           => array(),
			'additionalProperties' => false,
		);
		foreach ( self::$filters[ $resource_name ] as $namespace => $filters ) {
			$extensions['properties'][ $namespace ] = array(
				'type'                 => 'object',
				'properties'           => array_map(
					static function ( array $args ): array {
						return $args['schema'];
					},
					$filters
				),
				'additionalProperties' => false,
			);
		}
		$schema['properties']['filters'] = array(
			'type'                 => 'object',
			'properties'           => array( 'extensions' => $extensions ),
			'additionalProperties' => false,
		);
		return $schema;
	}

	/**
	 * Get the query arguments of the filters in the input of a list ability.
	 * Each key holds one clause for each filter, so `array_merge_recursive()`
	 * adds them to WP_Query or wc_get_orders() arguments with AND. `post__in`
	 * holds the IDs that every `include_ids` filter returned, without the IDs
	 * that any `exclude_ids` filter returned, or `0` when none remain, because
	 * an empty `post__in` does not filter. Without `include_ids`, the excluded
	 * IDs go to `post__not_in`, which WP_Query ignores next to `post__in`.
	 *
	 * @since 11.3.0
	 *
	 * @param string               $resource_name Resource.
	 * @param array<string, mixed> $input         Ability input.
	 * @return array<string, array>|\WP_Error The error of a value that fails its schema, or of a filter that refused its value or failed.
	 */
	public static function get_query_args( string $resource_name, array $input ) {
		$query_args = array();
		$include    = null;
		$exclude    = array();
		if ( ! self::is_enabled() ) {
			return $query_args;
		}

		foreach ( self::$filters[ $resource_name ] ?? array() as $namespace => $filters ) {
			foreach ( $filters as $filter => $args ) {
				if ( ! is_array( $input['filters']['extensions'][ $namespace ] ?? null ) || ! array_key_exists( $filter, $input['filters']['extensions'][ $namespace ] ) ) {
					continue;
				}

				$value = rest_sanitize_value_from_schema( $input['filters']['extensions'][ $namespace ][ $filter ], $args['schema'], $filter );
				if ( is_wp_error( $value ) ) {
					$value->add_data( array( 'status' => 400 ) );
					return $value;
				}
				try {
					$clauses = call_user_func( $args['callback'], $value );
				} catch ( \Throwable $e ) {
					wc_get_logger()->error(
						sprintf( 'Ability filter "%s.%s" of "%s" failed: %s', $namespace, $filter, $resource_name, $e->getMessage() ),
						array( 'source' => 'extensible-abilities' )
					);
					$clauses = null;
				}
				if ( is_wp_error( $clauses ) ) {
					return $clauses;
				}
				if (
					! is_array( $clauses )
					|| ! empty( array_diff_key( $clauses, array_flip( self::QUERY_ARG_KEYS ) ) )
					|| ( 'product' !== $resource_name && isset( $clauses['tax_query'] ) )
					|| in_array( false, array_map( 'is_array', $clauses ), true )
				) {
					return new \WP_Error(
						'woocommerce_ability_extension_filter_failed',
						/* translators: %s: filter name, such as subscriptions.subscribable. */
						sprintf( __( 'The "%s" filter failed.', 'woocommerce' ), $namespace . '.' . $filter ),
						array( 'status' => 500 )
					);
				}
				if ( isset( $clauses['include_ids'] ) ) {
					$filter_ids = self::get_filter_ids( $clauses['include_ids'], $namespace . '.' . $filter );
					if ( is_wp_error( $filter_ids ) ) {
						return $filter_ids;
					}
					$include = null === $include ? $filter_ids : array_values( array_intersect( $include, $filter_ids ) );
					unset( $clauses['include_ids'] );
				}
				if ( isset( $clauses['exclude_ids'] ) ) {
					$filter_ids = self::get_filter_ids( $clauses['exclude_ids'], $namespace . '.' . $filter );
					if ( is_wp_error( $filter_ids ) ) {
						return $filter_ids;
					}
					$exclude = array_merge( $exclude, $filter_ids );
					unset( $clauses['exclude_ids'] );
				}
				foreach ( $clauses as $key => $clause ) {
					if ( empty( $clause ) ) {
						continue;
					}
					$query_args[ $key ][] = $clause;
				}
			}
		}
		if ( null !== $include ) {
			$include                = array_values( array_diff( $include, $exclude ) );
			$query_args['post__in'] = empty( $include ) ? array( 0 ) : $include;
		} elseif ( ! empty( $exclude ) ) {
			$query_args['post__not_in'] = array_values( array_unique( $exclude ) );
		}
		return $query_args;
	}

	/**
	 * The IDs that a filter returned, as integers.
	 *
	 * @param array  $ids    IDs from the filter callback.
	 * @param string $filter Filter name, such as `subscriptions.related_to`.
	 * @return int[]|\WP_Error An error when an ID is not a positive integer.
	 */
	private static function get_filter_ids( array $ids, string $filter ) {
		$valid = array_filter(
			array_map(
				static fn( $id ) => filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ),
				$ids
			)
		);
		if ( count( $valid ) !== count( $ids ) ) {
			return new \WP_Error(
				'woocommerce_ability_extension_filter_failed',
				/* translators: %s: filter name, such as subscriptions.subscribable. */
				sprintf( __( 'The "%s" filter failed.', 'woocommerce' ), $filter ),
				array( 'status' => 500 )
			);
		}
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Refuse a direct call of a stage method when a class overrides its saving
	 * method without overriding the stage method too, because staging would skip
	 * the rules of that override. WC_Data classes use
	 * WC_Data::refuse_unstaged_override(). Another class calls this method at the
	 * start of its stage method, unless its saving method runs the stage method.
	 *
	 * @since 11.4.0
	 *
	 * @param object $subject      Object that gets the change.
	 * @param string $method       Saving method, for example add_tracking_item.
	 * @param string $stage_method Stage method, for example stage_tracking_item.
	 * @throws \WC_Data_Exception When a class has not adopted the stage method.
	 */
	public static function refuse_unstaged_override( object $subject, string $method, string $stage_method ): void {
		$class_name = StagedMethod::unstaged_class( $subject, $method, $stage_method );
		if ( null !== $class_name ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A plain text message for a client, built from PHP names.
			throw new \WC_Data_Exception(
				'woocommerce_data_staging_unsupported',
				sprintf(
					/* translators: 1: PHP class name of the object, 2: method that the class overrides, 3: method that the class needs to override. */
					__( 'This object does not support this change yet. The class %1$s overrides %2$s() and needs to override %3$s() too.', 'woocommerce' ),
					$class_name,
					$method,
					$stage_method
				),
				400
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Register a validator that can refuse a change to an object of a resource.
	 *
	 * Call it inside the `woocommerce_extensible_abilities_init` action. Validators
	 * run only when the `extensible_abilities` feature is on.
	 *
	 * Use a field `set_callback` to refuse a bad value of one field. It runs
	 * only when the input sends that field. Use a validator to refuse a change as
	 * a whole. It runs on every change of the resource, also when the input sends
	 * no extension fields. For example, a validator refuses a product change that
	 * would break a subscription plan.
	 *
	 * Core's product abilities and order-update-status run validators. An order
	 * validator sees the order with its new status staged, including the paid
	 * and completed dates that the status sets. A stage_*() method refuses a class
	 * that overrides its saving method without overriding the stage method too, so
	 * the ability refuses that class while the feature is on. See
	 * WC_Data::refuse_unstaged_override().
	 *
	 * @since 11.3.0
	 *
	 * @param array $args {
	 *     Validator arguments.
	 *
	 *     @type string   $resource  Resource that an ActionableAbility changes, such as `product`.
	 *     @type string   $namespace Slug of the extension that owns the validator, such as `subscriptions`.
	 *     @type callable $callback  Receives the changed object, the ability and `array $context`, after Core's
	 *                               changes and the field callbacks, before the one save. A callback that only
	 *                               needs the object or the ability can take fewer arguments.
	 *                               `$context['primary']` is the primary object of the call. It differs from the
	 *                               changed object when the object is a variation that the call saves through
	 *                               its parent. On product-create that parent is not saved yet, so the variation
	 *                               has no parent ID and a rule reads the parent from here.
	 *                               `$context['deleting']` is true when the call deletes or trashes the object,
	 *                               such as a variation of a deleted parent, or an item with `delete` in the
	 *                               `variations` input. It returns a WP_Error to refuse the
	 *                               change, and then nothing is saved. The other validators still run, and
	 *                               the client gets every refusal in one error. The client sees the code
	 *                               prefixed with the namespace, as `<namespace>:<code>`. A code that another
	 *                               validator in the same namespace already used gets a numeric suffix, as
	 *                               `<namespace>:<code>:2`. The error gets the
	 *                               namespace in its data, and the 400 status when it has no status. Its
	 *                               message can name the ability or screen to use instead. Validators also
	 *                               run after a field callback refuses a value, so the client gets the field
	 *                               refusals and the validator refusals together. The object then does not
	 *                               have that value. A refusal from that run gets `unconfirmed` in its data,
	 *                               because it can disappear when the client fixes the field. Rules:
	 *                               1. It only reads. It does not change the object, save, send email or
	 *                                  make HTTP requests.
	 *                               2. It checks the ability name when the extension's own abilities
	 *                                  change the same resource.
	 * }
	 */
	public static function register_validator( array $args ): void {
		if ( did_action( 'woocommerce_extensible_abilities_init' ) && ! doing_action( 'woocommerce_extensible_abilities_init' ) ) {
			wc_doing_it_wrong( __METHOD__, 'Register a validator inside the woocommerce_extensible_abilities_init action.', '11.3.0' );
			return;
		}
		foreach ( array( 'resource', 'namespace' ) as $key ) {
			if ( ! is_string( $args[ $key ] ?? null ) || '' === $args[ $key ] || sanitize_key( $args[ $key ] ) !== $args[ $key ] ) {
				wc_doing_it_wrong(
					__METHOD__,
					sprintf(
						'The "%s" argument must be a slug of lowercase letters, numbers, dashes and underscores.',
						$key
					),
					'11.3.0'
				);
				return;
			}
		}
		if ( ! is_callable( $args['callback'] ?? null ) ) {
			wc_doing_it_wrong( __METHOD__, 'The "callback" argument must be callable.', '11.3.0' );
			return;
		}
		if ( ! isset( self::$resources[ $args['resource'] ] ) ) {
			wc_doing_it_wrong(
				__METHOD__,
				sprintf(
					'The "%s" resource is not registered. Call ExtensibleAbilities::register_resource() first.',
					$args['resource']
				),
				'11.3.0'
			);
			return;
		}

		self::$validators[ $args['resource'] ][] = $args;
	}

	/**
	 * Register a sync on a resource. A sync runs once per ability call, after
	 * every object of the call is saved, and writes what the saved objects imply
	 * elsewhere, such as the price range a variable parent derives from its
	 * variations, or a row in a table of the extension.
	 *
	 * Call it inside the `woocommerce_extensible_abilities_init` action. Syncs
	 * run only when the `extensible_abilities` feature is on, inside the
	 * transaction of the ActionableAbility.
	 *
	 * A sync registered for a resource runs only when an object of that resource
	 * was saved, deleted or trashed in the call. It runs once per call, not once per
	 * object. The saved objects can belong to more than one resource, so a sync
	 * reads the objects it owns and ignores the rest.
	 *
	 * @since 11.4.0
	 *
	 * @param array $args {
	 *     Sync arguments.
	 *
	 *     @type string   $resource  Resource that an ActionableAbility saves, such as `product`.
	 *     @type string   $namespace Slug of the extension that owns the sync, such as `subscriptions`.
	 *     @type callable $callback  Receives `\WC_Data[] $saved`, the ActionableAbility and `array $context`.
	 *                               `$saved` is every saved object of the call, the primary object first
	 *                               unless the call deleted it.
	 *                               `$context['deleted']` is every object the call deleted for good, as it
	 *                               was before the delete, with its ID. `$context['trashed']` is every object
	 *                               the call moved to the trash; it can be restored, so a sync keeps what a
	 *                               restore needs. And
	 *                               `$context['input']` is the input of the call, so a sync can skip work
	 *                               when the call did not write its fields. It returns a WP_Error to
	 *                               refuse, and then every save of the call is undone. A thrown exception
	 *                               refuses it too. The client sees the code prefixed with the namespace, as
	 *                               `<namespace>:<code>`, and the error gets the namespace in its data. A sync must not
	 *                               send email or make HTTP requests, because an undo does not reach outside
	 *                               the database.
	 * }
	 */
	public static function register_sync( array $args ): void {
		if ( did_action( 'woocommerce_extensible_abilities_init' ) && ! doing_action( 'woocommerce_extensible_abilities_init' ) ) {
			wc_doing_it_wrong( __METHOD__, __( 'Register a sync inside the woocommerce_extensible_abilities_init action.', 'woocommerce' ), '11.4.0' );
			return;
		}
		foreach ( array( 'resource', 'namespace' ) as $key ) {
			if ( ! is_string( $args[ $key ] ?? null ) || '' === $args[ $key ] || sanitize_key( $args[ $key ] ) !== $args[ $key ] ) {
				wc_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: argument name. */
						__( 'The "%s" argument must be a slug of lowercase letters, numbers, dashes and underscores.', 'woocommerce' ),
						$key
					),
					'11.4.0'
				);
				return;
			}
		}
		if ( ! is_callable( $args['callback'] ?? null ) ) {
			wc_doing_it_wrong( __METHOD__, __( 'The "callback" argument must be callable.', 'woocommerce' ), '11.4.0' );
			return;
		}
		if ( ! isset( self::$resources[ $args['resource'] ] ) ) {
			wc_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: resource name. */
					__( 'The "%s" resource is not registered. Call ExtensibleAbilities::register_resource() first.', 'woocommerce' ),
					$args['resource']
				),
				'11.4.0'
			);
			return;
		}

		self::$syncs[ $args['resource'] ][] = $args;
	}

	/**
	 * Add the field values of an object to its formatted output, under
	 * `extensions.<namespace>.<field>`. A value is cast to its schema type, so
	 * `'12'` becomes `12` for an integer field. A field that returns null is left
	 * out with no error. A field that throws or returns another value that its
	 * schema or the JSON rules do not allow is left out, and
	 * `extensions.<namespace>._errors.<field>` says why. A namespace with no
	 * values and no fields left out is left out, and `extensions` is left out
	 * when no namespace is in the output or the feature is off.
	 *
	 * @since 11.3.0
	 *
	 * @param array<string, mixed> $output   Formatted object.
	 * @param string               $resource_name Resource.
	 * @param object               $subject  Object to read.
	 * @return array<string, mixed>
	 */
	public static function add_fields_to_object( array $output, string $resource_name, $subject ): array {
		if ( empty( self::$fields[ $resource_name ] ) || ! self::is_enabled() ) {
			return $output;
		}

		$extensions = array();
		foreach ( self::$fields[ $resource_name ] as $namespace => $fields ) {
			foreach ( $fields as $field => $args ) {
				if ( ! isset( $args['get_callback'] ) || ! self::field_applies_to( $args, $subject ) ) {
					continue;
				}
				try {
					$value = call_user_func( $args['get_callback'], $subject );
				} catch ( \Throwable $e ) {
					wc_get_logger()->error(
						sprintf( 'Ability field "%s.%s" of "%s" failed: %s', $namespace, $field, $resource_name, $e->getMessage() ),
						array( 'source' => 'extensible-abilities' )
					);
					$extensions[ $namespace ][ self::ERRORS_KEY ][ $field ] = __( 'The extension failed to read this value.', 'woocommerce' );
					continue;
				}
				$valid     = self::matches_schema( $args, $value );
				$sanitized = true === $valid ? rest_sanitize_value_from_schema( $value, $args['output_schema'], $field ) : $valid;
				if ( ! is_wp_error( $sanitized ) && $args['needs_json_check'] ) {
					$sanitized = self::prepare_json_value( $sanitized, $args['output_schema'], $field );
					if ( is_wp_error( $sanitized ) ) {
						self::report_left_out( $args, $sanitized->get_error_message() );
					}
				}
				if ( ! is_wp_error( $sanitized ) ) {
					$extensions[ $namespace ][ $field ] = $sanitized;
				} elseif ( null !== $value ) {
					$extensions[ $namespace ][ self::ERRORS_KEY ][ $field ] = $sanitized->get_error_message();
				}
			}
		}
		if ( ! empty( $extensions ) ) {
			$output['extensions'] = $extensions;
		}
		return $output;
	}

	/**
	 * Whether a field applies to an object, from the `object_types` of the field.
	 *
	 * @since 11.4.0
	 *
	 * @param array<string, mixed> $args    Field arguments from `register_field()`.
	 * @param mixed                $subject The object.
	 * @return bool
	 */
	public static function field_applies_to( array $args, $subject ): bool {
		if ( empty( $args['object_types'] ) ) {
			return true;
		}
		$type = self::get_object_type( $subject );
		return null !== $type && in_array( $type, $args['object_types'], true );
	}

	/**
	 * The type of an object, from its `get_type()` method.
	 *
	 * @since 11.4.0
	 *
	 * @param mixed $subject The object.
	 * @return string|null Null when the object has no callable `get_type()` method.
	 */
	public static function get_object_type( $subject ): ?string {
		if ( ! is_object( $subject ) || ! is_callable( array( $subject, 'get_type' ) ) ) {
			return null;
		}
		return (string) call_user_func( array( $subject, 'get_type' ) );
	}

	/**
	 * Add the `extensions` property to an object schema when the feature is on.
	 * It lists each namespace with its fields. Call it after the
	 * `woocommerce_extensible_abilities_init` action, when the ability builds its
	 * schema. The schema stays unchanged when the resource has no readable field.
	 *
	 * @since 11.3.0
	 *
	 * @param array<string, mixed> $schema        Object schema.
	 * @param string               $resource_name Resource.
	 * @param callable|null        $field_filter  Optional. Gets the arguments of a field and returns whether the schema lists it.
	 * @return array<string, mixed>
	 */
	public static function add_fields_schema( array $schema, string $resource_name, ?callable $field_filter = null ): array {
		if ( ! self::is_enabled() || empty( self::$fields[ $resource_name ] ) ) {
			return $schema;
		}

		$extensions = array(
			'type'                 => 'object',
			'description'          => __( 'Values that extensions add, keyed by extension namespace, then field.', 'woocommerce' ),
			'additionalProperties' => false,
		);
		foreach ( self::$fields[ $resource_name ] ?? array() as $namespace => $fields ) {
			$listed = array_filter(
				null === $field_filter ? $fields : array_filter( $fields, $field_filter ),
				static function ( array $args ): bool {
					return isset( $args['get_callback'] );
				}
			);
			if ( empty( $listed ) ) {
				continue;
			}
			$extensions['properties'][ $namespace ] = array(
				'type'                 => 'object',
				'properties'           => array_map(
					static function ( array $args ): array {
						return $args['output_schema'];
					},
					$listed
				) + array(
					self::ERRORS_KEY => array(
						'type'                 => 'object',
						'description'          => __( 'Fields of this extension that were left out because of an error, with the error for each one.', 'woocommerce' ),
						'additionalProperties' => array( 'type' => 'string' ),
					),
				),
				'additionalProperties' => false,
			);
		}
		if ( empty( $extensions['properties'] ) ) {
			return $schema;
		}
		$schema['properties']['extensions'] = $extensions;
		return $schema;
	}

	/**
	 * The schema of the `extensions` input of a resource. It lists the fields
	 * that have a set_callback, each with its input schema as declared.
	 * ActionableAbility adds it to the input schema of every
	 * ability. An ability that writes other objects of the resource in the same
	 * call, such as the variations of a product, adds it to their input too.
	 *
	 * @since 11.4.0
	 *
	 * @param string        $resource_name Resource.
	 * @param callable|null $field_filter  Optional. Gets the arguments of a field and returns whether the schema lists it.
	 * @return array<string, mixed>
	 */
	public static function get_input_schema( string $resource_name, ?callable $field_filter = null ): array {
		$extensions = array(
			'type'                 => 'object',
			'description'          => __( 'Values to write to the fields that extensions add, keyed by extension namespace, then field.', 'woocommerce' ),
			'additionalProperties' => false,
		);
		foreach ( self::get_fields( $resource_name ) as $namespace => $fields ) {
			$writable = array_filter(
				null === $field_filter ? $fields : array_filter( $fields, $field_filter ),
				static function ( array $args ): bool {
					return isset( $args['set_callback'] );
				}
			);
			if ( empty( $writable ) ) {
				continue;
			}
			$extensions['properties'][ $namespace ] = array(
				'type'                 => 'object',
				'properties'           => array_map(
					static function ( array $args ): array {
						return $args['input_schema'];
					},
					$writable
				),
				'additionalProperties' => false,
			);
		}
		return $extensions;
	}

	/**
	 * Fields of a resource, keyed by namespace, then field.
	 *
	 * @internal
	 *
	 * @since 11.3.0
	 *
	 * @param string $resource_name Resource.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function get_fields( string $resource_name ): array {
		return self::$fields[ $resource_name ] ?? array();
	}

	/**
	 * Validators of a resource.
	 *
	 * @internal
	 *
	 * @param string $resource_name Resource.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_validators( string $resource_name ): array {
		return self::$validators[ $resource_name ] ?? array();
	}

	/**
	 * Whether a resource is registered.
	 *
	 * @internal
	 *
	 * @since 11.4.0
	 *
	 * @param string $resource_name Resource.
	 * @return bool
	 */
	public static function is_resource_registered( string $resource_name ): bool {
		return isset( self::$resources[ $resource_name ] );
	}

	/**
	 * Syncs of a resource.
	 *
	 * @internal
	 *
	 * @param string $resource_name Resource.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_syncs( string $resource_name ): array {
		return self::$syncs[ $resource_name ] ?? array();
	}

	/**
	 * Whether a value matches the output schema of its field. A value other than null that
	 * does not match is logged and reported one time for each field.
	 *
	 * @param array<string, mixed> $args  Field arguments.
	 * @param mixed                $value Value that the get_callback returned.
	 * @return true|\WP_Error The error when the value does not match.
	 */
	private static function matches_schema( array $args, $value ) {
		$valid = rest_validate_value_from_schema( $value, $args['output_schema'], $args['field'] );
		if ( true === $valid ) {
			return true;
		}

		if ( null !== $value ) {
			self::report_left_out( $args, $valid->get_error_message() );
		}
		return $valid;
	}

	/**
	 * Log and report a field that was left out, one time for each field.
	 *
	 * @param array<string, mixed> $args   Field arguments.
	 * @param string               $reason Why the value was left out.
	 */
	private static function report_left_out( array $args, string $reason ): void {
		$key = $args['resource'] . '.' . $args['namespace'] . '.' . $args['field'];
		if ( isset( self::$reported[ $key ] ) ) {
			return;
		}

		self::$reported[ $key ] = true;
		wc_get_logger()->error(
			sprintf( 'Ability field "%s.%s" of "%s" was left out because its value does not match its schema: %s', $args['namespace'], $args['field'], $args['resource'], $reason ),
			array( 'source' => 'extensible-abilities' )
		);
		wc_doing_it_wrong(
			__CLASS__ . '::register_field',
			sprintf(
				'Ability field "%1$s.%2$s" of "%3$s" was left out because its value does not match its schema: %4$s',
				$args['namespace'],
				$args['field'],
				$args['resource'],
				$reason
			),
			'11.3.0'
		);
	}

	/**
	 * Whether a schema has an `object` or a `date-time`, the two places where
	 * the JSON rules are stricter than rest_validate_value_from_schema().
	 * WordPress has no hook inside its validation, so prepare_json_value() walks
	 * the value, and this keeps other fields out of that walk.
	 *
	 * @param array<string, mixed> $schema Schema.
	 * @return bool
	 */
	private static function needs_json_check( array $schema ): bool {
		if ( 'date-time' === ( $schema['format'] ?? null ) || in_array( 'object', (array) ( $schema['type'] ?? array() ), true ) ) {
			return true;
		}
		foreach ( array_merge( array_filter( array( $schema['items'] ?? null ) ), $schema['anyOf'] ?? array(), $schema['oneOf'] ?? array() ) as $child ) {
			if ( is_array( $child ) && self::needs_json_check( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check a sanitized value against the JSON rules. An empty array for an
	 * `object` becomes an empty object, because PHP has no other way to say `{}`.
	 * A part with no schema stays as it is.
	 *
	 * @param mixed                $value  Sanitized value.
	 * @param array<string, mixed> $schema Schema of the value.
	 * @param string               $param  Name of the value for the error.
	 * @return mixed|\WP_Error The value for the JSON output, or an error when the JSON rules refuse it.
	 */
	private static function prepare_json_value( $value, array $schema, string $param ) {
		if ( isset( $schema['anyOf'] ) || isset( $schema['oneOf'] ) ) {
			$matching = isset( $schema['anyOf'] ) ? rest_find_any_matching_schema( $value, $schema, $param ) : rest_find_one_matching_schema( $value, $schema, $param );
			return is_wp_error( $matching ) ? $matching : self::prepare_json_value( $value, $matching, $param );
		}

		$type = rest_get_best_type_for_value( $value, (array) ( $schema['type'] ?? array() ) );
		if ( 'string' === $type && 'date-time' === ( $schema['format'] ?? null ) && ! preg_match( self::DATE_TIME_PATTERN, $value ) ) {
			return new \WP_Error( 'woocommerce_ability_invalid_date_time', sprintf( '%s is not a date-time with a UTC offset.', $param ) );
		}
		if ( 'array' === $type ) {
			$children = array_fill_keys( array_keys( $value ), $schema['items'] ?? null );
		} elseif ( 'object' === $type ) {
			if ( empty( $value ) ) {
				return new \stdClass();
			}
			if ( array_values( $value ) === $value ) {
				return new \WP_Error( 'woocommerce_ability_invalid_object', sprintf( '%s is a list, not an object.', $param ) );
			}
			$children = array();
			foreach ( array_keys( $value ) as $key ) {
				$children[ $key ] = $schema['properties'][ $key ] ?? rest_find_matching_pattern_property_schema( (string) $key, $schema ) ?? ( $schema['additionalProperties'] ?? null );
			}
		} else {
			return $value;
		}

		foreach ( $children as $key => $child_schema ) {
			if ( is_array( $child_schema ) ) {
				$value[ $key ] = self::prepare_json_value( $value[ $key ], $child_schema, $param . '.' . $key );
				if ( is_wp_error( $value[ $key ] ) ) {
					return $value[ $key ];
				}
			}
		}
		return $value;
	}
}
