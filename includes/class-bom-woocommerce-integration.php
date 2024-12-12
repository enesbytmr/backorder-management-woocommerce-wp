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
    }

    /**
     * Updates the backorder sales count for products and variations after an order is completed.
     *
     * @param int $order_id The ID of the completed order.
     */
    public function update_backorder_sales_count( $order_id ) {
        // Retrieve the order object.
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Iterate through each item in the order.
        foreach ( $order->get_items() as $item ) {
            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $quantity     = $item->get_quantity();

            // Determine the ID to update (variation ID if exists, otherwise product ID).
            $target_id = $variation_id ? $variation_id : $product_id;

            // Check if backorders are enabled for this product or variation.
            $backorders_allowed = get_post_meta( $target_id, '_backorders', true );
            if ( 'yes' === $backorders_allowed ) {
                // Retrieve the current sold count.
                $current_sold = (int) get_post_meta( $target_id, '_backorder_sold', true );

                // Update the sold count by adding the quantity from the order.
                update_post_meta( $target_id, '_backorder_sold', $current_sold + $quantity );

                // Ensure stock status is set to on backorder if applicable.
                update_post_meta( $target_id, '_stock_status', 'onbackorder' );
            }
        }
    }

    /**
     * Displays the backorder progress on the single product page.
     */
    public function display_backorder_progress() {
        global $product;

        if ( ! $product ) {
            return;
        }

        // Determine the product or variation ID.
        $product_id = $product->get_id();

        // Check if backorders are enabled for this product or variation.
        $backorders_allowed = get_post_meta( $product_id, '_backorders', true );
        if ( 'yes' === $backorders_allowed ) {
            // Retrieve the backorder limit and current sold count.
            $backorder_limit = (int) get_post_meta( $product_id, '_backorder_limit', true );
            $backorder_sold  = (int) get_post_meta( $product_id, '_backorder_sold', true );

            // Display the backorder progress if a limit is set.
            if ( $backorder_limit > 0 ) {
                echo '<p class="backorder-progress">';
                printf( esc_html__( '%d of %d items sold on backorder.', 'backorder-management' ), $backorder_sold, $backorder_limit );
                echo '</p>';

                // Display stock status for clarity.
                echo '<p class="backorder-stock-status">';
                esc_html_e( 'Stock Status: On Backorder', 'backorder-management' );
                echo '</p>';
            }
        }
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
