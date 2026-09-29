<?php
/**
 * Plugin Name: CafeFlo Connect for WordPress
 * Description: Secure WordPress integration boundary for the local FloCafe Bridge using the products custom post type and ACF.
 * Version: 0.9.0
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: CafeFlo
 * License: GPL-2.0-or-later
 * Text Domain: cafeflo-connect
 */

defined( 'ABSPATH' ) || exit;

define( 'CAFEFLO_CONNECT_VERSION', '0.9.0' );
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
        CafeFlo_DB::maybe_upgrade();
        CafeFlo_Catalog::boot();
        CafeFlo_Orders::boot();
        CafeFlo_REST::boot();
        CafeFlo_Admin::boot();
    }
);