<?php
/**
 * Which accounts a role may manage.
 *
 * Each "manager" role can be given the list of account roles it may view, edit,
 * create and delete (`sm_editable_roles` in its settings). That lets a site run
 * several manager roles side by side, each with its own scope. The `customer`
 * role is always implicitly editable, matching WooCommerce's own behaviour, and
 * is never stored.
 *
 * HOW IT IS APPLIED
 *
 * - For `shop_manager`: through WooCommerce's own filter
 *   `woocommerce_shop_manager_editable_roles`, which WooCommerce uses both for
 *   `editable_roles` and for `map_meta_cap` on individual users.
 * - For any other configured role: through core's `editable_roles` filter
 *   (narrowed to the configured list plus customer) and a `map_meta_cap`
 *   equivalent to WooCommerce's, so editing an out-of-scope account directly by
 *   URL is blocked as well.
 *
 * This file governs WHO is editable. The POWER to edit — the list_users,
 * edit_users and related capabilities — is granted here too, but only as the
 * direct consequence of a scope being configured; see
 * dahu_role_manager_get_account_management_general_caps().
 *
 * @package DahuRoleManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Roles that must never appear in the list, nor be storable as editable, even
 * through a forged POST.
 *
 * The administrator is excluded because letting a manager edit administrators
 * is a privilege-escalation path, and shop_manager because WooCommerce already
 * governs it. Sites with their own high-privilege roles can add them:
 *
 *     add_filter(
 *         'dahu_role_manager_excluded_editable_roles',
 *         function ( $roles ) {
 *             $roles[] = 'my_super_admin';
 *             return $roles;
 *         }
 *     );
 *
 * @return string[]
 */
function dahu_role_manager_get_sm_excluded_roles() {

	/**
	 * Filters the roles that can never be managed by another role.
	 *
	 * @param string[] $roles Role slugs.
	 */
	$roles = apply_filters(
		'dahu_role_manager_excluded_editable_roles',
		array( 'administrator', 'shop_manager' )
	);

	return array_values( array_unique( array_filter( (array) $roles, 'is_string' ) ) );
}

/**
 * Can this role be given an editable-accounts scope at all?
 *
 * The roles below are the ones with no business managing accounts, whatever the
 * settings say — enforced in the interface, when saving, and on import.
 *
 * @param string $role_slug Role slug.
 * @return bool
 */
function dahu_role_manager_role_can_manage_accounts( $role_slug ) {

	/**
	 * Filters the roles that may never manage accounts.
	 *
	 * @param string[] $forbidden Role slugs.
	 */
	$forbidden = apply_filters(
		'dahu_role_manager_roles_barred_from_managing_accounts',
		array( 'contributor', 'author', 'subscriber', 'customer' )
	);

	return ! in_array( $role_slug, (array) $forbidden, true );
}

/**
 * The union of the scopes configured on the current user's roles.
 *
 * Roles not allowed to carry the option are skipped. An empty result means no
 * scope is configured, and nothing about the native behaviour changes.
 *
 * @return string[]
 */
function dahu_role_manager_get_sm_editable_for_current_user() {

	$union = array();

	foreach ( dahu_role_manager_get_current_user_role_settings() as $role_slug => $role_settings ) {

		if ( ! dahu_role_manager_role_can_manage_accounts( $role_slug ) ) {
			continue;
		}

		if ( ! empty( $role_settings['sm_editable_roles'] ) && is_array( $role_settings['sm_editable_roles'] ) ) {
			$union = array_merge( $union, $role_settings['sm_editable_roles'] );
		}
	}

	return array_values( array_unique( $union ) );
}

/**
 * Strict allow-list validation of a submitted scope.
 *
 * A slug survives only if it names a role that really is registered and is not
 * excluded. Anything else is dropped silently.
 *
 * The slug is NOT passed through sanitize_key() first: that lowercases and
 * strips characters, so a role slug containing capitals or unusual characters
 * — common with roles created by third-party role editors — would no longer
 * match its real key in wp_roles() and would be rejected every time. The strict
 * comparison against the roles that actually exist IS the sanitization: a value
 * matching an existing, non-excluded role exactly is safe by definition.
 *
 * @param mixed $raw Submitted value.
 * @return string[]
 */
function dahu_role_manager_sanitize_sm_editable_roles( $raw ) {

	if ( ! is_array( $raw ) ) {
		return array();
	}

	$excluded    = dahu_role_manager_get_sm_excluded_roles();
	$valid_roles = array_keys( wp_roles()->roles );
	$clean       = array();

	foreach ( $raw as $slug ) {

		if ( ! is_string( $slug ) ) {
			continue;
		}

		if ( '' === $slug || in_array( $slug, $excluded, true ) || ! in_array( $slug, $valid_roles, true ) ) {
			continue;
		}

		$clean[] = $slug;
	}

	return array_values( array_unique( $clean ) );
}

