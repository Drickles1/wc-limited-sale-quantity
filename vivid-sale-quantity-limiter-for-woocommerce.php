<?php
/**
 * Plugin Name: Vivid - Sale Quantity Limiter for WooCommerce
 * Plugin URI: https://github.com/Drickles1/wc-limited-sale-quantity
 * Description: Cap how many units of a product sell at the WooCommerce sale price. Set an allocation (e.g. 3), and once that many units have been sold — via a WooCommerce order OR an external stock sync (inventory tools, POS systems, etc.) — the sale price is automatically removed and the product reverts to regular price, even if more physical stock remains.
 * Version: 1.2.4
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * Author: Drickles
 * Author URI: https://github.com/Drickles1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: vivid-sale-quantity-limiter-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress 6.5+ enforces the "Requires Plugins" header automatically, but
 * older versions don't — guard directly so a site without WooCommerce active
 * gets a plain admin notice instead of a fatal error from the WC_Product
 * class references below.
 */
function lsqw_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p>'
        . esc_html__( 'Vivid - Sale Quantity Limiter for WooCommerce requires WooCommerce to be installed and active.', 'vivid-sale-quantity-limiter-for-woocommerce' )
        . '</p></div>';
}

function lsqw_is_woocommerce_active() {
    $active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) );
    if ( in_array( 'woocommerce/woocommerce.php', $active_plugins, true ) ) {
        return true;
    }

    if ( is_multisite() ) {
        $network_active = get_site_option( 'active_sitewide_plugins', array() );
        return isset( $network_active['woocommerce/woocommerce.php'] );
    }

    return false;
}

if ( ! lsqw_is_woocommerce_active() ) {
    add_action( 'admin_notices', 'lsqw_woocommerce_missing_notice' );
    return;
}

define( 'LSQW_ALLOCATION_META', '_lsqw_sale_qty_allocation' );
define( 'LSQW_REMAINING_META', '_lsqw_sale_qty_remaining' );
define( 'LSQW_LAST_SEEN_STOCK_META', '_lsqw_sale_qty_last_seen_stock' );
define( 'LSQW_REARM_FIELD', '_lsqw_sale_qty_rearm' );

/**
 * Apply the allocation number from a product edit save. The number itself is
 * always stored as plain config with no side effects. The remaining/last-seen
 * counters — the ones that actually gate the sale price — are ONLY touched
 * when $rearm is true (the admin explicitly ticked the "start new batch"
 * checkbox on this save). This is deliberate: the allocation text field is
 * pre-filled with its current value, so it resubmits on every product save
 * whether or not the admin meant to touch it. Inferring "re-arm" from the
 * number alone (e.g. "remaining hit 0, so treat any resubmission as a signal
 * to restart") would silently reactivate a sale that already sold out the
 * next time the admin edits anything else on the product — an explicit
 * checkbox is the only way to make "manual re-entry only" actually manual.
 */
function lsqw_apply_allocation( $product, $submitted_value, $rearm ) {
    if ( null === $submitted_value ) {
        return;
    }

    $allocation = absint( $submitted_value );
    $product->update_meta_data( LSQW_ALLOCATION_META, $allocation );

    if ( $allocation <= 0 ) {
        // Field cleared to 0/blank: disable the feature for this product.
        $product->delete_meta_data( LSQW_REMAINING_META );
        $product->delete_meta_data( LSQW_LAST_SEEN_STOCK_META );
        return;
    }

    if ( $rearm ) {
        $product->update_meta_data( LSQW_REMAINING_META, $allocation );
        $product->update_meta_data( LSQW_LAST_SEEN_STOCK_META, $product->get_stock_quantity() );
    }
}

/* ---------------------------------------------------------------------
 * Admin UI — simple products (General / Pricing tab)
 * ------------------------------------------------------------------ */

