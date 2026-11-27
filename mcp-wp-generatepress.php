<?php
/**
 * Plugin Name:       MCP WP GeneratePress
 * Description:       Espone abilities (Abilities API + MCP Adapter) per gestire GeneratePress, GP Premium, contenuti e snapshot delle impostazioni tramite agenti AI.
 * Version:           0.2.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Juri Paiusco
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       mcp-wp-generatepress
 *
 * @package McpWpGeneratePress
 */

defined( 'ABSPATH' ) || exit;

define( 'GPMCP_VERSION', '0.2.0' );
define( 'GPMCP_FILE', __FILE__ );
define( 'GPMCP_DIR', plugin_dir_path( __FILE__ ) );

require_once GPMCP_DIR . 'includes/helpers.php';
require_once GPMCP_DIR . 'includes/class-plugin.php';

GPMCP_Plugin::init();
