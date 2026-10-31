<?php
/**
 * Role management: duplicate, rename, delete.
 *
 * @package DahuRoleManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reserved slugs: the WordPress built-in roles, never overwritten nor deleted
 * from this tab.
 *
 * @return string[]
 */
function dahu_role_manager_get_reserved_role_slugs() {
    return ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
}

/**
 * Roles protected from renaming and deletion.
 *
 * The WordPress built-ins, the two roles WooCommerce relies on (customer,
 * shop_manager), and whatever the site has set as its default role for new
 * registrations — deleting that one would leave new accounts role-less.
 * Everything else counts as a custom role and is fair game.
 *
 * @return string[]
 */
function dahu_role_manager_get_protected_role_slugs() {
    $protected = array_merge(
        dahu_role_manager_get_reserved_role_slugs(),
        ['shop_manager', 'customer']
    );

    $default_role = get_option('default_role');
    if ($default_role) {
        $protected[] = $default_role;
    }

    return array_values(array_unique($protected));
}

/**
 * Can this role be renamed or deleted from this tab?
 *
 * @param string $role_slug Role slug.
 * @return bool
 */
function dahu_role_manager_role_is_manageable($role_slug) {
    return !in_array($role_slug, dahu_role_manager_get_protected_role_slugs(), true);
}

/**
 * How many users currently hold a role.
 *
 * @param string $role_slug Role slug.
 * @return int
 */
function dahu_role_manager_count_users_for_role($role_slug) {
    $counts = count_users();
    if (empty($counts['avail_roles']) || !isset($counts['avail_roles'][$role_slug])) {
        return 0;
    }
    return (int) $counts['avail_roles'][$role_slug];
}

/**
 * Leave a timestamped trace of role creations, renames and deletions.
 *
 * Roles are shared, site-wide structure, and several plugins can create or
 * remove them. When one goes missing, there is usually nothing to go on. Every
 * role touched through this tab therefore leaves a line in the PHP log, which
 * is where a diagnosis starts.
 *
 * Only written when WP_DEBUG_LOG is on, so a production site gets no noise.
 *
 * @param string $action    One of create, rename, delete.
 * @param string $role_slug The role concerned.
 * @param string $details   Free-form extra context.
 * @return void
 */
function dahu_role_manager_log_role_action($action, $role_slug, $details = '') {

    if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
        return;
    }

    $user = wp_get_current_user();
    $login = ($user && !empty($user->user_login)) ? $user->user_login : '(unknown)';

    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate audit trail, gated on WP_DEBUG_LOG.
    error_log(sprintf(
        '[Dahu Role Manager v%s] %s UTC | action=%s | role=%s | by=%s%s',
        DAHU_ROLE_MANAGER_VERSION,
        gmdate('Y-m-d H:i:s'),
        $action,
        $role_slug,
        $login,
        $details !== '' ? ' | ' . $details : ''
    ));
}

/**
 * URL back to the Roles tab, with an optional message code and role slug.
 *
 * @param string $message Message code to show on arrival.
 * @param string $slug    Role the message is about.
 * @return string
 */
function dahu_role_manager_manage_redirect_url($message = '', $slug = '') {
    $url = admin_url('options-general.php?page=' . DAHU_ROLE_MANAGER_SLUG . '&tab=roles');
    if ($message !== '') {
        $url = add_query_arg('dahu_role_manager_role_msg', $message, $url);
    }
    if ($slug !== '') {
        $url = add_query_arg('dahu_role_manager_role_ref', rawurlencode($slug), $url);
    }
    return $url;
}

/**
 * Create a role by copying another role's capabilities wholesale.
 *
 * Not one capability is added or removed: fine-tuning is left to whatever role
 * editor the site already uses. Copying is the part that is tedious by hand.
 *
 * @return void
 */