/**
 * Register WooCommerce's filter only when WooCommerce is actually active.
 *
 * @return void
 */
function dahu_role_manager_maybe_register_sm_editable_roles_filter() {

	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	add_filter( 'woocommerce_shop_manager_editable_roles', 'dahu_role_manager_add_sm_editable_roles' );
}
add_action( 'plugins_loaded', 'dahu_role_manager_maybe_register_sm_editable_roles_filter' );

/**
 * Widen the shop manager's editable roles with the configured scope.
 *
 * Roles are only ever ADDED here: 'customer' and whatever WooCommerce already
 * allowed are never removed.
 *
 * @param array $roles Roles WooCommerce considers editable.
 * @return array
 */
function dahu_role_manager_add_sm_editable_roles( $roles ) {

	$extra_roles = dahu_role_manager_get_sm_editable_for_current_user();

	if ( empty( $extra_roles ) ) {
		return $roles;
	}

	return array_values( array_unique( array_merge( $roles, $extra_roles ) ) );
}

/**
 * Narrow the assignable roles for manager roles other than shop_manager.
 *
 * WooCommerce already handles shop_manager through its own filter, fed above.
 * Does nothing when no scope is configured for the current user, so the native
 * WordPress behaviour is kept.
 *
 * @param array $all_roles Roles core considers editable.
 * @return array
 */
function dahu_role_manager_filter_editable_roles( $all_roles ) {

	if ( current_user_can( 'administrator' ) ) {
		return $all_roles;
	}

	$user = wp_get_current_user();

	if ( ! $user || empty( $user->roles ) || in_array( 'shop_manager', (array) $user->roles, true ) ) {
		return $all_roles;
	}

	$union = dahu_role_manager_get_sm_editable_for_current_user();

	if ( empty( $union ) ) {
		return $all_roles;
	}

	$allowed = array_merge( $union, array( 'customer' ) );

	return array_intersect_key( $all_roles, array_flip( $allowed ) );
}
add_filter( 'editable_roles', 'dahu_role_manager_filter_editable_roles', 99 );

/**
 * The "primitive" capabilities needed to manage accounts at all.
 *
 * As opposed to the "meta" capabilities that apply to one specific user
 * (edit_user, delete_user, promote_user). These are granted dynamically as soon
 * as a scope is configured, so configuring who a role may edit also gives it
 * the means to do so — otherwise the scope would be set and nothing would work.
 *
 *   list_users, edit_users  see the user list and the edit screen
 *   create_users            create accounts
 *   delete_users            show the "Delete" link (the actual deletion stays
 *                           bounded to the scope by the delete_user meta cap)
 *   promote_users           show the "Change role to…" dropdown (the promotion
 *                           stays bounded by promote_user)
 *
 * @return string[]
 */
function dahu_role_manager_get_account_management_general_caps() {
	return array( 'list_users', 'edit_users', 'create_users', 'delete_users', 'promote_users' );
}

/**
 * Grant the capabilities above when a scope is configured.
 *
 * Returning an empty array grants the capability unconditionally; see
 * WP_User::has_cap().
 *
 * @param array $caps    Capabilities required.
 * @param int   $user_id The user being tested.
 * @return array
 */
function dahu_role_manager_maybe_grant_account_management_cap( $caps, $user_id ) {

	$user = get_userdata( $user_id );

	if ( ! dahu_role_manager_user_has_editable_scope( $user ) ) {
		return $caps;
	}

	return array();
}

/**
 * Does this user hold a manager role with a non-empty scope?
 *
 * The administrator is excluded, having all of this natively. This is the single
 * condition behind granting the account-management capabilities.
 *
 * @param WP_User|false|null $user The user to test.
 * @return bool
 */
