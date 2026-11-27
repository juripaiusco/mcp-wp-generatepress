<?php
/**
 * Snapshot abilities: save / restore theme settings.
 *
 * @package McpWpGeneratePress
 */

defined( 'ABSPATH' ) || exit;

defined( 'GPMCP_SNAPSHOT_OPTION' ) || define( 'GPMCP_SNAPSHOT_OPTION', 'gpmcp_snapshots' );
defined( 'GPMCP_SNAPSHOT_MAX' ) || define( 'GPMCP_SNAPSHOT_MAX', 30 );

/**
 * Option names captured by a snapshot.
 *
 * @return string[]
 */
function gpmcp_snapshot_option_names() {
	$names = array(
		'generate_settings',
		'generate_spacing_settings',
		'generate_blog_settings',
		'generate_menu_plus_settings',
		'generate_secondary_nav_settings',
		'generate_background_settings',
		'generate_woocommerce_settings',
		'generate_page_header_settings',
		'generate_hooks',
		'theme_mods_' . get_stylesheet(),
	);

	$modules = array(
		'backgrounds', 'blog', 'colors', 'copyright', 'disable_elements', 'elements', 'font_library',
		'hooks', 'menu_plus', 'page_header', 'secondary_nav', 'sections', 'site_library', 'spacing',
		'typography', 'woocommerce',
	);

	foreach ( $modules as $module ) {
		$names[] = 'generate_package_' . $module;
	}

	return $names;
}

/**
 * All stored snapshots.
 *
 * @return array
 */
function gpmcp_get_snapshots() {
	$snapshots = get_option( GPMCP_SNAPSHOT_OPTION, array() );
	return is_array( $snapshots ) ? $snapshots : array();
}

/**
 * Create a snapshot of the current theme settings.
 *
 * @param string $label Label.
 * @return array Snapshot summary.
 */
function gpmcp_snapshot_create( $label = '' ) {
	$missing = '__gpmcp_missing__';
	$options = array();

	foreach ( gpmcp_snapshot_option_names() as $name ) {
		$value            = get_option( $name, $missing );
		$options[ $name ] = ( $missing === $value ) ? null : $value;
	}

	$snapshot = array(
		'id'         => gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false, false ),
		'label'      => sanitize_text_field( $label ),
		'created'    => current_time( 'mysql' ),
		'user'       => get_current_user_id(),
		'stylesheet' => get_stylesheet(),
		'options'    => $options,
		'custom_css' => wp_get_custom_css(),
	);

	$snapshots   = gpmcp_get_snapshots();
	$snapshots[] = $snapshot;

	if ( count( $snapshots ) > GPMCP_SNAPSHOT_MAX ) {
		$snapshots = array_slice( $snapshots, -GPMCP_SNAPSHOT_MAX );
	}

	update_option( GPMCP_SNAPSHOT_OPTION, $snapshots, false );

	return gpmcp_snapshot_summary( $snapshot );
}

/**
 * Public summary of a snapshot (no raw data).
 *
 * @param array $snapshot Snapshot.
 * @return array
 */
function gpmcp_snapshot_summary( array $snapshot ) {
	return array(
		'id'         => $snapshot['id'],
		'label'      => $snapshot['label'],
		'created'    => $snapshot['created'],
		'stylesheet' => $snapshot['stylesheet'],
		'options'    => array_keys( array_filter( $snapshot['options'], static function ( $v ) {
			return null !== $v;
		} ) ),
	);
}

/**
 * Restore a snapshot.
 *
 * @param string $id Snapshot ID.
 * @return array|WP_Error
 */
