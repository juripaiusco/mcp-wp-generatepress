<?php
/**
 * Shared helpers.
 *
 * @package McpWpGeneratePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether GeneratePress (parent theme) is active.
 *
 * @return bool
 */
function gpmcp_is_generatepress_active() {
	return defined( 'GENERATE_VERSION' ) && function_exists( 'generate_get_defaults' );
}

/**
 * Whether GP Premium is active.
 *
 * @return bool
 */
function gpmcp_is_gp_premium_active() {
	return defined( 'GP_PREMIUM_VERSION' );
}

/**
 * Build a WP_Error with an HTTP status (404 for *not_found, 403 for *forbidden, else 400).
 *
 * @param string $code    Error code.
 * @param string $message Message.
 * @return WP_Error
 */
function gpmcp_error( $code, $message ) {
	$status = 400;
	if ( false !== strpos( $code, 'not_found' ) ) {
		$status = 404;
	} elseif ( false !== strpos( $code, 'forbidden' ) ) {
		$status = 403;
	}

	return new WP_Error( $code, $message, array( 'status' => $status ) );
}

/**
 * Register an ability exposed to MCP, with plugin defaults.
 *
 * @param string $name Ability name without namespace (e.g. "get-global-colors").
 * @param array  $args Ability args. Extra keys: `readonly`, `destructive`, `idempotent`, `capability`.
 */
function gpmcp_register( $name, array $args ) {
	$capability = $args['capability'] ?? 'edit_theme_options';

	$annotations = array(
		'readonly'    => $args['readonly'] ?? false,
		'destructive' => $args['destructive'] ?? false,
		'idempotent'  => $args['idempotent'] ?? ( $args['readonly'] ?? false ),
	);

	unset( $args['capability'], $args['readonly'], $args['destructive'], $args['idempotent'] );

	$args = array_merge(
		array(
			'input_schema'        => gpmcp_object_schema(),
			'permission_callback' => static function () use ( $capability ) {
				return current_user_can( $capability );
			},
		),
		$args
	);

	$args['meta'] = array(
		'annotations' => $annotations,
		'show_in_rest' => true,
		'mcp'         => array(
			'public' => true,
			'type'   => 'tool',
		),
	);

	wp_register_ability( 'gpmcp/' . $name, $args );
}

/**
 * Build an object JSON schema.
 *
 * @param array $properties Properties.
 * @param array $required   Required property names.
 * @return array
 */
function gpmcp_object_schema( array $properties = array(), array $required = array() ) {
	// No input: omit the schema so the MCP Adapter normalizes `{}` to null.
	if ( ! $properties ) {
		return array();
	}

	$schema = array(
		'type'                 => 'object',
		'properties'           => $properties,
		'additionalProperties' => false,
	);

	if ( $required ) {
		$schema['required'] = $required;
	} else {
		// All-optional input: let callers omit it entirely (core applies this default to null input).
		$schema['default'] = array();
	}

	return $schema;
}

/**
 * Recursively sanitize a settings value: strings via sanitize_text_field, scalars kept.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function gpmcp_sanitize_value( $value ) {
	if ( is_array( $value ) ) {
		$clean = array();
		foreach ( $value as $k => $v ) {
			$clean[ is_int( $k ) ? $k : sanitize_text_field( $k ) ] = gpmcp_sanitize_value( $v );
		}
		return $clean;
	}

	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
		return $value;
	}

	return sanitize_text_field( (string) $value );
}

/**
 * Validate a CSS color value (hex, rgb(a), hsl(a), var(--x), transparent, empty).
 *
 * @param string $color Color.
 * @return bool
 */
function gpmcp_is_valid_color( $color ) {
	if ( '' === $color ) {
		return true;
	}

	return (bool) preg_match(
		'/^(#[0-9a-fA-F]{3,8}|rgba?\([\d\s.,%]+\)|hsla?\([\d\s.,%deg]+\)|var\(--[a-z0-9-]+\)|transparent|currentColor)$/',
		trim( $color )
	);
}

/**
 * Force GeneratePress to rebuild its cached dynamic CSS on next request.
 */
function gpmcp_clear_css_cache() {
	delete_option( 'generate_dynamic_css_output' );
	delete_option( 'generate_dynamic_css_cached_version' );
}

