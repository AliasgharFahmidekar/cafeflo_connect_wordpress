<?php
/**
 * Plugin Name: CafeFlo Connect for WordPress
 * Description: Secure WordPress/WooCommerce integration boundary for the CafeFlo/FloCafe local bridge.
 * Version: 0.5.0
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: CafeFlo
 * License: GPL-2.0-or-later
 * Text Domain: cafeflo-connect
 */

defined( 'ABSPATH' ) || exit;

define( 'CAFEFLO_CONNECT_VERSION', '0.5.0' );
define( 'CAFEFLO_CONNECT_FILE', __FILE__ );
define( 'CAFEFLO_CONNECT_DIR', plugin_dir_path( __FILE__ ) );

require_once CAFEFLO_CONNECT_DIR . 'includes/class-cafeflo-db.php';
require_once CAFEFLO_CONNECT_DIR . 'includes/class-cafeflo-auth.php';
require_once CAFEFLO_CONNECT_DIR . 'includes/class-cafeflo-catalog.php';
require_once CAFEFLO_CONNECT_DIR . 'includes/class-cafeflo-orders.php';
require_once CAFEFLO_CONNECT_DIR . 'includes/class-cafeflo-rest.php';
require_once CAFEFLO_CONNECT_DIR . 'includes/class-cafeflo-admin.php';

register_activation_hook( __FILE__, array( 'CafeFlo_DB', 'activate' ) );

add_action(
    'plugins_loaded',
    function () {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action(
                'admin_notices',
                function () {
                    if ( current_user_can( 'activate_plugins' ) ) {
                        echo '<div class="notice notice-error"><p><strong>CafeFlo Connect</strong> requires WooCommerce to be active.</p></div>';
                    }
                }
            );
            return;
        }

        CafeFlo_DB::maybe_upgrade();

        CafeFlo_Catalog::boot();
        CafeFlo_Orders::boot();
        CafeFlo_REST::boot();
        CafeFlo_Admin::boot();
    }
);
