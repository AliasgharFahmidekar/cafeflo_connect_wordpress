<?php

defined( 'ABSPATH' ) || exit;

/**
 * Order compatibility facade.
 *
 * CafeFlo Connect no longer stores or reads WordPress orders. The Bridge still
 * polls the historical order endpoints, so they intentionally remain alive and
 * return successful no-op responses instead of transport errors.
 */
final class CafeFlo_Orders {
    const HEARTBEAT_TTL = 90;

    public static function boot() {
        // Intentionally empty. There is no WordPress checkout/order lifecycle.
    }

    public static function bridge_fresh() {
        $last = (int) get_option( 'cafeflo_bridge_last_heartbeat', 0 );
        return $last > 0 && ( time() - $last ) <= self::HEARTBEAT_TTL;
    }

    public static function flocafe_store_fresh() {
        $last = (int) get_option( 'cafeflo_flocafe_store_state_received_at', 0 );
        return $last > 0 && ( time() - $last ) <= self::HEARTBEAT_TTL;
    }

    public static function checkout_ready() {
        return false;
    }

    public static function pending_orders( $limit = 50 ) {
        return array();
    }

    private static function disabled_response( $payload = array() ) {
        $result = array(
            'ok' => true,
            'ignored' => true,
            'reason' => 'orders_disabled',
        );
        if ( is_array( $payload ) ) {
            if ( isset( $payload['flocafe_order_id'] ) ) {
                $result['flocafe_order_id'] = sanitize_text_field( (string) $payload['flocafe_order_id'] );
            }
            if ( isset( $payload['external_order_id'] ) ) {
                $result['external_order_id'] = sanitize_text_field( (string) $payload['external_order_id'] );
            }
        }
        return $result;
    }

    public static function claim( $order_id, $bridge_id = '' ) {
        return self::disabled_response();
    }

    public static function ack( $order_id, $payload ) {
        return self::disabled_response( is_array( $payload ) ? $payload : array() );
    }

    public static function failed( $order_id, $payload ) {
        return self::disabled_response( is_array( $payload ) ? $payload : array() );
    }

    public static function update_status( $order_id, $payload ) {
        return self::disabled_response( is_array( $payload ) ? $payload : array() );
    }
}
