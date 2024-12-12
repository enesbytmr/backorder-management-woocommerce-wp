<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Class BOM_WooCommerce_Integration
 * Handles integration with WooCommerce for backorder management.
 */
class BOM_WooCommerce_Integration {

    public function __construct() {
        // Hook into order completion to update backorder sales count.
        add_action( 'woocommerce_order_status_completed', array( $this, 'update_backorder_sales_count' ) );

        // Add custom fields for variation backorder limits in the admin interface.
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_backorder_fields' ), 10, 3 );

        // Save custom fields for variation backorder limits.
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_backorder_fields' ), 10, 2 );

        // Ensure Manage Stock reflects changes from the admin page.
        add_action( 'woocommerce_update_product', array( $this, 'sync_manage_stock_with_variations' ) );
    }

    /**
     * Updates the backorder sales count for products and variations after an order is completed.
     *
     * @param int $order_id The ID of the completed order.
     */
    public function update_backorder_sales_count( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $quantity     = $item->get_quantity();

            $target_id = $variation_id ? $variation_id : $product_id;

            $backorders_allowed = get_post_meta( $target_id, '_backorders', true );
            $backorder_limit = (int) get_post_meta( $target_id, '_backorder_limit', true );
            $current_sold = (int) get_post_meta( $target_id, '_backorder_sold', true );

            if ( in_array( $backorders_allowed, array( 'yes', 'notify' ), true ) ) {
                $new_sold = $current_sold + $quantity;

                // Update sold count.
                update_post_meta( $target_id, '_backorder_sold', $new_sold );

                // Ensure stock status reflects backorder status.
                update_post_meta( $target_id, '_stock_status', 'onbackorder' );

                // Notify admin if backorder limit is exceeded.
                if ( $new_sold > $backorder_limit && $backorder_limit > 0 ) {
                    $this->notify_admin_limit_exceeded( $target_id, $new_sold, $backorder_limit );
                }
            }
        }
    }

    /**
     * Notify admin if the backorder limit is exceeded.
     */
    private function notify_admin_limit_exceeded( $product_id, $current_sold, $backorder_limit ) {
        $product = wc_get_product( $product_id );
        $subject = __( 'Backorder Limit Exceeded', 'backorder-management' );
        $message = sprintf(
            __( 'The backorder limit for %s has been exceeded. Current Sold: %d, Limit: %d.', 'backorder-management' ),
            $product->get_name(),
            $current_sold,
            $backorder_limit
        );
        wp_mail( get_option( 'admin_email' ), $subject, $message );
    }

    /**
     * Add custom fields for variation backorder limits in the admin interface.
     */
    public function add_variation_backorder_fields( $loop, $variation_data, $variation ) {
        $manage_stock = get_post_meta( $variation->ID, '_manage_stock', true );
        echo '<div class="form-row form-row-full">';

        // Backorder limit field.
        woocommerce_wp_text_input( array(
            'id'            => "_backorder_limit_{$variation->ID}",
            'label'         => __( 'Backorder Limit', 'backorder-management' ),
            'description'   => __( 'Set the backorder limit for this variation.', 'backorder-management' ),
            'value'         => get_post_meta( $variation->ID, '_backorder_limit', true ),
            'type'          => 'number',
            'desc_tip'      => true,
            'custom_attributes' => array(
                'min'      => '0',
                'readonly' => ( 'yes' !== $manage_stock ) ? 'readonly' : '',
            ),
        ) );

        // Current sold field (read-only).
        woocommerce_wp_text_input( array(
            'id'            => "_backorder_sold_{$variation->ID}",
            'label'         => __( 'Current Sold', 'backorder-management' ),
            'description'   => __( 'Number of items sold on backorder.', 'backorder-management' ),
            'value'         => get_post_meta( $variation->ID, '_backorder_sold', true ),
            'type'          => 'number',
            'custom_attributes' => array(
                'readonly' => 'readonly',
            ),
        ) );
        echo '</div>';
    }

    /**
     * Save custom fields for variation backorder limits.
     */
    public function save_variation_backorder_fields( $variation_id, $i ) {
        if ( isset( $_POST["_backorder_limit_{$variation_id}"] ) ) {
            update_post_meta( $variation_id, '_backorder_limit', absint( $_POST["_backorder_limit_{$variation_id}"] ) );
        }

        // Current sold is not updated manually; no save action needed for it.
    }

    /**
     * Sync Manage Stock settings between parent product and variations.
     *
     * @param int $product_id The parent product ID.
     */
    public function sync_manage_stock_with_variations( $product_id ) {
        $product = wc_get_product( $product_id );

        if ( $product->is_type( 'variable' ) ) {
            $variations = $product->get_children();

            foreach ( $variations as $variation_id ) {
                $manage_stock = get_post_meta( $product_id, '_manage_stock', true );
                update_post_meta( $variation_id, '_manage_stock', $manage_stock );
            }
        }
    }
}
