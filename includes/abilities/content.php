<?php
/**
 * Content abilities: posts, pages, terms, media.
 *
 * @package McpWpGeneratePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types manageable through MCP.
 *
 * @return string[]
 */
function gpmcp_content_post_types() {
	$types = get_post_types(
		array(
			'public'       => true,
			'show_in_rest' => true,
		)
	);
	unset( $types['attachment'] );
	return array_values( $types );
}

/**
 * Post data for output.
 *
 * @param WP_Post $post    Post.
 * @param bool    $details Include content, terms and meta.
 * @return array
 */
function gpmcp_post_data( WP_Post $post, $details = false ) {
	$data = array(
		'id'       => $post->ID,
		'type'     => $post->post_type,
		'title'    => $post->post_title,
		'status'   => $post->post_status,
		'slug'     => $post->post_name,
		'date'     => $post->post_date,
		'modified' => $post->post_modified,
		'link'     => get_permalink( $post ),
		'parent'   => $post->post_parent,
	);

	if ( $details ) {
		$data['content']        = $post->post_content;
		$data['excerpt']        = $post->post_excerpt;
		$data['author']         = (int) $post->post_author;
		$data['featured_media'] = (int) get_post_thumbnail_id( $post );
		$data['template']       = get_page_template_slug( $post );
		$data['terms']          = array();

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'id=>name' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$data['terms'][ $taxonomy ] = $terms;
			}
		}
		$data['terms'] = (object) $data['terms'];
	} else {
		$data['excerpt'] = wp_trim_words( $post->post_excerpt ? $post->post_excerpt : wp_strip_all_tags( $post->post_content ), 30 );
	}

	return $data;
}

/**
 * Shared post fields schema.
 *
 * @return array
 */
function gpmcp_post_fields_schema() {
	return array(
		'title'          => array( 'type' => 'string' ),
		'content'        => array(
			'type'        => 'string',
			'description' => 'HTML o markup a blocchi Gutenberg.',
		),
		'excerpt'        => array( 'type' => 'string' ),
		'status'         => array(
			'type' => 'string',
			'enum' => array( 'draft', 'pending', 'private', 'publish', 'future' ),
		),
		'slug'           => array( 'type' => 'string' ),
		'date'           => array(
			'type'        => 'string',
			'description' => 'Data locale Y-m-d H:i:s (richiesta per status future).',
		),
		'parent'         => array( 'type' => 'integer' ),
		'menu_order'     => array( 'type' => 'integer' ),
		'template'       => array( 'type' => 'string' ),
		'featured_media' => array( 'type' => 'integer' ),
		'categories'     => array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		),
		'tags'           => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => 'Nomi dei tag (creati se mancanti).',
		),
	);
}

/**
 * Apply post fields from input to a wp_insert_post array and save.
 *
 * @param array $input   Input.
 * @param array $postarr Base array (post_type, ID ...).
 * @return array|WP_Error Post data.
 */
function gpmcp_save_post( array $input, array $postarr ) {
	$map = array(
		'title'      => 'post_title',
		'content'    => 'post_content',
		'excerpt'    => 'post_excerpt',
		'status'     => 'post_status',
		'slug'       => 'post_name',
		'date'       => 'post_date',
		'parent'     => 'post_parent',
		'menu_order' => 'menu_order',
		'template'   => 'page_template',
	);

	foreach ( $map as $field => $key ) {
		if ( isset( $input[ $field ] ) ) {
			$postarr[ $key ] = $input[ $field ];
		}
	}

	$type_object = get_post_type_object( $postarr['post_type'] );

	if ( isset( $postarr['post_status'] ) && in_array( $postarr['post_status'], array( 'publish', 'future', 'private' ), true )
		&& ! current_user_can( $type_object->cap->publish_posts ) ) {
		return gpmcp_error( 'gpmcp_forbidden', 'Permesso di pubblicazione mancante.' );
	}

	if ( isset( $postarr['post_content'] ) && ! current_user_can( 'unfiltered_html' ) ) {
		$postarr['post_content'] = wp_kses_post( $postarr['post_content'] );
	}

	$id = empty( $postarr['ID'] ) ? wp_insert_post( wp_slash( $postarr ), true ) : wp_update_post( wp_slash( $postarr ), true );

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	if ( isset( $input['categories'] ) && is_object_in_taxonomy( $postarr['post_type'], 'category' ) ) {
		wp_set_post_categories( $id, array_map( 'absint', $input['categories'] ) );
	}

	if ( isset( $input['tags'] ) && is_object_in_taxonomy( $postarr['post_type'], 'post_tag' ) ) {
		wp_set_post_tags( $id, array_map( 'sanitize_text_field', $input['tags'] ) );
	}

	if ( isset( $input['featured_media'] ) ) {
		if ( $input['featured_media'] ) {
			set_post_thumbnail( $id, (int) $input['featured_media'] );
		} else {
			delete_post_thumbnail( $id );
		}
	}

	return gpmcp_post_data( get_post( $id ), true );
}

