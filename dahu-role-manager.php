<?php
/**
 * Plugin Name:       Dahu Role Manager
 * Plugin URI:        https://dahu-concept.fr
 * Description:       Shapes the admin for each role: which menus, submenus and admin-bar items they see, which accounts they may edit, where they land on login. Duplicate, rename and delete roles, and export the whole thing as CSV.
 * Version:           3.0.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        mailto:contact@dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahu-role-manager
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package DahuRoleManager
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_ROLE_MANAGER_VERSION', '3.0.0' );
define( 'DAHU_ROLE_MANAGER_FILE', __FILE__ );
define( 'DAHU_ROLE_MANAGER_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_ROLE_MANAGER_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_ROLE_MANAGER_SLUG', 'dahu-role-manager' );

/** Option holding every per-role setting. */
define( 'DAHU_ROLE_MANAGER_OPTION', 'dahu_role_manager_settings' );

/* -------------------------------------------------------------------------
 * Translations
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'dahu-role-manager',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

/* -------------------------------------------------------------------------
 * Modules
 *
 * No WooCommerce guard: everything but the completed-order lock works on a
 * plain WordPress install, and that module checks for WooCommerce itself.
 * ---------------------------------------------------------------------- */

require_once DAHU_ROLE_MANAGER_PATH . 'includes/discovery.php';
require_once DAHU_ROLE_MANAGER_PATH . 'includes/hiding.php';
require_once DAHU_ROLE_MANAGER_PATH . 'includes/order-lock.php';
require_once DAHU_ROLE_MANAGER_PATH . 'includes/editable-accounts.php';
require_once DAHU_ROLE_MANAGER_PATH . 'includes/csv.php';
require_once DAHU_ROLE_MANAGER_PATH . 'includes/roles.php';
require_once DAHU_ROLE_MANAGER_PATH . 'includes/settings.php';

/* -------------------------------------------------------------------------
 * Plugins screen
 * ---------------------------------------------------------------------- */

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . DAHU_ROLE_MANAGER_SLUG ) ),
			esc_html__( 'Settings', 'dahu-role-manager' )
		);
		array_unshift( $links, $settings );
		return $links;
	}
);

add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {

		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$header = get_file_data( __FILE__, array( 'Version' => 'Version' ) );

		return array(
			sprintf(
				/* translators: %s: plugin version number. */
				esc_html__( 'Version %s', 'dahu-role-manager' ),
				esc_html( $header['Version'] )
			),
			sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://dahu-concept.fr' ),
				esc_html__( 'By Hugo Vial-Jaime — Dahu-Concept', 'dahu-role-manager' )
			),
			sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://dahu-concept.fr' ),
				esc_html__( 'Plugin website', 'dahu-role-manager' )
			),
		);
	},
	10,
	2
);