/**
 * Settings groups: group => option name + defaults callback.
 *
 * @return array<string, array{option: string, defaults: callable|null, label: string}>
 */
function gpmcp_settings_groups() {
	$groups = array(
		'main'          => array(
			'option'   => 'generate_settings',
			'label'    => 'Impostazioni principali GeneratePress (layout, colori, tipografia, logo)',
			'defaults' => static function () {
				$defaults = generate_get_defaults();
				if ( function_exists( 'generate_get_color_defaults' ) ) {
					$defaults = array_merge( $defaults, generate_get_color_defaults() );
				}
				if ( function_exists( 'generate_get_default_fonts' ) ) {
					$defaults = array_merge( $defaults, generate_get_default_fonts() );
				}
				return $defaults;
			},
		),
		'spacing'       => array(
			'option'   => 'generate_spacing_settings',
			'label'    => 'Spaziature (padding, margini, larghezza sidebar)',
			'defaults' => 'generate_spacing_get_defaults',
		),
		'blog'          => array(
			'option'   => 'generate_blog_settings',
			'label'    => 'Blog GP Premium (archivi, colonne, masonry, meta)',
			'defaults' => 'generate_blog_get_defaults',
		),
		'menu_plus'     => array(
			'option'   => 'generate_menu_plus_settings',
			'label'    => 'Menu Plus GP Premium (sticky, off-canvas, mobile header)',
			'defaults' => 'generate_menu_plus_get_defaults',
		),
		'secondary_nav' => array(
			'option'   => 'generate_secondary_nav_settings',
			'label'    => 'Navigazione secondaria GP Premium',
			'defaults' => 'generate_secondary_nav_get_defaults',
		),
		'backgrounds'   => array(
			'option'   => 'generate_background_settings',
			'label'    => 'Sfondi GP Premium',
			'defaults' => 'generate_get_background_defaults',
		),
		'woocommerce'   => array(
			'option'   => 'generate_woocommerce_settings',
			'label'    => 'WooCommerce GP Premium',
			'defaults' => 'generatepress_wc_defaults',
		),
	);

	foreach ( $groups as $key => $group ) {
		if ( is_string( $group['defaults'] ) && ! function_exists( $group['defaults'] ) ) {
			$groups[ $key ]['defaults'] = null;
		}
	}

	return $groups;
}

/**
 * Defaults for a settings group (empty array when unknown).
 *
 * @param string $group Group key.
 * @return array
 */
function gpmcp_group_defaults( $group ) {
	$groups = gpmcp_settings_groups();
	if ( empty( $groups[ $group ]['defaults'] ) ) {
		return array();
	}
	$defaults = call_user_func( $groups[ $group ]['defaults'] );
	return is_array( $defaults ) ? $defaults : array();
}

/**
 * Stored GeneratePress main settings.
 *
 * @return array
 */
function gpmcp_get_main_settings() {
	$settings = get_option( 'generate_settings', array() );
	return is_array( $settings ) ? $settings : array();
}

/**
 * Update one or more keys of the main GeneratePress settings and clear CSS cache.
 *
 * @param array $values Key => value.
 */
function gpmcp_update_main_settings( array $values ) {
	$settings = array_merge( gpmcp_get_main_settings(), $values );
	update_option( 'generate_settings', $settings );
	gpmcp_clear_css_cache();
}

/**
 * Ensure media/file admin functions are loaded.
 */
function gpmcp_load_media_includes() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
}

/**
 * Sideload an image from URL into the media library.
 *
 * @param string $url     Image URL.
 * @param int    $post_id Parent post ID (0 = unattached).
 * @param string $title   Optional title/description.
 * @return int|WP_Error Attachment ID.
 */
function gpmcp_sideload_image( $url, $post_id = 0, $title = '' ) {
	if ( ! wp_http_validate_url( $url ) ) {
		return gpmcp_error( 'gpmcp_invalid_url', 'URL non valido.' );
	}

	$url = esc_url_raw( $url );

	// Reuse an attachment previously sideloaded from the same URL (keeps calls idempotent).
	$existing = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_gpmcp_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}

	gpmcp_load_media_includes();

	$id = media_sideload_image( $url, (int) $post_id, $title ? $title : null, 'id' );
	if ( ! is_wp_error( $id ) ) {
		update_post_meta( $id, '_gpmcp_source_url', $url );
	}

	return $id;
}
