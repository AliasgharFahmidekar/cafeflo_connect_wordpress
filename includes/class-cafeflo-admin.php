<?php

defined( 'ABSPATH' ) || exit;

final class CafeFlo_Admin {
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'settings' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
    }

    public static function menu() {
        add_submenu_page(
            'woocommerce',
            'CafeFlo Connect',
            'CafeFlo Connect',
            'manage_woocommerce',
            'cafeflo-connect',
            array( __CLASS__, 'page' )
        );
    }

    public static function settings() {
        register_setting( 'cafeflo_connect', 'cafeflo_bridge_api_key', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_key' ) ) );
        register_setting( 'cafeflo_connect', 'cafeflo_site_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'cafeflo_connect', 'cafeflo_price_rial_to_toman', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_toggle' ) ) );
    }

    public static function sanitize_toggle( $value ) {
        return empty( $value ) ? '0' : '1';
    }

    public static function sanitize_key( $value ) {
        $value = trim( (string) $value );
        return '' === $value ? (string) get_option( 'cafeflo_bridge_api_key', wp_generate_password( 64, true, true ) ) : $value;
    }

    public static function page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage CafeFlo Connect.', 'cafeflo-connect' ) );
        }
        $health = CafeFlo_REST::health();
        $data = $health instanceof WP_REST_Response ? $health->get_data() : array();
        ?>
        <div class="wrap">
            <h1>CafeFlo Connect</h1>
            <p>Connection boundary between this WooCommerce store and the local FloCafe Bridge.</p>
            <table class="widefat striped" style="max-width:900px;margin:16px 0">
                <tbody>
                    <tr><td><strong>Site ID</strong></td><td><code><?php echo esc_html( (string) get_option( 'cafeflo_site_id', '' ) ); ?></code></td></tr>
                    <tr><td><strong>Bridge status</strong></td><td><?php echo ! empty( $data['bridge_connected'] ) ? '<span style="color:#14804a">Connected</span>' : '<span style="color:#a61b1b">Not recently seen</span>'; ?></td></tr>
                    <tr><td><strong>FloCafe source catalog revision</strong></td><td><?php echo esc_html( (string) (int) get_option( 'cafeflo_last_flocafe_revision', 0 ) ); ?></td></tr>
                    <tr><td><strong>API base</strong></td><td><code><?php echo esc_html( rest_url( 'flocafe/v1' ) ); ?></code></td></tr>
                </tbody>
            </table>
            <form method="post" action="options.php" style="max-width:900px">
                <?php settings_fields( 'cafeflo_connect' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cafeflo_site_id">Site ID</label></th>
                        <td><input class="regular-text" id="cafeflo_site_id" name="cafeflo_site_id" value="<?php echo esc_attr( get_option( 'cafeflo_site_id', '' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cafeflo_bridge_api_key">Bridge API key</label></th>
                        <td><input class="regular-text code" id="cafeflo_bridge_api_key" name="cafeflo_bridge_api_key" type="text" autocomplete="off" value="<?php echo esc_attr( get_option( 'cafeflo_bridge_api_key', '' ) ); ?>" /><p class="description">This shared secret is used only by the Bridge. Store it securely. It is shown only to users who can manage WooCommerce.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Price unit conversion</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cafeflo_price_rial_to_toman" value="1" <?php checked( '1', get_option( 'cafeflo_price_rial_to_toman', '1' ) ); ?> />
                                Convert FloCafe prices from Rial to Toman
                            </label>
                            <p class="description">When enabled, FloCafe prices such as 2,500,000 Rial are divided by 10 before being stored in ACF and WooCommerce, so the website uses 250,000 Toman. Disable this only if FloCafe already sends prices in Toman.</p>
                        </td>
                    </tr>
                    <tr><th scope="row">FloCafe online ordering</th><td><strong><?php echo '1' === get_option( 'cafeflo_online_ordering_enabled', '0' ) ? 'Enabled' : 'Disabled'; ?></strong> / <strong><?php echo '1' === get_option( 'cafeflo_online_ordering_open', '0' ) ? 'Open' : 'Closed'; ?></strong><p class="description">These values are controlled by FloCafe and refreshed by the Bridge. They are not editable here.</p></td></tr>
                </table>
                <?php submit_button( 'Save CafeFlo settings' ); ?>
            </form>
            <p><strong>Security:</strong> the bridge endpoint accepts only the configured secret; no WordPress login cookie or browser nonce is required for Bridge traffic.</p>
            <p><strong>Price:</strong> FloCafe prices are treated as Rial by default and converted to Toman once at the catalog boundary. You can disable this in the setting above if the source already sends Toman.</p>
            <p><strong>ACF:</strong> catalog sync writes FloCafe product data to <code>price</code>, <code>description</code>, <code>available</code>, <code>visible</code>, and <code>product_image</code>. The <code>featured</code> field is intentionally left untouched because FloCafe does not currently provide that value.</p>
        </div>
        <?php
    }

    public static function meta_boxes() {
        add_meta_box( 'cafeflo-product-sync', 'CafeFlo', array( __CLASS__, 'product_meta_box' ), 'product', 'side', 'default' );
        add_meta_box( 'cafeflo-order-sync', 'CafeFlo', array( __CLASS__, 'order_meta_box' ), 'shop_order', 'side', 'default' );
        add_meta_box( 'cafeflo-order-sync-hpos', 'CafeFlo', array( __CLASS__, 'order_meta_box' ), 'woocommerce_page_wc-orders', 'side', 'default' );
    }

    public static function product_meta_box( $post ) {
        echo '<p><strong>FloCafe product ID:</strong><br><code>' . esc_html( get_post_meta( $post->ID, '_cafeflo_product_id', true ) ?: 'Not mapped' ) . '</code></p>';
        $available = function_exists( 'get_field' ) ? get_field( 'available', $post->ID ) : ( '1' === get_post_meta( $post->ID, '_cafeflo_available', true ) );
        $visible = function_exists( 'get_field' ) ? get_field( 'visible', $post->ID ) : ( '1' === get_post_meta( $post->ID, '_cafeflo_visible', true ) );
        echo '<p><strong>Available:</strong> ' . ( $available ? 'Yes' : 'No' ) . '</p>';
        echo '<p><strong>Visible:</strong> ' . ( $visible ? 'Yes' : 'No' ) . '</p>';
    }

    public static function order_meta_box( $post ) {
        $order = wc_get_order( $post->ID );
        if ( ! $order ) {
            echo '<p>Order unavailable.</p>';
            return;
        }
        echo '<p><strong>FloCafe order:</strong><br><code>' . esc_html( $order->get_meta( '_cafeflo_order_id', true ) ?: 'Not yet received' ) . '</code></p>';
        echo '<p><strong>Sync:</strong> ' . esc_html( $order->get_meta( '_cafeflo_sync_status', true ) ?: 'n/a' ) . '</p>';
        $error = $order->get_meta( '_cafeflo_last_error', true );
        if ( $error ) {
            echo '<p><strong>Last error:</strong><br>' . esc_html( $error ) . '</p>';
        }
    }
}
