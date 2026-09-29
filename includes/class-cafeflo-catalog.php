<?php

defined( 'ABSPATH' ) || exit;

final class CafeFlo_Catalog {
    const PRODUCT_POST_TYPE = 'products';
    const DEFAULT_TAXONOMY = 'cafeflo_product_category';

    private static $syncing = false;

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'register_category_taxonomy' ), 20 );
        add_action( 'save_post_' . self::PRODUCT_POST_TYPE, array( __CLASS__, 'track_local_product_change' ), 20, 3 );
        add_action( 'edited_term', array( __CLASS__, 'track_local_category_change' ), 20, 3 );
    }

    public static function register_category_taxonomy() {
        if ( ! post_type_exists( self::PRODUCT_POST_TYPE ) || taxonomy_exists( self::DEFAULT_TAXONOMY ) ) {
            return;
        }

        register_taxonomy(
            self::DEFAULT_TAXONOMY,
            array( self::PRODUCT_POST_TYPE ),
            array(
                'labels' => array(
                    'name' => 'CafeFlo Categories',
                    'singular_name' => 'CafeFlo Category',
                    'search_items' => 'Search CafeFlo Categories',
                    'all_items' => 'All CafeFlo Categories',
                    'parent_item' => 'Parent CafeFlo Category',
                    'parent_item_colon' => 'Parent CafeFlo Category:',
                    'edit_item' => 'Edit CafeFlo Category',
                    'update_item' => 'Update CafeFlo Category',
                    'add_new_item' => 'Add CafeFlo Category',
                    'new_item_name' => 'New CafeFlo Category Name',
                    'menu_name' => 'Categories',
                ),
                'public' => true,
                'show_ui' => true,
                'show_admin_column' => true,
                'show_in_rest' => true,
                'hierarchical' => true,
                'rewrite' => array( 'slug' => 'cafeflo-category' ),
            )
        );
    }

    public static function taxonomy() {
        self::register_category_taxonomy();
        $selected = sanitize_key( (string) get_option( 'cafeflo_product_taxonomy', self::DEFAULT_TAXONOMY ) );
        if ( taxonomy_exists( $selected ) ) {
            $taxonomy = get_taxonomy( $selected );
            if ( $taxonomy && in_array( self::PRODUCT_POST_TYPE, (array) $taxonomy->object_type, true ) ) {
                return $selected;
            }
        }
        return self::DEFAULT_TAXONOMY;
    }

    public static function available_taxonomies() {
        self::register_category_taxonomy();
        if ( ! post_type_exists( self::PRODUCT_POST_TYPE ) ) {
            return array();
        }

        $taxonomies = get_object_taxonomies( self::PRODUCT_POST_TYPE, 'objects' );
        $result = array();
        foreach ( $taxonomies as $taxonomy ) {
            if ( empty( $taxonomy->show_ui ) ) {
                continue;
            }
            $result[] = $taxonomy;
        }

        usort(
            $result,
            static function ( $a, $b ) {
                if ( self::DEFAULT_TAXONOMY === $a->name ) return -1;
                if ( self::DEFAULT_TAXONOMY === $b->name ) return 1;
                return strcasecmp( $a->label, $b->label );
            }
        );
        return $result;
    }

    public static function sync( $payload ) {
        if ( ! post_type_exists( self::PRODUCT_POST_TYPE ) ) {
            return new WP_Error( 'cafeflo_products_cpt_required', 'The products custom post type is required.', array( 'status' => 503 ) );
        }
        if ( ! function_exists( 'update_field' ) ) {
            return new WP_Error( 'cafeflo_acf_required', 'Advanced Custom Fields is required to synchronize product catalog fields.', array( 'status' => 503 ) );
        }
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'cafeflo_invalid_catalog', 'Catalog payload must be an object.', array( 'status' => 422 ) );
        }

        $revision = isset( $payload['revision'] ) ? max( 0, (int) $payload['revision'] ) : 0;
        $source_instance_id = isset( $payload['source_instance_id'] ) ? sanitize_text_field( (string) $payload['source_instance_id'] ) : '';
        $stored_source_instance_id = CafeFlo_DB::current_source_instance_id();

        if ( '' !== $source_instance_id && '' === $stored_source_instance_id ) {
            self::migrate_empty_source_mappings( $source_instance_id );
        }
        if ( '' !== $source_instance_id && '' !== $stored_source_instance_id && $source_instance_id !== $stored_source_instance_id ) {
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
                'ok' => true,
                'ignored' => true,
                'reason' => 'already_applied',
                'revision' => $revision,
                'source_revision' => $revision,
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
                if ( empty( $category['parent_id'] ) || empty( $category['id'] ) ) continue;
                $map = CafeFlo_DB::get_mapping( 'category', (string) $category['id'] );
                $parent = CafeFlo_DB::get_mapping( 'category', (string) $category['parent_id'] );
                if ( $map && $parent && term_exists( (int) $map['wp_id'], self::taxonomy() ) && term_exists( (int) $parent['wp_id'], self::taxonomy() ) ) {
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
            if ( isset( $payload['currency'] ) ) {
                update_option( 'cafeflo_currency', sanitize_text_field( (string) $payload['currency'] ), false );
            }

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

    private static function migrate_empty_source_mappings( $source_instance_id ) {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . CafeFlo_DB::table( 'mappings' ) . ' SET source_instance_id=%s WHERE source_instance_id=%s',
                $source_instance_id,
                ''
            )
        );
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
                $post = get_post( (int) $row['wp_id'] );
                if ( ! $post || self::PRODUCT_POST_TYPE !== $post->post_type ) continue;
                $products[] = array(
                    'flocafe_product_id' => (string) $row['flocafe_id'],
                    'wordpress_product_id' => (int) $row['wp_id'],
                    'woo_product_id' => (int) $row['wp_id'],
                );
            } elseif ( 'category' === $row['entity_type'] ) {
                $term = get_term( (int) $row['wp_id'], self::taxonomy() );
                if ( ! $term || is_wp_error( $term ) ) continue;
                $categories[] = array(
                    'flocafe_category_id' => (string) $row['flocafe_id'],
                    'wordpress_category_id' => (int) $row['wp_id'],
                    'woo_category_id' => (int) $row['wp_id'],
                );
            }
        }
        return array( 'products' => $products, 'categories' => $categories );
    }

    private static function sync_category( $data ) {
        if ( empty( $data['id'] ) || ! isset( $data['name'] ) ) {
            return new WP_Error( 'cafeflo_invalid_category', 'Category requires id and name.', array( 'status' => 422 ) );
        }

        $id = (string) $data['id'];
        $taxonomy = self::taxonomy();
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

        if ( $term_id && get_term( $term_id, $taxonomy ) && $source_hash === get_term_meta( $term_id, '_cafeflo_source_hash', true ) ) {
            return array( 'flocafe_id' => $id, 'wp_id' => $term_id, 'changed' => false );
        }

        $name = sanitize_text_field( (string) $data['name'] );
        $args = array(
            'description' => isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : '',
            'slug' => ! empty( $data['slug'] ) ? sanitize_title( $data['slug'] ) : sanitize_title( $name ),
        );

        if ( $term_id && get_term( $term_id, $taxonomy ) ) {
            $result = wp_update_term( $term_id, $taxonomy, array_merge( array( 'name' => $name ), $args ) );
            if ( is_wp_error( $result ) ) return $result;
            $term_id = (int) $result['term_id'];
            $action = 'updated';
        } else {
            $result = wp_insert_term( $name, $taxonomy, $args );
            if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() ) {
                $args['slug'] = sanitize_title( $name . '-flocafe-' . $id );
                $result = wp_insert_term( $name, $taxonomy, $args );
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
        $post = $product_id ? get_post( $product_id ) : false;

        if ( ! $post || self::PRODUCT_POST_TYPE !== $post->post_type ) {
            $recover = self::find_product_by_flocafe_id( $id );
            if ( $recover ) {
                $product_id = $recover;
                $post = get_post( $product_id );
            }
        }

        $category_active = true;
        if ( ! empty( $data['category_id'] ) ) {
            $category_map = CafeFlo_DB::get_mapping( 'category', (string) $data['category_id'] );
            if ( $category_map && '1' !== get_term_meta( (int) $category_map['wp_id'], '_cafeflo_active', true ) ) {
                $category_active = false;
            }
        }

        $available = array_key_exists( 'available', $data )
            ? ! empty( $data['available'] )
            : ( array_key_exists( 'is_available', $data ) ? ! empty( $data['is_available'] ) : true );
        $source_active = array_key_exists( 'active', $data ) ? ! empty( $data['active'] ) : true;
        $visible = $category_active && $source_active && $available;
        $price = self::price_for_website( $data['price'] );
        $name = sanitize_text_field( (string) $data['name'] );
        $slug = ! empty( $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : sanitize_title( $name );

        $post_data = array(
            'post_type' => self::PRODUCT_POST_TYPE,
            'post_title' => $name,
            'post_status' => $visible ? 'publish' : 'draft',
        );
        if ( '' !== $slug ) $post_data['post_name'] = self::unique_product_slug( $slug, $product_id );
        if ( isset( $data['sort_order'] ) ) $post_data['menu_order'] = (int) $data['sort_order'];

        if ( $post && self::PRODUCT_POST_TYPE === $post->post_type ) {
            $post_data['ID'] = $product_id;
            $saved_id = wp_update_post( wp_slash( $post_data ), true );
            $action = 'updated';
        } else {
            $saved_id = wp_insert_post( wp_slash( $post_data ), true );
            $action = 'created';
        }

        if ( is_wp_error( $saved_id ) || ! $saved_id ) {
            return new WP_Error( 'cafeflo_product_save_failed', 'Could not save the products custom post type record.', array( 'status' => 500 ) );
        }
        $product_id = (int) $saved_id;

        self::sync_acf_product_fields( $product_id, $data, $available, $visible, $price );

        update_post_meta( $product_id, '_cafeflo_product_id', $id );
        update_post_meta( $product_id, '_cafeflo_source_revision', (int) $source_revision );
        update_post_meta( $product_id, '_cafeflo_source_hash', md5( wp_json_encode( array( $id, $data, $available, $visible ) ) ) );
        update_post_meta( $product_id, '_cafeflo_available', $available ? '1' : '0' );
        update_post_meta( $product_id, '_cafeflo_visible', $visible ? '1' : '0' );
        update_post_meta( $product_id, '_cafeflo_image_url', ! empty( $data['image_url'] ) ? esc_url_raw( $data['image_url'] ) : '' );
        update_post_meta( $product_id, '_cafeflo_sale_unit', isset( $data['sale_unit'] ) ? sanitize_text_field( (string) $data['sale_unit'] ) : '' );
        update_post_meta( $product_id, '_cafeflo_sku', isset( $data['sku'] ) ? sanitize_text_field( (string) $data['sku'] ) : '' );
        update_post_meta( $product_id, '_cafeflo_tags', isset( $data['tags'] ) && is_array( $data['tags'] ) ? wp_json_encode( array_values( array_map( 'sanitize_text_field', $data['tags'] ) ) ) : '[]' );

        self::remove_managed_category_terms( $product_id );
        if ( ! empty( $data['category_id'] ) ) {
            $category_map = CafeFlo_DB::get_mapping( 'category', (string) $data['category_id'] );
            if ( $category_map && term_exists( (int) $category_map['wp_id'], self::taxonomy() ) ) {
                wp_set_object_terms( $product_id, array( (int) $category_map['wp_id'] ), self::taxonomy(), true );
            }
        }

        $mapped = CafeFlo_DB::upsert_mapping( 'product', $id, $product_id );
        if ( is_wp_error( $mapped ) ) return $mapped;

        CafeFlo_DB::record_catalog_change( 'product', $id, $action );
        return array( 'flocafe_id' => $id, 'wp_id' => $product_id, 'changed' => true );
    }

    private static function find_product_by_flocafe_id( $flocafe_id ) {
        $ids = get_posts( array(
            'post_type' => self::PRODUCT_POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_cafeflo_product_id',
            'meta_value' => (string) $flocafe_id,
        ) );
        return empty( $ids ) ? 0 : (int) $ids[0];
    }

    private static function unique_product_slug( $slug, $exclude_id = 0 ) {
        $base = sanitize_title( $slug );
        if ( '' === $base ) return '';
        $candidate = $base;
        $suffix = 2;
        while ( true ) {
            $existing = get_page_by_path( $candidate, OBJECT, self::PRODUCT_POST_TYPE );
            if ( ! $existing || (int) $existing->ID === (int) $exclude_id ) {
                return $candidate;
            }
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }
    }

    private static function price_conversion_enabled() {
        return '1' === get_option( 'cafeflo_price_rial_to_toman', '1' );
    }

    private static function normalize_price( $price ) {
        return is_numeric( $price ) ? (float) $price : 0.0;
    }

    private static function price_for_website( $price ) {
        $price = self::normalize_price( $price );
        if ( ! self::price_conversion_enabled() ) return $price;
        return round( $price / 10, 6 );
    }

    private static function price_for_flocafe( $price ) {
        $price = self::normalize_price( $price );
        if ( ! self::price_conversion_enabled() ) return $price;
        return round( $price * 10, 6 );
    }

    private static function sync_acf_product_fields( $product_id, $data, $available, $visible, $price ) {
        update_field( 'price', $price, $product_id );
        update_field( 'description', isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : '', $product_id );
        update_field( 'available', (bool) $available, $product_id );
        update_field( 'visible', (bool) $visible, $product_id );

        if ( array_key_exists( 'image_url', $data ) ) {
            self::sync_acf_product_image( $product_id, $data['image_url'] );
        }
    }

    private static function sync_acf_product_image( $product_id, $image_url ) {
        $image_url = '' !== (string) $image_url ? esc_url_raw( (string) $image_url ) : '';
        $field = function_exists( 'get_field_object' ) ? get_field_object( 'product_image', $product_id, false, false ) : false;

        if ( is_array( $field ) && isset( $field['type'] ) && 'image' === $field['type'] ) {
            if ( '' === $image_url ) {
                update_field( 'product_image', false, $product_id );
                return;
            }
            $attachment_id = self::find_or_import_acf_image( $image_url, $product_id );
            if ( $attachment_id ) update_field( 'product_image', $attachment_id, $product_id );
            return;
        }

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

        if ( ! empty( $existing ) ) return (int) $existing[0];

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
            $post = get_post( (int) $row['wp_id'] );
            if ( ! $post || self::PRODUCT_POST_TYPE !== $post->post_type ) continue;
            if ( 'publish' === $post->post_status ) {
                wp_update_post( array( 'ID' => (int) $row['wp_id'], 'post_status' => 'draft' ) );
            }
            update_post_meta( (int) $row['wp_id'], '_cafeflo_available', '0' );
            update_post_meta( (int) $row['wp_id'], '_cafeflo_visible', '0' );
            if ( function_exists( 'update_field' ) ) {
                update_field( 'available', false, (int) $row['wp_id'] );
                update_field( 'visible', false, (int) $row['wp_id'] );
            }
        }
    }

    private static function deactivate_missing_managed_categories( $categories ) {
        $seen = array();
        foreach ( $categories as $category ) {
            if ( isset( $category['id'] ) ) $seen[(string) $category['id'] = true;
        }
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
            if ( get_term( (int) $row['wp_id'], self::taxonomy() ) ) {
                update_term_meta( (int) $row['wp_id'], '_cafeflo_active', '0' );
            }
        }
    }

    public static function track_local_product_change( $post_id, $post, $update ) {
        if ( ! $post || self::PRODUCT_POST_TYPE !== $post->post_type || wp_is_post_revision( $post_id ) || self::$syncing ) return;
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
                if ( is_array( $value ) && ! empty( $value['url'] ) ) return esc_url_raw( (string) $value['url'] );
                if ( is_numeric( $value ) ) {
                    $url = wp_get_attachment_url( (int) $value );
                    if ( $url ) return esc_url_raw( $url );
                }
                if ( is_string( $value ) && '' !== $value ) return esc_url_raw( $value );
            }
        }
        return get_post_meta( $product_id, '_cafeflo_image_url', true ) ?: null;
    }

    public static function snapshot() {
        self::register_category_taxonomy();
        $taxonomy = self::taxonomy();
        $categories = array();
        $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
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
                    'color' => get_term_meta( $term->term_id, '_cafeflo_color', true ) ?: null,
                    'icon' => get_term_meta( $term->term_id, '_cafeflo_icon', true ) ?: null,
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
            $post = get_post( (int) $row['wp_id'] );
            if ( ! $post || self::PRODUCT_POST_TYPE !== $post->post_type ) continue;

            $cat_id = null;
            $term_ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $term_ids ) ) {
                foreach ( $term_ids as $term_id ) {
                    $cat = CafeFlo_DB::get_mapping_by_wp_id( 'category', (int) $term_id );
                    if ( $cat ) {
                        $cat_id = (string) $cat['flocafe_id'];
                        break;
                    }
                }
            }

            $tags = get_post_meta( $post->ID, '_cafeflo_tags', true );
            $tags = $tags ? json_decode( $tags, true ) : array();
            $website_description = self::get_acf_product_field( 'description', $post->ID, $post->post_content );
            $website_price = self::get_acf_product_field( 'price', $post->ID, get_post_meta( $post->ID, '_cafeflo_price', true ) );
            $website_available = self::get_acf_product_field( 'available', $post->ID, 'publish' === $post->post_status );

            $products[] = array(
                'id' => (string) $row['flocafe_id'],
                'category_id' => $cat_id,
                'name' => $post->post_title,
                'description' => (string) $website_description,
                'price' => (float) self::price_for_flocafe( $website_price ),
                'sku' => get_post_meta( $post->ID, '_cafeflo_sku', true ) ?: null,
                'image_url' => self::get_acf_product_image_url( $post->ID ),
                'is_available' => (bool) $website_available,
                'sort_order' => (int) $post->menu_order,
                'sale_unit' => get_post_meta( $post->ID, '_cafeflo_sale_unit', true ) ?: null,
                'tags' => is_array( $tags ) ? $tags : array(),
            );
        }

        return array(
            'revision' => (int) get_option( 'cafeflo_last_flocafe_revision', 0 ),
            'source_instance_id' => CafeFlo_DB::current_source_instance_id(),
            'generated_at' => gmdate( 'c' ),
            'full_snapshot' => true,
            'currency' => (string) get_option( 'cafeflo_currency', 'IRR' ),
            'categories' => $categories,
            'products' => $products,
        );
    }
}
