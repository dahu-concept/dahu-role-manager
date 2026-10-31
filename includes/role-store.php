<?php
/**
 * Reading the stored roles straight from the database.
 *
 * WordPress keeps every role in one option, and `WP_Roles` holds a copy of it
 * in memory, filled from the object cache. Every write of that option — a role
 * created, renamed or deleted, a capability added — rewrites the WHOLE option
 * from that copy. So a copy that is out of date does not merely fail to see a
 * role: it ERASES it, because the role is absent from the array being written
 * back.
 *
 * A stale copy is not hypothetical on a site with a persistent object cache
 * (Redis, Memcached): a cache replayed from an old dump, or a concurrent write
 * from another request, is enough. The helpers below let the plugin start its
 * own writes from what the database really holds.
 *
 * Scope: this reduces the risk that a write FROM THIS PLUGIN starts from a
 * stale copy. It cannot protect the option from anything else on the site.
 *
 * @package DahuRoleManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Name of the option holding the roles.
 *
 * `WP_Roles::$role_key` is the authority when the object exists; the blog
 * prefix is the same value WordPress itself computes.
 *
 * @return string
 */
function dahu_role_manager_roles_option_name() {

	global $wp_roles, $wpdb;

	if ( isset( $wp_roles ) && is_object( $wp_roles ) && ! empty( $wp_roles->role_key ) ) {
		return (string) $wp_roles->role_key;
	}

	return $wpdb->get_blog_prefix() . 'user_roles';
}

/**
 * Read the roles option from the database, bypassing every cache.
 *
 * @return array<string,mixed> The stored roles, or an empty array.
 */
function dahu_role_manager_read_roles_db() {

	global $wpdb;

	/*
	 * A direct query on purpose: get_option() is served from the object cache,
	 * and the whole point here is to see past it. No caching of the result
	 * either — it is read once, right before a write.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberate cache bypass, see above.
	$raw = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			dahu_role_manager_roles_option_name()
		)
	);

	if ( null === $raw ) {
		return array();
	}

	$value = maybe_unserialize( $raw );

	return is_array( $value ) ? $value : array();
}

/**
 * Drop every cache entry that could serve a stale copy of the roles option.
 *
 * `alloptions` and `notoptions` are dropped too: the roles option is loaded on
 * every request and usually travels inside `alloptions`.
 *
 * @return void
 */
function dahu_role_manager_purge_roles_cache() {

	$option = dahu_role_manager_roles_option_name();

	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( $option, 'options' );
}

/**
 * Refill the in-memory roles from the database.
 *
 * Call this JUST BEFORE writing the roles option, never as a precaution on a
 * read path: it costs one query and one cache purge.
 *
 * Returns false when the database holds no roles at all. That is not a site
 * with no roles — it is a read that went wrong, and writing the option from an
 * empty set would wipe every role on the site. A caller that gets false must
 * refuse to write.
 *
 * @return bool Whether the reloaded roles can be written from.
 */
function dahu_role_manager_reload_roles_from_db() {

	$roles   = wp_roles();
	$site_id = get_current_blog_id();

	/*
	 * ALIGN THE OBJECT ON THIS SITE BEFORE READING, AND SAY WHICH SITE.
	 *
	 * `for_site()` with no argument recalibrates `role_key` on the current
	 * site. Reading first therefore risked reading site A's option while the
	 * write that follows went to site B, so the guard would validate against
	 * the wrong network site. Reachable when a network plugin switches blogs
	 * during `plugins_loaded` without restoring before `init`: before `init`,
	 * `switch_to_blog()` does not recalibrate `role_key` by itself.
	 */
	if ( is_object( $roles ) && method_exists( $roles, 'for_site' ) ) {
		$roles->for_site( $site_id );
	}

	$db_roles = dahu_role_manager_read_roles_db();

	dahu_role_manager_purge_roles_cache();

	// And again, to load back what the database really holds now that the
	// cache no longer stands in the way.
	if ( is_object( $roles ) && method_exists( $roles, 'for_site' ) ) {
		$roles->for_site( $site_id );
	}

	return ! empty( $db_roles );
}

/**
 * Does the database copy of a role already grant a capability?
 *
 * @param array<string,mixed> $db_roles  Roles as read from the database.
 * @param string              $role_slug Role to look at.
 * @param string              $cap       Capability to look for.
 * @return bool
 */
function dahu_role_manager_db_role_has_cap( $db_roles, $role_slug, $cap ) {

	if ( ! isset( $db_roles[ $role_slug ]['capabilities'] ) || ! is_array( $db_roles[ $role_slug ]['capabilities'] ) ) {
		return false;
	}

	$caps = $db_roles[ $role_slug ]['capabilities'];

	return isset( $caps[ $cap ] ) && $caps[ $cap ];
}

/**
 * Keep the stored role slugs that the live roles do not know about.
 *
 * A list of role slugs is saved from a screen that only draws a checkbox for
 * a role `wp_roles()` knows. While a role is missing from the in-memory copy,
 * its box is not drawn, so it is not submitted, so the strict validation drops
 * it from the list: the role comes back with its capabilities but without its
 * place in the list, silently.
 *
 * A slug ALREADY STORED and unknown to `wp_roles()` is therefore kept. A role
 * that is known and was unchecked is still removed — that is a real choice —
 * and so is one the Roles tab deletes.
 *
 * @param string[] $clean  Slugs that survived validation.
 * @param mixed    $stored Slugs stored before this save.
 * @return string[]
 */
function dahu_role_manager_keep_stored_unknown_roles( $clean, $stored ) {

	if ( ! is_array( $stored ) ) {
		return $clean;
	}

	$known = array_keys( wp_roles()->roles );

	foreach ( $stored as $slug ) {

		if ( ! is_string( $slug ) || '' === $slug ) {
			continue;
		}

		if ( in_array( $slug, $known, true ) ) {
			continue;
		}

		if ( ! in_array( $slug, $clean, true ) ) {
			$clean[] = $slug;
		}
	}

	return array_values( array_unique( $clean ) );
}