add_action( 'woocommerce_product_options_pricing', function () {
    global $product_object;

    $allocation = $product_object ? $product_object->get_meta( LSQW_ALLOCATION_META ) : '';
    $remaining  = $product_object ? $product_object->get_meta( LSQW_REMAINING_META ) : '';

    woocommerce_wp_text_input( array(
        'id'                => LSQW_ALLOCATION_META,
        'label'             => __( 'Sale Quantity Allocation', 'vivid-sale-quantity-limiter-for-woocommerce' ),
        'description'       => __( 'Max units to sell at the sale price above. Once that many units sell (by order or stock sync), the sale price is auto-removed, even if stock remains. Leave blank/0 to disable.', 'vivid-sale-quantity-limiter-for-woocommerce' ),
        'desc_tip'          => true,
        'type'              => 'number',
        'custom_attributes' => array( 'step' => '1', 'min' => '0' ),
        'value'             => $allocation,
    ) );

    woocommerce_wp_checkbox( array(
        'id'          => LSQW_REARM_FIELD,
        'label'       => __( 'Start new limited-sale batch now', 'vivid-sale-quantity-limiter-for-woocommerce' ),
        'description' => __( 'Check this AND save to (re)arm the allocation above. Required every time you want to start or restart a batch — leaving it unchecked on a routine save never touches the counter, even if this same allocation number was already used up before.', 'vivid-sale-quantity-limiter-for-woocommerce' ),
    ) );

    if ( '' !== $remaining && null !== $remaining ) {
        echo '<p class="form-field lsqw-remaining-display">'
            . '<label>' . esc_html__( 'Remaining at Sale Price', 'vivid-sale-quantity-limiter-for-woocommerce' ) . '</label>'
            . '<span>' . esc_html( $remaining ) . '</span>'
            . '</p>';
    }
} );

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
    // woocommerce_process_product_meta only fires after
    // WC_Admin_Meta_Boxes::save() has already verified this same nonce
    // (see includes/admin/class-wc-admin-meta-boxes.php), but we verify it
    // again explicitly here rather than relying on that upstream check.
    if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
        return;
    }

    $product = wc_get_product( $post_id );
    if ( ! $product ) {
        return;
    }

    $submitted = isset( $_POST[ LSQW_ALLOCATION_META ] ) ? sanitize_text_field( wp_unslash( $_POST[ LSQW_ALLOCATION_META ] ) ) : '0';
    $rearm     = ! empty( $_POST[ LSQW_REARM_FIELD ] );
    lsqw_apply_allocation( $product, '' === $submitted ? '0' : $submitted, $rearm );
    $product->save();
} );

/* ---------------------------------------------------------------------
 * Admin UI — variations
 * ------------------------------------------------------------------ */

add_action( 'woocommerce_variation_options_pricing', function ( $loop, $variation_data, $variation ) {
    $variation_product = wc_get_product( $variation->ID );
    $allocation         = $variation_product ? $variation_product->get_meta( LSQW_ALLOCATION_META ) : '';
    $remaining          = $variation_product ? $variation_product->get_meta( LSQW_REMAINING_META ) : '';

    echo '<div class="form-row form-row-full lsqw-variation-row">';
    woocommerce_wp_text_input( array(
        'id'                => LSQW_ALLOCATION_META . '[' . $loop . ']',
        'label'             => __( 'Sale Qty Allocation', 'vivid-sale-quantity-limiter-for-woocommerce' ),
        'wrapper_class'     => 'form-row form-row-full',
        'type'              => 'number',
        'custom_attributes' => array( 'step' => '1', 'min' => '0' ),
        'value'             => $allocation,
    ) );
    woocommerce_wp_checkbox( array(
        'id'            => LSQW_REARM_FIELD . '[' . $loop . ']',
        'label'         => __( 'Start new batch now', 'vivid-sale-quantity-limiter-for-woocommerce' ),
        'wrapper_class' => 'form-row form-row-full',
        'description'   => __( 'Check to (re)arm the allocation above.', 'vivid-sale-quantity-limiter-for-woocommerce' ),
    ) );
    if ( '' !== $remaining && null !== $remaining ) {
        echo '<p class="lsqw-remaining-display">' . esc_html__( 'Remaining at sale price:', 'vivid-sale-quantity-limiter-for-woocommerce' ) . ' ' . esc_html( $remaining ) . '</p>';
    }
    echo '</div>';
}, 10, 3 );

