<?php
/**
 * GP Premium abilities: modules and Elements.
 *
 * @package McpWpGeneratePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * GP Premium modules with status.
 *
 * @return array[]
 */
function gpmcp_premium_modules() {
	$modules = array();

	if ( class_exists( 'GeneratePress_Pro_Dashboard' ) && method_exists( 'GeneratePress_Pro_Dashboard', 'get_modules' ) ) {
		foreach ( GeneratePress_Pro_Dashboard::get_modules() as $title => $data ) {
			$modules[] = array(
				'key'        => $data['key'],
				'title'      => $title,
				'active'     => ! empty( $data['isActive'] ),
				'deprecated' => ! empty( $data['deprecated'] ),
			);
		}
		return $modules;
	}

	foreach ( gpmcp_snapshot_option_names() as $name ) {
		if ( 0 === strpos( $name, 'generate_package_' ) ) {
			$modules[] = array(
				'key'        => $name,
				'title'      => ucwords( str_replace( '_', ' ', substr( $name, 17 ) ) ),
				'active'     => 'activated' === get_option( $name ),
				'deprecated' => false,
			);
		}
	}

	return $modules;
}

/**
 * Element meta keys that may never be written through MCP.
 *
 * @return string[]
 */
function gpmcp_element_forbidden_meta() {
	// Executing PHP from an element is remote code execution: keep it UI-only.
	return array( '_generate_hook_execute_php' );
}

/**
 * Sanitize a conditions list [{rule, object}].
 *
 * @param array $conditions Conditions.
 * @return array
 */
function gpmcp_sanitize_conditions( array $conditions ) {
	$clean = array();
	foreach ( $conditions as $condition ) {
		if ( empty( $condition['rule'] ) ) {
			continue;
		}
		$clean[] = array(
			'rule'   => sanitize_text_field( $condition['rule'] ),
			'object' => sanitize_key( (string) ( $condition['object'] ?? '' ) ),
		);
	}
	return $clean;
}

/**
 * Element data for output.
 *
 * @param WP_Post $post    Element post.
 * @param bool    $details Include content and all meta.
 * @return array
 */
function gpmcp_element_data( WP_Post $post, $details = false ) {
	$data = array(
		'id'                 => $post->ID,
		'title'              => $post->post_title,
		'status'             => $post->post_status,
		'type'               => get_post_meta( $post->ID, '_generate_element_type', true ),
		'block_type'         => get_post_meta( $post->ID, '_generate_block_type', true ),
		'hook'               => get_post_meta( $post->ID, '_generate_hook', true ),
		'custom_hook'        => get_post_meta( $post->ID, '_generate_custom_hook', true ),
		'priority'           => get_post_meta( $post->ID, '_generate_hook_priority', true ),
		'display_conditions' => get_post_meta( $post->ID, '_generate_element_display_conditions', true ) ?: array(),
		'exclude_conditions' => get_post_meta( $post->ID, '_generate_element_exclude_conditions', true ) ?: array(),
		'user_conditions'    => get_post_meta( $post->ID, '_generate_element_user_conditions', true ) ?: array(),
		'modified'           => $post->post_modified,
	);

	if ( $details ) {
		$meta = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( 0 === strpos( $key, '_generate_' ) ) {
				$meta[ $key ] = maybe_unserialize( $values[0] );
			}
		}
		$data['post_content']    = $post->post_content;
		$data['element_content'] = get_post_meta( $post->ID, '_generate_element_content', true );
		$data['meta']            = (object) $meta;
	}

	return $data;
}

/**
 * Register GP Premium abilities.
 */