function gpmcp_snapshot_restore( $id ) {
	$target = null;
	foreach ( gpmcp_get_snapshots() as $snapshot ) {
		if ( $snapshot['id'] === $id ) {
			$target = $snapshot;
			break;
		}
	}

	if ( ! $target ) {
		return gpmcp_error( 'gpmcp_snapshot_not_found', 'Snapshot non trovato: ' . $id );
	}

	if ( $target['stylesheet'] !== get_stylesheet() ) {
		return gpmcp_error( 'gpmcp_snapshot_theme_mismatch', 'Lo snapshot appartiene al tema ' . $target['stylesheet'] . ', tema attivo: ' . get_stylesheet() );
	}

	$backup = gpmcp_snapshot_create( 'auto: prima del ripristino di ' . $id );

	foreach ( $target['options'] as $name => $value ) {
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}

	// Theme mods are restored above (incl. custom_css_post_id): only touch the CSS post if it still differs.
	if ( wp_get_custom_css() !== (string) $target['custom_css'] ) {
		wp_update_custom_css_post( (string) $target['custom_css'], array( 'stylesheet' => get_stylesheet() ) );
	}
	gpmcp_clear_css_cache();

	return array(
		'restored'      => $id,
		'backup_before' => $backup['id'],
	);
}

/**
 * Register snapshot abilities.
 */
function gpmcp_register_snapshot_abilities() {
	gpmcp_register(
		'snapshot-create',
		array(
			'label'            => 'Crea snapshot impostazioni tema',
			'description'      => 'Salva una copia di impostazioni GeneratePress/GP Premium, theme mods e CSS aggiuntivo. Le abilities di scrittura design ne creano uno automatico.',
			'category'         => 'gpmcp-snapshot',
			'input_schema'     => gpmcp_object_schema(
				array(
					'label' => array(
						'type'        => 'string',
						'description' => 'Descrizione dello snapshot.',
					),
				)
			),
			'execute_callback' => static function ( $input = array() ) {
				return gpmcp_snapshot_create( $input['label'] ?? 'manuale' );
			},
		)
	);

	gpmcp_register(
		'snapshot-list',
		array(
			'label'            => 'Elenca snapshot',
			'description'      => 'Elenca gli snapshot salvati (più recenti per ultimi).',
			'category'         => 'gpmcp-snapshot',
			'readonly'         => true,
			'execute_callback' => static function () {
				return array( 'snapshots' => array_map( 'gpmcp_snapshot_summary', gpmcp_get_snapshots() ) );
			},
		)
	);

	gpmcp_register(
		'snapshot-restore',
		array(
			'label'            => 'Ripristina snapshot',
			'description'      => 'Ripristina impostazioni tema e CSS aggiuntivo da uno snapshot. Prima crea automaticamente un backup dello stato attuale.',
			'category'         => 'gpmcp-snapshot',
			'destructive'      => true,
			'idempotent'       => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'id' => array(
						'type'        => 'string',
						'description' => 'ID dello snapshot (da snapshot-list).',
					),
				),
				array( 'id' )
			),
			'execute_callback' => static function ( $input ) {
				return gpmcp_snapshot_restore( $input['id'] );
			},
		)
	);

	gpmcp_register(
		'snapshot-delete',
		array(
			'label'            => 'Elimina snapshot',
			'description'      => 'Elimina uno snapshot salvato.',
			'category'         => 'gpmcp-snapshot',
			'destructive'      => true,
			'idempotent'       => true,
			'input_schema'     => gpmcp_object_schema(
				array(
					'id' => array( 'type' => 'string' ),
				),
				array( 'id' )
			),
			'execute_callback' => static function ( $input ) {
				$before    = gpmcp_get_snapshots();
				$snapshots = array_values( array_filter( $before, static function ( $s ) use ( $input ) {
					return $s['id'] !== $input['id'];
				} ) );

				if ( count( $snapshots ) === count( $before ) ) {
					return gpmcp_error( 'gpmcp_snapshot_not_found', 'Snapshot non trovato: ' . $input['id'] );
				}

				update_option( GPMCP_SNAPSHOT_OPTION, $snapshots, false );
				return array( 'deleted' => $input['id'] );
			},
		)
	);
}
