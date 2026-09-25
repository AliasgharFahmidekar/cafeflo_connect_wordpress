<?php

defined( 'ABSPATH' ) || exit;

final class CafeFlo_DB {
    const DB_VERSION = '4';

    public static function table( $name ) {
        global $wpdb;
        $tables = array(
            'mappings' => $wpdb->prefix . 'cafeflo_mappings',
            'catalog_changes' => $wpdb->prefix . 'cafeflo_catalog_changes',
            'order_claims' => $wpdb->prefix . 'cafeflo_order_claims',
        );
        return isset( $tables[ $name ] ) ? $tables[ $name ] : '';
    }

    public static function maybe_upgrade() {
        $current = (string) get_option( 'cafeflo_db_version', '0' );
        if ( version_compare( $current, self::DB_VERSION, '<' ) ) {
            self::activate();
        }
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $mappings = self::table( 'mappings' ); $changes = self::table( 'catalog_changes' ); $claims = self::table( 'order_claims' );
        dbDelta( "CREATE TABLE {$mappings} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_type varchar(32) NOT NULL,
            flocafe_id varchar(191) NOT NULL,
            wp_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id), UNIQUE KEY entity_map (entity_type, flocafe_id),
            UNIQUE KEY wp_entity_unique (entity_type, wp_id), KEY wp_entity (entity_type, wp_id)
        ) {$charset};" );
        dbDelta( "CREATE TABLE {$changes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            revision bigint(20) unsigned NOT NULL,
            entity_type varchar(32) NOT NULL,
            entity_id varchar(191) NOT NULL,
            action varchar(32) NOT NULL,
            changed_at datetime NOT NULL,
            PRIMARY KEY  (id), UNIQUE KEY revision_key (revision), KEY entity_key (entity_type, entity_id), KEY revision_idx (revision)
        ) {$charset};" );
        dbDelta( "CREATE TABLE {$claims} (
            order_id bigint(20) unsigned NOT NULL,
            claim_id char(36) NOT NULL,
            bridge_id varchar(191) NOT NULL DEFAULT '',
            claimed_at datetime NOT NULL,
            PRIMARY KEY (order_id), UNIQUE KEY claim_key (claim_id), KEY claimed_at_idx (claimed_at)
        ) {$charset};" );

        if ( false === get_option( 'cafeflo_catalog_revision', false ) ) add_option( 'cafeflo_catalog_revision', 0, '', false );
        if ( false === get_option( 'cafeflo_last_flocafe_revision', false ) ) add_option( 'cafeflo_last_flocafe_revision', 0, '', false );
        if ( false === get_option( 'cafeflo_catalog_synced', false ) ) add_option( 'cafeflo_catalog_synced', '0', '', false );
        if ( false === get_option( 'cafeflo_bridge_last_heartbeat', false ) ) add_option( 'cafeflo_bridge_last_heartbeat', 0, '', false );
        if ( false === get_option( 'cafeflo_flocafe_store_state_received_at', false ) ) add_option( 'cafeflo_flocafe_store_state_received_at', 0, '', false );
        if ( false === get_option( 'cafeflo_flocafe_store_state', false ) ) add_option( 'cafeflo_flocafe_store_state', array(), '', false );
        if ( false === get_option( 'cafeflo_online_ordering_enabled', false ) ) add_option( 'cafeflo_online_ordering_enabled', '1', '', false );
        if ( false === get_option( 'cafeflo_online_ordering_open', false ) ) add_option( 'cafeflo_online_ordering_open', '1', '', false );
        if ( false === get_option( 'cafeflo_bridge_api_key', false ) ) add_option( 'cafeflo_bridge_api_key', wp_generate_password( 64, true, true ), '', false );
        if ( false === get_option( 'cafeflo_site_id', false ) ) add_option( 'cafeflo_site_id', wp_generate_uuid4(), '', false );
        if ( '4' === self::DB_VERSION ) {
            $columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::table( 'order_claims' ), ARRAY_A );
            $has_bridge_id = false;
            foreach ( $columns as $column ) if ( 'bridge_id' === $column['Field'] ) $has_bridge_id = true;
            if ( ! $has_bridge_id ) $wpdb->query( 'ALTER TABLE ' . self::table( 'order_claims' ) . " ADD COLUMN bridge_id varchar(191) NOT NULL DEFAULT '' AFTER claim_id" );
            $wpdb->query( 'ALTER TABLE ' . self::table( 'order_claims' ) . ' ADD KEY bridge_id_idx (bridge_id)' );
        }
        update_option( 'cafeflo_db_version', self::DB_VERSION, false );
        flush_rewrite_rules( false );
    }

    public static function get_mapping( $entity_type, $flocafe_id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare("SELECT * FROM ".self::table('mappings')." WHERE entity_type=%s AND flocafe_id=%s LIMIT 1", $entity_type, (string)$flocafe_id), ARRAY_A ); }
    public static function get_mapping_by_wp_id( $entity_type, $wp_id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare("SELECT * FROM ".self::table('mappings')." WHERE entity_type=%s AND wp_id=%d LIMIT 1", $entity_type, (int)$wp_id), ARRAY_A ); }
    public static function upsert_mapping( $entity_type, $flocafe_id, $wp_id ) {
        global $wpdb; $table=self::table('mappings'); $now=current_time('mysql',true); $existing=self::get_mapping($entity_type,$flocafe_id);
        $conflict=self::get_mapping_by_wp_id($entity_type,$wp_id);
        if($conflict && (!$existing || (int)$conflict['id']!==(int)$existing['id'])) return new WP_Error('cafeflo_mapping_conflict','The WordPress object is already mapped to another FloCafe ID.',array('status'=>409));
        if($existing){$wpdb->update($table,array('wp_id'=>(int)$wp_id,'updated_at'=>$now),array('id'=>(int)$existing['id']),array('%d','%s'),array('%d'));return (int)$existing['id'];}
        $ok=$wpdb->insert($table,array('entity_type'=>$entity_type,'flocafe_id'=>(string)$flocafe_id,'wp_id'=>(int)$wp_id,'created_at'=>$now,'updated_at'=>$now),array('%s','%s','%d','%s','%s')); return $ok ? (int)$wpdb->insert_id : new WP_Error('cafeflo_mapping_insert_failed','Could not store mapping.',array('status'=>500));
    }
    public static function next_catalog_revision() {
        global $wpdb;
        $option = 'cafeflo_wp_catalog_revision';
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
            $option
        ) );
        if ( 1 !== (int) $updated ) {
            add_option( $option, 1, '', false );
            return (int) get_option( $option, 1 );
        }
        return (int) get_option( $option, 0 );
    }
    public static function record_catalog_change($entity_type,$entity_id,$action){global $wpdb;$rev=self::next_catalog_revision();$wpdb->insert(self::table('catalog_changes'),array('revision'=>$rev,'entity_type'=>$entity_type,'entity_id'=>(string)$entity_id,'action'=>$action,'changed_at'=>current_time('mysql',true)),array('%d','%s','%s','%s','%s'));return $rev;}
    public static function get_changes_after($revision,$limit=500){global $wpdb;$limit=max(1,min(500,(int)$limit));return $wpdb->get_results($wpdb->prepare("SELECT revision,entity_type,entity_id,action,changed_at FROM ".self::table('catalog_changes')." WHERE revision>%d ORDER BY revision ASC LIMIT %d",(int)$revision,$limit),ARRAY_A);}
}
