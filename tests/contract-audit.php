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
);
foreach ( $files as $file ) {
    if ( ! is_file( $root . '/' . $file ) ) {
        fwrite( STDERR, "MISSING FILE: {$file}\n" );
        exit( 1 );
    }
}

$source = array();
foreach ( $files as $file ) $source[ $file ] = (string) file_get_contents( $root . '/' . $file );

$checks = array(
    'REST namespace' => strpos( $source['includes/class-cafeflo-rest.php'], "const NS = 'flocafe/v1'" ) !== false,
    'REST auth callbacks' => substr_count( $source['includes/class-cafeflo-rest.php'], 'self::args()' ) >= 7,
    'Bridge heartbeat persistence' => strpos( $source['includes/class-cafeflo-rest.php'], "cafeflo_bridge_last_heartbeat" ) !== false,
    'FloCafe store freshness persistence' => strpos( $source['includes/class-cafeflo-rest.php'], "cafeflo_flocafe_store_state_received_at" ) !== false,
    'Pending order limit is bounded' => strpos( $source['includes/class-cafeflo-orders.php'], "min( 50" ) !== false && strpos( $source['includes/class-cafeflo-orders.php'], "limit" ) !== false,
    'Atomic claim insert' => strpos( $source['includes/class-cafeflo-orders.php'], 'INSERT IGNORE' ) !== false,
    'Claim table has Bridge identity' => strpos( $source['includes/class-cafeflo-db.php'], 'bridge_id' ) !== false,
    'Claim token required for ACK' => strpos( $source['includes/class-cafeflo-orders.php'], 'cafeflo_claim_mismatch' ) !== false,
    'ACK replay is idempotent' => strpos( $source['includes/class-cafeflo-orders.php'], 'idempotent_replay' ) !== false,
    'FloCafe order ID identity is checked' => strpos( $source['includes/class-cafeflo-orders.php'], 'cafeflo_order_id_mismatch' ) !== false,
    'Meaningful status allow-list' => strpos( $source['includes/class-cafeflo-orders.php'], "'preparing'" ) !== false && strpos( $source['includes/class-cafeflo-orders.php'], "'ready'" ) !== false,
    'Permanent failure refund' => strpos( $source['includes/class-cafeflo-orders.php'], 'wc_create_refund' ) !== false && strpos( $source['includes/class-cafeflo-orders.php'], "'refund_payment' => true" ) !== false,
    'Refund does not restock' => strpos( $source['includes/class-cafeflo-orders.php'], "'restock_items' => false" ) !== false,
    'Refund failure goes to hold' => strpos( $source['includes/class-cafeflo-orders.php'], "'on-hold'" ) !== false,
    'Immutable FloCafe product mapping' => strpos( $source['includes/class-cafeflo-catalog.php'], "get_mapping( 'product'" ) !== false && strpos( $source['includes/class-cafeflo-catalog.php'], '_cafeflo_product_id' ) !== false,
    'Catalog same-revision recovery' => strpos( $source['includes/class-cafeflo-catalog.php'], 'current_mappings' ) !== false && strpos( $source['includes/class-cafeflo-catalog.php'], 'already_applied' ) !== false,
    'Full snapshot hides missing products' => strpos( $source['includes/class-cafeflo-catalog.php'], 'deactivate_missing_managed_products' ) !== false,
    'ACF remains presentation-only' => preg_match( '/\bupdate_field\s*\(/', $source['includes/class-cafeflo-catalog.php'] ) !== 1,
    'Woo inventory quantity is not authoritative' => strpos( $source['includes/class-cafeflo-catalog.php'], 'set_manage_stock( false )' ) !== false,
    'Checkout freshness gate' => strpos( $source['includes/class-cafeflo-orders.php'], 'cafeflo_bridge_last_heartbeat' ) !== false && strpos( $source['includes/class-cafeflo-orders.php'], 'HEARTBEAT_TTL' ) !== false,
    'Constant-time bridge auth' => strpos( $source['includes/class-cafeflo-auth.php'], 'hash_equals' ) !== false,
    'Safe DB upgrade path' => strpos( $source['includes/class-cafeflo-db.php'], 'maybe_upgrade' ) !== false && strpos( $source['cafeflo-connect-wordpress.php'], 'CafeFlo_DB::maybe_upgrade' ) !== false,
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
