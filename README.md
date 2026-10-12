# Dahu Role Manager

Shape the WordPress admin for each role: which menus and admin-bar items they
see, which accounts they may edit, where they land on login. Duplicate, rename
and delete roles, and move the whole configuration as CSV.

Everything the settings screen offers to hide is discovered from the live
install at runtime, so whatever plugins a site runs appear in the list by
themselves.

## What it does, per role

| | |
|---|---|
| Menus and submenus | Hide what a role has no use for |
| Admin bar | Hide individual nodes, or the bar entirely |
| Log out | Add a log-out entry at the bottom of the admin menu |
| Landing page | Send the role somewhere other than the dashboard on login |
| CSS hiding | Hide elements by selector, for third-party notices |
| Editable accounts | Say which account roles this role may manage |
| Order lock | Make a completed WooCommerce order read-only |

Plus a Roles tab that duplicates a role's capabilities wholesale, renames a
role's display name, and deletes a custom role no user holds — and a CSV
export/import of the whole configuration.

## Hiding is not a permission

Hiding a menu removes clutter; it does not stop anyone who knows the URL. If a
role must not reach a page, remove the capability that grants it. This plugin
does not try to replace capabilities, and says so on the screen rather than
implying a security boundary it does not provide.

The two things it does enforce — the editable-accounts scope and the
completed-order lock — are checked server-side.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- WooCommerce is optional: without it, everything but the order lock and the
  WooCommerce side of the account scope works unchanged

## Installation

Copy the folder into `wp-content/plugins/`, activate, and open
**Settings → Dahu Role Manager**.

## Hooks

| Hook | Type | What it does |
|---|---|---|
| `dahu_role_manager_extra_role_caps` | filter | Capabilities to add, keyed by role slug. Empty by default; only ever adds. |
| `dahu_role_manager_excluded_editable_roles` | filter | Roles no other role may ever manage. Defaults to `administrator`, `shop_manager`. |
| `dahu_role_manager_roles_barred_from_managing_accounts` | filter | Roles that may never be given an account scope. |
| `dahu_role_manager_hidden_adminbar_css` | filter | The CSS applied when the admin bar is hidden entirely, as selector => declarations. |

## Uninstalling

Removes this plugin's settings and nothing else. **Roles are left untouched** —
users hold them and other plugins may grant capabilities on them, so removing
them on uninstall would silently change who can do what. Deleting a role stays a
deliberate act, done from the Roles tab.

## License

MIT. See [LICENSE](LICENSE).

Hugo Vial-Jaime — Dahu-Concept
