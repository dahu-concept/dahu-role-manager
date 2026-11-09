<?php
/**
 * Plugin Name:       DahuConcept Role Manager
 * Description:       Shapes the admin for each role: which menus, submenus and admin-bar items they see, which accounts they may edit, where they land on login. Duplicate, rename and delete roles, and export the whole thing as CSV.
 * Version:           3.2.4
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahuconcept-role-manager
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 *
 * @package UbacRoles
 */

defined( 'ABSPATH' ) || exit;

define( 'UBAC_ROLES_VERSION', '3.2.4' );
define( 'UBAC_ROLES_FILE', __FILE__ );
define( 'UBAC_ROLES_PATH', plugin_dir_path( __FILE__ ) );
define( 'UBAC_ROLES_URL', plugin_dir_url( __FILE__ ) );
define( 'UBAC_ROLES_SLUG', 'ubac-roles' );

/** Option holding every per-role setting. */
define( 'UBAC_ROLES_OPTION', 'ubac_roles_settings' );

/* -------------------------------------------------------------------------
 * Translations
 *
 * There is deliberately no load_plugin_textdomain() call here. Since
 * WordPress 4.6 the translations of a plugin hosted on WordPress.org are
 * loaded on demand, and the directory's review asks that the call be removed
 * rather than kept: this plugin requires 6.0, so there is no older WordPress
 * to cover. Translations come from translate.wordpress.org.
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Modules
 *
 * No WooCommerce guard: everything but the completed-order lock works on a
 * plain WordPress install, and that module checks for WooCommerce itself.
 * ---------------------------------------------------------------------- */

require_once UBAC_ROLES_PATH . 'includes/role-store.php';
require_once UBAC_ROLES_PATH . 'includes/discovery.php';
require_once UBAC_ROLES_PATH . 'includes/hiding.php';
require_once UBAC_ROLES_PATH . 'includes/order-lock.php';
require_once UBAC_ROLES_PATH . 'includes/editable-accounts.php';
require_once UBAC_ROLES_PATH . 'includes/csv.php';
require_once UBAC_ROLES_PATH . 'includes/roles.php';
require_once UBAC_ROLES_PATH . 'includes/settings.php';

/* -------------------------------------------------------------------------
 * Plugins screen
 * ---------------------------------------------------------------------- */

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . UBAC_ROLES_SLUG ) ),
			esc_html__( 'Settings', 'dahuconcept-role-manager' )
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
				esc_html__( 'Version %s', 'dahuconcept-role-manager' ),
				esc_html( $header['Version'] )
			),
			sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://dahu-concept.fr' ),
				esc_html__( 'By Hugo Vial-Jaime — Dahu-Concept', 'dahuconcept-role-manager' )
			),
			sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://dahu-concept.fr' ),
				esc_html__( 'Plugin website', 'dahuconcept-role-manager' )
			),
		);
	},
	10,
	2
);

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once UBAC_ROLES_PATH . 'includes/ubac-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Ubac_Welcome::mark_activated( 'dahuconcept-role-manager' );
	}
);

add_action(
	'init',
	static function () {
		Ubac_Welcome::register(
			array(
				'slug'       => 'dahuconcept-role-manager',
				'name'       => __( 'Role manager', 'dahuconcept-role-manager' ),
				'tagline'    => __( 'Who can do what, and what changed since last time.', 'dahuconcept-role-manager' ),
				'file'       => UBAC_ROLES_FILE,
				'version'    => UBAC_ROLES_VERSION,
				'textdomain' => 'dahuconcept-role-manager',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M23.0 30.0 a17.0 17.0 0 1 0 34.0 0 a17.0 17.0 0 1 0 -34.0 0',
						'M14 76 Q14 53 40 53 Q66 53 66 76',
						'M62.0 92.0 a12.0 12.0 0 1 0 24.0 0 a12.0 12.0 0 1 0 -24.0 0',
						'M85 92 L110 92 M101 92 L101 102 M92 92 L92 100',
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'dahuconcept-role-manager' ),
					'done'   => __( 'Done', 'dahuconcept-role-manager' ),
					'skip'   => __( 'Skip', 'dahuconcept-role-manager' ),
					'of'     => __( 'of', 'dahuconcept-role-manager' ),
					'denied' => __( 'You are not allowed to view this page.', 'dahuconcept-role-manager' ),
				),
				/*
				 * The one-time tour, on the main screen (Settings > Role manager).
				 * Every target is rendered whatever the shop holds: the role
				 * selector and the tab bar sit outside any condition, and the
				 * tab bar is built from a fixed list.
				 */
				'tour_screen' => 'settings_page_ubac-roles',
				'tour'        => array(
					array(
						'selector' => '#ubac_role',
						'title'    => __( 'Pick a role', 'dahuconcept-role-manager' ),
						'text'     => __( 'Everything below applies to the role chosen here.', 'dahuconcept-role-manager' ),
					),
					array(
						'selector' => '.ubac-rm-tabs a[href*="tab=roles"]',
						'title'    => __( 'Manage the roles', 'dahuconcept-role-manager' ),
						'text'     => __( 'Create, rename or delete a role.', 'dahuconcept-role-manager' ),
					),
					array(
						'selector' => '.ubac-rm-tabs a[href*="tab=csv"]',
						'title'    => __( 'Export and import', 'dahuconcept-role-manager' ),
						'text'     => __( 'Copy every setting to another shop with a CSV file.', 'dahuconcept-role-manager' ),
					),
				),
				'points'     => array(
				array(
					'title' => __( 'Roles, plainly', 'dahuconcept-role-manager' ),
					'text'  => __( 'Create, rename and retire roles without a developer.', 'dahuconcept-role-manager' ),
				),
				array(
					'title' => __( 'Capabilities, readably', 'dahuconcept-role-manager' ),
					'text'  => __( 'What each role may do, in one screen.', 'dahuconcept-role-manager' ),
				),
				array(
					'title' => __( 'A record', 'dahuconcept-role-manager' ),
					'text'  => __( 'Every change is logged with who made it.', 'dahuconcept-role-manager' ),
				),
				),
			)
		);
	}
);