add_action('admin_post_dahu_role_manager_create_role', 'dahu_role_manager_handle_create_role');
function dahu_role_manager_handle_create_role() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to manage roles.', 'dahu-role-manager'));
    }
    check_admin_referer('dahu_role_manager_create_role');

    // An explicit is_string() on every field: a forged POST can send an
    // array, which trim() and sanitize_text_field() cannot handle.
    $source = (isset($_POST['dahu_source_role']) && is_string($_POST['dahu_source_role']))
        ? wp_unslash($_POST['dahu_source_role']) : '';
    $label = (isset($_POST['dahu_new_role_label']) && is_string($_POST['dahu_new_role_label']))
        ? sanitize_text_field(wp_unslash($_POST['dahu_new_role_label'])) : '';
    $slug = (isset($_POST['dahu_new_role_slug']) && is_string($_POST['dahu_new_role_slug']))
        ? strtolower(trim(wp_unslash($_POST['dahu_new_role_slug']))) : '';

    // Derive the slug from the display name when none was typed.
    if ($slug === '' && $label !== '') {
        $slug = str_replace('-', '_', sanitize_title($label));
    }

    // The source role must exist, and the administrator is excluded on
    // purpose: a role copied from it would hold every capability on the site
    // with nothing to stop it.
    if (!is_string($source) || $source === '' || $source === 'administrator'
        || !in_array($source, array_keys(wp_roles()->roles), true)) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_source'));
        exit;
    }

    if ($label === '') {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_label'));
        exit;
    }

    if ($slug === '' || !preg_match('/^[a-z0-9_]+$/', $slug)) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_slug', $slug));
        exit;
    }

    if (in_array($slug, dahu_role_manager_get_reserved_role_slugs(), true)) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_reserved', $slug));
        exit;
    }

    if (get_role($slug) !== null) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_exists', $slug));
        exit;
    }

    $source_role = get_role($source);
    if (!$source_role) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_source'));
        exit;
    }

    // The capability array is copied whole and as-is.
    $capabilities = is_array($source_role->capabilities) ? $source_role->capabilities : [];

    // add_role() rewrites the whole roles option from the copy in memory:
    // start from what the database really holds, or a role created since that
    // copy was cached would be erased. See includes/role-store.php.
    if (!dahu_role_manager_reload_roles_from_db()) {
        // The database read came back empty: writing from it would wipe every
        // role on the site. Refuse rather than guess.
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_create', $slug));
        exit;
    }

    // The reloaded copy may already know this slug.
    if (get_role($slug) !== null) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_exists', $slug));
        exit;
    }

    $created = add_role($slug, $label, $capabilities);
    if (!$created) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_create', $slug));
        exit;
    }

    dahu_role_manager_log_role_action('create', $slug, 'source=' . $source . ' | label=' . $label . ' | caps=' . count($capabilities));

    wp_safe_redirect(dahu_role_manager_manage_redirect_url('created', $slug));
    exit;
}

/**
 * Rename a role: the display name ONLY.
 *
 * WordPress offers no way to change an existing role's slug — that would mean
 * recreating the role and reassigning every user — so the screen says as much
 * rather than pretending otherwise.
 *
 * @return void
 */
add_action('admin_post_dahu_role_manager_rename_role', 'dahu_role_manager_handle_rename_role');
function dahu_role_manager_handle_rename_role() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to manage roles.', 'dahu-role-manager'));
    }
    check_admin_referer('dahu_role_manager_rename_role');

    $slug = (isset($_POST['dahu_role_slug']) && is_string($_POST['dahu_role_slug']))
        ? wp_unslash($_POST['dahu_role_slug']) : '';
    $label = (isset($_POST['dahu_role_label']) && is_string($_POST['dahu_role_label']))
        ? sanitize_text_field(wp_unslash($_POST['dahu_role_label'])) : '';

    if ($slug === '' || get_role($slug) === null || !dahu_role_manager_role_is_manageable($slug)) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_protected', $slug));
        exit;
    }

    if ($label === '') {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_label', $slug));
        exit;
    }

    // This write rewrites the whole roles option: start from what the database
    // really holds, or roles created since the cached copy would be erased.
    // See includes/role-store.php.
    if (!dahu_role_manager_reload_roles_from_db()) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_unreadable', $slug));
        exit;
    }

    // Refused when the reloaded copy has no such role: renaming it would put
    // a role back that the database no longer has.
    if (get_role($slug) === null) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_vanished', $slug));
        exit;
    }

    // Update the label on the global object AND in the stored option:
    // WP_Roles exposes no renaming API.
    global $wp_roles;
    if (!isset($wp_roles)) {
        $wp_roles = new WP_Roles();
    }
    $wp_roles->roles[$slug]['name'] = $label;
    $wp_roles->role_names[$slug] = $label;
    update_option($wp_roles->role_key, $wp_roles->roles);

    dahu_role_manager_log_role_action('rename', $slug, 'label=' . $label);

    wp_safe_redirect(dahu_role_manager_manage_redirect_url('renamed', $slug));
    exit;
}

