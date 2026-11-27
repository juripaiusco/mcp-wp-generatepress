<?php
/**
 * Menu abilities: navigation menus, items and theme locations.
 *
 * @package McpWpGeneratePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu item data for output, nested by parent.
 *
 * @param WP_Term $menu Menu term.
 * @return array
 */
function gpmcp_menu_items_tree( WP_Term $menu ) {
	$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
	if ( ! $items ) {
		return array();
	}

	$by_parent = array();
	foreach ( $items as $item ) {
		$by_parent[ (int) $item->menu_item_parent ][] = $item;
	}

	$build = static function ( $parent_id ) use ( &$build, $by_parent ) {
		$out = array();
		foreach ( $by_parent[ $parent_id ] ?? array() as $item ) {
			$row = array(
				'id'        => (int) $item->ID,
				'title'     => $item->title,
				'type'      => $item->type,
				'object'    => $item->object,
				'object_id' => (int) $item->object_id,
				'url'       => $item->url,
				'target'    => $item->target,
				'classes'   => array_values( array_filter( (array) $item->classes ) ),
				'order'     => (int) $item->menu_order,
			);

			$children = $build( (int) $item->ID );
			if ( $children ) {
				$row['children'] = $children;
			}
			$out[] = $row;
		}
		return $out;
	};

	return $build( 0 );
}

/**
 * Menu data for output.
 *
 * @param WP_Term $menu      Menu term.
 * @param bool    $with_items Include items.
 * @return array
 */
function gpmcp_menu_data( WP_Term $menu, $with_items = true ) {
	$locations = array_keys( array_filter( (array) get_nav_menu_locations(), static fn( $id ) => (int) $id === (int) $menu->term_id ) );

	$data = array(
		'id'        => (int) $menu->term_id,
		'name'      => $menu->name,
		'slug'      => $menu->slug,
		'count'     => (int) $menu->count,
		'locations' => $locations,
	);

	if ( $with_items ) {
		$data['items'] = gpmcp_menu_items_tree( $menu );
	}

	return $data;
}

/**
 * Menu item input schema (recursive via `children`, one level described).
 *
 * @return array
 */
function gpmcp_menu_item_schema() {
	$item = array(
		'type'       => 'object',
		'properties' => array(
			'title'     => array( 'type' => 'string' ),
			'type'      => array(
				'type'        => 'string',
				'enum'        => array( 'custom', 'post_type', 'taxonomy', 'post_type_archive' ),
				'description' => 'custom = link libero (url); post_type = post/pagina (object + object_id); taxonomy = termine (object + object_id); post_type_archive = archivio (object).',
			),
			'url'       => array( 'type' => 'string' ),
			'object'    => array(
				'type'        => 'string',
				'description' => 'Post type o tassonomia, es. page, post, category.',
			),
			'object_id' => array( 'type' => 'integer' ),
			'target'    => array(
				'type' => 'string',
				'enum' => array( '', '_blank' ),
			),
			'classes'   => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'children'  => array(
				'type'        => 'array',
				'description' => 'Sottovoci, stessa struttura.',
				'items'       => array( 'type' => 'object' ),
			),
		),
	);

	return $item;
}

/**
 * Validate a list of menu items recursively before writing anything.
 *
 * @param array  $items Items.
 * @param string $path  Path for error messages.
 * @return true|WP_Error
 */
