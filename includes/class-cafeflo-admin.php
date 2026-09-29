<?php

defined( 'ABSPATH' ) || exit;

final class CafeFlo_Admin {
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'settings' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
    }

    public static function menu() {
        add_options_page(
            'CafeFlo Connect',
            'CafeFlo Connect',
            'manage_options',
            'cafeflo-connect',
            array( __CLASS__, 'page' )
        );
    }

    public static function settings() {
        register_setting( 'cafeflo_connect', 'cafeflo_bridge_api_key', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_key' ) ) );
        register_setting( 'cafeflo_connect', 'cafeflo_site_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'cafeflo_connect', 'cafeflo_price_rial_to_toman', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_toggle' ) ) );
        register_setting( 'cafeflo_connect', 'cafeflo_product_taxonomy', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_taxonomy' ) ) );
    }

    public static function sanitize_toggle( $value ) {
        return empty( $value ) ? '0' : '1';
    }

    public static function sanitize_taxonomy( $value ) {
        $value = sanitize_key( $value );
        foreach ( CafeFlo_Catalog::available_taxonomies() as $taxonomy ) {
            if ( $taxonomy->name === $value ) return $value;
        }
        return CafeFlo_Catalog::DEFAULT_TAXONOMY;
    }

    public static function sanitize_key( $value ) {
        $value = trim( (string) $value );
        return '' === $value ? (string) get_option( 'cafeflo_bridge_api_key', wp_generate_password( 64, true, true ) ) : $value;
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage CafeFlo Connect.', 'cafeflo-connect' ) );
        }

        $health = CafeFlo_REST::health();
        $data = $health instanceof WP_REST_Response ? $health->get_data() : array();
        $cpt_ok = post_type_exists( CafeFlo_Catalog::PRODUCT_POST_TYPE );
        $acf_ok = function_exists( 'get_field' ) && function_exists( 'update_field' );
        ?>
        <div class="wrap">
            <h1>CafeFlo Connect</h1>
            <p>WordPress-side catalog boundary for FloCafe. Products are stored in the <code>products</code> custom post type and their website-facing fields are managed by ACF.</p>
            <table class="widefat striped" style="max-width:980px;margin:16px 0">
                <tbody>
                    <tr><td><strong>Site ID</strong></td><td><code><?php echo esc_html( (string) get_option( 'cafeflo_site_id', '' ) ); ?></code></td></tr>
                    <tr><td><strong>Bridge status</strong></td><td><?php echo ! empty( $data['bridge_connected'] ) ? '<span style="color:#14804a">Connected</span>' : '<span style="color:#a61b1b">Not recently seen</span>'; ?></td></tr>
                    <tr><td><strong>Products post type</strong></td><td><?php echo $cpt_ok ? '<span style="color:#14804a">Available: products</span>' : '<span style="color:#a61b1b">Missing: products</span>'; ?></td></tr>
                    <tr><td><strong>ACF</strong></td><td><?php echo $acf_ok ? '<span style="color:#14804a">Detected</span>' : '<span style="color:#a61b1b">Not detected</span>'; ?></td></tr>
                    <tr><td><strong>Catalog revision</strong></td><td><?php echo esc_html( (string) (int) get_option( 'cafeflo_last_flocafe_revision', 0 ) ); ?></td></tr>
                    <tr><td><strong>API base</strong></td><td><code><?php echo esc_html( rest_url( 'flocafe/v1' ) ); ?></code></td></tr>
                    <tr><td><strong>WordPress orders</strong></td><td>Disabled. The Bridge receives an empty order queue and successful compatibility no-ops.</td></tr>
                </tbody>
            </table>

            <form method="post" action="options.php" style="max-width:980px">
                <?php settings_fields( 'cafeflo_connect' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cafeflo_site_id">Site ID</label></th>
                        <td><input class="regular-text" id="cafeflo_site_id" name="cafeflo_site_id" value="<?php echo esc_attr( get_option( 'cafeflo_site_id', '' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cafeflo_bridge_api_key">Bridge API key</label></th>
                        <td><input class="regular-text code" id="cafeflo_bridge_api_key" name="cafeflo_bridge_api_key" type="text" autocomplete="off" value="<?php echo esc_attr( get_option( 'cafeflo_bridge_api_key', '' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cafeflo_product_taxonomy">Product taxonomy</label></th>
                        <td>
                            <select class="regular-text" id="cafeflo_product_taxonomy" name="cafeflo_product_taxonomy">
                                <?php foreach ( CafeFlo_Catalog::available_taxonomies() as $taxonomy ) : ?>
                                    <option value="<?php echo esc_attr( $taxonomy->name ); ?>" <?php selected( $taxonomy->name, CafeFlo_Catalog::taxonomy() ); ?>><?php echo esc_html( $taxonomy->label . ' (' . $taxonomy->name . ')' ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Categories are attached to <code>products</code>. The built-in CafeFlo taxonomy is used by default, but an existing taxonomy attached to <code>products</code> can be selected.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Price unit conversion</th>
                        <td>
                            <label>
                                <input type="hidden" name="cafeflo_price_rial_to_toman" value="0" />
                                <input type="checkbox" name="cafeflo_price_rial_to_toman" value="1" <?php checked( '1', get_option( 'cafeflo_price_rial_to_toman', '1' ) ); ?> />
                                Convert FloCafe prices from Rial to Toman
                            </label>
                            <p class="description">When enabled, FloCafe prices are divided by 10 before being stored in the ACF <code>price</code> field.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save CafeFlo settings' ); ?>
            </form>

            <h2>ACF field contract</h2>
            <p><code>price</code>, <code>description</code>, <code>available</code>, <code>visible</code>, and <code>product_image</code> are synchronized. <code>featured</code> is never overwritten by the connector.</p>
        </div>
        <?php
    }

    public static function meta_boxes() {
        add_meta_box( 'cafeflo-product-sync', 'CafeFlo', array( __CLASS__, 'product_meta_box' ), CafeFlo_Catalog::PRODUCT_POST_TYPE, 'side', 'default' );
    }

    public static function product_meta_box( $post ) {
        echo '<p><strong>FloCafe product ID:</strong><br><code>' . esc_html( get_post_meta( $post->ID, '_cafeflo_product_id', true ) ?: 'Not mapped' ) . '</code></p>';
        $available = function_exists( 'get_field' ) ? get_field( 'available', $post->ID ) : ( '1' === get_post_meta( $post->ID, '_cafeflo_available', true ) );
        $visible = function_exists( 'get_field' ) ? get_field( 'visible', $post->ID ) : ( '1' === get_post_meta( $post->ID, '_cafeflo_visible', true ) );
        echo '<p><strong>Available:</strong> ' . ( $available ? 'Yes' : 'No' ) . '</p>';
        echo '<p><strong>Visible:</strong> ' . ( $visible ? 'Yes' : 'No' ) . '</p>';
    }
}