/**
 * Delete a custom role.
 *
 * Refused while users still hold it: they have to be reassigned first, or
 * those accounts would end up with no role at all.
 *
 * @return void
 */
add_action('admin_post_dahu_role_manager_delete_role', 'dahu_role_manager_handle_delete_role');
function dahu_role_manager_handle_delete_role() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to manage roles.', 'dahu-role-manager'));
    }
    check_admin_referer('dahu_role_manager_delete_role');

    $slug = (isset($_POST['dahu_role_slug']) && is_string($_POST['dahu_role_slug']))
        ? wp_unslash($_POST['dahu_role_slug']) : '';

    if ($slug === '' || get_role($slug) === null || !dahu_role_manager_role_is_manageable($slug)) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_protected', $slug));
        exit;
    }

    // Server-side guard: the JavaScript confirmation is not a check.
    $user_count = dahu_role_manager_count_users_for_role($slug);
    if ($user_count > 0) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_has_users', $slug));
        exit;
    }

    // remove_role() rewrites the whole roles option from the copy in memory:
    // start from what the database really holds, or roles created since that
    // copy was cached would disappear along with this one.
    // See includes/role-store.php.
    if (!dahu_role_manager_reload_roles_from_db()) {
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('error_unreadable', $slug));
        exit;
    }

    if (get_role($slug) === null) {
        // Already gone from the database. Nothing to remove, but the settings
        // and the editable-accounts lists still name it.
        dahu_role_manager_forget_role_settings($slug);
        dahu_role_manager_log_role_action('delete', $slug, 'already absent from database');
        wp_safe_redirect(dahu_role_manager_manage_redirect_url('deleted', $slug));
        exit;
    }

    remove_role($slug);

    dahu_role_manager_forget_role_settings($slug);

    dahu_role_manager_log_role_action('delete', $slug, 'users=0');

    wp_safe_redirect(dahu_role_manager_manage_redirect_url('deleted', $slug));
    exit;
}

/**
 * Forget everything this plugin stored about a role that is going away.
 *
 * Its own interface settings, and its slug in every other role's
 * editable-accounts list. A deliberate deletion has to win over the rule that
 * keeps stored but unknown slugs — otherwise the slug stays in those lists for
 * good, and the day somebody recreates it for another purpose, shop managers
 * silently regain the right to create and delete those accounts. Access
 * granted without an administrator ever asking for it.
 *
 * A FUNCTION RATHER THAN A BLOCK, and that is the fix: this ran only on the
 * path where `remove_role()` was actually called. The other path — the role
 * has already gone from the database, so there is nothing to remove — returned
 * «  deleted  » and left the lists untouched.
 *
 * @param string $slug Role being deleted.
 * @return void
 */
function dahu_role_manager_forget_role_settings($slug) {

    $settings = get_option(DAHU_ROLE_MANAGER_OPTION, []);
    if (!is_array($settings)) {
        return;
    }

    $changed = false;

    if (isset($settings[$slug])) {
        unset($settings[$slug]);
        $changed = true;
    }

    foreach ($settings as $other_slug => $data) {
        if (!is_array($data) || empty($data['sm_editable_roles']) || !is_array($data['sm_editable_roles'])) {
            continue;
        }
        $kept = array_values(array_diff($data['sm_editable_roles'], [$slug]));
        if (count($kept) !== count($data['sm_editable_roles'])) {
            $settings[$other_slug]['sm_editable_roles'] = $kept;
            $changed = true;
        }
    }

    if ($changed) {
        update_option(DAHU_ROLE_MANAGER_OPTION, $settings);
    }
}