function dahu_role_manager_user_has_editable_scope( $user ) {

	if ( ! $user || empty( $user->roles ) || in_array( 'administrator', (array) $user->roles, true ) ) {
		return false;
	}

	$settings = get_option( DAHU_ROLE_MANAGER_OPTION, array() );

	if ( ! is_array( $settings ) ) {
		return false;
	}

	foreach ( (array) $user->roles as $role_slug ) {

		if ( ! dahu_role_manager_role_can_manage_accounts( $role_slug ) ) {
			continue;
		}

		if ( ! empty( $settings[ $role_slug ]['sm_editable_roles'] )
			&& is_array( $settings[ $role_slug ]['sm_editable_roles'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Inject the primitive account-management capabilities into `allcaps`.
 *
 * WHY THIS IS NEEDED — the meta-cap versus primitive-cap trap. The meta
 * capabilities `delete_user` and `promote_user` (singular, about one account)
 * are translated by core into the primitive capabilities `delete_users` and
 * `promote_users` (plural), which are then looked up DIRECTLY in
 * WP_User::$allcaps, without going back through map_meta_cap. Granting them via
 * map_meta_cap (by returning an empty array) therefore only covers the
 * capabilities asked for by their primitive name: list_users, edit_users,
 * create_users, and showing the buttons in the table. For a deletion or a role
 * change to actually go through, the primitive has to exist in `allcaps` —
 * which is what this filter does.
 *
 * The scope restriction is enforced separately and still holds: map_meta_cap
 * adds `do_not_allow` for an out-of-scope account, and `do_not_allow` is never
 * present in `allcaps`, so the primitive granted here does not short-circuit
 * the per-account restriction.
 *
 * @param array   $allcaps All capabilities of the user.
 * @param array   $caps    Capabilities being checked.
 * @param array   $args    Arguments of the check.
 * @param WP_User $user    The user being tested.
 * @return array
 */
function dahu_role_manager_grant_account_primitive_caps( $allcaps, $caps, $args, $user ) {

	$general = dahu_role_manager_get_account_management_general_caps();

	// Only do the work when one of ours is actually being asked for.
	if ( empty( array_intersect( (array) $caps, $general ) ) ) {
		return $allcaps;
	}

	if ( ! dahu_role_manager_user_has_editable_scope( $user ) ) {
		return $allcaps;
	}

	foreach ( $general as $cap ) {
		$allcaps[ $cap ] = true;
	}

	return $allcaps;
}
add_filter( 'user_has_cap', 'dahu_role_manager_grant_account_primitive_caps', 10, 4 );

/**
 * WooCommerce's map_meta_cap equivalent, for every manager role.
 *
 * Blocks editing, deleting or promoting — directly by URL included — an account
 * whose role falls outside the configured scope. Applies to ALL manager roles,
 * shop_manager included, because WooCommerce bounds only edit_user, remove_user
 * and promote_user, not delete_user: since the primitive delete_users is
 * granted above, without this restriction a shop manager could delete any
 * account at all, administrators included. For edit and promote the check is
 * redundant with WooCommerce's, and harmless.
 *
 * No effect for the administrator, nor for users with no scope configured.
 *
 * @param array  $caps    Primitive capabilities required.
 * @param string $cap     The capability being mapped.
 * @param int    $user_id The user being tested.
 * @param array  $args    Arguments; $args[0] is the target user id.
 * @return array
 */
function dahu_role_manager_map_meta_cap_editable( $caps, $cap, $user_id, $args ) {

	if ( in_array( $cap, dahu_role_manager_get_account_management_general_caps(), true ) ) {
		return dahu_role_manager_maybe_grant_account_management_cap( $caps, $user_id );
	}

	if ( ! in_array( $cap, array( 'edit_user', 'delete_user', 'promote_user' ), true ) || empty( $args[0] ) ) {
		return $caps;
	}

	$current_user = get_userdata( $user_id );

	if ( ! $current_user || empty( $current_user->roles ) ) {
		return $caps;
	}

	$current_roles = (array) $current_user->roles;

	if ( in_array( 'administrator', $current_roles, true ) ) {
		return $caps;
	}

	// The scope configured for THIS user, who is not necessarily the current
	// one: map_meta_cap can be called for any user id.
	$settings = get_option( DAHU_ROLE_MANAGER_OPTION, array() );
	$union    = array();

	if ( is_array( $settings ) ) {
		foreach ( $current_roles as $role_slug ) {

			if ( ! dahu_role_manager_role_can_manage_accounts( $role_slug ) ) {
				continue;
			}

			if ( ! empty( $settings[ $role_slug ]['sm_editable_roles'] )
				&& is_array( $settings[ $role_slug ]['sm_editable_roles'] ) ) {
				$union = array_merge( $union, $settings[ $role_slug ]['sm_editable_roles'] );
			}
		}
	}

	if ( empty( $union ) ) {
		return $caps;
	}

	// A user can always act on their own account.
	if ( (int) $args[0] === (int) $user_id ) {
		return $caps;
	}

	$target = get_userdata( (int) $args[0] );

	if ( ! $target ) {
		return $caps;
	}

	$allowed = array_merge( $union, array( 'customer' ) );

	foreach ( (array) $target->roles as $target_role ) {
		if ( ! in_array( $target_role, $allowed, true ) ) {
			$caps[] = 'do_not_allow';
			break;
		}
	}

	return $caps;
}
add_filter( 'map_meta_cap', 'dahu_role_manager_map_meta_cap_editable', 99, 4 );
