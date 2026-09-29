<?php

defined( 'ABSPATH' ) || exit;

final class CafeFlo_Orders {
    const HEARTBEAT_TTL = 90;

    public static function boot() {
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'queue_paid_order' ) );
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'queue_paid_order' ) );
        add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'mark_online_order' ), 20, 1 );
        add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'guard_checkout' ) );
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'guard_add_to_cart' ), 10, 3 );
        add_action( 'init', array( __CLASS__, 'register_statuses' ) );
        add_filter( 'wc_order_statuses', array( __CLASS__, 'add_statuses' ) );
    }

    public static function register_statuses() {
        $statuses = array(
            'wc-waiting-for-cafe' => 'Waiting for Cafe',
            'wc-received-by-cafe' => 'Received by Cafe',
            'wc-preparing' => 'Preparing',
            'wc-ready' => 'Ready',
        );
        foreach ( $statuses as $key => $label ) {
            register_post_status( $key, array(
                'label' => $label,
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>' ),
            ) );
        }
    }

    public static function add_statuses( $statuses ) {
        $custom = array(
            'wc-waiting-for-cafe' => 'Waiting for Cafe',
            'wc-received-by-cafe' => 'Received by Cafe',
            'wc-preparing' => 'Preparing',
            'wc-ready' => 'Ready',
        );
        return array_slice( $statuses, 0, 2, true ) + $custom + array_slice( $statuses, 2, null, true );
    }

    public static function mark_online_order( $order ) {
        if ( ! $order instanceof WC_Order ) return;
        $items = $order->get_items( 'line_item' );
        if ( empty( $items ) ) return;
        foreach ( $items as $item ) {
            $product = $item->get_product();
            if ( ! $product || '' === (string) $product->get_meta( '_cafeflo_product_id', true ) ) {
                return;
            }
        }
        $order->update_meta_data( '_cafeflo_online_order', '1' );
        $order->update_meta_data( '_cafeflo_external_id', (string) $order->get_id() );
        $order->update_meta_data( '_cafeflo_sync_status', 'pending' );
        $order->save();
    }

    public static function queue_paid_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! self::is_online_order( $order ) ) return;
        if ( ! $order->is_paid() ) return;

        if ( ! $order->get_meta( '_cafeflo_external_id', true ) ) {
            $order->update_meta_data( '_cafeflo_external_id', (string) $order->get_id() );
        }
        $order->update_meta_data( '_cafeflo_online_order', '1' );
        $order->update_meta_data( '_cafeflo_sync_status', 'pending' );
        $order->update_meta_data( '_cafeflo_last_error', '' );
        $order->update_meta_data( '_cafeflo_failure_refunded', '0' );
        $order->save();

        if ( ! in_array( $order->get_status(), array( 'waiting-for-cafe', 'received-by-cafe', 'preparing', 'ready', 'completed' ), true ) ) {
            $order->set_status( 'waiting-for-cafe' );
            $order->save();
        }
    }

    public static function is_online_order( $order ) {
        if ( ! $order instanceof WC_Order ) return false;
        return 'yes' === $order->get_meta( '_cafeflo_online_order', true ) || '1' === $order->get_meta( '_cafeflo_online_order', true );
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
        if ( ! self::bridge_fresh() || ! self::flocafe_store_fresh() ) return false;
        if ( '1' !== get_option( 'cafeflo_online_ordering_enabled', '0' ) ) return false;
        if ( '1' !== get_option( 'cafeflo_online_ordering_open', '0' ) ) return false;
        return true;
    }

    public static function guard_add_to_cart( $passed, $product_id, $quantity ) {
        if ( ! self::checkout_ready() && '1' === get_post_meta( $product_id, '_cafeflo_product_id', true ) ) {
            wc_add_notice( 'Online ordering is temporarily unavailable. Please try again shortly.', 'error' );
            return false;
        }
        return $passed;
    }

    public static function guard_checkout() {
        if ( is_admin() && ! wp_doing_ajax() ) return;
        if ( ! self::checkout_ready() && WC()->cart && ! WC()->cart->is_empty() ) {
            wc_add_notice( 'Online ordering is temporarily unavailable because the cafe connection is offline or closed.', 'error' );
            return;
        }
        if ( WC()->cart && ! WC()->cart->is_empty() ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                $product = isset( $cart_item['data'] ) ? $cart_item['data'] : false;
                if ( ! $product || '' === (string) $product->get_meta( '_cafeflo_product_id', true ) ) {
                    wc_add_notice( 'This cart contains an item that is not connected to FloCafe. Please remove it before ordering.', 'error' );
                    break;
                }
            }
        }
    }

    public static function pending_orders( $limit = 50 ) {
        $limit = max( 1, min( 50, (int) $limit ) );
        $orders = wc_get_orders( array(
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'ASC',
            'type' => 'shop_order',
            'return' => 'objects',
            'meta_query' => array(
                array( 'key' => '_cafeflo_online_order', 'value' => '1' ),
                array( 'key' => '_cafeflo_sync_status', 'value' => 'pending' ),
            ),
        ) );

        $result = array();
        foreach ( $orders as $order ) {
            if ( ! $order->is_paid() || ! self::is_online_order( $order ) ) continue;
            $payload = self::order_payload( $order );
            if ( is_wp_error( $payload ) ) {
                self::handle_permanent_failure( $order, $payload->get_error_message() );
                continue;
            }
            $result[] = $payload;
        }
        return $result;
    }

    private static function order_payload( $order ) {
        $items = array();
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $product = $item->get_product();
            if ( ! $product ) return new WP_Error( 'cafeflo_missing_product', 'A WooCommerce order item no longer has a product.', array( 'status' => 422 ) );
            $flocafe_id = (string) $product->get_meta( '_cafeflo_product_id', true );
            if ( '' === $flocafe_id ) return new WP_Error( 'cafeflo_unmapped_product', 'An order contains a product that is not mapped to FloCafe.', array( 'status' => 422 ) );

            $items[] = array(
                'product_id' => (int) $product->get_id(),
                'flocafe_product_id' => $flocafe_id,
                'quantity' => (float) $item->get_quantity(),
                'name' => $item->get_name(),
                'subtotal' => (float) $item->get_subtotal(),
                'total' => (float) $item->get_total(),
                'meta' => array(),
                'special_instructions' => sanitize_textarea_field( (string) $item->get_meta( '_cafeflo_item_note', true ) ),
            );
        }

        $customer = array(
            'name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        );

        return array(
            'wordpress_order_id' => (int) $order->get_id(),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'total' => (float) $order->get_total(),
            'subtotal' => (float) $order->get_subtotal(),
            'tax_total' => (float) $order->get_total_tax(),
            'shipping_total' => (float) $order->get_shipping_total(),
            'discount_total' => (float) $order->get_discount_total(),
            'external_order_id' => (string) ( $order->get_meta( '_cafeflo_external_id', true ) ?: $order->get_id() ),
            'type' => 'online',
            'online_platform' => 'wordpress',
            'items' => $items,
            'customer' => $customer,
            'billing' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ),
            'shipping' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ),
            'customer_note' => sanitize_textarea_field( (string) $order->get_customer_note() ),
            'payment_method' => $order->get_payment_method() ?: null,
            'note' => sanitize_textarea_field( (string) $order->get_customer_note() ),
            'created_at' => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null,
            'special_instructions' => sanitize_textarea_field( (string) $order->get_customer_note() ),
        );
    }

    public static function claim( $order_id, $bridge_id = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! self::is_online_order( $order ) ) {
            return new WP_Error( 'cafeflo_order_not_found', 'Online order not found.', array( 'status' => 404 ) );
        }
        if ( $order->get_meta( '_cafeflo_order_id', true ) ) {
            return array( 'claimed' => false, 'already_acknowledged' => true, 'flocafe_order_id' => (string) $order->get_meta( '_cafeflo_order_id', true ) );
        }

        global $wpdb;
        $table = CafeFlo_DB::table( 'order_claims' );
        $now = current_time( 'mysql', true );
        $expires = gmdate( 'Y-m-d H:i:s', time() - 300 );

        $wpdb->query( 'START TRANSACTION' );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE claimed_at < %s", $expires ) );
        $claim_id = wp_generate_uuid4();
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (order_id, claim_id, bridge_id, claimed_at) VALUES (%d,%s,%s,%s)",
            (int) $order_id, $claim_id, sanitize_text_field( $bridge_id ), $now
        ) );
        $wpdb->query( 'COMMIT' );

        if ( 1 !== (int) $inserted ) {
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT claim_id, bridge_id, claimed_at FROM {$table} WHERE order_id=%d", (int) $order_id ), ARRAY_A );
            return new WP_Error( 'cafeflo_claimed', 'Order is already claimed by another Bridge instance.', array( 'status' => 409, 'claim_id' => $existing ? $existing['claim_id'] : null ) );
        }

        $order->update_meta_data( '_cafeflo_sync_status', 'processing' );
        $order->update_meta_data( '_cafeflo_claim_id', $claim_id );
        $order->update_meta_data( '_cafeflo_bridge_id', sanitize_text_field( $bridge_id ) );
        $order->save();

        return array( 'claimed' => true, 'claim_id' => $claim_id, 'order_id' => (int) $order_id );
    }

    public static function ack( $order_id, $payload ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! self::is_online_order( $order ) ) {
            return new WP_Error( 'cafeflo_order_not_found', 'Online order not found.', array( 'status' => 404 ) );
        }
        $flocafe_id = isset( $payload['flocafe_order_id'] ) ? sanitize_text_field( (string) $payload['flocafe_order_id'] ) : '';
        $claim_id = isset( $payload['claim_id'] ) ? sanitize_text_field( (string) $payload['claim_id'] ) : '';
        $bridge_id = isset( $payload['bridge_id'] ) ? sanitize_text_field( (string) $payload['bridge_id'] ) : '';

        $already = (string) $order->get_meta( '_cafeflo_order_id', true );
        if ( '' !== $already ) {
            return array( 'ok' => true, 'idempotent_replay' => true, 'flocafe_order_id' => $already );
        }
        if ( '' === $claim_id && '' !== $bridge_id ) {
            global $wpdb;
            $claim_row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT claim_id, bridge_id FROM ' . CafeFlo_DB::table( 'order_claims' ) . ' WHERE order_id=%d LIMIT 1',
                    (int) $order_id
                ),
                ARRAY_A
            );
            if ( $claim_row && hash_equals( (string) $claim_row['bridge_id'], $bridge_id ) ) {
                $claim_id = (string) $claim_row['claim_id'];
            }
        }
        if ( '' === $claim_id || ! self::valid_claim( $order_id, $claim_id ) ) {
            return new WP_Error( 'cafeflo_claim_mismatch', 'A valid current claim_id is required for ACK.', array( 'status' => 409 ) );
        }
        if ( '' === $flocafe_id ) {
            return new WP_Error( 'cafeflo_missing_flocafe_order_id', 'FloCafe order ID is required.', array( 'status' => 422 ) );
        }

        $order->update_meta_data( '_cafeflo_order_id', $flocafe_id );
        $order->update_meta_data( '_cafeflo_sync_status', 'received' );
        $order->update_meta_data( '_cafeflo_claim_id', '' );
        $order->update_meta_data( '_cafeflo_last_error', '' );
        $order->set_status( 'received-by-cafe' );
        $order->save();

        global $wpdb;
        $wpdb->delete( CafeFlo_DB::table( 'order_claims' ), array( 'order_id' => (int) $order_id ), array( '%d' ) );

        return array( 'ok' => true, 'idempotent_replay' => false, 'flocafe_order_id' => $flocafe_id );
    }

    private static function valid_claim( $order_id, $claim_id ) {
        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare( 'SELECT claim_id FROM ' . CafeFlo_DB::table( 'order_claims' ) . ' WHERE order_id=%d LIMIT 1', (int) $order_id ) );
        return is_string( $row ) && hash_equals( $row, $claim_id );
    }

    public static function failed( $order_id, $payload ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! self::is_online_order( $order ) ) return new WP_Error( 'cafeflo_order_not_found', 'Online order not found.', array( 'status' => 404 ) );

        $retryable = ! empty( $payload['retryable'] );
        $message = isset( $payload['error'] )
            ? sanitize_text_field( (string) $payload['error'] )
            : ( isset( $payload['message'] ) ? sanitize_text_field( (string) $payload['message'] ) : 'Bridge transfer failed.' );
        $claim_id = isset( $payload['claim_id'] ) ? sanitize_text_field( (string) $payload['claim_id'] ) : '';
        if ( '' === $claim_id || ! self::valid_claim( $order_id, $claim_id ) ) {
            return new WP_Error( 'cafeflo_claim_mismatch', 'A valid current claim_id is required to record a transfer failure.', array( 'status' => 409 ) );
        }
        $order->update_meta_data( '_cafeflo_last_error', $message );

        if ( $retryable ) {
            $order->update_meta_data( '_cafeflo_sync_status', 'pending' );
            $order->update_meta_data( '_cafeflo_claim_id', '' );
            $order->save();
            global $wpdb;
            $wpdb->delete( CafeFlo_DB::table( 'order_claims' ), array( 'order_id' => (int) $order_id ), array( '%d' ) );
            return array( 'ok' => true, 'retryable' => true );
        }

        $result = self::handle_permanent_failure( $order, $message );
        if ( is_wp_error( $result ) ) return $result;
        return $result;
    }

    private static function handle_permanent_failure( $order, $message ) {
        global $wpdb;
        $claims_table = CafeFlo_DB::table( 'order_claims' );

        if ( 'refunded' === $order->get_status() || $order->get_total_refunded() >= $order->get_total() ) {
            $order->update_meta_data( '_cafeflo_failure_refunded', '1' );
            $order->update_meta_data( '_cafeflo_sync_status', 'failed_permanent' );
            $order->update_meta_data( '_cafeflo_claim_id', '' );
            $order->save();
            $wpdb->delete( $claims_table, array( 'order_id' => (int) $order->get_id() ), array( '%d' ) );
            return array( 'ok' => true, 'action' => 'already_refunded' );
        }

        if ( ! $order->is_paid() ) {
            $order->update_meta_data( '_cafeflo_sync_status', 'failed_permanent' );
            $order->update_meta_data( '_cafeflo_claim_id', '' );
            $order->set_status( 'cancelled' );
            $order->save();
            $wpdb->delete( $claims_table, array( 'order_id' => (int) $order->get_id() ), array( '%d' ) );
            return array( 'ok' => true, 'action' => 'cancelled' );
        }

        if ( '1' === $order->get_meta( '_cafeflo_failure_refunded', true ) ) {
            $order->update_meta_data( '_cafeflo_sync_status', 'failed_permanent' );
            $order->set_status( 'refunded' );
            $order->save();
            return array( 'ok' => true, 'action' => 'already_refunded' );
        }

        $refund = wc_create_refund( array(
            'amount' => max( 0, $order->get_total() - (float) $order->get_total_refunded() ),
            'reason' => 'CafeFlo Bridge permanent transfer failure: ' . $message,
            'order_id' => $order->get_id(),
            'refund_payment' => true,
            'restock_items' => false,
        ) );

        if ( is_wp_error( $refund ) ) {
            $order->update_meta_data( '_cafeflo_sync_status', 'manual_review' );
            $order->update_meta_data( '_cafeflo_last_error', $refund->get_error_message() );
            $order->set_status( 'on-hold' );
            $order->save();
            return new WP_Error( 'cafeflo_refund_failed', 'The payment refund failed; the order has been placed on hold for manual review.', array( 'status' => 502 ) );
        }

        $order->update_meta_data( '_cafeflo_failure_refunded', '1' );
        $order->update_meta_data( '_cafeflo_sync_status', 'failed_permanent' );
        $order->update_meta_data( '_cafeflo_claim_id', '' );
        $order->set_status( 'refunded' );
        $order->save();
        $wpdb->delete( $claims_table, array( 'order_id' => (int) $order->get_id() ), array( '%d' ) );
        return array( 'ok' => true, 'action' => 'refunded' );
    }

    public static function update_status( $order_id, $payload ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! self::is_online_order( $order ) ) return new WP_Error( 'cafeflo_order_not_found', 'Online order not found.', array( 'status' => 404 ) );
        $flocafe_id = sanitize_text_field( (string) ( $payload['flocafe_order_id'] ?? '' ) );
        $source_status = strtolower( sanitize_text_field( (string) ( $payload['flocafe_status'] ?? $payload['status'] ?? '' ) ) );
        $aliases = array(
            'waiting-for-cafe' => 'pending',
            'waiting-cafe' => 'pending',
            'received-by-cafe' => 'received',
            'received-cafe' => 'received',
        );
        $source_status = isset( $aliases[ $source_status ] ) ? $aliases[ $source_status ] : $source_status;
        $known = array( 'pending', 'accepted', 'received', 'preparing', 'ready', 'served', 'completed', 'cancelled' );
        if ( '' === $flocafe_id || '' === $source_status || ! in_array( $source_status, $known, true ) ) {
            return new WP_Error( 'cafeflo_unknown_flocafe_status', 'Unknown FloCafe order status.', array( 'status' => 422 ) );
        }
        if ( $order->get_meta( '_cafeflo_order_id', true ) !== $flocafe_id ) {
            return new WP_Error( 'cafeflo_order_id_mismatch', 'FloCafe order identity does not match this WooCommerce order.', array( 'status' => 409 ) );
        }

        $map = array(
            'pending' => 'waiting-for-cafe',
            'accepted' => 'received-by-cafe',
            'received' => 'received-by-cafe',
            'preparing' => 'preparing',
            'ready' => 'ready',
            'served' => 'completed',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
        );
        $mapped = $map[ $source_status ];
        $order->update_meta_data( '_cafeflo_sync_status', $source_status );
        $order->update_meta_data( '_cafeflo_flocafe_status', $source_status );
        $order->update_meta_data( '_cafeflo_last_status_at', current_time( 'mysql', true ) );
        if ( 'cancelled' === $mapped ) {
            if ( 'refunded' === $order->get_status() || $order->get_total_refunded() >= $order->get_total() ) {
                $order->update_meta_data( '_cafeflo_sync_status', 'cancelled' );
                $order->update_meta_data( '_cafeflo_flocafe_status', 'cancelled' );
            } elseif ( $order->is_paid() ) {
                $refund_result = wc_create_refund( array(
                    'amount' => max( 0, $order->get_total() - (float) $order->get_total_refunded() ),
                    'reason' => 'FloCafe cancelled online order ' . $flocafe_id,
                    'order_id' => $order->get_id(),
                    'refund_payment' => true,
                    'restock_items' => false,
                ) );
                if ( is_wp_error( $refund_result ) ) {
                    $order->update_meta_data( '_cafeflo_sync_status', 'manual_review' );
                    $order->update_meta_data( '_cafeflo_last_error', 'FloCafe cancelled the order but payment refund failed: ' . $refund_result->get_error_message() );
                    $order->set_status( 'on-hold' );
                    $order->save();
                    return new WP_Error( 'cafeflo_cancel_refund_failed', 'FloCafe cancelled the order but its payment refund failed; manual review is required.', array( 'status' => 502 ) );
                }
                $order->update_meta_data( '_cafeflo_sync_status', 'cancelled' );
                $order->update_meta_data( '_cafeflo_flocafe_status', 'cancelled' );
                $order->set_status( 'refunded' );
            } else {
                $order->update_meta_data( '_cafeflo_sync_status', 'cancelled' );
                $order->update_meta_data( '_cafeflo_flocafe_status', 'cancelled' );
                $order->set_status( 'cancelled' );
            }
        } else {
            if ( $order->get_status() !== $mapped ) $order->set_status( $mapped );
        }
        $order->save();

        return array( 'ok' => true, 'woo_status' => $mapped, 'flocafe_order_id' => $flocafe_id );
    }
}