function gpmcp_register_premium_abilities() {
	gpmcp_register(
		'list-modules',
		array(
			'label'            => 'Elenca moduli GP Premium',
			'description'      => 'Elenca i moduli GP Premium con stato attivo/disattivo.',
			'category'         => 'gpmcp-premium',
			'readonly'         => true,
			'execute_callback' => static function () {
				return array( 'modules' => gpmcp_premium_modules() );
			},
		)
	);

	gpmcp_register(
		'toggle-module',
		array(
			'label'            => 'Attiva/disattiva modulo GP Premium',
			'description'      => 'Attiva o disattiva un modulo GP Premium (key da list-modules, es. generate_package_elements). Crea snapshot automatico.',
			'category'         => 'gpmcp-premium',
			'capability'       => 'manage_options',
			'idempotent'       => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'key'    => array( 'type' => 'string' ),
					'active' => array( 'type' => 'boolean' ),
				),
				array( 'key', 'active' )
			),
			'execute_callback' => static function ( $input ) {
				$keys = wp_list_pluck( gpmcp_premium_modules(), 'key' );

				if ( ! in_array( $input['key'], $keys, true ) ) {
					return gpmcp_error( 'gpmcp_bad_module', 'Modulo sconosciuto: ' . $input['key'] );
				}

				$snapshot = gpmcp_snapshot_create( 'auto: toggle-module ' . $input['key'] );
				update_option( $input['key'], $input['active'] ? 'activated' : 'deactivated' );
				gpmcp_clear_css_cache();

				return array(
					'key'      => $input['key'],
					'active'   => (bool) $input['active'],
					'snapshot' => $snapshot['id'],
				);
			},
		)
	);

	if ( 'activated' !== get_option( 'generate_package_elements' ) && ! defined( 'GENERATE_ELEMENTS' ) ) {
		return;
	}

	gpmcp_register(
		'list-elements',
		array(
			'label'            => 'Elenca Elements',
			'description'      => 'Elenca gli Elements GP Premium (hook, block, layout, header) con condizioni di visualizzazione.',
			'category'         => 'gpmcp-premium',
			'readonly'         => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'type'   => array(
						'type' => 'string',
						'enum' => array( 'hook', 'block', 'layout', 'header' ),
					),
					'status' => array(
						'type'    => 'string',
						'enum'    => array( 'any', 'publish', 'draft' ),
						'default' => 'any',
					),
				)
			),
			'execute_callback' => static function ( $input = array() ) {
				$args = array(
					'post_type'      => 'gp_elements',
					'post_status'    => ( $input['status'] ?? 'any' ),
					'posts_per_page' => 200,
					'orderby'        => 'title',
					'order'          => 'ASC',
				);

				if ( ! empty( $input['type'] ) ) {
					$args['meta_key']   = '_generate_element_type'; // phpcs:ignore WordPress.DB.SlowDBQuery
					$args['meta_value'] = $input['type']; // phpcs:ignore WordPress.DB.SlowDBQuery
				}

				$elements = array();
				foreach ( get_posts( $args ) as $post ) {
					$elements[] = gpmcp_element_data( $post );
				}

				return array( 'elements' => $elements );
			},
		)
	);

	gpmcp_register(
		'get-element',
		array(
			'label'            => 'Leggi Element',
			'description'      => 'Restituisce un Element GP Premium con contenuto e tutti i meta _generate_*.',
			'category'         => 'gpmcp-premium',
			'readonly'         => true,
			'input_schema'     => gpmcp_object_schema(
				array( 'id' => array( 'type' => 'integer' ) ),
				array( 'id' )
			),
			'execute_callback' => static function ( $input ) {
				$post = get_post( (int) $input['id'] );
				if ( ! $post || 'gp_elements' !== $post->post_type ) {
					return gpmcp_error( 'gpmcp_not_found', 'Element non trovato.' );
				}
				return gpmcp_element_data( $post, true );
			},
		)
	);

	gpmcp_register(
		'upsert-element',
		array(
			'label'            => 'Crea o aggiorna Element',
			'description'      => 'Crea (senza id) o aggiorna (con id) un Element GP Premium. type: hook | block | layout | header. Contenuto: per "block" è markup a blocchi (post_content), per hook/header è HTML (_generate_element_content). block_type per i block: hook, site-header, site-footer, page-hero, content-template, loop-template, post-meta-template, post-navigation-template, archive-navigation-template, right-sidebar, left-sidebar, search-modal. Condizioni: [{rule:"general:site", object:""}], es. general:front_page, post:post, post:page. Nuovi Element creati in draft salvo status esplicito. Esecuzione PHP non consentita.',
			'category'         => 'gpmcp-premium',
			'input_schema'     => gpmcp_object_schema(
				array(
					'id'                 => array( 'type' => 'integer' ),
					'title'              => array( 'type' => 'string' ),
					'type'               => array(
						'type' => 'string',
						'enum' => array( 'hook', 'block', 'layout', 'header' ),
					),
					'status'             => array(
						'type' => 'string',
						'enum' => array( 'draft', 'publish' ),
					),
					'content'            => array( 'type' => 'string' ),
					'block_type'         => array( 'type' => 'string' ),
					'hook'               => array(
						'type'        => 'string',
						'description' => 'Es. generate_before_header, generate_after_header, generate_before_footer, custom.',
					),
					'custom_hook'        => array( 'type' => 'string' ),
					'priority'           => array( 'type' => 'integer' ),
					'display_conditions' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'rule'   => array( 'type' => 'string' ),
								'object' => array( 'type' => array( 'string', 'integer' ) ),
							),
							'required'   => array( 'rule' ),
						),
					),
					'exclude_conditions' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'rule'   => array( 'type' => 'string' ),
								'object' => array( 'type' => array( 'string', 'integer' ) ),
							),
							'required'   => array( 'rule' ),
						),
					),
					'user_conditions'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Es. general:all, general:logged_in, administrator.',
					),
					'meta'               => array(
						'type'        => 'object',
						'description' => 'Altri meta _generate_* (es. _generate_sidebar_layout, _generate_disable_site_header).',
					),
				)
			),
			'execute_callback' => static function ( $input = array() ) {
				$id   = (int) ( $input['id'] ?? 0 );
				$post = $id ? get_post( $id ) : null;

				if ( $id && ( ! $post || 'gp_elements' !== $post->post_type ) ) {
					return gpmcp_error( 'gpmcp_not_found', 'Element non trovato.' );
				}

				if ( ! $id && empty( $input['type'] ) ) {
					return gpmcp_error( 'gpmcp_missing_type', 'type obbligatorio per creare un Element.' );
				}

				$type = $input['type'] ?? get_post_meta( $id, '_generate_element_type', true );

				// Validate extra meta before touching the database.
				$extra_meta = (array) ( $input['meta'] ?? array() );
				foreach ( array_keys( $extra_meta ) as $key ) {
					if ( 0 !== strpos( $key, '_generate_' ) || in_array( $key, gpmcp_element_forbidden_meta(), true ) ) {
						return gpmcp_error( 'gpmcp_forbidden_meta', 'Meta non consentito: ' . $key );
					}
				}

				$content = null;
				if ( isset( $input['content'] ) ) {
					$content = current_user_can( 'unfiltered_html' ) ? $input['content'] : wp_kses_post( $input['content'] );
				}

				$postarr = array( 'post_type' => 'gp_elements' );
				if ( $id ) {
					$postarr['ID'] = $id;
				} else {
					$postarr['post_status'] = 'draft';
					$postarr['post_title']  = 'Element MCP';
				}
				if ( isset( $input['title'] ) ) {
					$postarr['post_title'] = sanitize_text_field( $input['title'] );
				}
				if ( isset( $input['status'] ) ) {
					$postarr['post_status'] = $input['status'];
				}
				if ( null !== $content && 'block' === $type ) {
					$postarr['post_content'] = $content;
				}

				$result = $id ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$id = (int) $result;

				$meta = array( '_generate_element_type' => $type );

				if ( null !== $content && 'block' !== $type ) {
					$meta['_generate_element_content'] = $content;
				}
				if ( isset( $input['block_type'] ) ) {
					$meta['_generate_block_type'] = sanitize_key( $input['block_type'] );
				}
				if ( isset( $input['hook'] ) ) {
					$meta['_generate_hook'] = sanitize_text_field( $input['hook'] );
				}
				// GP Premium renders page-hero blocks on _generate_hook: the admin UI defaults it, MCP must too.
				$block_type = $input['block_type'] ?? get_post_meta( $id, '_generate_block_type', true );
				if ( 'block' === $type && 'page-hero' === $block_type && ! isset( $input['hook'] ) && ! get_post_meta( $id, '_generate_hook', true ) ) {
					$meta['_generate_hook'] = 'generate_after_header';
				}
				if ( isset( $input['custom_hook'] ) ) {
					$meta['_generate_custom_hook'] = sanitize_text_field( $input['custom_hook'] );
				}
				if ( isset( $input['priority'] ) ) {
					$meta['_generate_hook_priority'] = (string) (int) $input['priority'];
				}
				if ( isset( $input['display_conditions'] ) ) {
					$meta['_generate_element_display_conditions'] = gpmcp_sanitize_conditions( $input['display_conditions'] );
				}
				if ( isset( $input['exclude_conditions'] ) ) {
					$meta['_generate_element_exclude_conditions'] = gpmcp_sanitize_conditions( $input['exclude_conditions'] );
				}
				if ( ! isset( $input['user_conditions'] ) && ! get_post_meta( $id, '_generate_element_user_conditions', true ) ) {
					$meta['_generate_element_user_conditions'] = array( 'general:all' );
				}
				if ( isset( $input['user_conditions'] ) ) {
					$meta['_generate_element_user_conditions'] = array_values( array_filter( array_map( 'sanitize_text_field', $input['user_conditions'] ) ) );
				}

				foreach ( $extra_meta as $key => $value ) {
					$meta[ $key ] = gpmcp_sanitize_value( $value );
				}

				foreach ( $meta as $key => $value ) {
					if ( '' === $value || null === $value || false === $value || array() === $value ) {
						delete_post_meta( $id, $key );
					} else {
						update_post_meta( $id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
					}
				}

				return gpmcp_element_data( get_post( $id ) );
			},
		)
	);
}
