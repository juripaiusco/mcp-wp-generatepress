<?php
/**
 * GeneratePress design abilities.
 *
 * @package McpWpGeneratePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Typography selector keywords understood by GeneratePress (other values are used as raw CSS selectors).
 *
 * @return string[]
 */
function gpmcp_typography_selectors() {
	return array(
		'body', 'main-title', 'site-description', 'primary-menu-items', 'primary-sub-menu-items',
		'primary-menu-toggle', 'buttons', 'all-headings', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'single-content-title', 'archive-content-title', 'top-bar', 'widget-titles', 'footer', 'custom',
	);
}

/**
 * Customizer UI group for a typography selector.
 *
 * @param string $selector Selector keyword.
 * @return string
 */
function gpmcp_typography_group( $selector ) {
	$map = array(
		'body'                   => 'base',
		'top-bar'                => 'header',
		'main-title'             => 'header',
		'site-description'       => 'header',
		'primary-menu-items'     => 'header',
		'primary-sub-menu-items' => 'header',
		'primary-menu-toggle'    => 'header',
		'widget-titles'          => 'widgets',
		'footer'                 => 'footer',
		'custom'                 => 'other',
	);

	return $map[ $selector ] ?? 'content';
}

/**
 * Validate and sanitize a settings values map against a group's defaults.
 *
 * @param string $group         Group key.
 * @param array  $values        Values.
 * @param bool   $allow_unknown Allow keys missing from defaults.
 * @return array|WP_Error
 */
function gpmcp_prepare_settings_values( $group, array $values, $allow_unknown ) {
	$defaults = gpmcp_group_defaults( $group );
	$clean    = array();
	$unknown  = array();

	foreach ( $values as $key => $value ) {
		$key = sanitize_key( $key );

		if ( $defaults && ! array_key_exists( $key, $defaults ) && ! $allow_unknown ) {
			$unknown[] = $key;
			continue;
		}

		$value = gpmcp_sanitize_value( $value );

		if ( is_string( $value ) && false !== strpos( $key, 'color' ) && ! gpmcp_is_valid_color( $value ) ) {
			return gpmcp_error( 'gpmcp_invalid_color', sprintf( 'Colore non valido per %s: %s', $key, $value ) );
		}

		$clean[ $key ] = $value;
	}

	if ( $unknown ) {
		return new WP_Error(
			'gpmcp_unknown_keys',
			'Chiavi non presenti nei default del gruppo "' . $group . '": ' . implode( ', ', $unknown ) . '. Usa get-settings con include_defaults per l\'elenco, oppure allow_unknown=true.'
		);
	}

	return $clean;
}

/**
 * Normalize one global color entry.
 *
 * @param array $color Entry with slug, color, optional name.
 * @return array|WP_Error
 */
function gpmcp_prepare_global_color( array $color ) {
	$slug  = sanitize_title( $color['slug'] ?? '' );
	$value = trim( (string) ( $color['color'] ?? '' ) );

	if ( '' === $slug ) {
		return gpmcp_error( 'gpmcp_invalid_color_slug', 'Ogni colore richiede uno slug.' );
	}

	if ( '' === $value || ! gpmcp_is_valid_color( $value ) ) {
		return gpmcp_error( 'gpmcp_invalid_color', 'Colore non valido per ' . $slug . ': ' . $value );
	}

	return array(
		'name'  => sanitize_text_field( $color['name'] ?? ucwords( str_replace( '-', ' ', $slug ) ) ),
		'slug'  => $slug,
		'color' => $value,
	);
}

/**
 * Register design abilities.
 */
