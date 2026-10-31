<?php
/**
 * CSV export and import of every per-role setting.
 *
 * FORMAT: three columns, role,type,value
 *
 *   type  = menu | submenu | adminbar | sm_editable | hide_class
 *           | flag | start_menu
 *   value = a menu or admin-bar slug (menu, adminbar),
 *           "parent_slug|sub_slug" (submenu),
 *           the slug of a role this role may edit (sm_editable),
 *           a CSS selector (hide_class),
 *           the name of a boolean option that is on (flag),
 *           the landing page (start_menu).
 *
 * Every row carries its role in the first column, including the ones that
 * are not lists: `flag` names a boolean option that is enabled, and
 * `start_menu` carries the landing page. Without those two types an
 * export/import round trip would silently drop them, since an import is a
 * complete replacement rather than a merge.
 *
 * BACKWARD COMPATIBILITY: an `sm_editable` row with an empty role column
 * comes from an older global format and is assigned to `shop_manager`.
 *
 * @package DahuRoleManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * The boolean options each role carries.
 *
 * One list, shared by the export and the import, so the two cannot drift
 * apart as options are added.
 *
 * @return string[]
 */
function dahu_role_manager_get_boolean_role_keys() {
	return array(
		'hide_adminbar_full',
		'show_logout_menu',
		'lock_completed_orders',
	);
}

/**
 * Stream every setting out as a CSV download.
 *
 * @return void
 */
function dahu_role_manager_export_csv() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to export these settings.', 'dahu-role-manager' ) );
	}

	check_admin_referer( 'dahu_role_manager_export_csv' );

	$settings = get_option( DAHU_ROLE_MANAGER_OPTION, array() );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=dahu-role-manager-export-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	dahu_role_manager_write_csv_settings( $out, $settings );
	fclose( $out );
	exit;
}

/**
 * Write every setting to an open handle as CSV rows.
 *
 * Kept separate from the request handler for the same reason as the parser: the
 * format can then be exercised without sending headers or ending the request.
 *
 * @param resource $out      An open, writable handle.
 * @param mixed    $settings The stored settings.
 * @return void
 */
function dahu_role_manager_write_csv_settings( $out, $settings ) {

	fputcsv( $out, array( 'role', 'type', 'value' ) );

	if ( ! is_array( $settings ) ) {
		return;
	}

	$list_keys = array(
		'menus'             => 'menu',
		'submenus'          => 'submenu',
		'adminbar'          => 'adminbar',
		'sm_editable_roles' => 'sm_editable',
		'hide_classes'      => 'hide_class',
	);

	foreach ( $settings as $role_slug => $role_data ) {

		if ( ! is_array( $role_data ) ) {
			continue;
		}

		foreach ( $list_keys as $key => $type ) {

			if ( empty( $role_data[ $key ] ) || ! is_array( $role_data[ $key ] ) ) {
				continue;
			}

			foreach ( $role_data[ $key ] as $value ) {
				fputcsv( $out, array( $role_slug, $type, $value ) );
			}
		}

		// Boolean options go out as `flag` rows carrying the key name, the
		// landing page as a `start_menu` row.
		foreach ( dahu_role_manager_get_boolean_role_keys() as $key ) {
			if ( ! empty( $role_data[ $key ] ) ) {
				fputcsv( $out, array( $role_slug, 'flag', $key ) );
			}
		}

		if ( ! empty( $role_data['start_menu'] ) && is_string( $role_data['start_menu'] ) ) {
			fputcsv( $out, array( $role_slug, 'start_menu', $role_data['start_menu'] ) );
		}
	}
}
add_action( 'admin_post_dahu_role_manager_export_csv', 'dahu_role_manager_export_csv' );

/**
 * Parse an open CSV handle into a settings array.
 *
 * Kept separate from the request handler so the format can be exercised on its
 * own: the handler deals with the upload and the redirects, this deals with the
 * file. Returns null when the header is not the expected one.
 *
 * @param resource $handle An open, readable handle positioned at the start.
 * @return array<string,array<string,mixed>>|null
 */
