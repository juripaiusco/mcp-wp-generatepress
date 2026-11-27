<?php
/**
 * Overview ability.
 *
 * @package McpWpGeneratePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register overview abilities.
 */
function gpmcp_register_overview_abilities() {
	gpmcp_register(
		'get-overview',
		array(
			'label'            => 'Panoramica tema e plugin',
			'description'      => 'Restituisce tema attivo, versioni GeneratePress/GP Premium, tipografia dinamica, gruppi di impostazioni disponibili e moduli premium. Da chiamare per primo.',
			'category'         => 'gpmcp-overview',
			'readonly'         => true,
			'execute_callback' => static function () {
				$theme  = wp_get_theme();
				$groups = array();

				if ( gpmcp_is_generatepress_active() ) {
					foreach ( gpmcp_settings_groups() as $key => $group ) {
						$groups[ $key ] = array(
							'option'       => $group['option'],
							'label'        => $group['label'],
							'has_defaults' => null !== $group['defaults'],
							'stored'       => false !== get_option( $group['option'], false ),
						);
					}
				}

				return array(
					'plugin_version'         => GPMCP_VERSION,
					'site_url'               => home_url(),
					'theme'                  => array(
						'stylesheet' => get_stylesheet(),
						'template'   => get_template(),
						'name'       => $theme->get( 'Name' ),
						'version'    => $theme->get( 'Version' ),
					),
					'generatepress_version'  => defined( 'GENERATE_VERSION' ) ? GENERATE_VERSION : null,
					'gp_premium_version'     => defined( 'GP_PREMIUM_VERSION' ) ? GP_PREMIUM_VERSION : null,
					'dynamic_typography'     => function_exists( 'generate_is_using_dynamic_typography' ) ? generate_is_using_dynamic_typography() : null,
					'settings_groups'        => $groups,
					'premium_modules'        => gpmcp_is_gp_premium_active() && function_exists( 'gpmcp_premium_modules' ) ? gpmcp_premium_modules() : array(),
					'custom_logo_id'         => (int) get_theme_mod( 'custom_logo' ),
					'snapshots'              => count( gpmcp_get_snapshots() ),
				);
			},
		)
	);
}