add_action( 'woocommerce_save_product_variation', function ( $variation_id, $index ) {
    // woocommerce_save_product_variation only fires after
    // WC_AJAX::save_variations() has already verified this same nonce
    // (see includes/class-wc-ajax.php), but we verify it again explicitly
    // here rather than relying on that upstream check.
    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'save-variations' ) ) {
        return;
    }

    $variation = wc_get_product( $variation_id );
    if ( ! $variation ) {
        return;
    }

    $submitted = isset( $_POST[ LSQW_ALLOCATION_META ][ $index ] ) ? sanitize_text_field( wp_unslash( $_POST[ LSQW_ALLOCATION_META ][ $index ] ) ) : '0';
    $rearm     = ! empty( $_POST[ LSQW_REARM_FIELD ][ $index ] );
    lsqw_apply_allocation( $variation, '' === $submitted ? '0' : $submitted, $rearm );
    $variation->save();
}, 10, 2 );

/* ---------------------------------------------------------------------
 * Stock-change watcher — fires no matter what dropped the stock
 * (a paid order, a manual edit, or an external inventory sync).
 * ------------------------------------------------------------------ */

function lsqw_handle_stock_change( $product ) {
    static $in_progress = array();

    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $id = $product->get_id();
    if ( ! empty( $in_progress[ $id ] ) ) {
        // Reentrancy guard: our own save() below re-fires this same action.
        return;
    }

    $allocation = (int) $product->get_meta( LSQW_ALLOCATION_META );
    if ( $allocation <= 0 ) {
        return;
    }

    $new_stock = $product->get_stock_quantity();
    if ( null === $new_stock ) {
        return;
    }

    $last_seen_raw = $product->get_meta( LSQW_LAST_SEEN_STOCK_META );
    $last_seen     = ( '' === $last_seen_raw || null === $last_seen_raw ) ? $new_stock : (int) $last_seen_raw;

    if ( $new_stock === $last_seen ) {
        // Nothing changed since we last looked — critically, do NOT save()
        // here, or a stock-managed product's save() re-fires this same
        // action forever (infinite recursion, since new_stock === last_seen
        // stays true on every recursive re-entry).
        return;
    }

    $in_progress[ $id ] = true;

    if ( $new_stock < $last_seen ) {
        $dropped   = $last_seen - $new_stock;
        $remaining = max( 0, (int) $product->get_meta( LSQW_REMAINING_META ) - $dropped );
        $product->update_meta_data( LSQW_REMAINING_META, $remaining );

        if ( $remaining <= 0 && $product->is_on_sale() ) {
            $product->set_sale_price( '' );
            $product->set_date_on_sale_from( '' );
            $product->set_date_on_sale_to( '' );
        }
    }

    $product->update_meta_data( LSQW_LAST_SEEN_STOCK_META, $new_stock );
    $product->save();

    unset( $in_progress[ $id ] );
}
add_action( 'woocommerce_product_set_stock', 'lsqw_handle_stock_change' );
add_action( 'woocommerce_variation_set_stock', 'lsqw_handle_stock_change' );

/* ---------------------------------------------------------------------
 * Frontend badge — "Only N left at this price!"
 * ------------------------------------------------------------------ */

function lsqw_render_badge() {
    global $product;
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $allocation = (int) $product->get_meta( LSQW_ALLOCATION_META );
    if ( $allocation <= 0 || ! $product->is_on_sale() ) {
        return;
    }

    $remaining = (int) $product->get_meta( LSQW_REMAINING_META );
    if ( $remaining <= 0 ) {
        return;
    }

    printf(
        '<p class="lsqw-sale-qty-badge">%s</p>',
        esc_html( sprintf(
            /* translators: %d: number of units remaining at the sale price */
            _n( 'Only %d left at this price!', 'Only %d left at this price!', $remaining, 'vivid-sale-quantity-limiter-for-woocommerce' ),
            $remaining
        ) )
    );
}
add_action( 'woocommerce_single_product_summary', 'lsqw_render_badge', 11 );
add_action( 'woocommerce_after_shop_loop_item_title', 'lsqw_render_badge', 11 );
