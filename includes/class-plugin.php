<?php
/**
 * Bootstrap: registers ability categories and abilities.
 *
 * @package McpWpGeneratePress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class GPMCP_Plugin {

	/**
	 * Hook into the Abilities API.
	 */
	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_api_notice' ) );
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Admin notice when the Abilities API is not available.
	 */
	public static function missing_api_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'MCP WP GeneratePress richiede WordPress 6.9+ (Abilities API).', 'mcp-wp-generatepress' );
		echo '</p></div>';
	}

	/**
	 * Ability categories.
	 */
	public static function register_categories() {
		$categories = array(
			'gpmcp-overview' => array( 'Panoramica', 'Informazioni su tema, versioni e moduli GeneratePress.' ),
			'gpmcp-design'   => array( 'Design GeneratePress', 'Colori globali, tipografia, impostazioni Customizer, logo e CSS aggiuntivo.' ),
			'gpmcp-premium'  => array( 'GP Premium', 'Moduli GP Premium ed Elements (hook, block, layout, header).' ),
			'gpmcp-content'  => array( 'Contenuti', 'Post, pagine, tassonomie e media.' ),
			'gpmcp-snapshot' => array( 'Snapshot', 'Salvataggio e ripristino delle impostazioni del tema.' ),
		);

		foreach ( $categories as $slug => $data ) {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => $data[0],
					'description' => $data[1],
				)
			);
		}
	}

	/**
	 * Load and register all abilities.
	 */
	public static function register_abilities() {
		require_once GPMCP_DIR . 'includes/abilities/snapshot.php';
		require_once GPMCP_DIR . 'includes/abilities/overview.php';
		require_once GPMCP_DIR . 'includes/abilities/content.php';
		require_once GPMCP_DIR . 'includes/abilities/menus.php';

		gpmcp_register_snapshot_abilities();
		gpmcp_register_overview_abilities();
		gpmcp_register_content_abilities();
		gpmcp_register_menu_abilities();

		if ( gpmcp_is_generatepress_active() ) {
			require_once GPMCP_DIR . 'includes/abilities/design.php';
			gpmcp_register_design_abilities();
		}

		if ( gpmcp_is_gp_premium_active() ) {
			require_once GPMCP_DIR . 'includes/abilities/premium.php';
			gpmcp_register_premium_abilities();
		}
	}
}
