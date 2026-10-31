<?php
/**
 * Discovery of admin menus and admin-bar nodes, plus an opt-in capability hook.
 *
 * Everything the settings screen offers to hide is discovered at runtime from
 * the live install, never from a hardcoded list: whatever plugins are active
 * on the site shows up by itself.
 *
 * @package DahuRoleManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Grant extra capabilities to roles, from a filter.
 *
 * Some plugins declare custom capabilities that role editors do not always
 * propagate. Rather than guess which plugins a site runs, this hook stays
 * empty by default and lets the site owner name the capabilities to add:
 *
 *     add_filter(
 *         'dahu_role_manager_extra_role_caps',
 *         function ( $caps ) {
 *             $caps['editor'] = array( 'some_plugin_manage_items' );
 *             return $caps;
 *         }
 *     );
 *
 * Capabilities are only ever added, never removed, and only to roles that
 * already exist. Nothing happens unless the filter returns something.
 *
 * This runs on `init`, so on every request. `WP_Role::add_cap()` rewrites the
 * WHOLE roles option from the copy held in memory, which itself comes from the
 * object cache: a stale copy would erase the roles missing from it. And the
 * capability missing from that copy may well be granted in the database
 * already. So nothing is written before the database itself has been read —
 * and when the copy lacks nothing, not even that read happens.
 *
 * @return void
 */
