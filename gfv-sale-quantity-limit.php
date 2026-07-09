<?php
/**
 * Plugin Name: WooCommerce Limited Sale Quantity
 * Plugin URI: https://github.com/Drickles1/wc-limited-sale-quantity
 * Description: Cap how many units of a product sell at the WooCommerce sale price. Set an allocation (e.g. 3), and once that many units have been sold — via a WooCommerce order OR an external stock sync (inventory tools, POS systems, etc.) — the sale price is automatically removed and the product reverts to regular price, even if more physical stock remains.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Author: Drickles
 * Author URI: https://github.com/Drickles1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gfv-sql
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GFV_SQL_ALLOCATION_META', '_gfv_sale_qty_allocation' );
define( 'GFV_SQL_REMAINING_META', '_gfv_sale_qty_remaining' );
define( 'GFV_SQL_LAST_SEEN_STOCK_META', '_gfv_sale_qty_last_seen_stock' );
define( 'GFV_SQL_REARM_FIELD', '_gfv_sale_qty_rearm' );

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
function gfv_sql_apply_allocation( $product, $submitted_value, $rearm ) {
    if ( null === $submitted_value ) {
        return;
    }

    $allocation = absint( $submitted_value );
    $product->update_meta_data( GFV_SQL_ALLOCATION_META, $allocation );

    if ( $allocation <= 0 ) {
        // Field cleared to 0/blank: disable the feature for this product.
        $product->delete_meta_data( GFV_SQL_REMAINING_META );
        $product->delete_meta_data( GFV_SQL_LAST_SEEN_STOCK_META );
        return;
    }

    if ( $rearm ) {
        $product->update_meta_data( GFV_SQL_REMAINING_META, $allocation );
        $product->update_meta_data( GFV_SQL_LAST_SEEN_STOCK_META, $product->get_stock_quantity() );
    }
}

/* ---------------------------------------------------------------------
 * Admin UI — simple products (General / Pricing tab)
 * ------------------------------------------------------------------ */

add_action( 'woocommerce_product_options_pricing', function () {
    global $product_object;

    $allocation = $product_object ? $product_object->get_meta( GFV_SQL_ALLOCATION_META ) : '';
    $remaining  = $product_object ? $product_object->get_meta( GFV_SQL_REMAINING_META ) : '';

    woocommerce_wp_text_input( array(
        'id'                => GFV_SQL_ALLOCATION_META,
        'label'             => __( 'Sale Quantity Allocation', 'gfv-sql' ),
        'description'       => __( 'Max units to sell at the sale price above. Once that many units sell (by order or stock sync), the sale price is auto-removed, even if stock remains. Leave blank/0 to disable.', 'gfv-sql' ),
        'desc_tip'          => true,
        'type'              => 'number',
        'custom_attributes' => array( 'step' => '1', 'min' => '0' ),
        'value'             => $allocation,
    ) );

    woocommerce_wp_checkbox( array(
        'id'          => GFV_SQL_REARM_FIELD,
        'label'       => __( 'Start new limited-sale batch now', 'gfv-sql' ),
        'description' => __( 'Check this AND save to (re)arm the allocation above. Required every time you want to start or restart a batch — leaving it unchecked on a routine save never touches the counter, even if this same allocation number was already used up before.', 'gfv-sql' ),
    ) );

    if ( '' !== $remaining && null !== $remaining ) {
        echo '<p class="form-field gfv-sql-remaining-display">'
            . '<label>' . esc_html__( 'Remaining at Sale Price', 'gfv-sql' ) . '</label>'
            . '<span>' . esc_html( $remaining ) . '</span>'
            . '</p>';
    }
} );

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
    $product = wc_get_product( $post_id );
    if ( ! $product ) {
        return;
    }

    $submitted = isset( $_POST[ GFV_SQL_ALLOCATION_META ] ) ? sanitize_text_field( wp_unslash( $_POST[ GFV_SQL_ALLOCATION_META ] ) ) : '0';
    $rearm     = ! empty( $_POST[ GFV_SQL_REARM_FIELD ] );
    gfv_sql_apply_allocation( $product, '' === $submitted ? '0' : $submitted, $rearm );
    $product->save();
} );

/* ---------------------------------------------------------------------
 * Admin UI — variations
 * ------------------------------------------------------------------ */

