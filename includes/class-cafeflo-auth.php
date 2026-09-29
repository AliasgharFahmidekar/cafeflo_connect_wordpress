<?php

defined( 'ABSPATH' ) || exit;

final class CafeFlo_Auth {
    public static function check_request( $request ) {
        $expected = trim( (string) get_option( 'cafeflo_bridge_api_key', '' ) );
        if ( '' === $expected ) {
            return new WP_Error( 'cafeflo_not_configured', 'CafeFlo Bridge authentication is not configured.', array( 'status' => 503 ) );
        }

        $supplied = '';
        $header = $request->get_header( 'x-cafeflo-bridge-key' );
        if ( is_string( $header ) ) {
            $supplied = trim( $header );
        }
        if ( '' === $supplied ) {
            $header = $request->get_header( 'x-flocafe-bridge-key' );
            if ( is_string( $header ) ) {
                $supplied = trim( $header );
            }
        }
        if ( '' === $supplied ) {
            $header = $request->get_header( 'x-flocafe-integration-key' );
            if ( is_string( $header ) ) {
                $supplied = trim( $header );
            }
        }
        if ( '' === $supplied ) {
            $auth = $request->get_header( 'authorization' );
            if ( is_string( $auth ) && preg_match( '/^Bearer\s+(.+)$/i', $auth, $matches ) ) {
                $supplied = trim( $matches[1] );
            }
        }

        if ( '' === $supplied || ! hash_equals( $expected, $supplied ) ) {
            return new WP_Error( 'cafeflo_unauthorized', 'Invalid CafeFlo Bridge credentials.', array( 'status' => 401 ) );
        }

        return true;
    }

    public static function admin_only() {
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
            return true;
        }
        return new WP_Error( 'cafeflo_forbidden', 'Administrator permission required.', array( 'status' => 403 ) );
    }
}
