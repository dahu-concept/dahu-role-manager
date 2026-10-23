=== Dahu Role Manager ===
Contributors: hugovialjaime
Tags: roles, capabilities, admin menu, user management, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.2.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Shape the admin for each role: which menus they see, which accounts they may edit, where they land on login. Duplicate, rename and delete roles.

== Description ==

WordPress gives every role the same admin. Dahu Role Manager lets you decide, role by role, what that admin actually looks like — without writing a line of code and without touching a single capability.

Everything it offers to hide is discovered from your own install at runtime. Whatever plugins you run, their menus and admin-bar items show up in the list by themselves; there is no hardcoded list to wait on.

= What you can do, per role =

* **Hide menus and submenus.** Tick what that role should not see. Hiding is cosmetic by design: it tidies the admin, it is not a security boundary (see the FAQ).
* **Hide admin-bar items,** or the admin bar entirely.
* **Add a "Log out" button** at the bottom of the admin menu — the natural companion to hiding the admin bar, which otherwise takes the log-out link with it.
* **Choose where the role lands on login,** instead of the dashboard. Useful when the dashboard is hidden from their menu.
* **Hide elements by CSS selector,** for the persistent notices third-party plugins leave behind.
* **Say which accounts the role may manage.** By default WooCommerce lets a shop manager edit customers and nobody else. Here you pick exactly which account roles each manager role may view, edit, create and delete — and the capabilities needed to do so are granted automatically, because a scope with no means to use it is no use to anyone.
* **Lock completed WooCommerce orders.** Once an order is completed, its "Order details" panel becomes read-only for that role: status, customer, creation date, addresses, payment method.

= Managing roles =

A second tab creates a role by **duplicating** an existing one. The new role receives exactly the source role's capabilities — nothing added, nothing removed — which is the tedious part to do by hand. Fine-tuning stays with whatever role editor you already use; this plugin does not try to replace one.

You can also rename a role's display name, and delete a custom role. Deletion is refused while any user still holds it, so no account is ever left without a role.

= Export and import =

The whole configuration goes out as one CSV file (`role,type,value`) and comes back in the same way. An import is a complete replacement, not a merge, so the same file always produces the same result — which is what makes it usable for moving a configuration from staging to production.

= Built-in roles are protected =

The WordPress built-ins, WooCommerce's `customer` and `shop_manager`, and whatever role your site uses as its default for new registrations can be neither renamed nor deleted here.

The administrator is treated with particular care. Menus and submenus can never be hidden from it: hiding one could hide this very settings page and leave you locked out. The sections that take nothing away — the admin bar, CSS hiding, the order lock — are open to it, because each one is reversible from a page it can always reach.

== Installation ==

1. Upload the `dahu-role-manager` folder to `wp-content/plugins/`, or install the
   zip from Plugins > Add New.
2. Activate the plugin.
3. Open **Settings > Dahu Role Manager**.

Pick a role in the selector at the top, tick what that role should not see, and
save. Nothing changes for any role until you configure it: a fresh install is a
no-op.

WooCommerce is optional. Without it, everything but the order lock and the
WooCommerce side of the editable-accounts scope works unchanged.

== Frequently Asked Questions ==

= Does hiding a menu stop that role from reaching the page? =

No, and it is important to be clear about this. Hiding is about tidiness: it removes clutter so people see the part of the admin that is theirs. Someone who knows the URL can still open the page. If a role must not reach a page, remove the capability that grants it — that is what capabilities are for, and this plugin deliberately does not pretend to replace them.

The one place this plugin does enforce something is the editable-accounts scope and the completed-order lock. Both are checked server-side, not in the browser.

= Will uninstalling delete the roles I created? =

No. Uninstalling removes this plugin's settings and nothing else: every menu comes back, every admin bar comes back, no order is locked. The roles themselves survive, because users hold them and other plugins may grant capabilities on them. Deleting a role stays a deliberate act, done from the Roles tab.

= Does it need WooCommerce? =

No. Everything but the order lock and the WooCommerce side of the editable-accounts scope works on a plain WordPress install. The WooCommerce parts check for WooCommerce themselves and stay quiet when it is absent.

= Does it work with HPOS? =

Yes. The order lock covers both the legacy order screen and High-Performance Order Storage, along with the order list's quick actions and bulk actions.

= A role created by another plugin has capitals in its slug. Is that a problem? =

No. Role slugs are compared to what is really registered rather than normalised, precisely so roles created by other tools keep working.

= Can I grant extra capabilities from code? =

Yes, with the `dahu_role_manager_extra_role_caps` filter, which is empty by default:

`add_filter( 'dahu_role_manager_extra_role_caps', function ( $caps ) {
    $caps['editor'] = array( 'some_plugin_manage_items' );
    return $caps;
} );`

Capabilities are only ever added, never removed.

== Screenshots ==

1. Per-role settings: menus and submenus discovered from the live install.
2. The admin-bar section, grouped by root node.
3. Role management: duplicate, rename, delete.
4. CSV export and import of the whole configuration.

== Changelog ==

= 3.2.1 =
* The Menus and Admin bar cards now say so when the admin menu or the admin bar could not be read, instead of showing a "check all" box above an empty list.
* The selector example in the hiding field is no longer written in French.

= 3.2.0 =
* The activation screen now opens on the plugin's own mark rather than its initials, and the banner carries the same drawing as a watermark.

= 3.1.0 =
* Fixed: creating, renaming or deleting a role, and the extra-capabilities hook, now read the roles straight from the database before writing. WordPress rewrites the whole roles option from its in-memory copy, so a copy served stale by a persistent object cache could erase roles created since it was cached.
* Fixed: the extra-capabilities hook writes nothing at all when the capability is already granted in the database, and leaves a log line instead.
* Fixed: a role slug already saved in an editable-accounts list is kept even while that role is missing from the live roles, instead of being dropped silently. Deleting a role from the Roles tab still removes its slug from every list — including when the role had already gone from the database, which previously left the slug in those lists for good.
* Fixed: the extra-capabilities hook refuses to write when the roles cannot be read from the database, instead of calling the check and ignoring its answer. That hook runs on every request, so it was the one place where writing from a stale copy did the most damage.
* Fixed: an operation refused because the roles could not be read now says so, instead of claiming the role is protected — a message that named a cause which was not the cause, and left no way to tell the two apart.
* Fixed: the reload names the site it is reading, so on a network it can no longer validate against one site's roles while writing another's.

= 3.0.0 =
* First public release.

== Upgrade Notice ==

= 3.1.0 =
Recommended on any site with a persistent object cache (Redis, Memcached).

= 3.0.0 =
First public release.