function gpmcp_validate_menu_items( array $items, $path = 'items' ) {
	foreach ( $items as $i => $item ) {
		$where = $path . '[' . $i . ']';

		if ( ! is_array( $item ) ) {
			return gpmcp_error( 'gpmcp_bad_menu_item', $where . ': voce non valida.' );
		}

		$type = $item['type'] ?? 'custom';

		switch ( $type ) {
			case 'custom':
				if ( empty( $item['url'] ) || empty( $item['title'] ) ) {
					return gpmcp_error( 'gpmcp_bad_menu_item', $where . ': custom richiede title e url.' );
				}
				break;

			case 'post_type':
				$post = get_post( (int) ( $item['object_id'] ?? 0 ) );
				if ( ! $post || 'publish' !== $post->post_status ) {
					return gpmcp_error( 'gpmcp_bad_menu_item', $where . ': object_id non è un contenuto pubblicato.' );
				}
				break;

			case 'taxonomy':
				$term = get_term( (int) ( $item['object_id'] ?? 0 ), $item['object'] ?? '' );
				if ( ! $term || is_wp_error( $term ) ) {
					return gpmcp_error( 'gpmcp_bad_menu_item', $where . ': termine non trovato (object + object_id).' );
				}
				break;

			case 'post_type_archive':
				if ( empty( $item['object'] ) || ! get_post_type_object( $item['object'] ) || ! get_post_type_object( $item['object'] )->has_archive ) {
					return gpmcp_error( 'gpmcp_bad_menu_item', $where . ': post type senza archivio.' );
				}
				break;

			default:
				return gpmcp_error( 'gpmcp_bad_menu_item', $where . ': type non valido.' );
		}

		if ( ! empty( $item['children'] ) ) {
			$check = gpmcp_validate_menu_items( (array) $item['children'], $where . '.children' );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
	}

	return true;
}

/**
 * Insert menu items recursively.
 *
 * @param int   $menu_id   Menu ID.
 * @param array $items     Items.
 * @param int   $parent_id Parent item ID.
 * @param int   $position  Running menu order (by reference).
 * @return true|WP_Error
 */
function gpmcp_insert_menu_items( $menu_id, array $items, $parent_id, &$position ) {
	foreach ( $items as $item ) {
		$type = $item['type'] ?? 'custom';
		++$position;

		$args = array(
			'menu-item-title'     => sanitize_text_field( $item['title'] ?? '' ),
			'menu-item-type'      => $type,
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => (int) $parent_id,
			'menu-item-position'  => $position,
			'menu-item-target'    => '_blank' === ( $item['target'] ?? '' ) ? '_blank' : '',
			'menu-item-classes'   => implode( ' ', array_map( 'sanitize_html_class', (array) ( $item['classes'] ?? array() ) ) ),
		);

		if ( 'custom' === $type ) {
			$args['menu-item-url'] = esc_url_raw( $item['url'] );
		} elseif ( 'post_type' === $type ) {
			$args['menu-item-object-id'] = (int) $item['object_id'];
			$args['menu-item-object']    = get_post_type( (int) $item['object_id'] );
		} elseif ( 'taxonomy' === $type ) {
			$args['menu-item-object-id'] = (int) $item['object_id'];
			$args['menu-item-object']    = sanitize_key( $item['object'] );
		} else {
			$args['menu-item-object'] = sanitize_key( $item['object'] );
		}

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		if ( ! empty( $item['children'] ) ) {
			$result = gpmcp_insert_menu_items( $menu_id, (array) $item['children'], $item_id, $position );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}

	return true;
}

/**
 * Register menu abilities.
 */
function gpmcp_register_menu_abilities() {
	gpmcp_register(
		'list-menus',
		array(
			'label'            => 'Elenca menu',
			'description'      => 'Restituisce i menu di navigazione con le voci (annidate) e le posizioni del tema (registrate e assegnate).',
			'category'         => 'gpmcp-content',
			'readonly'         => true,
			'execute_callback' => static function () {
				$menus = array_map( 'gpmcp_menu_data', wp_get_nav_menus() );

				$assigned  = (array) get_nav_menu_locations();
				$locations = array();
				foreach ( get_registered_nav_menus() as $slug => $description ) {
					$locations[ $slug ] = array(
						'description' => $description,
						'menu_id'     => (int) ( $assigned[ $slug ] ?? 0 ),
					);
				}

				return array(
					'menus'     => $menus,
					'locations' => (object) $locations,
				);
			},
		)
	);

	gpmcp_register(
		'upsert-menu',
		array(
			'label'            => 'Crea o aggiorna menu',
			'description'      => 'Crea (senza id) o aggiorna (con id) un menu di navigazione. Se passi items, le voci esistenti vengono SOSTITUITE da quelle indicate (annidabili con children). Voce: {title, type: custom|post_type|taxonomy|post_type_archive, url, object, object_id, target, classes, children}. Per assegnarlo a una posizione usa assign-menu-location o il parametro location.',
			'category'         => 'gpmcp-content',
			'input_schema'     => gpmcp_object_schema(
				array(
					'id'       => array( 'type' => 'integer' ),
					'name'     => array( 'type' => 'string' ),
					'items'    => array(
						'type'  => 'array',
						'items' => gpmcp_menu_item_schema(),
					),
					'location' => array(
						'type'        => 'string',
						'description' => 'Posizione del tema a cui assegnarlo, es. primary.',
					),
				)
			),
			'execute_callback' => static function ( $input = array() ) {
				$id = (int) ( $input['id'] ?? 0 );

				if ( $id && ! wp_get_nav_menu_object( $id ) ) {
					return gpmcp_error( 'gpmcp_not_found', 'Menu non trovato: ' . $id );
				}
				if ( ! $id && empty( $input['name'] ) ) {
					return gpmcp_error( 'gpmcp_missing_name', 'name obbligatorio per creare un menu.' );
				}
				if ( ! empty( $input['location'] ) && ! array_key_exists( $input['location'], get_registered_nav_menus() ) ) {
					return gpmcp_error( 'gpmcp_bad_location', 'Posizione non registrata: ' . $input['location'] );
				}
				if ( isset( $input['items'] ) ) {
					$check = gpmcp_validate_menu_items( (array) $input['items'] );
					if ( is_wp_error( $check ) ) {
						return $check;
					}
				}

				if ( ! $id ) {
					$id = wp_create_nav_menu( sanitize_text_field( $input['name'] ) );
				} elseif ( isset( $input['name'] ) ) {
					$id = wp_update_nav_menu_object( $id, array( 'menu-name' => sanitize_text_field( $input['name'] ) ) );
				}
				if ( is_wp_error( $id ) ) {
					return $id;
				}

				if ( isset( $input['items'] ) ) {
					foreach ( (array) wp_get_nav_menu_items( $id, array( 'post_status' => 'any' ) ) as $old ) {
						wp_delete_post( $old->ID, true );
					}

					$position = 0;
					$result   = gpmcp_insert_menu_items( $id, (array) $input['items'], 0, $position );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}

				if ( ! empty( $input['location'] ) ) {
					$locations                       = (array) get_nav_menu_locations();
					$locations[ $input['location'] ] = $id;
					set_theme_mod( 'nav_menu_locations', $locations );
				}

				return gpmcp_menu_data( wp_get_nav_menu_object( $id ) );
			},
		)
	);

	gpmcp_register(
		'assign-menu-location',
		array(
			'label'            => 'Assegna menu a posizione',
			'description'      => 'Assegna un menu a una posizione del tema (es. primary, slideout, secondary). menu_id 0 rimuove l\'assegnazione.',
			'category'         => 'gpmcp-content',
			'idempotent'       => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'location' => array( 'type' => 'string' ),
					'menu_id'  => array( 'type' => 'integer' ),
				),
				array( 'location', 'menu_id' )
			),
			'execute_callback' => static function ( $input ) {
				$location = $input['location'];
				$menu_id  = (int) $input['menu_id'];

				if ( ! array_key_exists( $location, get_registered_nav_menus() ) ) {
					return gpmcp_error( 'gpmcp_bad_location', 'Posizione non registrata: ' . $location );
				}
				if ( $menu_id && ! wp_get_nav_menu_object( $menu_id ) ) {
					return gpmcp_error( 'gpmcp_not_found', 'Menu non trovato: ' . $menu_id );
				}

				$locations = (array) get_nav_menu_locations();
				if ( $menu_id ) {
					$locations[ $location ] = $menu_id;
				} else {
					unset( $locations[ $location ] );
				}
				set_theme_mod( 'nav_menu_locations', $locations );

				return array(
					'location' => $location,
					'menu_id'  => $menu_id,
				);
			},
		)
	);

	gpmcp_register(
		'delete-menu',
		array(
			'label'            => 'Elimina menu',
			'description'      => 'Elimina un menu di navigazione e tutte le sue voci. Le posizioni a cui era assegnato restano vuote.',
			'category'         => 'gpmcp-content',
			'destructive'      => true,
			'input_schema'     => gpmcp_object_schema(
				array( 'id' => array( 'type' => 'integer' ) ),
				array( 'id' )
			),
			'execute_callback' => static function ( $input ) {
				$menu = wp_get_nav_menu_object( (int) $input['id'] );
				if ( ! $menu ) {
					return gpmcp_error( 'gpmcp_not_found', 'Menu non trovato: ' . (int) $input['id'] );
				}

				$result = wp_delete_nav_menu( $menu->term_id );
				if ( ! $result || is_wp_error( $result ) ) {
					return is_wp_error( $result ) ? $result : gpmcp_error( 'gpmcp_delete_failed', 'Eliminazione non riuscita.' );
				}

				return array(
					'deleted' => (int) $menu->term_id,
					'name'    => $menu->name,
				);
			},
		)
	);
}