/**
 * Render the Roles tab notices.
 *
 * @return void
 */
function dahu_role_manager_render_roles_tab_notices() {
    if (!isset($_GET['dahu_role_manager_role_msg'])) {
        return;
    }

    $code = sanitize_key($_GET['dahu_role_manager_role_msg']);
    $ref = isset($_GET['dahu_role_manager_role_ref']) ? sanitize_text_field(wp_unslash($_GET['dahu_role_manager_role_ref'])) : '';

    $messages = [
        /* translators: %s: role display name. */
        'created'         => ['success', __('Role "%s" created with the source role\'s capabilities.', 'dahu-role-manager')],
        /* translators: %s: role display name. */
        'renamed'         => ['success', __('Display name of role "%s" updated.', 'dahu-role-manager')],
        /* translators: %s: role display name. */
        'deleted'         => ['success', __('Role "%s" deleted.', 'dahu-role-manager')],
        'error_source'    => ['error', __('Invalid source role (the Administrator role cannot be duplicated).', 'dahu-role-manager')],
        'error_label'     => ['error', __('The display name is required.', 'dahu-role-manager')],
        'error_slug'      => ['error', __('Invalid identifier: lowercase letters, digits and underscores only, no spaces.', 'dahu-role-manager')],
        /* translators: %s: the identifier that was refused. */
        'error_reserved'  => ['error', __('The identifier "%s" is reserved by WordPress and cannot be used.', 'dahu-role-manager')],
        /* translators: %s: the identifier that was refused. */
        'error_exists'    => ['error', __('A role with the identifier "%s" already exists.', 'dahu-role-manager')],
        /* translators: %s: the identifier that was refused. */
        'error_create'    => ['error', __('Creating the role "%s" failed.', 'dahu-role-manager')],
        'error_protected' => ['error', __('This role is protected: it can be neither renamed nor deleted from this tab.', 'dahu-role-manager')],
        // Saying «  protected  » here was a lie: nothing is protected, the
        // roles could not be read. An administrator told that retries for
        // ever, because the message names a cause that is not the cause.
        'error_unreadable' => ['error', __('Refused: the roles could not be read from the database, so nothing was written — that way no role is erased. Try again, and if it persists, have the roles option checked.', 'dahu-role-manager')],
        'error_vanished'  => ['error', __('The role "%s" is no longer in the database. The screen has been refreshed.', 'dahu-role-manager')],
        /* translators: %s: role display name. */
        'error_has_users' => ['error', __('Deletion refused: users are still assigned to the role "%s". Reassign them first.', 'dahu-role-manager')],
    ];

    if (!isset($messages[$code])) {
        return;
    }

    list($css_class, $text) = $messages[$code];
    if (strpos($text, '%s') !== false) {
        $text = sprintf($text, $ref);
    }
    ?>
    <div class="notice notice-<?php echo esc_attr($css_class); ?> is-dismissible">
        <p><?php echo esc_html($text); ?></p>
    </div>
    <?php
}

/**
 * Render the "settings saved" notice.
 *
 * @return void
 */
function dahu_role_manager_render_saved_notice() {
    if (!isset($_GET['dahu_role_manager_saved'])) {
        return;
    }

    $saved_messages = [
        'role' => __('Settings saved for this role.', 'dahu-role-manager'),
    ];

    $saved_key = sanitize_key($_GET['dahu_role_manager_saved']);
    if (!isset($saved_messages[$saved_key])) {
        return;
    }
    ?>
    <div class="notice notice-success is-dismissible">
        <p><?php echo esc_html($saved_messages[$saved_key]); ?></p>
    </div>
    <?php
}

/**
 * Render the tab bar.
 *
 * @param string $current_tab The tab currently open.
 * @return void
 */
