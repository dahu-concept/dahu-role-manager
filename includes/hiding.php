<?php
/**
 * Applying the hiding rules: menus, submenus, admin bar, and the landing page.
 *
 * @package DahuRoleManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove the menus and submenus hidden for the current user's roles.
 *
 * Runs late on admin_menu (priority 999) so every plugin has registered its
 * pages first.
 *
 * @return void
 */
function dahu_role_manager_apply_menu_restrictions() {

	if ( current_user_can( 'administrator' ) ) {
		return;
	}

	list( $hide_menus, $hide_submenus, ) = dahu_role_manager_get_user_hidden_items();

	foreach ( $hide_menus as $slug ) {
		remove_menu_page( $slug );
	}

	// Inventory every registered submenu slug, to spot the "carrier" submenus
	// explained below.
	global $submenu;

	$all_sub_slugs = array();

	foreach ( (array) $submenu as $items ) {
		foreach ( (array) $items as $item ) {
			if ( ! empty( $item[2] ) ) {
				$all_sub_slugs[] = $item[2];
			}
		}
	}

	foreach ( $hide_submenus as $combo ) {

		if ( false === strpos( $combo, '|' ) ) {
			continue;
		}

		list( $parent_slug, $sub_slug ) = explode( '|', $combo, 2 );

		// A WORDPRESS TRAP. Some pages share one $_GET['page'] value with
		// another submenu and differ only by extra query arguments. WooCommerce
		// does this: "Home" has the slug `wc-admin`, while "Customers" and
		// "Analytics" have slugs of the form `wc-admin&path=/...`. When any of
		// them is opened, WordPress sees page=wc-admin for all of them.
		//
		// Remove the `wc-admin` entry with remove_submenu_page() and
		// get_admin_page_parent() can no longer find the page's parent, the
		// computed hook is never registered, and EVERY one of those pages
		// answers "Sorry, you are not allowed to access this page."
		//
		// So for a submenu other slugs depend on, the entry is hidden in CSS
		// rather than removed: visually identical, and the sibling pages keep
		// working.
		$has_dependents = false;

		foreach ( $all_sub_slugs as $other_slug ) {
			if ( $other_slug !== $sub_slug && 0 === strpos( $other_slug, $sub_slug . '&' ) ) {
				$has_dependents = true;
				break;
			}
		}

		if ( $has_dependents ) {

			$GLOBALS['dahu_role_manager_css_hidden_submenus'][] = $sub_slug;

			// Move the hidden entry to the end of the list. WordPress points
			// the parent menu's own link at its FIRST submenu, so if that is
			// the entry being hidden, the parent link would lead to a page the
			// user cannot see.
			if ( ! empty( $submenu[ $parent_slug ] ) && is_array( $submenu[ $parent_slug ] ) ) {
				foreach ( $submenu[ $parent_slug ] as $index => $item ) {
					if ( isset( $item[2] ) && $item[2] === $sub_slug ) {
						unset( $submenu[ $parent_slug ][ $index ] );
						$submenu[ $parent_slug ][] = $item;
						break;
					}
				}
			}

			continue;
		}

		if ( ! in_array( $parent_slug, $hide_menus, true ) ) {
			remove_submenu_page( $parent_slug, $sub_slug );
		}

		// Some plugins register a page as a submenu for the administrator but
		// promote it to a top-level menu for roles that cannot reach the parent
		// menu. Drop the top-level variant of the same slug too — a no-op when
		// it does not exist for this role.
		remove_menu_page( $sub_slug );
	}
}
add_action( 'admin_menu', 'dahu_role_manager_apply_menu_restrictions', 999 );

/**
 * Hide, in CSS, the "carrier" submenus collected above.
 *
 * @return void
 */
function dahu_role_manager_print_css_hidden_submenus() {

	if ( empty( $GLOBALS['dahu_role_manager_css_hidden_submenus'] )
		|| ! is_array( $GLOBALS['dahu_role_manager_css_hidden_submenus'] ) ) {
		return;
	}

	$selectors = array();

	foreach ( array_unique( $GLOBALS['dahu_role_manager_css_hidden_submenus'] ) as $slug ) {

		$href = ( false !== strpos( $slug, '.php' ) ) ? $slug : 'admin.php?page=' . $slug;

		// Scoped to .wp-submenu on purpose: the top-level parent link carries
		// the same href as its first submenu (WordPress rewrites it that way),
		// so without this scope, hiding "Home" would hide the whole menu it
		// belongs to.
		//
		// The :has() rule hides the entire <li> where the browser supports it;
		// the second rule hides at least the link everywhere else.
		$selectors[] = '#adminmenu .wp-submenu li:has(> a[href="' . esc_attr( $href ) . '"])';
		$selectors[] = '#adminmenu .wp-submenu a[href="' . esc_attr( $href ) . '"]';
	}

	echo '<style id="dahu-role-manager-css-hidden-submenus">'
		. implode( ', ', $selectors ) // Already escaped above.
		. '{display:none !important;}</style>' . "\n";
}
add_action( 'admin_head', 'dahu_role_manager_print_css_hidden_submenus' );

