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

        // Display backorder progress on single product pages.
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_backorder_progress' ), 25 );

        // Add backorder data to variations for dynamic updates.
        add_filter( 'woocommerce_available_variation', array( $this, 'add_backorder_data_to_variations' ), 10, 3 );

        // Add custom fields for variation backorder limits in the admin interface.
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_backorder_fields' ), 10, 3 );

        // Save custom fields for variation backorder limits.
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_backorder_fields' ), 10, 2 );

        // Enforce backorder limits and notify admin.
        add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'enforce_backorder_limit' ) );
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

            if ( 'yes' === $backorders_allowed ) {
                $new_sold = $current_sold + $quantity;

                // Update sold count.
                update_post_meta( $target_id, '_backorder_sold', $new_sold );

                // Check if limit exceeded.
                if ( $new_sold > $backorder_limit && $backorder_limit > 0 ) {
                    update_post_meta( $target_id, '_backorders', 'no' ); // Disable backorders.
                    $this->notify_admin_limit_exceeded( $target_id, $new_sold, $backorder_limit );
                }

                // Ensure stock status reflects backorder status.
                update_post_meta( $target_id, '_stock_status', 'onbackorder' );
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
     * Adds backorder data to variations for dynamic updates.
     *
     * @param array $variation_data The variation data array.
     * @param WC_Product $product The parent product object.
     * @param WC_Product_Variation $variation The variation object.
     *
     * @return array Modified variation data array.
     */
    public function add_backorder_data_to_variations( $variation_data, $product, $variation ) {
        $variation_id = $variation->get_id();
        $backorders = get_post_meta( $variation_id, '_backorders', true );
        $limit = (int) get_post_meta( $variation_id, '_backorder_limit', true );
        $sold = (int) get_post_meta( $variation_id, '_backorder_sold', true );

        if ( 'yes' === $backorders && $limit > 0 ) {
            $variation_data['backorder_progress'] = sprintf( __( '%d of %d sold on backorder', 'backorder-management' ), $sold, $limit );
            $variation_data['stock_status'] = 'onbackorder';
        } else {
            $variation_data['backorder_progress'] = '';
        }

        return $variation_data;
    }

    /**
     * Add custom fields for variation backorder limits in the admin interface.
     */
    public function add_variation_backorder_fields( $loop, $variation_data, $variation ) {
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
                'min' => '0',
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
}