function dahu_role_manager_render_tabs_nav($current_tab) {
    $tabs = [
        'interface' => __('Role interface', 'dahu-role-manager'),
        'roles'     => __('Role management', 'dahu-role-manager'),
        'csv'       => __('Export / Import', 'dahu-role-manager'),
    ];
    ?>
    <h2 class="nav-tab-wrapper dahu-rm-tabs">
        <?php foreach ($tabs as $slug => $label):
            $url = admin_url('options-general.php?page=' . DAHU_ROLE_MANAGER_SLUG . '');
            if ($slug !== 'interface') {
                $url = add_query_arg('tab', $slug, $url);
            }
            ?>
            <a href="<?php echo esc_url($url); ?>"
               class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </h2>
    <?php
}

/**
 * Render the CSV import result notices.
 *
 * Shown on the Export / Import tab, where the import redirects, and kept on
 * the Role interface tab too: an old link or bookmark pointing at the page
 * with no `tab` parameter must still show the message.
 *
 * @return void
 */
function dahu_role_manager_render_csv_import_notices() {
    if (!isset($_GET['dahu_role_manager_import'])) {
        return;
    }

    $status = sanitize_key($_GET['dahu_role_manager_import']);
    $messages = [
        'success'       => ['success', __('Import complete. The settings were replaced by the contents of the CSV file.', 'dahu-role-manager')],
        'error_no_file' => ['error', __('No file was received.', 'dahu-role-manager')],
        'error_read'    => ['error', __('That file could not be read.', 'dahu-role-manager')],
        'error_format'  => ['error', __('Invalid file format. The expected header is: role,type,value', 'dahu-role-manager')],
    ];

    if (!isset($messages[$status])) {
        return;
    }

    list($css_class, $text) = $messages[$status];
    ?>
    <div class="notice notice-<?php echo esc_attr($css_class); ?> is-dismissible">
        <p><?php echo esc_html($text); ?></p>
    </div>
    <?php
}

/**
 * Render the Export / Import tab.
 *
 * @return void
 */
