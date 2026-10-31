<?php
/**
 * Locking completed WooCommerce orders, per role.
 *
 * Per-role option `lock_completed_orders`: once an order reaches the
 * "completed" status, nothing in the "Order details" panel can be changed any
 * more — status, customer, creation date, billing and shipping addresses,
 * payment method. While the order is NOT completed, nothing behaves
 * differently.
 *
 * THREE deliberately redundant levels:
 *
 * 1. woocommerce_process_shop_order_meta — WooCommerce's own panel-saving
 *    callback is REMOVED. This is the structural choice: one point of code
 *    covers every field in the panel, including fields WooCommerce adds later.
 *    The alternative, neutering $_POST keys one by one, would mean maintaining
 *    a list of fields, and worse, WC_Meta_Box_Order_Data::save() reads some
 *    missing keys as "clear this value" — so that approach would erase data
 *    while appearing to protect it.
 *
 * 2. woocommerce_before_order_object_save — level 1 only covers the edit
 *    screen. This one covers every other path (the order list's quick actions,
 *    bulk actions, HPOS as well as legacy), since they all go through
 *    WC_Order::save().
 *
 * 3. The interface — the panel's controls are disabled and made inert. Visual
 *    comfort only: the real protection is the two server-side levels.
 *
 * DELIBERATE SILENCE: no message, banner or tooltip appears on the screen of a
 * locked order. A consequence of that choice is that level 2's error notice can
 * no longer fire from this screen, since the status is not even read there. It
 * stays active from the order list and from bulk actions.
 *
 * @package DahuRoleManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the lock on for the current user, through any of their roles?
 *
 * Available for the administrator role too. The option is strictly opt-in, and
 * an administrator who turns it on for their own role still reaches the
 * settings page to turn it back off.
 *
 * @return bool
 */