function dahu_role_manager_grant_extra_caps() {

	/**
	 * Filters the capabilities to add, keyed by role slug.
	 *
	 * @param array<string,string[]> $caps Role slug => list of capabilities.
	 */
	$extra = apply_filters( 'dahu_role_manager_extra_role_caps', array() );

	if ( empty( $extra ) || ! is_array( $extra ) ) {
		return;
	}

	// First pass, on the copy in memory only: no query, no write.
	$candidates = array();

	foreach ( $extra as $role_slug => $caps ) {

		if ( ! is_string( $role_slug ) || ! is_array( $caps ) ) {
			continue;
		}

		$role = get_role( $role_slug );
		if ( ! $role ) {
			continue;
		}

		foreach ( $caps as $cap ) {
			if ( is_string( $cap ) && '' !== $cap && ! $role->has_cap( $cap ) ) {
				$candidates[ $role_slug ][] = $cap;
			}
		}
	}

	// Nothing missing from the copy: the common case, and it costs nothing.
	if ( empty( $candidates ) ) {
		return;
	}

	// Second pass, against the database: a capability already granted there
	// means the copy in memory is the thing that is wrong.
	$db_roles = dahu_role_manager_read_roles_db();
	$missing  = array();

	foreach ( $candidates as $role_slug => $caps ) {
		foreach ( $caps as $cap ) {
			if ( ! dahu_role_manager_db_role_has_cap( $db_roles, $role_slug, $cap ) ) {
				$missing[ $role_slug ][] = $cap;
			}
		}
	}

	if ( empty( $missing ) ) {
		// The database is already right. Refresh the copy, write nothing.
		dahu_role_manager_purge_roles_cache();
		dahu_role_manager_log_role_action(
			'extra_caps_skip',
			implode( ',', array_keys( $candidates ) ),
			'already granted in database, nothing written'
		);
		return;
	}

	/*
	 * Genuinely absent: write, but from the database copy, not the stale one —
	 * AND REFUSE IF THAT READ FAILED.
	 *
	 * This is the only path of the plugin that runs on EVERY request, so it is
	 * the one where writing from a stale copy does the most damage: `add_cap()`
	 * rewrites the whole roles option, and a copy that has lost a role erases
	 * it. Calling the reload and ignoring its answer cancelled the guard on
	 * precisely the hottest path, which is worse than not having it.
	 */
	if ( ! dahu_role_manager_reload_roles_from_db() ) {
		dahu_role_manager_log_role_action(
			'extra_caps_refused',
			implode( ',', array_keys( $missing ) ),
			'roles option unreadable in database, nothing written'
		);
		return;
	}

	foreach ( $missing as $role_slug => $caps ) {

		$role = get_role( $role_slug );
		if ( ! $role ) {
			continue;
		}

		foreach ( $caps as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}
}
add_action( 'init', 'dahu_role_manager_grant_extra_caps' );

/**
 * Build the map of admin menus and their submenus.
 *
 * Read from the globals WordPress has already populated, so the list reflects
 * exactly what the current user would see, plugins included.
 *
 * @return array<string,array{label:string,submenus:array<string,string>}>
 */
function dahu_role_manager_get_menu_map() {
	global $menu, $submenu;

	$map = array();

	if ( ! is_array( $menu ) ) {
		return $map;
	}

	foreach ( $menu as $item ) {

		if ( empty( $item[2] ) ) {
			continue;
		}

		$slug  = $item[2];
		$label = wp_strip_all_tags( $item[0] );

		// Menu titles carry pending counters ("Comments 3", "Updates 2") as
		// markup that strips down to bare digits. Drop them so the label
		// stays stable from one page load to the next.
		$label = trim( preg_replace( '/\d+/', '', $label ) );
		$label = '' !== $label ? $label : $slug;

		$map[ $slug ] = array(
			'label'    => $label,
			'submenus' => array(),
		);

		if ( ! empty( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
			foreach ( $submenu[ $slug ] as $sub ) {
				if ( empty( $sub[2] ) ) {
					continue;
				}
				$map[ $slug ]['submenus'][ $sub[2] ] = wp_strip_all_tags( $sub[0] );
			}
		}
	}

	return $map;
}

/**
 * Build the map of admin-bar nodes.
 *
 * On the settings screen the real admin bar is not built yet: it is assembled
 * later, on wp_before_admin_bar_render. A throwaway instance is spun up here
 * and the standard hook is fired against it, which is how core and plugins
 * register their nodes — that yields the full list of what can be hidden.
 *
 * WHY add_menus() IS CALLED HERE. Core does not register its own admin-bar
 * callbacks when admin-bar.php loads; it registers them inside
 * WP_Admin_Bar::add_menus(), which _wp_admin_bar_init() calls. And
 * _wp_admin_bar_init() returns early, BEFORE add_menus(), when
 * is_admin_bar_showing() is false.
 *
 * That early return is reachable from this very plugin: hiding the admin bar
 * entirely for a role filters show_admin_bar to false. So an administrator who
 * ticked that box for their own role would open this screen and find the list
 * of admin-bar nodes empty — unable to choose individual nodes precisely
 * because they had used the neighbouring option. Calling add_menus() on the
 * throwaway instance makes the discovery answer for itself instead of relying
 * on what WordPress happened to do earlier in the request.
 *
 * Calling it twice is harmless: add_action() keys a named function by its
 * name, so re-registering the same callback at the same priority is a no-op.
 *
 * @return array<string,array{label:string,parent:string}>
 */
function dahu_role_manager_get_adminbar_map() {

	if ( ! class_exists( 'WP_Admin_Bar' ) ) {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
	}

	if ( ! function_exists( 'wp_admin_bar_my_account_menu' ) ) {
		require_once ABSPATH . WPINC . '/admin-bar.php';
	}

	// Core's own edit-menu callback reads get_current_screen(); make sure it
	// exists before inviting it to run.
	if ( ! function_exists( 'get_current_screen' ) ) {
		require_once ABSPATH . 'wp-admin/includes/screen.php';
	}

	$temp_bar = new WP_Admin_Bar();
	$temp_bar->initialize();
	$temp_bar->add_menus();

	/*
	 * Fire the hook core and plugins use to register their nodes
	 * (see wp-includes/admin-bar.php).
	 *
	 * This deliberately runs OTHER plugins' callbacks, at a moment in the
	 * request they were not written for: the settings screen, rather than
	 * wp_before_admin_bar_render. A callback that assumes something is already
	 * set up can therefore throw — and without this guard it would throw
	 * through this function and take the whole settings page with it, on a site
	 * whose only fault was having that other plugin installed.
	 *
	 * A node that cannot be collected is a node missing from a checklist. That
	 * is a far better outcome than a white screen, so failure is swallowed
	 * here and whatever was gathered before it is kept.
	 */
	try {
		do_action_ref_array( 'admin_bar_menu', array( &$temp_bar ) );
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate, gated on WP_DEBUG_LOG.
			error_log(
				'[Dahu Role Manager] an admin_bar_menu callback threw while listing nodes: '
				. $e->getMessage()
			);
		}
	}

	$nodes = $temp_bar->get_nodes();

	$map = array();

	if ( ! $nodes ) {
		return $map;
	}

	foreach ( $nodes as $node ) {
		$label = trim( wp_strip_all_tags( $node->title ) );
		$map[ $node->id ] = array(
			'label'  => '' !== $label ? $label : $node->id,
			'parent' => $node->parent,
		);
	}

	return $map;
}
