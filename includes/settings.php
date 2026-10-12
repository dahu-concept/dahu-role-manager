<?php
/**
 * Settings screen.
 *
 * @package DahuRoleManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the screen under Settings.
 *
 * @return void
 */
add_action('admin_menu', 'dahu_role_manager_settings_menu');
function dahu_role_manager_settings_menu() {
    add_options_page(
        __('Role manager', 'dahu-role-manager'),
        __('Role manager', 'dahu-role-manager'),
        'manage_options',
        DAHU_ROLE_MANAGER_SLUG,
        'dahu_role_manager_settings_page'
    );
}

add_action('admin_enqueue_scripts', 'dahu_role_manager_enqueue_admin_style');
/**
 * Loads this screen's stylesheet, on this screen only.
 *
 * The hook suffix is compared rather than read from add_options_page()'s
 * return value: that value is only available inside the admin_menu handler,
 * and keeping a copy of it in a global just to read it here would be one more
 * thing able to fall out of step with the menu.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function dahu_role_manager_enqueue_admin_style($hook) {

    if ('settings_page_' . DAHU_ROLE_MANAGER_SLUG !== $hook) {
        return;
    }

    wp_enqueue_style(
        'dahu-role-manager-admin',
        DAHU_ROLE_MANAGER_URL . 'assets/css/admin.css',
        array(),
        DAHU_ROLE_MANAGER_VERSION
    );
}

/**
 * Save the settings of one role.
 *
 * Done on admin-post.php with its own nonce rather than through
 * `register_setting()`/`options.php`: options.php processes EVERY setting in
 * the submitted group and resets to `null` any setting absent from the POST,
 * which silently wiped the sections that were not on screen. One handler, one
 * nonce, one role at a time.
 *
 * @return void
 */

add_action('admin_post_dahu_role_manager_save_role_settings', 'dahu_role_manager_save_role_settings');
function dahu_role_manager_save_role_settings() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to change these settings.', 'dahu-role-manager'));
    }
    check_admin_referer('dahu_role_manager_save_role_settings');

    // NOT sanitize_key(): it lowercases, so the settings of a role whose
    // slug contains capitals would be stored under a key matching no real
    // role. The validation is the strict comparison against wp_roles() below.
    $role_slug = isset($_POST['dahu_role']) && is_string($_POST['dahu_role'])
        ? sanitize_text_field(wp_unslash($_POST['dahu_role']))
        : '';
    $redirect_base = add_query_arg('dahu_role', rawurlencode($role_slug), admin_url('options-general.php?page=' . DAHU_ROLE_MANAGER_SLUG . ''));

    if ($role_slug === '' || !in_array($role_slug, array_keys(wp_roles()->roles), true)) {
        wp_safe_redirect($redirect_base);
        exit;
    }

    $role_input = isset($_POST[DAHU_ROLE_MANAGER_OPTION][$role_slug]) && is_array($_POST[DAHU_ROLE_MANAGER_OPTION][$role_slug])
        ? $_POST[DAHU_ROLE_MANAGER_OPTION][$role_slug]
        : [];

    $existing = get_option(DAHU_ROLE_MANAGER_OPTION, []);
    if (!is_array($existing)) {
        $existing = [];
    }

    $existing[$role_slug] = dahu_role_manager_sanitize_role_data($role_input, $role_slug);

    update_option(DAHU_ROLE_MANAGER_OPTION, $existing);

    wp_safe_redirect(add_query_arg('dahu_role_manager_saved', 'role', $redirect_base));
    exit;
}

/**
 * Clean the list of CSS selectors to hide, one per line.
 *
 * @param mixed $input Raw textarea content.
 * @return string[]
 */
function dahu_role_manager_sanitize_hide_classes($input) {
    if (!is_string($input)) {
        return [];
    }

    $lines = preg_split('/[\r\n]+/', wp_unslash($input));
    $clean = [];

    foreach ($lines as $line) {
        $line = sanitize_text_field(trim($line));
        if ($line !== '') {
            $clean[] = $line;
        }
    }

    return array_values(array_unique($clean));
}

