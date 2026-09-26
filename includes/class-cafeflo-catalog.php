<?php
defined( 'ABSPATH' ) || exit;

final class CafeFlo_Catalog {
    private static $syncing = false;

    public static function boot() {
        add_action( 'save_post_product', array( __CLASS__, 'track_local_product_change' ), 20, 3 );
        add_action( 'edited_product_cat', array( __CLASS__, 'track_local_category_change' ), 20 );
    }

    public static function sync( $payload ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_Error( 'cafeflo_woocommerce_required', 'WooCommerce is required.', array( 'status' => 503 ) );
        }
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'cafeflo_invalid_catalog', 'Catalog payload must be an object.', array( 'status' => 422 ) );
        }

        $revision = isset( $payload['revision'] ) ? max( 0, (int) $payload['revision'] ) : 0;
        $source_instance_id = isset( $payload['source_instance_id'] ) ? sanitize_text_field( (string) $payload['source_instance_id'] ) : '';
        $stored_source_instance_id = (string) get_option( 'cafeflo_source_instance_id', '' );
        if ( '' !== $source_instance_id && '' !== $stored_source_instance_id && $source_instance_id !== $stored_source_instance_id ) {
            // A new FloCafe installation may legitimately start with a lower
            // local revision. Treat the source identity change as a new catalog
            // stream instead of permanently rejecting every snapshot as stale.
            update_option( 'cafeflo_last_flocafe_revision', 0, false );
            update_option( 'cafeflo_catalog_synced', '0', false );
        }
        if ( '' !== $source_instance_id && $source_instance_id !== $stored_source_instance_id ) {
            update_option( 'cafeflo_source_instance_id', $source_instance_id, false );
        }
        $last = (int) get_option( 'cafeflo_last_flocafe_revision', 0 );
        if ( $revision < $last ) {
            return new WP_Error( 'cafeflo_stale_catalog', 'Catalog revision is older than the last applied revision.', array( 'status' => 409, 'last_source_revision' => $last ) );
        }

        if ( '1' === get_option( 'cafeflo_catalog_synced', '0' ) && $revision === $last ) {
            return array(
                'ok' => true, 'ignored' => true, 'reason' => 'already_applied',
                'revision' => $revision, 'source_revision' => $revision,
                'mappings' => self::current_mappings(),
            );
        }

        $categories = isset( $payload['categories'] ) && is_array( $payload['categories'] ) ? $payload['categories'] : array();
        $products = isset( $payload['products'] ) && is_array( $payload['products'] ) ? $payload['products'] : array();
        $full_snapshot = ! empty( $payload['full_snapshot'] );

        if ( count( $categories ) > 5000 || count( $products ) > 10000 ) {
            return new WP_Error( 'cafeflo_catalog_too_large', 'Catalog payload is too large.', array( 'status' => 413 ) );
        }

        self::$syncing = true;
        try {
            foreach ( $categories as $category ) {
                $result = self::sync_category( $category );
                if ( is_wp_error( $result ) ) return $result;
            }

            foreach ( $categories as $category ) {
                $parent_id = ! empty( $category['parent_id'] ) ? (string) $category['parent_id'] : '';
                if ( '' === $parent_id ) continue;
                $map = CafeFlo_DB::get_mapping( 'category', (string) $category['id'] );
                $parent = CafeFlo_DB::get_mapping( 'category', $parent_id );
                if ( $map && $parent && get_term( (int) $map['wp_id'], 'product_cat' ) ) {
                    wp_update_term( (int) $map['wp_id'], 'product_cat', array( 'parent' => (int) $parent['wp_id'] ) );
                }
            }

            $seen_products = array();
            foreach ( $products as $product ) {
                $result = self::sync_product( $product, $revision );
                if ( is_wp_error( $result ) ) return $result;
                $seen_products[(string) $result['flocafe_id']] = true;
            }

            if ( $full_snapshot ) {
                self::deactivate_missing_managed_products( $seen_products );
                self::deactivate_missing_managed_categories( $categories );
            }

            update_option( 'cafeflo_last_flocafe_revision', $revision, false );
            update_option( 'cafeflo_catalog_synced', '1', false );

            return array(
                'ok' => true,
                'revision' => $revision,
                'source_revision' => $revision,
                'categories_received' => count( $categories ),
                'products_received' => count( $products ),
                'full_snapshot' => $full_snapshot,
                'mappings' => self::current_mappings(),
            );
        } finally {
            self::$syncing = false;
        }
    }

    private static function current_mappings() {
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT entity_type, flocafe_id, wp_id FROM ' . CafeFlo_DB::table( 'mappings' ), ARRAY_A );
        $products = array();
        $categories = array();
        foreach ( $rows as $row ) {
            if ( 'product' === $row['entity_type'] ) {
                $products[] = array( 'flocafe_product_id' => (string) $row['flocafe_id'], 'woo_product_id' => (int) $row['wp_id'] );
            } elseif ( 'category' === $row['entity_type'] ) {
                $categories[] = array( 'flocafe_category_id' => (string) $row['flocafe_id'], 'woo_category_id' => (int) $row['wp_id'] );
            }
        }
        return array( 'products' => $products, 'categories' => $categories );
    }

    private static function sync_category( $data ) {
        if ( empty( $data['id'] ) || ! isset( $data['name'] ) ) {
            return new WP_Error( 'cafeflo_invalid_category', 'Category requires id and name.', array( 'status' => 422 ) );
        }

        $id = (string) $data['id'];
        $map = CafeFlo_DB::get_mapping( 'category', $id );
        $term_id = $map ? (int) $map['wp_id'] : 0;

        $source_hash = md5( wp_json_encode( array(
            'id' => $id,
            'name' => (string) $data['name'],
            'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
            'parent_id' => isset( $data['parent_id'] ) ? (string) $data['parent_id'] : '',
            'slug' => isset( $data['slug'] ) ? (string) $data['slug'] : '',
            'is_active' => ! empty( $data['is_active'] ),
            'color' => isset( $data['color'] ) ? (string) $data['color'] : '',
            'icon' => isset( $data['icon'] ) ? (string) $data['icon'] : '',
        ) ) );

        if ( $term_id && get_term( $term_id, 'product_cat' ) && $source_hash === get_term_meta( $term_id, '_cafeflo_source_hash', true ) ) {
            return array( 'flocafe_id' => $id, 'wp_id' => $term_id, 'changed' => false );
        }

        $name = sanitize_text_field( (string) $data['name'] );
        $args = array(
            'description' => isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : '',
            'slug' => ! empty( $data['slug'] ) ? sanitize_title( $data['slug'] ) : sanitize_title( $name ),
        );

        if ( $term_id && get_term( $term_id, 'product_cat' ) ) {
            $result = wp_update_term( $term_id, 'product_cat', array_merge( array( 'name' => $name ), $args ) );
            if ( is_wp_error( $result ) ) return $result;
            $term_id = (int) $result['term_id'];
            $action = 'updated';
        } else {
            $result = wp_insert_term( $name, 'product_cat', $args );
            if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() ) {
                $args['slug'] = sanitize_title( $name . '-flocafe-' . $id );
                $result = wp_insert_term( $name, 'product_cat', $args );
            }
            if ( is_wp_error( $result ) ) return $result;
            $term_id = (int) $result['term_id'];
            $action = 'created';
        }

        $mapped = CafeFlo_DB::upsert_mapping( 'category', $id, $term_id );
        if ( is_wp_error( $mapped ) ) return $mapped;

        update_term_meta( $term_id, '_cafeflo_category_id', $id );
        update_term_meta( $term_id, '_cafeflo_active', ! empty( $data['is_active'] ) ? '1' : '0' );
        update_term_meta( $term_id, '_cafeflo_source_hash', $source_hash );
        if ( isset( $data['color'] ) ) update_term_meta( $term_id, '_cafeflo_color', sanitize_text_field( (string) $data['color'] ) );
        if ( isset( $data['icon'] ) ) update_term_meta( $term_id, '_cafeflo_icon', sanitize_text_field( (string) $data['icon'] ) );

        if ( ! empty( $data['parent_id'] ) ) {
            $parent = CafeFlo_DB::get_mapping( 'category', (string) $data['parent_id'] );
            if ( $parent ) {
                wp_update_term( $term_id, 'product_cat', array( 'parent' => (int) $parent['wp_id'] ) );
            }
        }

        CafeFlo_DB::record_catalog_change( 'category', $id, $action );
        return array( 'flocafe_id' => $id, 'wp_id' => $term_id, 'changed' => true );
    }

    private static function sync_product( $data, $source_revision ) {
        if ( empty( $data['id'] ) || ! isset( $data['name'] ) || ! isset( $data['price'] ) ) {
            return new WP_Error( 'cafeflo_invalid_product', 'Product requires id, name and price.', array( 'status' => 422 ) );
        }

        $id = (string) $data['id'];
        $map = CafeFlo_DB::get_mapping( 'product', $id );
        $product_id = $map ? (int) $map['wp_id'] : 0;
        $product = $product_id ? wc_get_product( $product_id ) : false;

        if ( ! $product ) {
            $product = new WC_Product_Simple();
            $action = 'created';
        } else {
            $action = 'updated';
        }

        $category_active = true;
        if ( ! empty( $data['category_id'] ) ) {
            $category_map = CafeFlo_DB::get_mapping( 'category', (string) $data['category_id'] );
            if ( $category_map && '1' !== get_term_meta( (int) $category_map['wp_id'], '_cafeflo_active', true ) ) {
                $category_active = false;
            }
        }
        $active = $category_active && ( ! isset( $data['is_available'] ) || ! empty( $data['is_available'] ) );

        $price = wc_format_decimal( $data['price'] );
        $product->set_name( wp_strip_all_tags( (string) $data['name'] ) );
        $product->set_description( isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : '' );
        $product->set_regular_price( $price );
        $product->set_sale_price( '' );
        $product->set_price( $price );
        $product->set_date_on_sale_from( null );
        $product->set_date_on_sale_to( null );
        $product->set_manage_stock( false );
        $product->set_stock_quantity( null );

        $sku = isset( $data['sku'] ) ? trim( (string) $data['sku'] ) : '';
        if ( '' === $sku ) {
            $product->set_sku( '' );
        } else {
            $existing_sku_id = wc_get_product_id_by_sku( $sku );
            if ( ! $existing_sku_id || (int) $existing_sku_id === (int) $product->get_id() ) {
                $product->set_sku( $sku );
            }
        }

        if ( isset( $data['sort_order'] ) ) $product->set_menu_order( (int) $data['sort_order'] );
        $product->set_status( $active ? 'publish' : 'draft' );
        $product->set_catalog_visibility( $active ? 'visible' : 'hidden' );

        $product_id = $product->save();
        if ( ! $product_id ) return new WP_Error( 'cafeflo_product_save_failed', 'Could not save WooCommerce product.', array( 'status' => 500 ) );

        $mapped = CafeFlo_DB::upsert_mapping( 'product', $id, $product_id );
        if ( is_wp_error( $mapped ) ) return $mapped;

        update_post_meta( $product_id, '_cafeflo_product_id', $id );
        update_post_meta( $product_id, '_cafeflo_source_revision', (int) $source_revision );
        update_post_meta( $product_id, '_cafeflo_source_hash', md5( wp_json_encode( array( $id, $data, $active ) ) ) );
        update_post_meta( $product_id, '_cafeflo_available', $active ? '1' : '0' );
        update_post_meta( $product_id, '_cafeflo_image_url', ! empty( $data['image_url'] ) ? esc_url_raw( $data['image_url'] ) : '' );
        update_post_meta( $product_id, '_cafeflo_sale_unit', isset( $data['sale_unit'] ) ? sanitize_text_field( (string) $data['sale_unit'] ) : '' );
        update_post_meta( $product_id, '_cafeflo_tags', isset( $data['tags'] ) && is_array( $data['tags'] ) ? wp_json_encode( array_values( $data['tags'] ) ) : '[]' );

        self::remove_managed_category_terms( $product_id );
        if ( ! empty( $data['category_id'] ) ) {
            $category_map = CafeFlo_DB::get_mapping( 'category', (string) $data['category_id'] );
            if ( $category_map ) {
                wp_set_object_terms( $product_id, array( (int) $category_map['wp_id'] ), 'product_cat', true );
            }
        }

        CafeFlo_DB::record_catalog_change( 'product', $id, $action );
        return array( 'flocafe_id' => $id, 'wp_id' => $product_id, 'changed' => true );
    }

    private static function remove_managed_category_terms( $product_id ) {
        $current = wp_get_object_terms( (int) $product_id, 'product_cat', array( 'fields' => 'ids' ) );
        if ( is_wp_error( $current ) ) return;
        $managed = array();
        foreach ( $current as $term_id ) {
            if ( CafeFlo_DB::get_mapping_by_wp_id( 'category', (int) $term_id ) ) $managed[] = (int) $term_id;
        }
        if ( $managed ) wp_remove_object_terms( (int) $product_id, $managed, 'product_cat' );
    }

    private static function deactivate_missing_managed_products( $seen ) {
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT flocafe_id, wp_id FROM ' . CafeFlo_DB::table( 'mappings' ) . " WHERE entity_type='product'", ARRAY_A );
        foreach ( $rows as $row ) {
            if ( isset( $seen[(string) $row['flocafe_id']] ) ) continue;
            $product = wc_get_product( (int) $row['wp_id'] );
            if ( ! $product ) continue;
            $was_active = 'publish' === $product->get_status() || 'visible' === $product->get_catalog_visibility();
            $product->set_status( 'draft' );
            $product->set_catalog_visibility( 'hidden' );
            $product->save();
            update_post_meta( (int) $row['wp_id'], '_cafeflo_available', '0' );
            if ( $was_active ) CafeFlo_DB::record_catalog_change( 'product', (string) $row['flocafe_id'], 'deactivated' );
        }
    }

    private static function deactivate_missing_managed_categories( $categories ) {
        $seen = array();
        foreach ( $categories as $category ) if ( isset( $category['id'] ) ) $seen[(string) $category['id']] = true;
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT flocafe_id, wp_id FROM ' . CafeFlo_DB::table( 'mappings' ) . " WHERE entity_type='category'", ARRAY_A );
        foreach ( $rows as $row ) {
            if ( isset( $seen[(string) $row['flocafe_id']] ) ) continue;
            if ( get_term( (int) $row['wp_id'], 'product_cat' ) ) update_term_meta( (int) $row['wp_id'], '_cafeflo_active', '0' );
        }
    }

    public static function track_local_product_change( $post_id, $post, $update ) {
        if ( ! $post || 'product' !== $post->post_type || wp_is_post_revision( $post_id ) || self::$syncing ) return;
        $id = get_post_meta( $post_id, '_cafeflo_product_id', true );
        if ( $id ) CafeFlo_DB::record_catalog_change( 'product', (string) $id, 'local_update' );
    }

    public static function track_local_category_change( $term_id ) {
        if ( self::$syncing ) return;
        $id = get_term_meta( $term_id, '_cafeflo_category_id', true );
        if ( $id ) CafeFlo_DB::record_catalog_change( 'category', (string) $id, 'local_update' );
    }

    public static function snapshot() {
        $categories = array();
        $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'meta_key' => '_cafeflo_category_id' ) );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $id = get_term_meta( $term->term_id, '_cafeflo_category_id', true );
                if ( '' === $id ) continue;
                $parent = $term->parent ? CafeFlo_DB::get_mapping_by_wp_id( 'category', $term->parent ) : null;
                $categories[] = array(
                    'id' => (string) $id,
                    'name' => $term->name,
                    'description' => $term->description,
                    'parent_id' => $parent ? (string) $parent['flocafe_id'] : null,
                    'slug' => $term->slug,
                    'is_active' => '1' === get_term_meta( $term->term_id, '_cafeflo_active', true ),
                );
            }
        }

        global $wpdb;
        $products = array();
        $rows = $wpdb->get_results( 'SELECT flocafe_id, wp_id FROM ' . CafeFlo_DB::table( 'mappings' ) . " WHERE entity_type='product' ORDER BY wp_id ASC", ARRAY_A );
        foreach ( $rows as $row ) {
            $product = wc_get_product( (int) $row['wp_id'] );
            if ( ! $product ) continue;
            $cat_id = null;
            foreach ( $product->get_category_ids() as $term_id ) {
                $cat = CafeFlo_DB::get_mapping_by_wp_id( 'category', $term_id );
                if ( $cat ) { $cat_id = (string) $cat['flocafe_id']; break; }
            }
            $tags = get_post_meta( $product->get_id(), '_cafeflo_tags', true );
            $tags = $tags ? json_decode( $tags, true ) : array();
            $products[] = array(
                'id' => (string) $row['flocafe_id'],
                'category_id' => $cat_id,
                'name' => $product->get_name(),
                'description' => $product->get_description(),
                'price' => (float) $product->get_price(),
                'sku' => $product->get_sku() ?: null,
                'image_url' => get_post_meta( $product->get_id(), '_cafeflo_image_url', true ) ?: null,
                'is_available' => 'publish' === $product->get_status() && 'hidden' !== $product->get_catalog_visibility(),
                'sort_order' => (int) $product->get_menu_order(),
                'sale_unit' => get_post_meta( $product->get_id(), '_cafeflo_sale_unit', true ) ?: null,
                'tags' => is_array( $tags ) ? $tags : array(),
            );
        }

        return array(
            'revision' => (int) get_option( 'cafeflo_last_flocafe_revision', 0 ),
            'categories' => $categories,
            'products' => $products,
        );
    }
}
