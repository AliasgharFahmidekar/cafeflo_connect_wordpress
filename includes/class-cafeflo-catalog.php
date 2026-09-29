<?php
defined( 'ABSPATH' ) || exit;

final class CafeFlo_Catalog {
    private static $syncing = false;

    public static function taxonomy() {
        $selected = sanitize_key( (string) get_option( 'cafeflo_product_taxonomy', 'product_cat' ) );
        if ( taxonomy_exists( $selected ) ) {
            $taxonomy = get_taxonomy( $selected );
            if ( $taxonomy && in_array( 'product', (array) $taxonomy->object_type, true ) ) {
                return $selected;
            }
        }
        return 'product_cat';
    }

    public static function available_taxonomies() {
        $taxonomies = get_object_taxonomies( 'product', 'objects' );
        $result = array();
        foreach ( $taxonomies as $taxonomy ) {
            if ( empty( $taxonomy->show_ui ) || ! empty( $taxonomy->_builtin ) && 'product_cat' !== $taxonomy->name ) {
                continue;
            }
            $result[] = $taxonomy;
        }
        usort(
            $result,
            static function ( $a, $b ) {
                if ( 'product_cat' === $a->name ) return -1;
                if ( 'product_cat' === $b->name ) return 1;
                return strcasecmp( $a->label, $b->label );
            }
        );
        return $result;
    }