/**
 * Remove the admin-bar nodes hidden for the current user's roles.
 *
 * The admin-bar section IS available for the administrator role, unlike the
 * menu sections: hiding the admin bar takes no capability away and stays
 * reversible, because the admin sidebar is untouched, so Settings > Dahu Role
 * Manager remains reachable to switch it back off. Hiding sidebar menus could
 * hide that settings page itself, which is why those stay closed to the
 * administrator.
 *
 * @return void
 */
function dahu_role_manager_apply_adminbar_restrictions() {

	global $wp_admin_bar;

	if ( ! is_object( $wp_admin_bar ) ) {
		return;
	}

	list( , , $hide_adminbar ) = dahu_role_manager_get_user_hidden_items();

	foreach ( $hide_adminbar as $node_id ) {
		$wp_admin_bar->remove_node( $node_id );
	}
}
add_action( 'wp_before_admin_bar_render', 'dahu_role_manager_apply_adminbar_restrictions', 999 );

/**
 * Hide the admin bar entirely for the roles that asked for it.
 *
 * @param bool $show Whether to show the admin bar.
 * @return bool
 */
function dahu_role_manager_maybe_hide_admin_bar( $show ) {

	list( , , , $hide_full_adminbar ) = dahu_role_manager_get_user_hidden_items();

	if ( $hide_full_adminbar ) {
		return false;
	}

	return $show;
}
add_filter( 'show_admin_bar', 'dahu_role_manager_maybe_hide_admin_bar' );

/**
 * CSS safety net for hiding the admin bar entirely.
 *
 * Some plugins offset their own fixed header by the admin bar's height with a
 * hardcoded `top:32px`, without consulting the `show_admin_bar` filter.
 * WooCommerce's admin header (`.woocommerce-layout__header`) does this. So
 * #wpadminbar is forced out of the layout and those headers are pulled back up
 * to `top:0`.
 *
 * DO NOT put that header back to `position:absolute`, and do not hide
 * `.woocommerce-layout__activity-panel-wrapper`. The notifications panel (the
 * bell) is a child of that header and positions itself relative to it:
 * changing its positioning mode, or hiding the wrapper, breaks the panel
 * completely — the button clicks but nothing renders and it cannot be closed
 * again. Only the vertical offset may be corrected.
 *
 * The rules are filterable, for a theme or plugin whose own fixed header needs
 * the same treatment:
 *
 *     add_filter(
 *         'dahu_role_manager_hidden_adminbar_css',
 *         function ( $rules ) {
 *             $rules['.my-plugin-header'] = 'top:0 !important;';
 *             return $rules;
 *         }
 *     );
 *
 * @return void
 */
function dahu_role_manager_output_full_adminbar_css() {

	list( , , , $hide_full_adminbar ) = dahu_role_manager_get_user_hidden_items();

	if ( ! $hide_full_adminbar ) {
		return;
	}

	$default_rules = array(
		'#wpadminbar'                   => 'display:none !important;',
		'html.wp-toolbar'               => 'padding-top:0 !important;',
		// WooCommerce's admin header offsets itself by the admin bar's height.
		'.woocommerce-layout__header'   => 'top:0 !important;',
	);

	/**
	 * Filters the CSS applied when the admin bar is hidden entirely.
	 *
	 * @param array<string,string> $rules CSS selector => declarations.
	 */
	$rules = apply_filters( 'dahu_role_manager_hidden_adminbar_css', $default_rules );

	if ( empty( $rules ) || ! is_array( $rules ) ) {
		return;
	}

	$css = '';

	foreach ( $rules as $selector => $declarations ) {
		if ( ! is_string( $selector ) || ! is_string( $declarations ) || '' === trim( $selector ) ) {
			continue;
		}
		$css .= esc_html( $selector ) . '{' . esc_html( $declarations ) . '}';
	}

	if ( '' === $css ) {
		return;
	}

	echo '<style id="dahu-role-manager-hide-full-adminbar">'
		. $css // Each selector and declaration escaped above.
		. '</style>' . "\n";
}
add_action( 'admin_head', 'dahu_role_manager_output_full_adminbar_css' );
add_action( 'wp_head', 'dahu_role_manager_output_full_adminbar_css' );