function dahu_role_manager_render_csv_tab() {
    ?>
    <div class="dahu-ui-card">
    <div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title"><?php esc_html_e('Export / import the settings', 'dahu-role-manager'); ?></h2></div>
    <p>
        <?php
        printf(
            /* translators: %s: the CSV column header role,type,value. */
            esc_html__('The CSV file (%s) covers the whole configuration: menus, submenus, admin bar, the per-role options, the landing page, the hidden CSS selectors and the editable accounts.', 'dahu-role-manager'),
            '<code>role,type,value</code>'
        );
        ?>
    </p>
    <div class="dahu-rm-tools">
        <div>
            <h3><?php esc_html_e('Export the settings', 'dahu-role-manager'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="dahu_role_manager_export_csv" />
                <?php wp_nonce_field('dahu_role_manager_export_csv'); ?>
                <?php submit_button(__('Download the CSV', 'dahu-role-manager'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <div>
            <h3><?php esc_html_e('Import settings', 'dahu-role-manager'); ?></h3>
            <p class="dahu-rm-warning"><?php esc_html_e('⚠️ An import completely replaces the current settings of every role.', 'dahu-role-manager'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="dahu_role_manager_import_csv" />
                <?php wp_nonce_field('dahu_role_manager_import_csv'); ?>
                <input type="file" name="dahu_role_manager_csv_file" accept=".csv" required />
                <?php submit_button(__('Import the CSV', 'dahu-role-manager'), 'secondary', 'submit', false); ?>
            </form>
        </div>
    </div>
    </div>
    <?php
}

/**
 * Render the Role management tab.
 *
 * @return void
 */
function dahu_role_manager_render_roles_tab() {
    global $wp_roles;
    $all_roles = $wp_roles->roles;
    $reserved = dahu_role_manager_get_reserved_role_slugs();
    ?>
    <div class="dahu-ui-card">
    <div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title"><?php esc_html_e('Create a role by duplication', 'dahu-role-manager'); ?></h2></div>
    <p>
        <?php
        printf(
            /* translators: %s: the bolded word "exactly". */
            esc_html__('The new role receives %s the capabilities of the source role, with nothing added and nothing taken away. Adjust them afterwards with whatever role editor you already use.', 'dahu-role-manager'),
            '<strong>' . esc_html__('exactly', 'dahu-role-manager') . '</strong>'
        );
        ?>
    </p>
    <p class="dahu-rm-hint">
        <?php
        printf(
            /* translators: %s: the bolded name of the Administrator role. */
            esc_html__('The %s role is deliberately not offered as a source: a role copied from it would hold every capability on the site with nothing to stop it.', 'dahu-role-manager'),
            '<strong>' . esc_html(translate_user_role('Administrator')) . '</strong>'
        );
        ?>
    </p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dahu-rm-form">
        <input type="hidden" name="action" value="dahu_role_manager_create_role" />
        <?php wp_nonce_field('dahu_role_manager_create_role'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="dahu_source_role"><?php esc_html_e('Source role', 'dahu-role-manager'); ?></label></th>
                <td>
                    <select name="dahu_source_role" id="dahu_source_role" required>
                        <?php foreach ($all_roles as $slug => $info):
                            if ($slug === 'administrator') {
                                continue;
                            } ?>
                            <option value="<?php echo esc_attr($slug); ?>">
                                <?php echo esc_html(translate_user_role($info['name']) . ' (' . $slug . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="dahu_new_role_label"><?php esc_html_e('Display name', 'dahu-role-manager'); ?></label></th>
                <td>
                    <input type="text" name="dahu_new_role_label" id="dahu_new_role_label" class="regular-text" required
                           oninput="dahuRoleManagerSlugify(this.value)" />
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: an example role display name. */
                            esc_html__('The label shown throughout the admin (for example %s).', 'dahu-role-manager'),
                            '<code>' . esc_html__('Warehouse staff', 'dahu-role-manager') . '</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="dahu_new_role_slug"><?php esc_html_e('Identifier', 'dahu-role-manager'); ?></label></th>
                <td>
                    <input type="text" name="dahu_new_role_slug" id="dahu_new_role_slug" class="regular-text"
                           pattern="[a-z0-9_]+" required />
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: the bolded warning "Final once the role is created." */
                            esc_html__('Generated from the display name, and editable by hand. Lowercase letters, digits and underscores only, no spaces. %s', 'dahu-role-manager'),
                            '<strong>' . esc_html__('Final once the role is created.', 'dahu-role-manager') . '</strong>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Create the role', 'dahu-role-manager')); ?>
    </form>
    </div>

    <div class="dahu-ui-card">
    <div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title"><?php esc_html_e('Existing roles', 'dahu-role-manager'); ?></h2></div>
    <p class="dahu-rm-hint">
        <?php
        printf(
            /* translators: %s: the bolded words "display name". */
            esc_html__('Only the %s can be changed after creation: the identifier is final, because WordPress offers no way to change it without recreating the role and reassigning all of its users. A role can only be deleted once no user holds it.', 'dahu-role-manager'),
            '<strong>' . esc_html__('display name', 'dahu-role-manager') . '</strong>'
        );
        ?>
    </p>

    <table class="widefat striped dahu-rm-table dahu-rm-table--wide">
        <thead>
            <tr>
                <th><?php esc_html_e('Display name', 'dahu-role-manager'); ?></th>
                <th class="dahu-rm-col-id"><?php esc_html_e('Identifier', 'dahu-role-manager'); ?></th>
                <th class="dahu-rm-col-count"><?php esc_html_e('Users', 'dahu-role-manager'); ?></th>
                <th class="dahu-rm-col-count"><?php esc_html_e('Capabilities', 'dahu-role-manager'); ?></th>
                <th class="dahu-rm-col-actions"><?php esc_html_e('Actions', 'dahu-role-manager'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($all_roles as $slug => $info):
                $manageable = dahu_role_manager_role_is_manageable($slug);
                $user_count = dahu_role_manager_count_users_for_role($slug);
                $cap_count = (!empty($info['capabilities']) && is_array($info['capabilities'])) ? count($info['capabilities']) : 0;
                ?>
                <tr>
                    <td><strong><?php echo esc_html(translate_user_role($info['name'])); ?></strong></td>
                    <td><code><?php echo esc_html($slug); ?></code></td>
                    <td><?php echo esc_html($user_count); ?></td>
                    <td><?php echo esc_html($cap_count); ?></td>
                    <td>
                        <?php if (!$manageable): ?>
                            <em class="dahu-rm-hint">
                                <?php
                                echo in_array($slug, $reserved, true)
                                    ? esc_html__('Protected role (WordPress built-in)', 'dahu-role-manager')
                                    : esc_html__('Protected role', 'dahu-role-manager');
                                ?>
                            </em>
                        <?php else: ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dahu-rm-inline-form">
                                <input type="hidden" name="action" value="dahu_role_manager_rename_role" />
                                <input type="hidden" name="dahu_role_slug" value="<?php echo esc_attr($slug); ?>" />
                                <?php wp_nonce_field('dahu_role_manager_rename_role'); ?>
                                <input type="text" name="dahu_role_label" value="<?php echo esc_attr(translate_user_role($info['name'])); ?>" required class="dahu-rm-rename-input" />
                                <button type="submit" class="dahu-ui-btn"><?php esc_html_e('Rename', 'dahu-role-manager'); ?></button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dahu-rm-inline-form--bare"
                                  onsubmit="return dahuRoleManagerConfirmDelete(this);">
                                <input type="hidden" name="action" value="dahu_role_manager_delete_role" />
                                <input type="hidden" name="dahu_role_slug" value="<?php echo esc_attr($slug); ?>" />
                                <input type="hidden" name="dahu_role_users" value="<?php echo esc_attr($user_count); ?>" />
                                <input type="hidden" name="dahu_role_name" value="<?php echo esc_attr(translate_user_role($info['name'])); ?>" />
                                <?php wp_nonce_field('dahu_role_manager_delete_role'); ?>
                                <button type="submit" class="dahu-ui-btn dahu-ui-btn--warn" <?php disabled($user_count > 0); ?>><?php esc_html_e('Delete', 'dahu-role-manager'); ?></button>
                            </form>
                            <?php if ($user_count > 0): ?>
                                <p class="dahu-rm-danger">
                                    <?php
                                    printf(
                                        esc_html(
                                            /* translators: %s: number of users holding the role. */
                                            _n(
                                                '%s user is assigned to this role: reassign them before it can be deleted.',
                                                '%s users are assigned to this role: reassign them before it can be deleted.',
                                                $user_count,
                                                'dahu-role-manager'
                                            )
                                        ),
                                        esc_html( number_format_i18n( $user_count ) )
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <script>
    var dahuRoleManagerI18n = <?php echo wp_json_encode(array(
        /* translators: 1: role display name, 2: number of users still assigned. */
        'stillAssigned' => __('Cannot delete "%1$s": %2$s user(s) are still assigned to it. Reassign them first.', 'dahu-role-manager'),
        /* translators: 1: role display name, 2: role identifier. */
        'confirmDelete' => __('Permanently delete the role "%1$s" (%2$s)?', 'dahu-role-manager'),
        'confirmDetail' => __('No user is assigned to it. This cannot be undone.', 'dahu-role-manager'),
    )); ?>;
    function dahuRoleManagerSlugify(value) {
        var field = document.getElementById('dahu_new_role_slug');
        if (!field || field.dataset.touched === '1') {
            return;
        }
        field.value = value.toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }
    (function () {
        var field = document.getElementById('dahu_new_role_slug');
        if (field) {
            // Once the identifier has been edited by hand, stop overwriting
            // it from the display name.
            field.addEventListener('input', function () { field.dataset.touched = '1'; });
        }
    })();
    function dahuRoleManagerConfirmDelete(form) {
        var name = form.querySelector('input[name="dahu_role_name"]').value;
        var slug = form.querySelector('input[name="dahu_role_slug"]').value;
        var users = parseInt(form.querySelector('input[name="dahu_role_users"]').value, 10) || 0;
        if (users > 0) {
            window.alert(
                dahuRoleManagerI18n.stillAssigned
                    .replace('%1$s', name)
                    .replace('%2$s', users)
            );
            return false;
        }
        return window.confirm(
            dahuRoleManagerI18n.confirmDelete
                .replace('%1$s', name)
                .replace('%2$s', slug)
            + '\n\n' + dahuRoleManagerI18n.confirmDetail
        );
    }
    </script>
    <?php
}