    public static function boot() {
        add_action( 'save_post_product', array( __CLASS__, 'track_local_product_change' ), 20, 3 );
        add_action( 'edited_term', array( __CLASS__, 'track_local_category_change' ), 20, 3 );
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
        if ( '' !== $source_instance_id && '' === $stored_source_instance_id ) {
            global $wpdb;
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . CafeFlo_DB::table( 'mappings' ) . ' SET source_instance_id=%s WHERE source_instance_id=%s',
                    $source_instance_id,
                    ''
                )
            );
        }
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
                if ( $map && $parent && get_term( (int) $map['wp_id'], self::taxonomy() ) ) {
                    wp_update_term( (int) $map['wp_id'], self::taxonomy(), array( 'parent' => (int) $parent['wp_id'] ) );
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
        $source = CafeFlo_DB::current_source_instance_id();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT entity_type, flocafe_id, wp_id FROM ' . CafeFlo_DB::table( 'mappings' ) . ' WHERE source_instance_id=%s',
                $source
            ),
            ARRAY_A
        );
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

        if ( $term_id && get_term( $term_id, self::taxonomy() ) && $source_hash === get_term_meta( $term_id, '_cafeflo_source_hash', true ) ) {
            return array( 'flocafe_id' => $id, 'wp_id' => $term_id, 'changed' => false );
        }

        $name = sanitize_text_field( (string) $data['name'] );
        $args = array(
            'description' => isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : '',
            'slug' => ! empty( $data['slug'] ) ? sanitize_title( $data['slug'] ) : sanitize_title( $name ),
        );

        if ( $term_id && get_term( $term_id, self::taxonomy() ) ) {
            $result = wp_update_term( $term_id, self::taxonomy(), array_merge( array( 'name' => $name ), $args ) );
            if ( is_wp_error( $result ) ) return $result;
            $term_id = (int) $result['term_id'];
            $action = 'updated';
        } else {
            $result = wp_insert_term( $name, self::taxonomy(), $args );
            if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() ) {
                $args['slug'] = sanitize_title( $name . '-flocafe-' . $id );
                $result = wp_insert_term( $name, self::taxonomy(), $args );
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
                wp_update_term( $term_id, self::taxonomy(), array( 'parent' => (int) $parent['wp_id'] ) );
            }
        }

        CafeFlo_DB::record_catalog_change( 'category', $id, $action );
        return array( 'flocafe_id' => $id, 'wp_id' => $term_id, 'changed' => true );
    }

    private static function sync_product( $data, $source_revision ) {
        if ( empty( $data['id'] ) || ! isset( $data['name'] ) || ! isset( $data['price'] ) ) {
            return new WP_Error( 'cafeflo_invalid_product', 'Product requires id, name and price.', array( 'status' => 422 ) );
        }
        if ( ! function_exists( 'update_field' ) ) {
            return new WP_Error(
                'cafeflo_acf_required',
                'Advanced Custom Fields is required to synchronize product catalog fields.',
                array( 'status' => 503 )
            );
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

        // Current and legacy Bridge payloads are both accepted.
        $available = array_key_exists( 'available', $data )
            ? ! empty( $data['available'] )
            : ( array_key_exists( 'is_available', $data ) ? ! empty( $data['is_available'] ) : true );

        $source_active = array_key_exists( 'active', $data ) ? ! empty( $data['active'] ) : true;

        // Keep the existing operational visibility behavior while exposing
        // the two separate ACF fields to the website.
        $visible = $category_active && $source_active && $available;

        // FloCafe's canonical price is Rial. When enabled, convert exactly once at
        // the catalog boundary so WooCommerce and ACF both work in Toman.
        $price = self::price_for_website( $data['price'] );

        // WooCommerce price remains authoritative for cart/order mechanics.
        // Website-facing catalog content is additionally written to ACF.
        $product->set_name( wp_strip_all_tags( (string) $data['name'] ) );
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

        if ( isset( $data['sort_order'] ) ) {
            $product->set_menu_order( (int) $data['sort_order'] );
        }

        // Keep Woo publication state as an operational compatibility layer.
        $product->set_status( $visible ? 'publish' : 'draft' );
        $product->set_catalog_visibility( $visible ? 'visible' : 'hidden' );

        $product_id = $product->save();
        if ( ! $product_id ) {
            return new WP_Error( 'cafeflo_product_save_failed', 'Could not save WooCommerce product.', array( 'status' => 500 ) );
        }

        self::sync_acf_product_fields( $product_id, $data, $available, $visible );

        $mapped = CafeFlo_DB::upsert_mapping( 'product', $id, $product_id );
        if ( is_wp_error( $mapped ) ) return $mapped;

        update_post_meta( $product_id, '_cafeflo_product_id', $id );
        update_post_meta( $product_id, '_cafeflo_source_revision', (int) $source_revision );
        update_post_meta( $product_id, '_cafeflo_source_hash', md5( wp_json_encode( array( $id, $data, $available, $visible ) ) ) );
        update_post_meta( $product_id, '_cafeflo_available', $available ? '1' : '0' );
        update_post_meta( $product_id, '_cafeflo_visible', $visible ? '1' : '0' );
        update_post_meta( $product_id, '_cafeflo_image_url', ! empty( $data['image_url'] ) ? esc_url_raw( $data['image_url'] ) : '' );
        update_post_meta( $product_id, '_cafeflo_sale_unit', isset( $data['sale_unit'] ) ? sanitize_text_field( (string) $data['sale_unit'] ) : '' );
        update_post_meta( $product_id, '_cafeflo_tags', isset( $data['tags'] ) && is_array( $data['tags'] ) ? wp_json_encode( array_values( $data['tags'] ) ) : '[]' );

        self::remove_managed_category_terms( $product_id );
        if ( ! empty( $data['category_id'] ) ) {
            $category_map = CafeFlo_DB::get_mapping( 'category', (string) $data['category_id'] );
            if ( $category_map ) {
                wp_set_object_terms( $product_id, array( (int) $category_map['wp_id'] ), self::taxonomy(), true );
            }
        }

        CafeFlo_DB::record_catalog_change( 'product', $id, $action );
        return array( 'flocafe_id' => $id, 'wp_id' => $product_id, 'changed' => true );
    }

    private static function price_conversion_enabled() {
        return '1' === get_option( 'cafeflo_price_rial_to_toman', '1' );
    }

    /**
     * Convert the canonical FloCafe Rial price to the website's Toman price.
     * This is the single inbound conversion boundary for catalog prices.
     */
    private static function price_for_website( $price ) {
        $price = wc_format_decimal( $price );
        if ( ! self::price_conversion_enabled() ) return $price;

        return wc_format_decimal( (float) $price / 10 );
    }

    /**
     * Convert a website Toman price back to FloCafe's canonical Rial price
     * when a catalog snapshot is sent back through the bridge.
     */
    private static function price_for_flocafe( $price ) {
        $price = wc_format_decimal( $price );
        if ( ! self::price_conversion_enabled() ) return $price;

        return wc_format_decimal( (float) $price * 10 );
    }

    private static function sync_acf_product_fields( $product_id, $data, $available, $visible ) {
        update_field( 'price', (float) self::price_for_website( $data['price'] ), $product_id );
        update_field(
            'description',
            isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : '',
            $product_id
        );
        update_field( 'available', (bool) $available, $product_id );
        update_field( 'visible', (bool) $visible, $product_id );

        // FloCafe does not currently expose a featured flag. Never overwrite
        // the site's existing ACF "featured" value.
        if ( array_key_exists( 'image_url', $data ) ) {
            self::sync_acf_product_image( $product_id, $data['image_url'] );
        }
    }

    private static function sync_acf_product_image( $product_id, $image_url ) {
        $image_url = '' !== (string) $image_url ? esc_url_raw( (string) $image_url ) : '';
        $field = function_exists( 'get_field_object' )
            ? get_field_object( 'product_image', $product_id, false, false )
            : false;

        if ( is_array( $field ) && isset( $field['type'] ) && 'image' === $field['type'] ) {
            if ( '' === $image_url ) {
                update_field( 'product_image', false, $product_id );
                return;
            }

            $attachment_id = self::find_or_import_acf_image( $image_url, $product_id );
            if ( $attachment_id ) {
                update_field( 'product_image', $attachment_id, $product_id );
            }
            return;
        }

        // URL/text/image fields can store the source URL directly.
        update_field( 'product_image', $image_url, $product_id );
    }

    private static function find_or_import_acf_image( $image_url, $product_id ) {
        $existing = get_posts( array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_cafeflo_image_source_url',
            'meta_value' => $image_url,
        ) );

        if ( ! empty( $existing ) ) {
            return (int) $existing[0];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        if ( ! function_exists( 'media_sideload_image' ) ) return 0;

        $attachment_id = media_sideload_image( $image_url, $product_id, null, 'id' );
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) return 0;

        update_post_meta( (int) $attachment_id, '_cafeflo_image_source_url', $image_url );
        return (int) $attachment_id;
    }

    private static function remove_managed_category_terms( $product_id ) {
        $current = wp_get_object_terms( (int) $product_id, self::taxonomy(), array( 'fields' => 'ids' ) );
        if ( is_wp_error( $current ) ) return;
        $managed = array();
        foreach ( $current as $term_id ) {
            if ( CafeFlo_DB::get_mapping_by_wp_id( 'category', (int) $term_id ) ) $managed[] = (int) $term_id;
        }
        if ( $managed ) wp_remove_object_terms( (int) $product_id, $managed, self::taxonomy() );
    }

    private static function deactivate_missing_managed_products( $seen ) {
        global $wpdb;
        $source = CafeFlo_DB::current_source_instance_id();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT flocafe_id, wp_id FROM ' . CafeFlo_DB::table( 'mappings' ) . " WHERE entity_type='product' AND source_instance_id=%s",
                $source
            ),
            ARRAY_A
        );
        foreach ( $rows as $row ) {
            if ( isset( $seen[(string) $row['flocafe_id']] ) ) continue;
            $product = wc_get_product( (int) $row['wp_id'] );
            if ( ! $product ) continue;
            $was_active = 'publish' === $product->get_status() || 'visible' === $product->get_catalog_visibility();
            $product->set_status( 'draft' );
            $product->set_catalog_visibility( 'hidden' );
            $product->save();
            update_post_meta( (int) $row['wp_id'], '_cafeflo_available', '0' );
            update_post_meta( (int) $row['wp_id'], '_cafeflo_visible', '0' );
            if ( function_exists( 'update_field' ) ) {
                update_field( 'available', false, (int) $row['wp_id'] );
                update_field( 'visible', false, (int) $row['wp_id'] );
            }
            if ( $was_active ) CafeFlo_DB::record_catalog_change( 'product', (string) $row['flocafe_id'], 'deactivated' );
        }
    }

    private static function deactivate_missing_managed_categories( $categories ) {
        $seen = array();
        foreach ( $categories as $category ) if ( isset( $category['id'] ) ) $seen[(string) $category['id']] = true;
        global $wpdb;
        $source = CafeFlo_DB::current_source_instance_id();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT flocafe_id, wp_id FROM ' . CafeFlo_DB::table( 'mappings' ) . " WHERE entity_type='category' AND source_instance_id=%s",
                $source
            ),
            ARRAY_A
        );
        foreach ( $rows as $row ) {
            if ( isset( $seen[(string) $row['flocafe_id']] ) ) continue;
            if ( get_term( (int) $row['wp_id'], self::taxonomy() ) ) update_term_meta( (int) $row['wp_id'], '_cafeflo_active', '0' );
        }
    }

    public static function track_local_product_change( $post_id, $post, $update ) {
        if ( ! $post || 'product' !== $post->post_type || wp_is_post_revision( $post_id ) || self::$syncing ) return;
        $id = get_post_meta( $post_id, '_cafeflo_product_id', true );
        if ( $id ) CafeFlo_DB::record_catalog_change( 'product', (string) $id, 'local_update' );
    }

    public static function track_local_category_change( $term_id, $tt_id = 0, $taxonomy = '' ) {
        if ( self::$syncing || self::taxonomy() !== $taxonomy ) return;
        $id = get_term_meta( $term_id, '_cafeflo_category_id', true );
        if ( $id ) CafeFlo_DB::record_catalog_change( 'category', (string) $id, 'local_update' );
    }

    private static function get_acf_product_field( $field_name, $product_id, $fallback ) {
        if ( ! function_exists( 'get_field' ) || ! function_exists( 'get_field_object' ) ) return $fallback;

        $field = get_field_object( $field_name, $product_id, false, false );
        if ( ! is_array( $field ) ) return $fallback;

        $value = get_field( $field_name, $product_id, false );
        return null === $value ? $fallback : $value;
    }

    private static function get_acf_product_image_url( $product_id ) {
        if ( function_exists( 'get_field' ) && function_exists( 'get_field_object' ) ) {
            $field = get_field_object( 'product_image', $product_id, false, false );
            if ( is_array( $field ) ) {
                $value = get_field( 'product_image', $product_id, true );

                if ( is_array( $value ) && ! empty( $value['url'] ) ) {
                    return esc_url_raw( (string) $value['url'] );
                }

                if ( is_numeric( $value ) ) {
                    $url = wp_get_attachment_url( (int) $value );
                    if ( $url ) return esc_url_raw( $url );
                }

                if ( is_string( $value ) && '' !== $value ) {
                    return esc_url_raw( $value );
                }
            }
        }

        return get_post_meta( $product_id, '_cafeflo_image_url', true ) ?: null;
    }

    public static function snapshot() {
        $categories = array();
        $terms = get_terms( array( 'taxonomy' => self::taxonomy(), 'hide_empty' => false, 'meta_key' => '_cafeflo_category_id' ) );
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
        $source = CafeFlo_DB::current_source_instance_id();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT flocafe_id, wp_id FROM ' . CafeFlo_DB::table( 'mappings' ) . " WHERE entity_type='product' AND source_instance_id=%s ORDER BY wp_id ASC",
                $source
            ),
            ARRAY_A
        );
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

            $website_description = self::get_acf_product_field( 'description', $product->get_id(), $product->get_description() );
            $website_price = self::get_acf_product_field( 'price', $product->get_id(), $product->get_price() );
            $website_available = self::get_acf_product_field(
                'available',
                $product->get_id(),
                'publish' === $product->get_status() && 'hidden' !== $product->get_catalog_visibility()
            );

            $products[] = array(
                'id' => (string) $row['flocafe_id'],
                'category_id' => $cat_id,
                'name' => $product->get_name(),
                'description' => (string) $website_description,
                'price' => (float) self::price_for_flocafe( $website_price ),
                'sku' => $product->get_sku() ?: null,
                'image_url' => self::get_acf_product_image_url( $product->get_id() ),
                'is_available' => (bool) $website_available,
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