/**
 * Everything hidden for the current user, merged across all of their roles.
 *
 * A user with several roles gets the union of what each role hides: hiding is
 * always additive, so holding one more role never reveals something another
 * role hid.
 *
 * @return array{0:string[],1:string[],2:string[],3:bool} Menus, submenus,
 *         admin-bar nodes, and whether the admin bar goes entirely.
 */
function dahu_role_manager_get_user_hidden_items() {

	$settings           = get_option( DAHU_ROLE_MANAGER_OPTION, array() );
	$hide_menus         = array();
	$hide_submenus      = array();
	$hide_adminbar      = array();
	$hide_full_adminbar = false;

	if ( empty( $settings ) || ! is_array( $settings ) ) {
		return array( $hide_menus, $hide_submenus, $hide_adminbar, $hide_full_adminbar );
	}

	$user = wp_get_current_user();

	if ( ! $user || empty( $user->roles ) ) {
		return array( $hide_menus, $hide_submenus, $hide_adminbar, $hide_full_adminbar );
	}

	foreach ( $user->roles as $role_slug ) {

		if ( empty( $settings[ $role_slug ] ) ) {
			continue;
		}

		$role_settings = $settings[ $role_slug ];

		if ( ! empty( $role_settings['menus'] ) && is_array( $role_settings['menus'] ) ) {
			$hide_menus = array_merge( $hide_menus, $role_settings['menus'] );
		}

		if ( ! empty( $role_settings['submenus'] ) && is_array( $role_settings['submenus'] ) ) {
			$hide_submenus = array_merge( $hide_submenus, $role_settings['submenus'] );
		}

		if ( ! empty( $role_settings['adminbar'] ) && is_array( $role_settings['adminbar'] ) ) {
			$hide_adminbar = array_merge( $hide_adminbar, $role_settings['adminbar'] );
		}

		if ( ! empty( $role_settings['hide_adminbar_full'] ) ) {
			$hide_full_adminbar = true;
		}
	}

	return array(
		array_unique( $hide_menus ),
		array_unique( $hide_submenus ),
		array_unique( $hide_adminbar ),
		$hide_full_adminbar,
	);
}

/**
 * The saved settings of every role the current user holds.
 *
 * Roles with nothing saved are left out. Shared helper for the per-role
 * features.
 *
 * @return array<string,array<string,mixed>> Role slug => settings.
 */
function dahu_role_manager_get_current_user_role_settings() {

	$settings = get_option( DAHU_ROLE_MANAGER_OPTION, array() );

	if ( empty( $settings ) || ! is_array( $settings ) ) {
		return array();
	}

	$user = wp_get_current_user();

	if ( ! $user || empty( $user->roles ) ) {
		return array();
	}

	$result = array();

	foreach ( $user->roles as $role_slug ) {
		if ( ! empty( $settings[ $role_slug ] ) && is_array( $settings[ $role_slug ] ) ) {
			$result[ $role_slug ] = $settings[ $role_slug ];
		}
	}

	return $result;
}

/**
 * Add a "Log out" entry at the very bottom of the admin menu.
 *
 * Per-role option `show_logout_menu`. Useful when the admin bar is hidden
 * entirely for that role, since the usual log-out link goes with it. Available
 * for the administrator role too: this option ADDS a menu entry, it takes none
 * away, and it is the natural companion to hiding the admin bar.
 *
 * @return void
 */
function dahu_role_manager_add_logout_menu() {

	$user = wp_get_current_user();

	if ( ! $user || empty( $user->roles ) ) {
		return;
	}

	$settings = get_option( DAHU_ROLE_MANAGER_OPTION, array() );

	if ( empty( $settings ) || ! is_array( $settings ) ) {
		return;
	}

	$show_logout = false;

	foreach ( $user->roles as $role_slug ) {
		if ( ! empty( $settings[ $role_slug ]['show_logout_menu'] ) ) {
			$show_logout = true;
			break;
		}
	}

	if ( ! $show_logout ) {
		return;
	}

	// A slug holding a full URL is output as the href as-is by WordPress: the
	// standard way to put an external link in the admin menu. Position 99999
	// puts it right at the bottom, below the existing separators.
	global $menu;

	$menu[99999] = array(
		__( 'Log out', 'dahu-role-manager' ),
		'read',
		wp_logout_url(),
		'',
		'menu-top dahu-role-manager-logout',
		'dahu-role-manager-logout',
		'dashicons-exit',
	);
}
add_action( 'admin_menu', 'dahu_role_manager_add_logout_menu', 1000 );

/**
 * The landing page configured for a user, if any.
 *
 * Per-role option `start_menu`: sends the user to a chosen menu or submenu
 * instead of the dashboard, both at login and if they open the dashboard
 * directly. Useful when the dashboard itself is hidden from their menu.
 *
 * @param WP_User|null $user The user to look up.
 * @return string The stored menu value, or an empty string.
 */