add_action( 'woocommerce_variation_options_pricing', function ( $loop, $variation_data, $variation ) {
    $variation_product = wc_get_product( $variation->ID );
    $allocation         = $variation_product ? $variation_product->get_meta( GFV_SQL_ALLOCATION_META ) : '';
    $remaining          = $variation_product ? $variation_product->get_meta( GFV_SQL_REMAINING_META ) : '';

    echo '<div class="form-row form-row-full gfv-sql-variation-row">';
    woocommerce_wp_text_input( array(
        'id'                => GFV_SQL_ALLOCATION_META . '[' . $loop . ']',
        'label'             => __( 'Sale Qty Allocation', 'gfv-sql' ),
        'wrapper_class'     => 'form-row form-row-full',
        'type'              => 'number',
        'custom_attributes' => array( 'step' => '1', 'min' => '0' ),
        'value'             => $allocation,
    ) );
    woocommerce_wp_checkbox( array(
        'id'            => GFV_SQL_REARM_FIELD . '[' . $loop . ']',
        'label'         => __( 'Start new batch now', 'gfv-sql' ),
        'wrapper_class' => 'form-row form-row-full',
        'description'   => __( 'Check to (re)arm the allocation above.', 'gfv-sql' ),
    ) );
    if ( '' !== $remaining && null !== $remaining ) {
        echo '<p class="gfv-sql-remaining-display">' . esc_html__( 'Remaining at sale price:', 'gfv-sql' ) . ' ' . esc_html( $remaining ) . '</p>';
    }
    echo '</div>';
}, 10, 3 );

add_action( 'woocommerce_save_product_variation', function ( $variation_id, $index ) {
    $variation = wc_get_product( $variation_id );
    if ( ! $variation ) {
        return;
    }

    $submitted = isset( $_POST[ GFV_SQL_ALLOCATION_META ][ $index ] ) ? sanitize_text_field( wp_unslash( $_POST[ GFV_SQL_ALLOCATION_META ][ $index ] ) ) : '0';
    $rearm     = ! empty( $_POST[ GFV_SQL_REARM_FIELD ][ $index ] );
    gfv_sql_apply_allocation( $variation, '' === $submitted ? '0' : $submitted, $rearm );
    $variation->save();
}, 10, 2 );

/* ---------------------------------------------------------------------
 * Stock-change watcher — fires no matter what dropped the stock
 * (a paid order, a manual edit, or an external inventory sync).
 * ------------------------------------------------------------------ */

function gfv_sql_handle_stock_change( $product ) {
    static $in_progress = array();

    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $id = $product->get_id();
    if ( ! empty( $in_progress[ $id ] ) ) {
        // Reentrancy guard: our own save() below re-fires this same action.
        return;
    }

    $allocation = (int) $product->get_meta( GFV_SQL_ALLOCATION_META );
    if ( $allocation <= 0 ) {
        return;
    }

    $new_stock = $product->get_stock_quantity();
    if ( null === $new_stock ) {
        return;
    }

    $last_seen_raw = $product->get_meta( GFV_SQL_LAST_SEEN_STOCK_META );
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
        $remaining = max( 0, (int) $product->get_meta( GFV_SQL_REMAINING_META ) - $dropped );
        $product->update_meta_data( GFV_SQL_REMAINING_META, $remaining );

        if ( $remaining <= 0 && $product->is_on_sale() ) {
            $product->set_sale_price( '' );
            $product->set_date_on_sale_from( '' );
            $product->set_date_on_sale_to( '' );
        }
    }

    $product->update_meta_data( GFV_SQL_LAST_SEEN_STOCK_META, $new_stock );
    $product->save();

    unset( $in_progress[ $id ] );
}
add_action( 'woocommerce_product_set_stock', 'gfv_sql_handle_stock_change' );
add_action( 'woocommerce_variation_set_stock', 'gfv_sql_handle_stock_change' );

/* ---------------------------------------------------------------------
 * Frontend badge — "Only N left at this price!"
 * ------------------------------------------------------------------ */

function gfv_sql_render_badge() {
    global $product;
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $allocation = (int) $product->get_meta( GFV_SQL_ALLOCATION_META );
    if ( $allocation <= 0 || ! $product->is_on_sale() ) {
        return;
    }

    $remaining = (int) $product->get_meta( GFV_SQL_REMAINING_META );
    if ( $remaining <= 0 ) {
        return;
    }

    printf(
        '<p class="gfv-sale-qty-badge">%s</p>',
        esc_html( sprintf(
            /* translators: %d: number of units remaining at the sale price */
            _n( 'Only %d left at this price!', 'Only %d left at this price!', $remaining, 'gfv-sql' ),
            $remaining
        ) )
    );
}
add_action( 'woocommerce_single_product_summary', 'gfv_sql_render_badge', 11 );
add_action( 'woocommerce_after_shop_loop_item_title', 'gfv_sql_render_badge', 11 );