function gpmcp_register_design_abilities() {
	$group_enum = array_keys( gpmcp_settings_groups() );

	gpmcp_register(
		'get-settings',
		array(
			'label'            => 'Leggi impostazioni GeneratePress',
			'description'      => 'Legge un gruppo di impostazioni (main = generate_settings, spacing, blog, menu_plus, secondary_nav, backgrounds, woocommerce). "stored" = valori salvati; con include_defaults anche "defaults" ed "effective" (default + salvati).',
			'category'         => 'gpmcp-design',
			'readonly'         => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'group'            => array(
						'type'    => 'string',
						'enum'    => $group_enum,
						'default' => 'main',
					),
					'include_defaults' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'keys'             => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Filtra solo queste chiavi.',
					),
				)
			),
			'execute_callback' => static function ( $input = array() ) {
				$group  = $input['group'] ?? 'main';
				$groups = gpmcp_settings_groups();
				$stored = get_option( $groups[ $group ]['option'], array() );
				$stored = is_array( $stored ) ? $stored : array();
				$keys   = $input['keys'] ?? array();

				$filter = static function ( array $data ) use ( $keys ) {
					return $keys ? array_intersect_key( $data, array_flip( $keys ) ) : $data;
				};

				$result = array(
					'group'  => $group,
					'option' => $groups[ $group ]['option'],
					'stored' => (object) $filter( $stored ),
				);

				if ( ! empty( $input['include_defaults'] ) ) {
					$defaults            = gpmcp_group_defaults( $group );
					$result['defaults']  = (object) $filter( $defaults );
					$result['effective'] = (object) $filter( array_merge( $defaults, $stored ) );
				}

				return $result;
			},
		)
	);

	gpmcp_register(
		'update-settings',
		array(
			'label'            => 'Aggiorna impostazioni GeneratePress',
			'description'      => 'Aggiorna (merge parziale) chiavi di un gruppo di impostazioni. Le chiavi sono validate contro i default del gruppo. Crea uno snapshot automatico e svuota la cache CSS. Esempi main: container_width, layout_setting, link_color, header_background_color, navigation_text_color, form_button_background_color.',
			'category'         => 'gpmcp-design',
			'idempotent'       => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'group'         => array(
						'type'    => 'string',
						'enum'    => $group_enum,
						'default' => 'main',
					),
					'values'        => array(
						'type'        => 'object',
						'description' => 'Mappa chiave => valore.',
					),
					'remove_keys'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Chiavi da rimuovere (tornano al default).',
					),
					'allow_unknown' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				)
			),
			'execute_callback' => static function ( $input = array() ) {
				$group  = $input['group'] ?? 'main';
				$groups = gpmcp_settings_groups();
				$option = $groups[ $group ]['option'];
				$values = gpmcp_prepare_settings_values( $group, (array) ( $input['values'] ?? array() ), ! empty( $input['allow_unknown'] ) );

				if ( is_wp_error( $values ) ) {
					return $values;
				}

				$remove = array_map( 'sanitize_key', (array) ( $input['remove_keys'] ?? array() ) );

				if ( ! $values && ! $remove ) {
					return gpmcp_error( 'gpmcp_nothing_to_do', 'Nessun valore da aggiornare.' );
				}

				$snapshot = gpmcp_snapshot_create( 'auto: update-settings ' . $group );
				$stored   = get_option( $option, array() );
				$stored   = array_merge( is_array( $stored ) ? $stored : array(), $values );

				foreach ( $remove as $key ) {
					unset( $stored[ $key ] );
				}

				update_option( $option, $stored );
				gpmcp_clear_css_cache();

				return array(
					'group'    => $group,
					'updated'  => array_keys( $values ),
					'removed'  => $remove,
					'snapshot' => $snapshot['id'],
				);
			},
		)
	);

	gpmcp_register(
		'get-global-colors',
		array(
			'label'            => 'Leggi colori globali',
			'description'      => 'Restituisce la palette globale GeneratePress (slug usabili come var(--slug) nelle altre impostazioni).',
			'category'         => 'gpmcp-design',
			'readonly'         => true,
			'execute_callback' => static function () {
				return array( 'global_colors' => generate_get_option( 'global_colors' ) );
			},
		)
	);

	gpmcp_register(
		'set-global-colors',
		array(
			'label'            => 'Imposta colori globali',
			'description'      => 'Aggiorna la palette globale. mode=merge aggiorna/aggiunge per slug, mode=replace sostituisce l\'intera palette. Slug standard: contrast, contrast-2, contrast-3, base, base-2, base-3, accent. Crea snapshot automatico.',
			'category'         => 'gpmcp-design',
			'idempotent'       => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'colors' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'slug'  => array( 'type' => 'string' ),
								'name'  => array( 'type' => 'string' ),
								'color' => array( 'type' => 'string' ),
							),
							'required'   => array( 'slug', 'color' ),
						),
					),
					'mode'   => array(
						'type'    => 'string',
						'enum'    => array( 'merge', 'replace' ),
						'default' => 'merge',
					),
				),
				array( 'colors' )
			),
			'execute_callback' => static function ( $input ) {
				$mode    = $input['mode'] ?? 'merge';
				$current = 'replace' === $mode ? array() : (array) generate_get_option( 'global_colors' );
				$by_slug = array();

				foreach ( $current as $color ) {
					$by_slug[ $color['slug'] ] = $color;
				}

				foreach ( $input['colors'] as $color ) {
					$prepared = gpmcp_prepare_global_color( $color );
					if ( is_wp_error( $prepared ) ) {
						return $prepared;
					}
					if ( isset( $by_slug[ $prepared['slug'] ] ) && empty( $color['name'] ) ) {
						$prepared['name'] = $by_slug[ $prepared['slug'] ]['name'];
					}
					$by_slug[ $prepared['slug'] ] = $prepared;
				}

				$snapshot = gpmcp_snapshot_create( 'auto: set-global-colors' );
				gpmcp_update_main_settings( array( 'global_colors' => array_values( $by_slug ) ) );

				return array(
					'global_colors' => array_values( $by_slug ),
					'snapshot'      => $snapshot['id'],
				);
			},
		)
	);

	gpmcp_register(
		'get-typography',
		array(
			'label'            => 'Leggi tipografia',
			'description'      => 'Restituisce font_manager (font caricati, es. Google Fonts), regole typography (per selettore) e i selettori disponibili.',
			'category'         => 'gpmcp-design',
			'readonly'         => true,
			'execute_callback' => static function () {
				return array(
					'use_dynamic_typography' => (bool) generate_get_option( 'use_dynamic_typography' ),
					'font_manager'           => generate_get_option( 'font_manager' ),
					'typography'             => generate_get_option( 'typography' ),
					'google_font_display'    => generate_get_option( 'google_font_display' ),
					'selectors'              => gpmcp_typography_selectors(),
					'rule_fields'            => class_exists( 'GeneratePress_Typography' ) ? array_keys( GeneratePress_Typography::get_defaults() ) : array(),
				);
			},
		)
	);

	gpmcp_register(
		'set-typography',
		array(
			'label'            => 'Imposta tipografia',
			'description'      => 'Aggiunge font al font_manager e regole typography. Regola: {selector, fontFamily, fontWeight, fontSize, fontSizeTablet, fontSizeMobile, fontSizeUnit, lineHeight, lineHeightUnit, letterSpacing, textTransform, marginBottom, ...}. selector "custom" richiede customSelector. mode=merge aggiorna per selector/customSelector, replace sostituisce. Crea snapshot automatico.',
			'category'         => 'gpmcp-design',
			'idempotent'       => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'fonts'      => array(
						'type'        => 'array',
						'description' => 'Font da registrare nel font_manager.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'fontFamily'         => array( 'type' => 'string' ),
								'googleFont'         => array( 'type' => 'boolean' ),
								'googleFontCategory' => array( 'type' => 'string' ),
								'googleFontVariants' => array(
									'type'        => 'string',
									'description' => 'Es. "400, 700, 400italic".',
								),
							),
							'required'   => array( 'fontFamily' ),
						),
					),
					'typography' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'selector'       => array( 'type' => 'string' ),
								'customSelector' => array( 'type' => 'string' ),
							),
							'required'   => array( 'selector' ),
						),
					),
					'mode'       => array(
						'type'    => 'string',
						'enum'    => array( 'merge', 'replace' ),
						'default' => 'merge',
					),
				)
			),
			'execute_callback' => static function ( $input = array() ) {
				$mode    = $input['mode'] ?? 'merge';
				$updates = array( 'use_dynamic_typography' => true );

				if ( isset( $input['fonts'] ) ) {
					$fonts = array();
					foreach ( ( 'replace' === $mode ? array() : (array) generate_get_option( 'font_manager' ) ) as $font ) {
						$fonts[ $font['fontFamily'] ] = $font;
					}
					foreach ( $input['fonts'] as $font ) {
						$family           = sanitize_text_field( $font['fontFamily'] );
						$fonts[ $family ] = array(
							'fontFamily'         => $family,
							'googleFont'         => ! empty( $font['googleFont'] ),
							'googleFontApi'      => 1,
							'googleFontCategory' => sanitize_text_field( $font['googleFontCategory'] ?? '' ),
							'googleFontVariants' => sanitize_text_field( $font['googleFontVariants'] ?? '' ),
						);
					}
					$updates['font_manager'] = array_values( $fonts );
				}

				if ( isset( $input['typography'] ) ) {
					$rule_defaults = class_exists( 'GeneratePress_Typography' ) ? GeneratePress_Typography::get_defaults() : array();
					$rules         = array();
					$rule_key      = static function ( $rule ) {
						return ( $rule['selector'] ?? '' ) . '|' . ( $rule['customSelector'] ?? '' );
					};

					foreach ( ( 'replace' === $mode ? array() : (array) generate_get_option( 'typography' ) ) as $rule ) {
						$rules[ $rule_key( $rule ) ] = $rule;
					}

					foreach ( $input['typography'] as $rule ) {
						$rule = gpmcp_sanitize_value( $rule );

						if ( 'custom' === $rule['selector'] && empty( $rule['customSelector'] ) ) {
							return gpmcp_error( 'gpmcp_missing_custom_selector', 'selector "custom" richiede customSelector.' );
						}

						$key      = $rule_key( $rule );
						$existing = $rules[ $key ] ?? array();
						$merged   = array_merge( $rule_defaults, $existing, $rule );

						$merged['module'] = $merged['module'] ?? 'core';
						$merged['group']  = $merged['group'] ?? gpmcp_typography_group( $merged['selector'] );

						$rules[ $key ] = $merged;
					}

					$updates['typography'] = array_values( $rules );
				}

				$snapshot = gpmcp_snapshot_create( 'auto: set-typography' );
				gpmcp_update_main_settings( $updates );

				return array(
					'font_manager' => generate_get_option( 'font_manager' ),
					'typography'   => generate_get_option( 'typography' ),
					'snapshot'     => $snapshot['id'],
				);
			},
		)
	);

	gpmcp_register(
		'get-custom-css',
		array(
			'label'            => 'Leggi CSS aggiuntivo',
			'description'      => 'Restituisce il CSS aggiuntivo (Customizer) del tema attivo.',
			'category'         => 'gpmcp-design',
			'readonly'         => true,
			'execute_callback' => static function () {
				return array(
					'stylesheet' => get_stylesheet(),
					'css'        => wp_get_custom_css(),
				);
			},
		)
	);

	gpmcp_register(
		'set-custom-css',
		array(
			'label'            => 'Imposta CSS aggiuntivo',
			'description'      => 'Scrive il CSS aggiuntivo del tema attivo. mode: replace | append | prepend. Crea snapshot automatico.',
			'category'         => 'gpmcp-design',
			'input_schema'     => gpmcp_object_schema(
				array(
					'css'  => array( 'type' => 'string' ),
					'mode' => array(
						'type'    => 'string',
						'enum'    => array( 'replace', 'append', 'prepend' ),
						'default' => 'append',
					),
				),
				array( 'css' )
			),
			'execute_callback' => static function ( $input ) {
				$css = (string) $input['css'];

				if ( preg_match( '#</?\w+#', $css ) ) {
					return gpmcp_error( 'gpmcp_invalid_css', 'Il CSS non può contenere tag HTML.' );
				}

				$current = wp_get_custom_css();
				$mode    = $input['mode'] ?? 'append';

				if ( 'append' === $mode ) {
					$css = rtrim( $current ) . "\n\n" . $css;
				} elseif ( 'prepend' === $mode ) {
					$css = $css . "\n\n" . ltrim( $current );
				}

				$snapshot = gpmcp_snapshot_create( 'auto: set-custom-css' );
				$result   = wp_update_custom_css_post( trim( $css ), array( 'stylesheet' => get_stylesheet() ) );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'length'   => strlen( wp_get_custom_css() ),
					'snapshot' => $snapshot['id'],
				);
			},
		)
	);

	gpmcp_register(
		'set-logo',
		array(
			'label'            => 'Imposta logo',
			'description'      => 'Imposta il logo del sito da media_id esistente o da URL (scaricato nella libreria media). Opzionale logo_width (px) e retina_logo_url.',
			'category'         => 'gpmcp-design',
			'input_schema'     => gpmcp_object_schema(
				array(
					'media_id'        => array( 'type' => 'integer' ),
					'url'             => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'logo_width'      => array( 'type' => 'integer' ),
					'retina_logo_url' => array(
						'type'   => 'string',
						'format' => 'uri',
					),
				)
			),
			'execute_callback' => static function ( $input = array() ) {
				if ( ! current_user_can( 'upload_files' ) && ! empty( $input['url'] ) ) {
					return gpmcp_error( 'gpmcp_forbidden', 'Permesso upload_files richiesto.' );
				}

				$media_id = (int) ( $input['media_id'] ?? 0 );

				if ( ! $media_id && ! empty( $input['url'] ) ) {
					$media_id = gpmcp_sideload_image( $input['url'], 0, get_bloginfo( 'name' ) . ' logo' );
					if ( is_wp_error( $media_id ) ) {
						return $media_id;
					}
				}

				if ( ! $media_id || ! wp_attachment_is_image( $media_id ) ) {
					return gpmcp_error( 'gpmcp_invalid_logo', 'Serve media_id di un\'immagine o url valido.' );
				}

				$snapshot = gpmcp_snapshot_create( 'auto: set-logo' );
				set_theme_mod( 'custom_logo', $media_id );

				$updates = array();
				if ( isset( $input['logo_width'] ) ) {
					$updates['logo_width'] = absint( $input['logo_width'] );
				}
				if ( ! empty( $input['retina_logo_url'] ) ) {
					$retina_id = gpmcp_sideload_image( $input['retina_logo_url'], 0, get_bloginfo( 'name' ) . ' logo retina' );
					if ( is_wp_error( $retina_id ) ) {
						return $retina_id;
					}
					$updates['retina_logo'] = wp_get_attachment_url( $retina_id );
				}
				if ( $updates ) {
					gpmcp_update_main_settings( $updates );
				} else {
					gpmcp_clear_css_cache();
				}

				return array(
					'media_id' => $media_id,
					'url'      => wp_get_attachment_url( $media_id ),
					'snapshot' => $snapshot['id'],
				);
			},
		)
	);

	gpmcp_register(
		'clear-css-cache',
		array(
			'label'            => 'Svuota cache CSS GeneratePress',
			'description'      => 'Forza la rigenerazione del CSS dinamico GeneratePress alla prossima richiesta.',
			'category'         => 'gpmcp-design',
			'idempotent'       => true,
			'execute_callback' => static function () {
				gpmcp_clear_css_cache();
				return array( 'cleared' => true );
			},
		)
	);
}