function dahu_role_manager_get_start_menu_for_user( $user ) {

	if ( ! $user || empty( $user->roles ) || ! is_array( $user->roles ) ) {
		return '';
	}

	$settings = get_option( DAHU_ROLE_MANAGER_OPTION, array() );

	if ( empty( $settings ) || ! is_array( $settings ) ) {
		return '';
	}

	foreach ( $user->roles as $role_slug ) {
		if ( ! empty( $settings[ $role_slug ]['start_menu'] ) ) {
			return $settings[ $role_slug ]['start_menu'];
		}
	}

	return '';
}

/**
 * Build the admin URL for a stored menu value.
 *
 * Accepts a menu slug ("slug") or a submenu ("parent_slug|sub_slug"), and
 * reproduces the way WordPress itself builds admin menu links.
 *
 * @param string $value Stored menu value.
 * @return string Admin URL.
 */
function dahu_role_manager_build_start_menu_url( $value ) {

	if ( '' === $value ) {
		return admin_url();
	}

	if ( false !== strpos( $value, '|' ) ) {

		list( $parent_slug, $sub_slug ) = explode( '|', $value, 2 );

		if ( false !== strpos( $sub_slug, '.php' ) ) {
			return admin_url( $sub_slug );
		}

		$parent_file = ( false !== strpos( $parent_slug, '.php' ) ) ? $parent_slug : 'admin.php';

		return admin_url( add_query_arg( 'page', $sub_slug, $parent_file ) );
	}

	if ( false !== strpos( $value, '.php' ) ) {
		return admin_url( $value );
	}

	return admin_url( 'admin.php?page=' . $value );
}

/**
 * Send the user to their landing page after logging in.
 *
 * A redirect the user (or a link) asked for explicitly always wins, so this
 * never overrides "you were going here before you had to log in".
 *
 * @param string  $redirect_to           Where WordPress intends to send them.
 * @param string  $requested_redirect_to Where the request asked to go.
 * @param WP_User $user                  The user who just logged in.
 * @return string
 */
function dahu_role_manager_login_redirect( $redirect_to, $requested_redirect_to, $user ) {

	if ( ! empty( $requested_redirect_to ) ) {
		return $redirect_to;
	}

	if ( ! ( $user instanceof WP_User ) || in_array( 'administrator', (array) $user->roles, true ) ) {
		return $redirect_to;
	}

	$start_menu = dahu_role_manager_get_start_menu_for_user( $user );

	if ( '' === $start_menu ) {
		return $redirect_to;
	}

	return dahu_role_manager_build_start_menu_url( $start_menu );
}
add_filter( 'login_redirect', 'dahu_role_manager_login_redirect', 10, 3 );

/**
 * Send the user to their landing page if they open the dashboard directly.
 *
 * @return void
 */
function dahu_role_manager_redirect_dashboard_to_start_menu() {

	global $pagenow;

	if ( 'index.php' !== $pagenow || isset( $_GET['page'] ) || current_user_can( 'administrator' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only presence check, no state changed.
		return;
	}

	$start_menu = dahu_role_manager_get_start_menu_for_user( wp_get_current_user() );

	if ( '' === $start_menu ) {
		return;
	}

	wp_safe_redirect( dahu_role_manager_build_start_menu_url( $start_menu ) );
	exit;
}
add_action( 'admin_init', 'dahu_role_manager_redirect_dashboard_to_start_menu' );

/**
 * Hide back-office elements by CSS selector, per role.
 *
 * Each role carries its own list of selectors (`hide_classes`). Useful for the
 * persistent notices third-party plugins leave behind — "please upgrade" bars,
 * spurious warnings and the like. A user holding several roles gets the
 * selectors of all of them.
 *
 * Nothing is removed from the page: this only hides, so whatever the selector
 * matches is still there for anything that needs it.
 *
 * @return void
 */
function dahu_role_manager_output_hidden_classes_css() {

	$selectors = array();

	foreach ( dahu_role_manager_get_current_user_role_settings() as $role_settings ) {
		if ( ! empty( $role_settings['hide_classes'] ) && is_array( $role_settings['hide_classes'] ) ) {
			$selectors = array_merge( $selectors, $role_settings['hide_classes'] );
		}
	}

	$selectors = array_unique( array_filter( array_map( 'trim', $selectors ) ) );

	if ( empty( $selectors ) ) {
		return;
	}

	echo '<style id="dahu-role-manager-hidden-classes">'
		. implode( ', ', array_map( 'esc_html', $selectors ) )
		. ' { display: none !important; }</style>' . "\n";
}
add_action( 'admin_head', 'dahu_role_manager_output_hidden_classes_css' );
