<?php
/**
 * Plugin Name: Backorder Management
 * Plugin URI:  https://lumiasoft.com
 * Description: A minimal viable product to manage backorders for WooCommerce products and variations by LUMIASOFT.
 * Version:     0.0.5
 * Author:      LUMIASOFT
 * Author URI:  https://lumiasoft.com
 * Text Domain: backorder-management
 * Domain Path: /languages
 *
 * @package BackorderManagement
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ensure WooCommerce is active.
if ( ! in_array(
    'woocommerce/woocommerce.php',
    apply_filters( 'active_plugins', get_option( 'active_plugins' ) ),
    true
) ) {
    // Deactivate and show a notice if WooCommerce is not found.
    add_action( 'admin_notices', 'bom_woo_missing_notice' );
    function bom_woo_missing_notice() {
        echo '<div class="error"><p><strong>Backorder Management</strong> requires WooCommerce to be installed and active.</p></div>';
    }
    return;
}

// Define plugin constants.
define( 'BOM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load text domain for translations.
add_action( 'plugins_loaded', 'bom_load_textdomain' );
function bom_load_textdomain() {
    load_plugin_textdomain( 'backorder-management', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

// Include required files.
require_once BOM_PLUGIN_DIR . 'includes/class-bom-admin-page.php';
require_once BOM_PLUGIN_DIR . 'includes/class-bom-woocommerce-integration.php';

// Initialize the plugin.
add_action( 'init', 'bom_init' );
function bom_init() {
    // Initialize WooCommerce integration.
    new BOM_WooCommerce_Integration();

    // Initialize Admin Page.
    new BOM_Admin_Page();
}

// Enqueue admin scripts and styles.
add_action( 'admin_enqueue_scripts', 'bom_admin_enqueue_assets' );
function bom_admin_enqueue_assets() {
    wp_enqueue_style( 'bom-admin-styles', BOM_PLUGIN_URL . 'assets/css/admin-styles.css', array(), '1.0.0', 'all' );
    wp_enqueue_script( 'bom-admin-scripts', BOM_PLUGIN_URL . 'assets/js/admin-scripts.js', array( 'jquery' ), '1.0.0', true );

    // Localize nonce for security.
    wp_localize_script( 'bom-admin-scripts', 'bomAjax', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'bom_nonce_action' ),
    ) );
}

// Hook into order completion to track sales vs. backorder limit.
add_action( 'woocommerce_order_status_completed', 'bom_update_sold_counts' );
function bom_update_sold_counts( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Iterate through order items.
    foreach ( $order->get_items() as $item_id => $item ) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $the_id = $variation_id ? $variation_id : $product_id;

        // Check if backorders are enabled.
        $backorders = get_post_meta( $the_id, '_backorders', true );

        if ( 'yes' === $backorders ) {
            // Increment the sold count by the quantity ordered.
            $qty = $item->get_quantity();
            $current_sold = (int) get_post_meta( $the_id, '_backorder_sold', true );
            $new_sold = $current_sold + $qty;
            update_post_meta( $the_id, '_backorder_sold', $new_sold );
        }
    }
}

// Display the progress indicator on the single product page.
add_action( 'woocommerce_single_product_summary', 'bom_display_progress_indicator', 35 );
function bom_display_progress_indicator() {
    global $product;

    if ( ! $product ) {
        return;
    }

    // For variable products, we will handle at variation level using a JS-based approach,
    // but let's handle simple products here.
    if ( $product->is_type( 'simple' ) ) {
        $backorders = get_post_meta( $product->get_id(), '_backorders', true );
        if ( 'yes' === $backorders ) {
            $limit = (int) get_post_meta( $product->get_id(), '_backorder_limit', true );
            $sold  = (int) get_post_meta( $product->get_id(), '_backorder_sold', true );

            if ( $limit > 0 ) {
                echo '<p class="backorder-progress">' . sprintf( __( '%d/%d sold on backorder', 'backorder-management' ), $sold, $limit ) . '</p>';
            }
        }
    }
}

// For variable products, we can show the info dynamically for each variation.
// The 'woocommerce_available_variation' filter is useful to add custom data to each variation.
add_filter( 'woocommerce_available_variation', 'bom_add_variation_data', 10, 3 );
function bom_add_variation_data( $variation_data, $product, $variation ) {
    $backorders = get_post_meta( $variation->get_id(), '_backorders', true );
    $limit      = (int) get_post_meta( $variation->get_id(), '_backorder_limit', true );
    $sold       = (int) get_post_meta( $variation->get_id(), '_backorder_sold', true );

    if ( 'yes' === $backorders && $limit > 0 ) {
        $variation_data['backorder_progress'] = sprintf( __( '%d/%d sold on backorder', 'backorder-management' ), $sold, $limit );
    } else {
        $variation_data['backorder_progress'] = '';
    }

    return $variation_data;
}

// On the front end, we can display variation-specific messages using a JS template or by hooking into variation templates.
// For simplicity, we've added it to the variation data above. A theme developer can print it out from variation_data if needed.
