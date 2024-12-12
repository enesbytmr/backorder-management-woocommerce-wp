<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class BOM_Admin_Page
 * Handles admin menu and page.
 */
class BOM_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
        add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
    }

    /**
     * Register the admin menu page.
     */
    public function register_menu_page() {
        add_menu_page(
            __( 'Backorder Management', 'backorder-management' ),
            __( 'Backorder Management', 'backorder-management' ),
            'manage_woocommerce',
            'bom-backorder-management',
            array( $this, 'render_admin_page' ),
            'dashicons-clipboard',
            56
        );
    }

    /**
     * Handle form submissions (enable/disable backorders and save limits).
     */
    public function handle_form_submission() {
        if ( isset( $_POST['bom_action'] ) && check_admin_referer( 'bom_nonce_action', 'bom_nonce_field' ) ) {
            $updates = isset( $_POST['bom'] ) && is_array( $_POST['bom'] ) ? $_POST['bom'] : array();
            foreach ( $updates as $product_id => $data ) {
                $product_id = absint( $product_id );

                if ( isset( $data['backorders'] ) ) {
                    $backorders_value = sanitize_text_field( $data['backorders'] );
                    update_post_meta( $product_id, '_backorders', $backorders_value );
                }

                if ( isset( $data['limit'] ) ) {
                    $limit_value = absint( $data['limit'] );
                    update_post_meta( $product_id, '_backorder_limit', $limit_value );
                }

                if ( isset( $data['backorders'] ) && 'no' === $data['backorders'] ) {
                    update_post_meta( $product_id, '_backorder_sold', 0 );
                }
            }

            wp_redirect( admin_url( 'admin.php?page=bom-backorder-management&updated=true' ) );
            exit;
        }
    }

    /**
     * Render the admin page.
     */
    public function render_admin_page() {
        $args = array(
            'post_type'      => array('product', 'product_variation'),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        );
        $products = get_posts( $args );

        wp_enqueue_style( 'woocommerce_admin_styles' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"> <?php esc_html_e( 'Backorder Management', 'backorder-management' ); ?> </h1>
            <form method="get" style="float: left; margin-top: -35px;">
                <select name="category" class="bom-category-filter">
                    <option value="all">All Categories</option>
                    <?php
                    $categories = get_terms( 'product_cat', array( 'hide_empty' => true ) );
                    foreach ( $categories as $category ) {
                        echo '<option value="' . esc_attr( $category->slug ) . '">' . esc_html( $category->name ) . '</option>';
                    }
                    ?>
                </select>
                <input type="text" name="s" placeholder="Search products" value="" />
                <button type="submit" class="button">Filter</button>
            </form>
            <form method="post" style="float: right; margin-top: -35px;">
                <?php wp_nonce_field( 'bom_nonce_action', 'bom_nonce_field' ); ?>
                <button type="submit" class="button-primary" style="margin-left: 10px;">Save Changes</button>
            </form>

            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="updated notice"><p><?php esc_html_e( 'Settings updated.', 'backorder-management' ); ?></p></div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field( 'bom_nonce_action', 'bom_nonce_field' ); ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Product', 'backorder-management' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'backorder-management' ); ?></th>
                            <th><?php esc_html_e( 'Backorders Enabled', 'backorder-management' ); ?></th>
                            <th><?php esc_html_e( 'Backorder Limit', 'backorder-management' ); ?></th>
                            <th><?php esc_html_e( 'Current Sold', 'backorder-management' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'backorder-management' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    if ( $products ) {
                        foreach ( $products as $p ) {
                            $product_obj = wc_get_product( $p->ID );
                            if ( ! $product_obj ) {
                                continue;
                            }

                            $type       = $product_obj->get_type();
                            $backorders = get_post_meta( $p->ID, '_backorders', true );
                            $limit      = get_post_meta( $p->ID, '_backorder_limit', true );
                            $sold       = get_post_meta( $p->ID, '_backorder_sold', true );

                            $backorders = $backorders ? $backorders : 'no';
                            $limit = $limit ? $limit : 0;
                            $sold = $sold ? $sold : 0;

                            // Get the edit URL for variable or simple products.
                            $edit_url = $type === 'variable' ? admin_url( 'post.php?post=' . $p->ID . '&action=edit' ) : admin_url( 'post.php?post=' . $p->ID . '&action=edit' );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $product_obj->get_name() ); ?></td>
                                <td><?php echo esc_html( ucfirst( $type ) ); ?></td>
                                <td>
                                    <select name="bom[<?php echo esc_attr( $p->ID ); ?>][backorders]">
                                        <option value="no" <?php selected( 'no', $backorders ); ?>><?php esc_html_e( 'No', 'backorder-management' ); ?></option>
                                        <option value="yes" <?php selected( 'yes', $backorders ); ?>><?php esc_html_e( 'Yes', 'backorder-management' ); ?></option>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="bom[<?php echo esc_attr( $p->ID ); ?>][limit]" value="<?php echo esc_attr( $limit ); ?>" min="0" />
                                </td>
                                <td><?php echo esc_html( $sold ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" class="button">Edit</a>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        ?>
                        <tr><td colspan="6"> <?php esc_html_e( 'No products found.', 'backorder-management' ); ?> </td></tr>
                        <?php
                    }
                    ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php
    }
}
