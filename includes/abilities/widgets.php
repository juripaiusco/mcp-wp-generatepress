<?php
/**
 * Widget abilities: widget areas (sidebars, footer widgets) with block widgets, and GP Premium copyright.
 *
 * @package McpWpGeneratePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Describe one widget instance.
 *
 * @param string $widget_id Widget ID (e.g. block-3, text-2).
 * @return array
 */
function gpmcp_widget_data( $widget_id ) {
	$data = array( 'id' => $widget_id );

	if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
		return $data;
	}

	$data['type'] = $m[1];
	$instances    = get_option( 'widget_' . $m[1], array() );
	$instance     = $instances[ (int) $m[2] ] ?? null;

	if ( 'block' === $m[1] ) {
		$data['content'] = $instance['content'] ?? '';
	} elseif ( null !== $instance ) {
		$data['settings'] = $instance;
	}

	return $data;
}

/**
 * Register widget abilities.
 */
function gpmcp_register_widget_abilities() {
	gpmcp_register(
		'list-widget-areas',
		array(
			'label'            => 'Elenca aree widget',
			'description'      => 'Restituisce le aree widget registrate dal tema (sidebar-1, footer-1..5, footer-bar, top-bar, header...) con i widget contenuti. Per i widget a blocchi restituisce il markup.',
			'category'         => 'gpmcp-design',
			'readonly'         => true,
			'execute_callback' => static function () {
				global $wp_registered_sidebars;

				$sidebars = wp_get_sidebars_widgets();
				$areas    = array();

				foreach ( (array) $wp_registered_sidebars as $id => $sidebar ) {
					$areas[] = array(
						'id'      => $id,
						'name'    => $sidebar['name'],
						'widgets' => array_map( 'gpmcp_widget_data', array_values( (array) ( $sidebars[ $id ] ?? array() ) ) ),
					);
				}

				return array(
					'areas'    => $areas,
					'inactive' => count( (array) ( $sidebars['wp_inactive_widgets'] ?? array() ) ),
				);
			},
		)
	);

	gpmcp_register(
		'set-widget-area',
		array(
			'label'            => 'Imposta widget di un\'area',
			'description'      => 'Sostituisce i widget di un\'area (es. footer-1) con widget a blocchi. blocks: array di markup a blocchi Gutenberg, uno per widget. I widget rimossi vengono spostati tra i widget inattivi (recuperabili). blocks vuoto svuota l\'area. Crea snapshot automatico (include i widget).',
			'category'         => 'gpmcp-design',
			'input_schema'     => gpmcp_object_schema(
				array(
					'area'   => array(
						'type'        => 'string',
						'description' => 'ID area da list-widget-areas, es. footer-1.',
					),
					'blocks' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				array( 'area', 'blocks' )
			),
			'execute_callback' => static function ( $input ) {
				global $wp_registered_sidebars;

				$area = $input['area'];
				if ( ! isset( $wp_registered_sidebars[ $area ] ) ) {
					return gpmcp_error( 'gpmcp_bad_area', 'Area widget non registrata: ' . $area );
				}

				$snapshot = gpmcp_snapshot_create( 'auto: set-widget-area ' . $area );

				$sidebars  = wp_get_sidebars_widgets();
				$instances = get_option( 'widget_block', array() );
				if ( ! is_array( $instances ) ) {
					$instances = array();
				}
				unset( $instances['_multiwidget'] );

				$sidebars['wp_inactive_widgets'] = array_merge(
					(array) ( $sidebars['wp_inactive_widgets'] ?? array() ),
					(array) ( $sidebars[ $area ] ?? array() )
				);

				$next = $instances ? max( array_map( 'intval', array_keys( $instances ) ) ) + 1 : 1;
				$ids  = array();
				foreach ( (array) $input['blocks'] as $markup ) {
					$instances[ $next ] = array(
						'content' => current_user_can( 'unfiltered_html' ) ? (string) $markup : wp_kses_post( (string) $markup ),
					);
					$ids[] = 'block-' . $next;
					++$next;
				}

				$instances['_multiwidget'] = 1;
				update_option( 'widget_block', $instances );

				$sidebars[ $area ] = $ids;
				wp_set_sidebars_widgets( $sidebars );

				return array(
					'area'     => $area,
					'widgets'  => array_map( 'gpmcp_widget_data', $ids ),
					'snapshot' => $snapshot['id'] ?? null,
				);
			},
		)
	);

	if ( ! gpmcp_is_gp_premium_active() ) {
		return;
	}

	gpmcp_register(
		'set-copyright',
		array(
			'label'            => 'Imposta copyright footer',
			'description'      => 'Imposta il testo della barra copyright di GeneratePress (modulo Copyright di GP Premium, theme mod generate_copyright). Segnaposto: %current_year% (anno corrente), %copy% (©). HTML base consentito. Stringa vuota = ripristina il testo di default. Crea snapshot automatico.',
			'category'         => 'gpmcp-premium',
			'input_schema'     => gpmcp_object_schema(
				array( 'text' => array( 'type' => 'string' ) ),
				array( 'text' )
			),
			'execute_callback' => static function ( $input ) {
				$snapshot = gpmcp_snapshot_create( 'auto: set-copyright' );
				$text     = wp_kses_post( $input['text'] );

				if ( '' === trim( $text ) ) {
					remove_theme_mod( 'generate_copyright' );
				} else {
					set_theme_mod( 'generate_copyright', $text );
				}

				return array(
					'text'           => $text,
					'module_active'  => 'activated' === get_option( 'generate_package_copyright' ),
					'snapshot'       => $snapshot['id'] ?? null,
				);
			},
		)
	);
}