/**
 * Clean the settings submitted for ONE role.
 *
 * The administrator role is never RESTRICTED: menus, submenus, landing page
 * and editable accounts stay empty for it even through a forged request. The
 * sections that take no access away ARE open to it: hiding elements in CSS,
 * the completed-order lock (a deliberate lock it can lift again from this same
 * screen), and the admin bar, which is always recoverable from the sidebar.
 *
 * Menus and submenus stay closed to the administrator for one reason: hiding a
 * menu from it could hide this settings page itself.
 *
 * @param array  $role_data Raw submitted data for the role.
 * @param string $role_slug The role being saved.
 * @return array<string,mixed>
 */
function dahu_role_manager_sanitize_role_data($role_data, $role_slug = '') {
    if ($role_slug === 'administrator') {
        $admin_clean = [
            'menus'                 => [],
            'submenus'              => [],
            'adminbar'              => [],
            'hide_adminbar_full'    => !empty($role_data['hide_adminbar_full']),
            'show_logout_menu'      => !empty($role_data['show_logout_menu']),
            'lock_completed_orders' => !empty($role_data['lock_completed_orders']),
            'start_menu'            => '',
            'hide_classes'          => isset($role_data['hide_classes']) ? dahu_role_manager_sanitize_hide_classes($role_data['hide_classes']) : [],
            'sm_editable_roles'     => [],
        ];

        // Admin-bar nodes are treated exactly as for any other role.
        // `menus`/`submenus` stay empty: see the docblock above.
        if (!empty($role_data['adminbar']) && is_array($role_data['adminbar'])) {
            foreach ($role_data['adminbar'] as $node_id) {
                $admin_clean['adminbar'][] = sanitize_text_field(wp_unslash($node_id));
            }
        }

        return $admin_clean;
    }

    $clean = [
        'menus'                 => [],
        'submenus'              => [],
        'adminbar'              => [],
        'hide_adminbar_full'    => !empty($role_data['hide_adminbar_full']),
        'show_logout_menu'      => !empty($role_data['show_logout_menu']),
        'lock_completed_orders' => !empty($role_data['lock_completed_orders']),
        'start_menu'            => isset($role_data['start_menu']) ? sanitize_text_field(wp_unslash($role_data['start_menu'])) : '',
        'hide_classes'          => isset($role_data['hide_classes']) ? dahu_role_manager_sanitize_hide_classes($role_data['hide_classes']) : [],
        'sm_editable_roles'     => [],
    ];

    // Editable accounts are only ever stored for a role allowed to manage
    // accounts at all, even through a forged request.
    if ($role_slug !== '' && dahu_role_manager_role_can_manage_accounts($role_slug)
        && !empty($role_data['sm_editable_roles']) && is_array($role_data['sm_editable_roles'])) {
        $clean['sm_editable_roles'] = dahu_role_manager_sanitize_sm_editable_roles($role_data['sm_editable_roles']);
    }

    if (!empty($role_data['menus']) && is_array($role_data['menus'])) {
        foreach ($role_data['menus'] as $slug) {
            $clean['menus'][] = sanitize_text_field(wp_unslash($slug));
        }
    }

    if (!empty($role_data['submenus']) && is_array($role_data['submenus'])) {
        foreach ($role_data['submenus'] as $combo) {
            $clean['submenus'][] = sanitize_text_field(wp_unslash($combo));
        }
    }

    if (!empty($role_data['adminbar']) && is_array($role_data['adminbar'])) {
        foreach ($role_data['adminbar'] as $node_id) {
            $clean['adminbar'][] = sanitize_text_field(wp_unslash($node_id));
        }
    }

    return $clean;
}

