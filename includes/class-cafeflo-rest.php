<?php

defined( 'ABSPATH' ) || exit;

final class CafeFlo_REST {
    const NS = 'flocafe/v1';

    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    private static function args() {
        return array( 'permission_callback' => array( 'CafeFlo_Auth', 'check_request' ) );
    }

    public static function register_routes() {
        register_rest_route( self::NS, '/health', array(
            array_merge( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'health' ) ), self::args() ),
        ) );

        register_rest_route( self::NS, '/bridge/heartbeat', array(
            array_merge( array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'heartbeat' ) ), self::args() ),
        ) );

        register_rest_route( self::NS, '/orders/pending', array(
            array_merge( array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array( __CLASS__, 'orders_pending' ),
                'args' => array( 'limit' => array( 'default' => 50, 'sanitize_callback' => 'absint' ) ),
            ), self::args() ),
        ) );

        foreach ( array( 'claim', 'ack', 'failed', 'status' ) as $action ) {
            register_rest_route( self::NS, '/orders/(?P<id>\d+)/' . $action, array(
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array( __CLASS__, 'orders_' . $action ),
                    'args' => array( 'id' => array( 'validate_callback' => 'is_numeric' ) ),
                    'permission_callback' => array( 'CafeFlo_Auth', 'check_request' ),
                ),
            ) );
        }

        register_rest_route( self::NS, '/store/status', array(
            array_merge( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'store_status' ) ), self::args() ),
        ) );

        register_rest_route( self::NS, '/catalog/sync', array(
            array_merge( array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'catalog_sync' ) ), self::args() ),
        ) );

        register_rest_route( self::NS, '/catalog/changes', array(
            array_merge( array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array( __CLASS__, 'catalog_changes' ),
                'args' => array( 'after_revision' => array( 'default' => 0, 'sanitize_callback' => 'absint' ) ),
            ), self::args() ),
        ) );

        register_rest_route( self::NS, '/catalog/snapshot', array(
            array_merge( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'catalog_snapshot' ) ), self::args() ),
        ) );
    }

    public static function health() {
        $state = get_option( 'cafeflo_flocafe_store_state', array() );
        return rest_ensure_response( array(
            'ok' => true,
            'status' => 'ok',
            'site_id' => (string) get_option( 'cafeflo_site_id', '' ),
            'api_version' => '1',
            'online_ordering_enabled' => '1' === get_option( 'cafeflo_online_ordering_enabled', '0' ),
            'online_ordering_open' => '1' === get_option( 'cafeflo_online_ordering_open', '0' ),
            'bridge_connected' => CafeFlo_Orders::bridge_fresh(),
            'catalog_revision' => (int) get_option( 'cafeflo_last_flocafe_revision', 0 ),
            'source_instance_id' => (string) get_option( 'cafeflo_source_instance_id', '' ),
            'catalog_synced' => '1' === get_option( 'cafeflo_catalog_synced', '0' ),
            'server_time' => gmdate( 'c' ),
            'timestamp' => time(),
            'currency' => isset( $state['currency'] ) && $state['currency'] ? $state['currency'] : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ),
        ) );
    }

    public static function heartbeat( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) $body = array();

        update_option( 'cafeflo_bridge_last_heartbeat', time(), false );

        $store = array();
        if ( isset( $body['flocafe_store'] ) && is_array( $body['flocafe_store'] ) ) {
            $store = $body['flocafe_store'];
        } else {
            $store = array(
                'online_ordering_enabled' => ! empty( $body['online_ordering_enabled'] ),
                'online_ordering_open' => ! empty( $body['online_ordering_open'] ),
            );
        }

        $enabled = ! empty( $store['online_ordering_enabled'] );
        $open = ! empty( $store['online_ordering_open'] );

        update_option( 'cafeflo_online_ordering_enabled', $enabled ? '1' : '0', false );
        update_option( 'cafeflo_online_ordering_open', $open ? '1' : '0', false );
        update_option( 'cafeflo_flocafe_store_state', array(
            'online_ordering_enabled' => $enabled,
            'online_ordering_open' => $open,
            'currency' => isset( $store['currency'] ) ? sanitize_text_field( (string) $store['currency'] ) : '',
            'received_at' => time(),
        ), false );
        update_option( 'cafeflo_flocafe_store_state_received_at', time(), false );

        if ( isset( $body['catalog_revision'] ) ) {
            $revision = max( 0, (int) $body['catalog_revision'] );
            $local = (int) get_option( 'cafeflo_last_flocafe_revision', 0 );
            if ( $revision >= $local ) update_option( 'cafeflo_last_bridge_catalog_revision', $revision, false );
        }

        return rest_ensure_response( array(
            'ok' => true,
            'received_at' => time(),
            'site_id' => (string) get_option( 'cafeflo_site_id', '' ),
        ) );
    }

    public static function orders_pending( WP_REST_Request $request ) {
        return rest_ensure_response( array( 'orders' => CafeFlo_Orders::pending_orders( max( 1, min( 50, (int) $request->get_param( 'limit' ) ) ) ) ) );
    }

    public static function orders_claim( WP_REST_Request $request ) {
        $result = CafeFlo_Orders::claim( (int) $request['id'], sanitize_text_field( (string) $request->get_header( 'x-cafeflo-bridge-id' ) ) );
        return self::response( $result );
    }

    public static function orders_ack( WP_REST_Request $request ) {
        return self::response( CafeFlo_Orders::ack( (int) $request['id'], $request->get_json_params() ) );
    }

    public static function orders_failed( WP_REST_Request $request ) {
        return self::response( CafeFlo_Orders::failed( (int) $request['id'], $request->get_json_params() ) );
    }

    public static function orders_status( WP_REST_Request $request ) {
        return self::response( CafeFlo_Orders::update_status( (int) $request['id'], $request->get_json_params() ) );
    }

    public static function store_status() {
        $state = get_option( 'cafeflo_flocafe_store_state', array() );
        return rest_ensure_response( array(
            'online_ordering_enabled' => '1' === get_option( 'cafeflo_online_ordering_enabled', '0' ),
            'online_ordering_open' => '1' === get_option( 'cafeflo_online_ordering_open', '0' ),
            'bridge_connected' => CafeFlo_Orders::bridge_fresh(),
            'catalog_revision' => (int) get_option( 'cafeflo_last_flocafe_revision', 0 ),
            'currency' => isset( $state['currency'] ) && $state['currency'] ? $state['currency'] : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ),
            'fresh' => CafeFlo_Orders::flocafe_store_fresh(),
            'received_at' => (int) get_option( 'cafeflo_flocafe_store_state_received_at', 0 ),
            'state' => $state,
            'timestamp' => gmdate( 'c' ),
        ) );
    }

    public static function catalog_sync( WP_REST_Request $request ) {
        return self::response( CafeFlo_Catalog::sync( $request->get_json_params() ) );
    }

    public static function catalog_changes( WP_REST_Request $request ) {
        $after = max( 0, (int) $request->get_param( 'after_revision' ) );
        $changes = CafeFlo_DB::get_changes_after( $after, 500 );
        return rest_ensure_response( array(
            'after_revision' => $after,
            'revision' => (int) get_option( 'cafeflo_wp_catalog_revision', 0 ),
            'has_more' => count( $changes ) >= 500,
            'changes' => $changes,
        ) );
    }

    public static function catalog_snapshot() {
        return rest_ensure_response( CafeFlo_Catalog::snapshot() );
    }

    private static function response( $value ) {
        if ( is_wp_error( $value ) ) return $value;
        return rest_ensure_response( $value );
    }
}
