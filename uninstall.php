<?php
/**
 * Uninstall routine.
 *
 * WHAT IS DELETED. Both options this plugin owns, and nothing else:
 *
 *   ubac_roles_settings  every per-role setting
 *
 * WHAT IS NOT TOUCHED — and this is the part that matters. The ROLES
 * themselves are left exactly as they are. A role created through this plugin is
 * an ordinary WordPress role the moment it exists: users hold it, other plugins
 * grant capabilities on it, and content may depend on it. Deleting roles on
 * uninstall would strip those users of their role and silently change who can do
 * what on the site. Capabilities granted through the
 * ubac_roles_extra_role_caps filter are left in place for the same
 * reason.
 *
 * So uninstalling removes the configuration — every menu comes back, every admin
 * bar comes back, no order is locked any more — while the site's role structure
 * survives untouched. Deleting a role is a deliberate act, done from the Roles
 * tab while the plugin is still installed and while it can still refuse to
 * delete a role that users hold.
 *
 * @package UbacRoles
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ubac_roles_settings' );

// The welcome screen's own three flags. They are interface state, not the
// shop's data: `includes/ubac-welcome.php` writes them with update_option(),
// so they outlive the plugin unless they are deleted here, and a reinstall
// would never greet anybody again — the screen and the guided tour staying
// silent for good, with nothing on screen to explain why.
delete_option( 'ubac_welcome_seen_ubac-roles' );
delete_option( 'ubac_welcome_pending_ubac-roles' );
delete_option( 'ubac_welcome_tour_ubac-roles' );

// Per-user transients carrying the "status is locked" notice. Short-lived
// anyway, but there is no reason to leave them behind.
global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE %s
		    OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_ubac_roles_locked_order_notice_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ubac_roles_locked_order_notice_' ) . '%'
	)
);

// Multisite: the same cleanup on every site of the network.
if ( is_multisite() ) {

	$ubac_roles_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $ubac_roles_site_ids as $ubac_roles_site_id ) {

		switch_to_blog( $ubac_roles_site_id );

		delete_option( 'ubac_roles_settings' );
		delete_option( 'ubac_welcome_seen_ubac-roles' );
		delete_option( 'ubac_welcome_pending_ubac-roles' );
		delete_option( 'ubac_welcome_tour_ubac-roles' );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				    OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_ubac_roles_locked_order_notice_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_ubac_roles_locked_order_notice_' ) . '%'
			)
		);

		restore_current_blog();
	}
}