function dahu_role_manager_parse_csv_settings( $handle ) {

	$header          = fgetcsv( $handle );
	$expected_header = array( 'role', 'type', 'value' );

	if ( ! $header || array_map( 'strtolower', array_map( 'trim', $header ) ) !== $expected_header ) {
		return null;
	}

	$new_settings = array();

	$type_to_key = array(
		'menu'        => 'menus',
		'submenu'     => 'submenus',
		'adminbar'    => 'adminbar',
		'sm_editable' => 'sm_editable_roles',
		'hide_class'  => 'hide_classes',
	);

	$sm_excluded_roles = dahu_role_manager_get_sm_excluded_roles();
	$valid_role_slugs  = array_keys( wp_roles()->roles );

	$empty_role_data = array(
		'menus'             => array(),
		'submenus'          => array(),
		'adminbar'          => array(),
		'sm_editable_roles' => array(),
		'hide_classes'      => array(),
	);

	while ( false !== ( $row = fgetcsv( $handle ) ) ) {

		if ( count( $row ) < 3 ) {
			continue;
		}

		list( $role_slug, $type, $value ) = $row;

		// Role slugs are NOT run through sanitize_key(): it lowercases and
		// strips characters, so a role created by a role editor with capitals
		// in its slug would no longer match any real key of wp_roles() and
		// its rows would be dropped. Slugs are compared against the real
		// list of roles further down instead, where that matters.
		$role_slug = trim( $role_slug );
		$type      = strtolower( trim( $type ) );
		$value     = sanitize_text_field( trim( $value ) );

		// ADMINISTRATOR GUARD: whatever the settings screen refuses to apply
		// to the administrator role, the import must refuse too — a CSV
		// edited by hand must never be able to restrict an administrator.
		// Only the two sections that take nothing away are open to it: CSS
		// hiding, and the completed-order lock (which an administrator can
		// always lift again from the same screen).
		if ( 'administrator' === $role_slug ) {

			$admin_allowed_flags = array(
				'lock_completed_orders',
				'hide_adminbar_full',
				'show_logout_menu',
			);

			$admin_allowed_types = array( 'hide_class', 'adminbar' );

			if ( 'flag' === $type ) {
				if ( ! in_array( $value, $admin_allowed_flags, true ) ) {
					continue;
				}
			} elseif ( ! in_array( $type, $admin_allowed_types, true ) ) {
				continue;
			}
		}

		// Boolean options and the landing page are not lists, so they do not
		// go through $type_to_key.
		if ( 'flag' === $type || 'start_menu' === $type ) {

			if ( '' === $role_slug || '' === $value
				|| ! in_array( $role_slug, $valid_role_slugs, true ) ) {
				continue;
			}

			if ( ! isset( $new_settings[ $role_slug ] ) ) {
				$new_settings[ $role_slug ] = $empty_role_data;
			}

			if ( 'flag' === $type ) {
				if ( in_array( $value, dahu_role_manager_get_boolean_role_keys(), true ) ) {
					$new_settings[ $role_slug ][ $value ] = true;
				}
			} else {
				$new_settings[ $role_slug ]['start_menu'] = $value;
			}

			continue;
		}

		if ( ! isset( $type_to_key[ $type ] ) || '' === $value ) {
			continue;
		}

		// Older global format: an sm_editable row with no role belongs to the
		// shop manager.
		if ( 'sm_editable' === $type && '' === $role_slug ) {
			$role_slug = 'shop_manager';
		}

		if ( '' === $role_slug ) {
			continue;
		}

		if ( 'sm_editable' === $type ) {
			// Same allow-list as saving through the screen, and the role
			// carrying the row must itself be allowed to manage accounts.
			if ( in_array( $value, $sm_excluded_roles, true )
				|| ! in_array( $value, $valid_role_slugs, true )
				|| ! dahu_role_manager_role_can_manage_accounts( $role_slug ) ) {
				continue;
			}
		}

		$key = $type_to_key[ $type ];

		if ( ! isset( $new_settings[ $role_slug ] ) ) {
			$new_settings[ $role_slug ] = $empty_role_data;
		}

		$new_settings[ $role_slug ][ $key ][] = $value;
	}

	// Deduplicate the list keys only: a role also carries booleans and a
	// string, which must not be passed to array_unique().
	foreach ( $new_settings as $role_slug => $role_data ) {
		foreach ( $role_data as $key => $values ) {
			if ( is_array( $values ) ) {
				$new_settings[ $role_slug ][ $key ] = array_values( array_unique( $values ) );
			}
		}
	}

	return $new_settings;
}

/**
 * Read an uploaded CSV back in, replacing every setting.
 *
 * An import is a COMPLETE replacement, not a merge: anything absent from the
 * file ends up cleared. That is deliberate — it makes the file the single
 * description of the configuration, so the same CSV always produces the same
 * result whatever the site started from.
 *
 * @return void
 */
function dahu_role_manager_import_csv() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to import these settings.', 'dahu-role-manager' ) );
	}

	check_admin_referer( 'dahu_role_manager_import_csv' );

	// Back to the tab the request came from.
	$redirect_base = admin_url( 'options-general.php?page=' . DAHU_ROLE_MANAGER_SLUG . '&tab=csv' );

	$upload = isset( $_FILES['dahu_role_manager_csv_file'] ) ? $_FILES['dahu_role_manager_csv_file'] : array();

	if ( empty( $upload['tmp_name'] )
		|| ! is_uploaded_file( sanitize_text_field( wp_unslash( $upload['tmp_name'] ) ) )
		|| ( isset( $upload['error'] ) && UPLOAD_ERR_OK !== (int) $upload['error'] ) ) {
		wp_safe_redirect( add_query_arg( 'dahu_role_manager_import', 'error_no_file', $redirect_base ) );
		exit;
	}

	$tmp_file = sanitize_text_field( wp_unslash( $upload['tmp_name'] ) );
	$handle   = fopen( $tmp_file, 'r' );

	if ( ! $handle ) {
		wp_safe_redirect( add_query_arg( 'dahu_role_manager_import', 'error_read', $redirect_base ) );
		exit;
	}

	$parsed = dahu_role_manager_parse_csv_settings( $handle );
	fclose( $handle );

	if ( null === $parsed ) {
		wp_safe_redirect( add_query_arg( 'dahu_role_manager_import', 'error_format', $redirect_base ) );
		exit;
	}

	update_option( DAHU_ROLE_MANAGER_OPTION, $parsed );

	wp_safe_redirect( add_query_arg( 'dahu_role_manager_import', 'success', $redirect_base ) );
	exit;
}
add_action( 'admin_post_dahu_role_manager_import_csv', 'dahu_role_manager_import_csv' );