/**
 * Register content abilities.
 */
function gpmcp_register_content_abilities() {
	gpmcp_register(
		'list-posts',
		array(
			'label'            => 'Elenca contenuti',
			'description'      => 'Elenca post, pagine o altri post type pubblici con filtri.',
			'category'         => 'gpmcp-content',
			'capability'       => 'edit_posts',
			'readonly'         => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'post_type' => array(
						'type'    => 'string',
						'default' => 'post',
					),
					'status'    => array(
						'type'    => 'string',
						'enum'    => array( 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
						'default' => 'any',
					),
					'search'    => array( 'type' => 'string' ),
					'category'  => array( 'type' => 'integer' ),
					'per_page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
					'page'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
				)
			),
			'execute_callback' => static function ( $input = array() ) {
				$post_type = $input['post_type'] ?? 'post';

				if ( ! in_array( $post_type, gpmcp_content_post_types(), true ) ) {
					return gpmcp_error( 'gpmcp_bad_post_type', 'Post type non gestito: ' . $post_type . '. Disponibili: ' . implode( ', ', gpmcp_content_post_types() ) );
				}

				$args = array(
					'post_type'      => $post_type,
					'post_status'    => $input['status'] ?? 'any',
					'posts_per_page' => $input['per_page'] ?? 20,
					'paged'          => $input['page'] ?? 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				);

				if ( ! empty( $input['search'] ) ) {
					$args['s'] = $input['search'];
				}
				if ( ! empty( $input['category'] ) ) {
					$args['cat'] = (int) $input['category'];
				}

				$query = new WP_Query( $args );
				$posts = array();
				foreach ( $query->posts as $post ) {
					$posts[] = gpmcp_post_data( $post );
				}

				return array(
					'total'       => (int) $query->found_posts,
					'total_pages' => (int) $query->max_num_pages,
					'posts'       => $posts,
				);
			},
		)
	);

	gpmcp_register(
		'get-post',
		array(
			'label'            => 'Leggi contenuto',
			'description'      => 'Restituisce un post/pagina completo (contenuto, termini, immagine in evidenza, template).',
			'category'         => 'gpmcp-content',
			'capability'       => 'edit_posts',
			'readonly'         => true,
			'input_schema'     => gpmcp_object_schema(
				array( 'id' => array( 'type' => 'integer' ) ),
				array( 'id' )
			),
			'execute_callback' => static function ( $input ) {
				$post = get_post( (int) $input['id'] );
				if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return gpmcp_error( 'gpmcp_not_found', 'Contenuto non trovato o non modificabile.' );
				}
				return gpmcp_post_data( $post, true );
			},
		)
	);

	gpmcp_register(
		'create-post',
		array(
			'label'            => 'Crea contenuto',
			'description'      => 'Crea un post/pagina. Default status draft: pubblicare solo su richiesta esplicita.',
			'category'         => 'gpmcp-content',
			'capability'       => 'edit_posts',
			'input_schema'     => gpmcp_object_schema(
				array_merge(
					array(
						'post_type' => array(
							'type'    => 'string',
							'default' => 'post',
						),
					),
					gpmcp_post_fields_schema()
				),
				array( 'title' )
			),
			'execute_callback' => static function ( $input ) {
				$post_type = $input['post_type'] ?? 'post';

				if ( ! in_array( $post_type, gpmcp_content_post_types(), true ) ) {
					return gpmcp_error( 'gpmcp_bad_post_type', 'Post type non gestito: ' . $post_type );
				}
				if ( ! current_user_can( get_post_type_object( $post_type )->cap->create_posts ) ) {
					return gpmcp_error( 'gpmcp_forbidden', 'Permesso di creazione mancante.' );
				}

				return gpmcp_save_post(
					$input,
					array(
						'post_type'   => $post_type,
						'post_status' => 'draft',
					)
				);
			},
		)
	);

	gpmcp_register(
		'update-post',
		array(
			'label'            => 'Aggiorna contenuto',
			'description'      => 'Aggiorna i campi indicati di un post/pagina esistente (gli altri restano invariati).',
			'category'         => 'gpmcp-content',
			'capability'       => 'edit_posts',
			'idempotent'       => true,
			'input_schema'     => gpmcp_object_schema(
				array_merge( array( 'id' => array( 'type' => 'integer' ) ), gpmcp_post_fields_schema() ),
				array( 'id' )
			),
			'execute_callback' => static function ( $input ) {
				$post = get_post( (int) $input['id'] );

				if ( ! $post || ! in_array( $post->post_type, gpmcp_content_post_types(), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
					return gpmcp_error( 'gpmcp_not_found', 'Contenuto non trovato o non modificabile.' );
				}

				return gpmcp_save_post(
					$input,
					array(
						'ID'        => $post->ID,
						'post_type' => $post->post_type,
					)
				);
			},
		)
	);

	gpmcp_register(
		'list-terms',
		array(
			'label'            => 'Elenca termini',
			'description'      => 'Elenca categorie, tag o termini di altre tassonomie pubbliche.',
			'category'         => 'gpmcp-content',
			'capability'       => 'edit_posts',
			'readonly'         => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'taxonomy' => array(
						'type'    => 'string',
						'default' => 'category',
					),
					'search'   => array( 'type' => 'string' ),
				)
			),
			'execute_callback' => static function ( $input = array() ) {
				$taxonomy = $input['taxonomy'] ?? 'category';

				if ( ! taxonomy_exists( $taxonomy ) || ! get_taxonomy( $taxonomy )->public ) {
					return gpmcp_error( 'gpmcp_bad_taxonomy', 'Tassonomia non valida: ' . $taxonomy );
				}

				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'search'     => $input['search'] ?? '',
						'number'     => 500,
					)
				);

				if ( is_wp_error( $terms ) ) {
					return $terms;
				}

				return array(
					'terms' => array_map(
						static function ( $term ) {
							return array(
								'id'          => $term->term_id,
								'name'        => $term->name,
								'slug'        => $term->slug,
								'parent'      => $term->parent,
								'count'       => $term->count,
								'description' => $term->description,
							);
						},
						array_values( $terms )
					),
				);
			},
		)
	);

	gpmcp_register(
		'create-term',
		array(
			'label'            => 'Crea termine',
			'description'      => 'Crea una categoria, tag o termine.',
			'category'         => 'gpmcp-content',
			'capability'       => 'edit_posts',
			'input_schema'     => gpmcp_object_schema(
				array(
					'taxonomy'    => array(
						'type'    => 'string',
						'default' => 'category',
					),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'parent'      => array( 'type' => 'integer' ),
					'description' => array( 'type' => 'string' ),
				),
				array( 'name' )
			),
			'execute_callback' => static function ( $input ) {
				$taxonomy = $input['taxonomy'] ?? 'category';

				if ( ! taxonomy_exists( $taxonomy ) || ! current_user_can( get_taxonomy( $taxonomy )->cap->manage_terms ) ) {
					return gpmcp_error( 'gpmcp_forbidden', 'Tassonomia non valida o permesso mancante.' );
				}

				$result = wp_insert_term(
					sanitize_text_field( $input['name'] ),
					$taxonomy,
					array(
						'slug'        => sanitize_title( $input['slug'] ?? '' ),
						'parent'      => (int) ( $input['parent'] ?? 0 ),
						'description' => sanitize_textarea_field( $input['description'] ?? '' ),
					)
				);

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'id'       => $result['term_id'],
					'taxonomy' => $taxonomy,
				);
			},
		)
	);

	gpmcp_register(
		'upload-media',
		array(
			'label'            => 'Carica media da URL',
			'description'      => 'Scarica un\'immagine da URL nella libreria media. Opzionale: titolo, alt text, post a cui allegarla.',
			'category'         => 'gpmcp-content',
			'capability'       => 'upload_files',
			'input_schema'     => gpmcp_object_schema(
				array(
					'url'     => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'title'   => array( 'type' => 'string' ),
					'alt'     => array( 'type' => 'string' ),
					'post_id' => array( 'type' => 'integer' ),
				),
				array( 'url' )
			),
			'execute_callback' => static function ( $input ) {
				$id = gpmcp_sideload_image( $input['url'], (int) ( $input['post_id'] ?? 0 ), $input['title'] ?? '' );

				if ( is_wp_error( $id ) ) {
					return $id;
				}

				if ( ! empty( $input['alt'] ) ) {
					update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
				}

				return array(
					'id'  => $id,
					'url' => wp_get_attachment_url( $id ),
				);
			},
		)
	);
}