function dahu_role_manager_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wp_roles;
    $all_roles = $wp_roles->roles;

    // Tabs: "interface" (the per-role settings, default), "roles"
    // (create/rename/delete roles) and "csv" (export/import).
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'interface';
    if (!in_array($tab, ['interface', 'roles', 'csv'], true)) {
        $tab = 'interface';
    }

    if ($tab === 'roles' || $tab === 'csv') {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Role manager', 'dahu-role-manager'); ?></h1>
            <p class="dahu-rm-intro"><?php esc_html_e('Dahu Role Manager decides what each WordPress role sees of the back-office: which menus, which admin-bar items, which screen it lands on, and which accounts it may edit.', 'dahu-role-manager'); ?></p>
            <hr class="wp-header-end">
            <?php dahu_role_manager_render_tabs_nav($tab); ?>
            <?php if ($tab === 'roles'): ?>
                <?php dahu_role_manager_render_roles_tab_notices(); ?>
                <?php dahu_role_manager_render_roles_tab(); ?>
            <?php else: ?>
                <?php dahu_role_manager_render_csv_import_notices(); ?>
                <?php dahu_role_manager_render_csv_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
        return;
    }

    // The role being configured, carried in the query string so the
    // selector is plain navigation. "administrator" stays a valid choice when
    // asked for explicitly, but is never the default when the page opens.
    //
    // NOT sanitize_key(): it lowercases, so a role whose slug contains
    // capitals would match no key of wp_roles() and the page would quietly
    // fall back to the default role, making that role impossible to
    // configure. The isset($all_roles[...]) check below IS the validation.
    $selected_role = isset($_GET['dahu_role']) && is_string($_GET['dahu_role'])
        ? sanitize_text_field(wp_unslash($_GET['dahu_role']))
        : '';
    if (!$selected_role || !isset($all_roles[$selected_role])) {
        // Default to the first role that is not the administrator.
        foreach ($all_roles as $slug => $info) {
            if ($slug !== 'administrator') {
                $selected_role = $slug;
                break;
            }
        }
    }

    $settings = get_option(DAHU_ROLE_MANAGER_OPTION, []);
    $default_role_settings = [
        'menus'                 => [],
        'submenus'              => [],
        'adminbar'              => [],
        'hide_adminbar_full'    => false,
        'show_logout_menu'      => false,
        'lock_completed_orders' => false,
        'start_menu'            => '',
        'hide_classes'          => [],
        'sm_editable_roles'     => [],
    ];
    $role_settings = isset($settings[$selected_role]) ? $settings[$selected_role] : $default_role_settings;
    $role_settings = wp_parse_args($role_settings, $default_role_settings);

    $menu_map = dahu_role_manager_get_menu_map();
    $adminbar_map = dahu_role_manager_get_adminbar_map();

    $hide_classes_text = is_array($role_settings['hide_classes']) ? implode("\n", $role_settings['hide_classes']) : '';

    $sm_editable_roles = is_array($role_settings['sm_editable_roles']) ? $role_settings['sm_editable_roles'] : [];
    $sm_excluded_roles = dahu_role_manager_get_sm_excluded_roles();
    $role_can_manage_accounts = dahu_role_manager_role_can_manage_accounts($selected_role);

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Role manager', 'dahu-role-manager'); ?></h1>
            <p class="dahu-rm-intro"><?php esc_html_e('Dahu Role Manager decides what each WordPress role sees of the back-office: which menus, which admin-bar items, which screen it lands on, and which accounts it may edit.', 'dahu-role-manager'); ?></p>
            <hr class="wp-header-end">
        <?php dahu_role_manager_render_tabs_nav($tab); ?>

        <?php dahu_role_manager_render_csv_import_notices(); ?>

        <?php dahu_role_manager_render_saved_notice(); ?>

        <form method="get" action="" class="dahu-rm-filter">
            <input type="hidden" name="page" value="<?php echo esc_attr( DAHU_ROLE_MANAGER_SLUG ); ?>" />
            <label for="dahu_role"><strong><?php esc_html_e('Role to configure:', 'dahu-role-manager'); ?></strong></label>
            <select name="dahu_role" id="dahu_role" onchange="this.form.submit()">
                <?php foreach ($all_roles as $slug => $info): ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_role, $slug); ?>>
                        <?php echo esc_html(translate_user_role($info['name'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($selected_role): ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dahu_role_manager_save_role_settings" />
            <input type="hidden" name="dahu_role" value="<?php echo esc_attr($selected_role); ?>" />
            <?php wp_nonce_field('dahu_role_manager_save_role_settings'); ?>

            <?php if ($selected_role !== 'administrator'): ?>
            <h2><?php esc_html_e('Land on', 'dahu-role-manager'); ?></h2>
            <p><?php esc_html_e('The page this role sees right after logging in, instead of the dashboard. Useful when the dashboard is hidden from their menu below.', 'dahu-role-manager'); ?></p>
            <p>
                <select name="<?php echo esc_attr(DAHU_ROLE_MANAGER_OPTION); ?>[<?php echo esc_attr($selected_role); ?>][start_menu]">
                    <option value="" <?php selected($role_settings['start_menu'], ''); ?>><?php esc_html_e('Dashboard (default)', 'dahu-role-manager'); ?></option>
                    <?php foreach ($menu_map as $slug => $data): ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($role_settings['start_menu'], $slug); ?>>
                            <?php echo esc_html($data['label']); ?>
                        </option>
                        <?php foreach ($data['submenus'] as $sub_slug => $sub_label):
                            $combo = $slug . '|' . $sub_slug;
                            ?>
                            <option value="<?php echo esc_attr($combo); ?>" <?php selected($role_settings['start_menu'], $combo); ?>>
                                &nbsp;&nbsp;↳ <?php echo esc_html($data['label'] . ' — ' . $sub_label); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
            </p>

            <h2><?php esc_html_e('Menus', 'dahu-role-manager'); ?></h2>
            <p>
                <label>
                    <input type="checkbox" onclick="dahuRoleManagerToggleAll(this,'dahu-role-manager-menus-columns')" />
                    <strong><?php esc_html_e('Check / uncheck all', 'dahu-role-manager'); ?></strong>
                </label>
            </p>
            <div id="dahu-role-manager-menus-columns" class="dahu-rm-grid">
                <?php foreach ($menu_map as $slug => $data):
                    $checked_menu = in_array($slug, $role_settings['menus'], true);
                    ?>
                    <div class="dahu-rm-card">
                        <label class="dahu-rm-card__label">
                            <input type="checkbox"
                                   name="<?php echo esc_attr(DAHU_ROLE_MANAGER_OPTION); ?>[<?php echo esc_attr($selected_role); ?>][menus][]"
                                   value="<?php echo esc_attr($slug); ?>"
                                   <?php checked($checked_menu); ?> />
                            <strong><?php echo esc_html($data['label']); ?></strong>
                            <code class="dahu-rm-slug"><?php echo esc_html($slug); ?></code>
                        </label>
                        <?php foreach ($data['submenus'] as $sub_slug => $sub_label):
                            $combo = $slug . '|' . $sub_slug;
                            $checked_sub = in_array($combo, $role_settings['submenus'], true);
                            ?>
                            <label class="dahu-rm-card__child">
                                <input type="checkbox"
                                       name="<?php echo esc_attr(DAHU_ROLE_MANAGER_OPTION); ?>[<?php echo esc_attr($selected_role); ?>][submenus][]"
                                       value="<?php echo esc_attr($combo); ?>"
                                       <?php checked($checked_sub); ?> />
                                ↳ <?php echo esc_html($sub_label); ?>
                                <code class="dahu-rm-slug"><?php echo esc_html($sub_slug); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php // Open to EVERY role, administrator included: hiding the
                  // admin bar removes no capability and stays reversible from
                  // the sidebar, which is never touched for the administrator. ?>
            <h2><?php esc_html_e('Admin bar', 'dahu-role-manager'); ?></h2>
            <p>
                <label>
                    <input type="checkbox"
                           name="<?php echo esc_attr(DAHU_ROLE_MANAGER_OPTION); ?>[<?php echo esc_attr($selected_role); ?>][hide_adminbar_full]"
                           value="1"
                           <?php checked(!empty($role_settings['hide_adminbar_full'])); ?> />
                    <?php
                    printf(
                        /* translators: %s: the bolded label "Hide the admin bar entirely". */
                        esc_html__('%s for this role (takes precedence over the selection below)', 'dahu-role-manager'),
                        '<strong>' . esc_html__('Hide the admin bar entirely', 'dahu-role-manager') . '</strong>'
                    );
                    ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox"
                           name="<?php echo esc_attr(DAHU_ROLE_MANAGER_OPTION); ?>[<?php echo esc_attr($selected_role); ?>][show_logout_menu]"
                           value="1"
                           <?php checked(!empty($role_settings['show_logout_menu'])); ?> />
                    <?php
                    printf(
                        /* translators: %s: the bolded label 'Add a "Log out" button'. */
                        esc_html__('%s at the very bottom of the admin menu for this role, so they can log out even with no admin bar', 'dahu-role-manager'),
                        '<strong>' . esc_html__('Add a "Log out" button', 'dahu-role-manager') . '</strong>'
                    );
                    ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" onclick="dahuRoleManagerToggleAll(this,'dahu-role-manager-adminbar-columns')" />
                    <strong><?php esc_html_e('Check / uncheck all', 'dahu-role-manager'); ?></strong>
                </label>
            </p>
            <?php
            // Group the admin-bar nodes: one card per root node, with every
            // descendant (at any depth) listed under it. A node whose parent
            // is not in the map is treated as a root itself.
            $adminbar_groups = [];
            foreach ($adminbar_map as $node_id => $data) {
                if (empty($data['parent']) || !isset($adminbar_map[$data['parent']])) {
                    $adminbar_groups[$node_id] = ['label' => $data['label'], 'children' => []];
                }
            }
            foreach ($adminbar_map as $node_id => $data) {
                if (isset($adminbar_groups[$node_id])) {
                    continue;
                }
                // Walk up the parent chain to a root, with a depth guard in
                // case a plugin ever leaves a cycle behind.
                $cursor = $data['parent'];
                $depth = 0;
                while ($cursor && !isset($adminbar_groups[$cursor]) && isset($adminbar_map[$cursor]) && $depth < 10) {
                    $cursor = $adminbar_map[$cursor]['parent'];
                    $depth++;
                }
                if ($cursor && isset($adminbar_groups[$cursor])) {
                    $adminbar_groups[$cursor]['children'][$node_id] = $data['label'];
                } else {
                    $adminbar_groups[$node_id] = ['label' => $data['label'], 'children' => []];
                }
            }
            ?>
            <div id="dahu-role-manager-adminbar-columns" class="dahu-rm-grid">
                <?php foreach ($adminbar_groups as $node_id => $group):
                    $checked_node = in_array($node_id, $role_settings['adminbar'], true);
                    ?>
                    <div class="dahu-rm-card">
                        <label class="dahu-rm-card__label">
                            <input type="checkbox"
                                   name="<?php echo esc_attr(DAHU_ROLE_MANAGER_OPTION); ?>[<?php echo esc_attr($selected_role); ?>][adminbar][]"
                                   value="<?php echo esc_attr($node_id); ?>"
                                   <?php checked($checked_node); ?> />
                            <strong><?php echo esc_html($group['label']); ?></strong>
                            <code class="dahu-rm-slug"><?php echo esc_html($node_id); ?></code>
                        </label>
                        <?php foreach ($group['children'] as $child_id => $child_label):
                            $checked_child = in_array($child_id, $role_settings['adminbar'], true);
                            ?>
                            <label class="dahu-rm-card__child">
                                <input type="checkbox"
                                       name="<?php echo esc_attr(DAHU_ROLE_MANAGER_OPTION); ?>[<?php echo esc_attr($selected_role); ?>][adminbar][]"
                                       value="<?php echo esc_attr($child_id); ?>"
                                       <?php checked($checked_child); ?> />
                                ↳ <?php echo esc_html($child_label); ?>
                                <code class="dahu-rm-slug"><?php echo esc_html($child_id); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // Open to EVERY role, administrator included: this option
                  // restricts no access. ?>
            <h2><?php esc_html_e('Orders (WooCommerce)', 'dahu-role-manager'); ?></h2>
            <p>
                <label>
                    <input type="checkbox"
                           name="<?php echo esc_attr(DAHU_ROLE_MANAGER_OPTION); ?>[<?php echo esc_attr($selected_role); ?>][lock_completed_orders]"
                           value="1"
                           <?php checked(!empty($role_settings['lock_completed_orders'])); ?> />
                    <?php
                    printf(
                        /* translators: %s: the bolded label "Lock editing once the order is Completed". */
                        esc_html__('%s for this role: as soon as an order is completed, its "Order details" panel becomes read-only (status, customer, creation date, addresses, payment method). Until it is completed, nothing changes.', 'dahu-role-manager'),
                        '<strong>' . esc_html__('Lock editing once the order is Completed', 'dahu-role-manager') . '</strong>'
                    );
                    ?>
                </label>
            </p>
            <?php if ($selected_role === 'administrator'): ?>
                <p class="dahu-rm-hint dahu-rm-hint--tight">
                    <?php esc_html_e( '⚠️ Ticking this applies the lock to you as well: no administrator will be able to change the status of a completed order any more. You can always untick it again from this page.', 'dahu-role-manager' ); ?>
                </p>
            <?php endif; ?>

            <h2><?php esc_html_e('Hiding back-office elements', 'dahu-role-manager'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: 1: an example CSS selector, 2: another example CSS selector, 3: the CSS declaration display:none. */
                    esc_html__('One CSS selector per line (for example %1$s or %2$s). These elements are hidden (%3$s) throughout the back-office for this role — handy for the persistent notices third-party plugins leave behind.', 'dahu-role-manager'),
                    '<code>.notice.notice-warning</code>',
                    '<code>#some-plugin-nag</code>',
                    '<code>display:none</code>'
                );
                ?>
            </p>
            <textarea name="<?php echo esc_attr(DAHU_ROLE_MANAGER_OPTION); ?>[<?php echo esc_attr($selected_role); ?>][hide_classes]" rows="6" cols="80" class="dahu-rm-textarea" placeholder=".notice.notice-warning&#10;#mon-plugin-notice"><?php echo esc_textarea($hide_classes_text); ?></textarea>

            <?php if ($role_can_manage_accounts && $selected_role !== 'administrator'): ?>
            <h2><?php esc_html_e('Accounts this role may edit', 'dahu-role-manager'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s: the display name of the role being configured. */
                    esc_html__('By default WooCommerce lets the shop manager edit accounts holding the Customer role and no other. Tick here the account roles %s may view, edit, create and delete.', 'dahu-role-manager'),
                    '<strong>' . esc_html(translate_user_role($all_roles[$selected_role]['name'])) . '</strong>'
                );
                ?>
            </p>
            <table class="widefat striped dahu-rm-table">
                <thead>
                    <tr>
                        <th class="dahu-rm-col-check"><?php esc_html_e('Allow', 'dahu-role-manager'); ?></th>
                        <th><?php esc_html_e('Role', 'dahu-role-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_roles as $slug => $info):
                        if (in_array($slug, $sm_excluded_roles, true)) {
                            continue;
                        }
                        $is_customer = ($slug === 'customer');
                        $checked = $is_customer || in_array($slug, $sm_editable_roles, true);
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox"
                                       <?php if (!$is_customer): ?>
                                       name="<?php echo esc_attr(DAHU_ROLE_MANAGER_OPTION); ?>[<?php echo esc_attr($selected_role); ?>][sm_editable_roles][]"
                                       value="<?php echo esc_attr($slug); ?>"
                                       <?php endif; ?>
                                       <?php checked($checked); ?>
                                       <?php disabled($is_customer); ?> />
                            </td>
                            <td>
                                <strong><?php echo esc_html(translate_user_role($info['name'])); ?></strong>
                                <code class="dahu-rm-slug"><?php echo esc_html($slug); ?></code>
                                <?php if ($is_customer): ?>
                                    <em class="dahu-rm-hint"><?php esc_html_e('(WooCommerce built-in, always on)', 'dahu-role-manager'); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php submit_button(__('Save the settings for this role', 'dahu-role-manager')); ?>
        </form>
        <?php endif; ?>

    </div>

    <script>
    function dahuRoleManagerToggleAll(checkbox, containerId) {
        var container = document.getElementById(containerId);
        if (!container) {
            return;
        }
        // One generic selector, working for the admin-bar table as well as
        // the column grid of menu cards. The "check all" box itself is skipped
        // when it happens to sit inside the container.
        var boxes = container.querySelectorAll('input[type="checkbox"]');
        boxes.forEach(function (box) {
            if (box !== checkbox && !box.disabled) {
                box.checked = checkbox.checked;
            }
        });
    }
    </script>
    <?php
}
