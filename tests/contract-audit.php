<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'cafeflo-connect-wordpress.php',
    'includes/class-cafeflo-auth.php',
    'includes/class-cafeflo-db.php',
    'includes/class-cafeflo-catalog.php',
    'includes/class-cafeflo-orders.php',
    'includes/class-cafeflo-rest.php',
    'includes/class-cafeflo-admin.php',
);
foreach ( $files as $file ) {
    if ( ! is_file( $root . '/' . $file ) ) {
        fwrite( STDERR, "MISSING FILE: {$file}\n" );
        exit( 1 );
    }
}

$source = array();
foreach ( $files as $file ) $source[ $file ] = (string) file_get_contents( $root . '/' . $file );
$all_code = implode( "\n", $source );

$checks = array(
    'REST namespace' => strpos( $source['includes/class-cafeflo-rest.php'], "const NS = 'flocafe/v1'" ) !== false,
    'REST auth callbacks' => substr_count( $source['includes/class-cafeflo-rest.php'], 'self::args()' ) >= 7,
    'Constant-time bridge auth' => strpos( $source['includes/class-cafeflo-auth.php'], 'hash_equals' ) !== false,
    'Admin capability is WordPress-native' => strpos( $source['includes/class-cafeflo-auth.php'], 'manage_options' ) !== false,
    'Products CPT is authoritative' => strpos( $source['includes/class-cafeflo-catalog.php'], "const PRODUCT_POST_TYPE = 'products'" ) !== false,
    'Local product hook uses products CPT' => strpos( $source['includes/class-cafeflo-catalog.php'], "save_post_' . self::PRODUCT_POST_TYPE" ) !== false,
    'Independent category taxonomy exists' => strpos( $source['includes/class-cafeflo-catalog.php'], "const DEFAULT_TAXONOMY = 'cafeflo_product_category'" ) !== false,
    'ACF fields are synchronized' =>
        strpos( $source['includes/class-cafeflo-catalog.php'], "update_field( 'price'" ) !== false &&
        strpos( $source['includes/class-cafeflo-catalog.php'], "update_field( 'description'" ) !== false &&
        strpos( $source['includes/class-cafeflo-catalog.php'], "update_field( 'available'" ) !== false &&
        strpos( $source['includes/class-cafeflo-catalog.php'], "update_field( 'visible'" ) !== false &&
        strpos( $source['includes/class-cafeflo-catalog.php'], "update_field( 'product_image'" ) !== false,
    'Featured field is preserved' => strpos( $source['includes/class-cafeflo-catalog.php'], "update_field( 'featured'" ) === false,
    'FloCafe product ID meta remains' => strpos( $source['includes/class-cafeflo-catalog.php'], "'_cafeflo_product_id'" ) !== false,
    'Source-scoped mapping identity remains' =>
        strpos( $source['includes/class-cafeflo-db.php'], 'source_instance_id' ) !== false &&
        strpos( $source['includes/class-cafeflo-db.php'], 'UNIQUE KEY entity_map (entity_type, source_instance_id, flocafe_id)' ) !== false,
    'Product keeps source identity marker' => strpos( $source['includes/class-cafeflo-catalog.php'], "'_cafeflo_source_instance_id'" ) !== false,
    'Bridge-compatible product mapping alias remains' =>
        strpos( $source['includes/class-cafeflo-catalog.php'], "'woo_product_id'" ) !== false &&
        strpos( $source['includes/class-cafeflo-catalog.php'], "'wordpress_product_id'" ) !== false,
    'Bridge-compatible empty order queue' => strpos( $source['includes/class-cafeflo-orders.php'], 'return array();' ) !== false,
    'Order compatibility no-op remains' => strpos( $source['includes/class-cafeflo-orders.php'], "'reason' => 'orders_disabled'" ) !== false,
    'REST order queue is explicitly disabled' => strpos( $source['includes/class-cafeflo-rest.php'], "'orders_enabled' => false" ) !== false,
    'Catalog snapshot has Bridge fields' =>
        strpos( $source['includes/class-cafeflo-catalog.php'], "'source_instance_id'" ) !== false &&
        strpos( $source['includes/class-cafeflo-catalog.php'], "'generated_at'" ) !== false &&
        strpos( $source['includes/class-cafeflo-catalog.php'], "'full_snapshot' => true" ) !== false,
    'No runtime WooCommerce class checks' =>
        strpos( $all_code, "class_exists( 'WooCommerce'" ) === false &&
        strpos( $all_code, 'WC_' ) === false,
    'No WooCommerce lifecycle hooks' =>
        strpos( $all_code, 'woocommerce_' ) === false &&
        strpos( $all_code, 'wc_get_' ) === false &&
        strpos( $all_code, 'wc_create_' ) === false,
    'No WooCommerce admin capability' => strpos( $all_code, 'manage_woocommerce' ) === false,
    'Price conversion remains configurable' =>
        strpos( $source['includes/class-cafeflo-catalog.php'], 'price_for_website' ) !== false &&
        strpos( $source['includes/class-cafeflo-catalog.php'], 'price_for_flocafe' ) !== false,
);

$failed = array();
foreach ( $checks as $label => $ok ) {
    echo ( $ok ? 'PASS' : 'FAIL' ) . " - {$label}\n";
    if ( ! $ok ) $failed[] = $label;
}
if ( $failed ) {
    fwrite( STDERR, "Failed checks: " . implode( ', ', $failed ) . "\n" );
    exit( 1 );
}