function dahu_role_manager_completed_orders_locked() {

	foreach ( dahu_role_manager_get_current_user_role_settings() as $role_settings ) {
		if ( ! empty( $role_settings['lock_completed_orders'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The order shown on the current edit screen, or null.
 *
 * Shared by the server-side lock and the interface lock: HPOS passes the id as
 * `?id=`, the legacy screen as `?post=`.
 *
 * @return WC_Order|null
 */
function dahu_role_manager_get_current_screen_order() {

	if ( ! function_exists( 'wc_get_order' ) ) {
		return null;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
		return null;
	}

	$order_id = 0;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which order is on screen, nothing is changed.
	if ( isset( $_GET['id'] ) ) {
		$order_id = (int) $_GET['id'];
	} elseif ( isset( $_GET['post'] ) ) {
		$order_id = (int) $_GET['post'];
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( ! $order_id ) {
		return null;
	}

	$order = wc_get_order( $order_id );

	return $order ? $order : null;
}

/**
 * LEVEL 1 — Neutralize saving the "Order details" panel of a completed order.
 *
 * WooCommerce's callback is removed rather than its fields filtered; see the
 * explanation at the top of this file.
 *
 * The native callback's priority is LOOKED UP AT RUNTIME with has_action(),
 * never hardcoded. It is 40 in current WooCommerce versions, but that is an
 * internal detail: should WooCommerce ever change it, a remove_action() written
 * against 40 would fail silently and the lock would quietly stop working.
 *
 * Priority 1, to run before the native callback whatever its own priority. The
 * scope is strictly this panel: the order's line items, and other plugins'
 * metaboxes, keep their own saving and stay editable.
 *
 * @param int           $order_id The order being saved.
 * @param WC_Order|null $order    The order object, when WooCommerce passes one.
 * @return void
 */
function dahu_role_manager_lock_completed_order_panel_save( $order_id, $order = null ) {

	if ( ! dahu_role_manager_completed_orders_locked() || ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order_id );

	if ( ! $order || 'completed' !== $order->get_status() ) {
		return;
	}

	$callback = 'WC_Meta_Box_Order_Data::save';
	$priority = has_action( 'woocommerce_process_shop_order_meta', $callback );

	if ( false !== $priority ) {
		remove_action( 'woocommerce_process_shop_order_meta', $callback, $priority );
	}
}
add_action( 'woocommerce_process_shop_order_meta', 'dahu_role_manager_lock_completed_order_panel_save', 1, 2 );

/**
 * LEVEL 2 — Refuse a status change away from "completed".
 *
 * Covers the paths that do not go through the edit screen: the order list's
 * quick actions and bulk actions.
 *
 * @param WC_Order $order The order about to be saved.
 * @return void
 */
function dahu_role_manager_lock_completed_order_status( $order ) {

	if ( ! $order || ! $order->get_id() || ! dahu_role_manager_completed_orders_locked() ) {
		return;
	}

	$changes = $order->get_changes();

	if ( empty( $changes['status'] ) ) {
		return;
	}

	// WC_Data::get_data() returns the persisted values: pending changes are
	// only merged in at save time, which is what makes the comparison possible.
	$data       = $order->get_data();
	$old_status = isset( $data['status'] ) ? str_replace( 'wc-', '', (string) $data['status'] ) : '';
	$new_status = str_replace( 'wc-', '', (string) $changes['status'] );

	if ( 'completed' === $old_status && 'completed' !== $new_status ) {
		$order->set_status( 'completed' );
		set_transient( 'dahu_role_manager_locked_order_notice_' . get_current_user_id(), $order->get_id(), 120 );
	}
}
add_action( 'woocommerce_before_order_object_save', 'dahu_role_manager_lock_completed_order_status' );

/**
 * Tell the user their status change was refused.
 *
 * @return void
 */
function dahu_role_manager_locked_order_notice() {

	$key      = 'dahu_role_manager_locked_order_notice_' . get_current_user_id();
	$order_id = get_transient( $key );

	if ( ! $order_id ) {
		return;
	}

	delete_transient( $key );
	?>
	<div class="notice notice-error is-dismissible">
		<p>
			<?php
			printf(
				/* translators: 1: order number, 2: the bolded order status "Completed". */
				esc_html__( 'Order #%1$s is %2$s: its status is locked for your role and was not changed.', 'dahu-role-manager' ),
				esc_html( $order_id ),
				'<strong>' . esc_html_x( 'Completed', 'Order status', 'dahu-role-manager' ) . '</strong>'
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'dahu_role_manager_locked_order_notice' );

/**
 * LEVEL 3 — Make the panel inert on screen.
 *
 * Two mechanisms, both GENERIC: the panel's container is targeted, never a list
 * of selectors field by field. Such a list would need maintaining at every
 * WooCommerce release, and the first field forgotten would leave a hole.
 *
 * - `disabled` on the form controls: blocks typing AND the keyboard (a disabled
 *   field drops out of the tab order), and is not submitted anyway.
 * - `pointer-events: none` in CSS: reaches what `disabled` cannot — the
 *   "edit address" pencils, which are links, and the select2 containers, which
 *   render in a div separate from the original select.
 *
 * The CSS is deliberately restricted to the controls and the pencils, and is
 * NOT applied to the whole panel: `pointer-events: none` on the container would
 * also make its TEXT unselectable, so anything another plugin prints inside
 * this panel could no longer be copied.
 *
 * Reapplied on `load` as well as `DOMContentLoaded`: select2 initializes on
 * jQuery's ready, whose order relative to this script is not guaranteed. The
 * second pass catches the controls rendered in between.
 *
 * @return void
 */
function dahu_role_manager_lock_completed_order_panel_ui() {

	if ( ! dahu_role_manager_completed_orders_locked() ) {
		return;
	}

	$order = dahu_role_manager_get_current_screen_order();

	if ( ! $order || 'completed' !== $order->get_status() ) {
		return;
	}
	?>
	<style id="dahu-role-manager-lock-completed-order">
		#woocommerce-order-data input,
		#woocommerce-order-data select,
		#woocommerce-order-data textarea,
		#woocommerce-order-data a.edit_address,
		#woocommerce-order-data .select2-container {
			pointer-events: none;
		}
	</style>
	<script>
	(function () {
		function dahuLockOrderPanel() {
			// Falls back to #order_data when the metabox is not found by its id,
			// so the panel stays locked even if WooCommerce ever renames the
			// metabox that wraps it.
			var panel = document.getElementById('woocommerce-order-data')
					 || document.getElementById('order_data');
			if (!panel) {
				return;
			}
			var controls = panel.querySelectorAll('input, select, textarea');
			for (var i = 0; i < controls.length; i++) {
				controls[i].disabled = true;
			}
		}
		document.addEventListener('DOMContentLoaded', dahuLockOrderPanel);
		window.addEventListener('load', dahuLockOrderPanel);
	})();
	</script>
	<?php
}
add_action( 'admin_head', 'dahu_role_manager_lock_completed_order_panel_ui' );
