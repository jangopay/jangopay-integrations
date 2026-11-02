<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/class-jpwc-items.php';
final class JPWC_Ledger {
    public static function table() {
        global $wpdb;
        $table=$wpdb->prefix.'jpwc_payments';
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('payment_ledger_table_invalid');
        return $table;
    }
    public static function install() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            merchant_order_id varchar(110) NOT NULL,
            invoice_id varchar(110) DEFAULT NULL,
            txn_id varchar(110) DEFAULT NULL,
            state varchar(32) NOT NULL DEFAULT 'creating_unknown',
            amount_cents bigint(20) unsigned NOT NULL,
            allowed_assets text NOT NULL,
            items_hash varchar(64) DEFAULT NULL,
            asset varchar(16) DEFAULT NULL,
            chain varchar(45) DEFAULT NULL,
            quote_amount varchar(70) DEFAULT NULL,
            quote_address varchar(512) DEFAULT NULL,
            expires_at varchar(70) DEFAULT NULL,
            last_checked_at datetime DEFAULT NULL,
            failure_code varchar(80) DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            UNIQUE KEY merchant_order_id (merchant_order_id),
            UNIQUE KEY invoice_id (invoice_id),
            UNIQUE KEY txn_id (txn_id),
            KEY state_checked (state,last_checked_at)
        ) $charset;");
        if(!self::hasSnapshotColumn())
            throw new RuntimeException('payment_ledger_schema_incomplete');
        update_option('jpwc_schema_version','1.0.1',false);
        return true;
    }
    /** Do not trust schema_version alone: v1.0.15 could mark a failed
     * migration successful. Verify the physical column before first invoice.
     */
    private static function hasSnapshotColumn() {
        global $wpdb;
        $table=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))
            throw new RuntimeException('payment_ledger_table_invalid');
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fresh payment ledger reads and atomic state transitions cannot use object-cache results; identifiers are validated and values prepared.
        return $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM `'.$table.'` LIKE %s','items_hash'))==='items_hash';
    }
    public static function schemaReady() {
        return function_exists('get_option') &&
            get_option('jpwc_schema_version')==='1.0.1' && self::hasSnapshotColumn();
    }
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fresh payment ledger reads and atomic state transitions cannot use object-cache results; identifiers are validated and values prepared.
    public static function get($order_id) { global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE order_id=%d',(int)$order_id)); }
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fresh payment ledger reads and atomic state transitions cannot use object-cache results; identifiers are validated and values prepared.
    public static function invoice($invoice) { global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE invoice_id=%s',$invoice)); }
    public static function reserve($order,$amount,$assets) {
        global $wpdb; $oid=(int)$order->get_id();
        if(function_exists('get_option') && !self::schemaReady())
            throw new RuntimeException('payment_ledger_schema_not_ready');
        // The first reservation must use the SAME advisory lock as every
        // guarded WC_Order::save(). A pre-ledger order save may already be
        // paused inside WooCommerce's own before-save hook; checking the order
        // only before taking this lock does not prevent a paid-order overwrite.
        return JPWC_Order_Guard::synchronizeReservation($oid, static function() use ($oid,$amount,$assets,$wpdb) {
            $prior=self::get($oid);
            if($prior)return $prior;
            // Freshly re-evaluate the order AFTER waiting for a save that may
            // have cancelled/repriced it. No external provider request here.
            $live=JPWC_Controller::freshOrder($oid);
            if(!$live || !JPWC_Controller::orderPayable($live,(object)['amount_cents'=>$amount]) ||
               !JPWC_Order_Guard::isGuarded($live))
                throw new RuntimeException('order_changed_before_reservation');
            $itemsHash=JPWC_Items::fingerprint($live);
            $merchant='wc-'.substr(hash('sha256',home_url('/')),0,16).'-'.$oid.'-'.substr(hash('sha256',$live->get_order_key()),0,16);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fresh payment ledger reads and atomic state transitions cannot use object-cache results; identifiers are validated and values prepared.
            $wpdb->insert(self::table(),['order_id'=>$oid,'merchant_order_id'=>$merchant,'amount_cents'=>$amount,'allowed_assets'=>wp_json_encode($assets),'items_hash'=>$itemsHash,'state'=>'reserved','updated_at'=>current_time('mysql',true)],['%d','%s','%d','%s','%s','%s','%s']);
            return self::get($oid); // UNIQUE(order_id) still guards concurrent claims.
        });
    }
    public static function cas($row,$allowed,$data) {
        global $wpdb;
        $allowed=(array)$allowed;
        if (!$allowed) { return false; }
        $columns=['invoice_id','txn_id','state','asset','chain','quote_amount','quote_address','expires_at','last_checked_at','failure_code','updated_at'];
        if(array_diff(array_keys($data),$columns))throw new RuntimeException('payment_ledger_column_invalid');
        $data['updated_at']=current_time('mysql',true);
        $keys=array_keys($data);$set=implode(',',array_map(static function($x){return '`'.$x.'`=%s';},$keys));
        $states=implode(',',array_fill(0,count($allowed),'%s'));
        $params=array_merge(array_map(static function($k)use($data){return $data[$k];},$keys),[(int)$row->id],$allowed);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fresh payment ledger reads and atomic state transitions cannot use object-cache results; identifiers are validated and values prepared.
        $sql=$wpdb->prepare('UPDATE '.self::table().' SET '.$set.' WHERE id=%d AND state IN ('.$states.')',$params);
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fresh payment ledger reads and atomic state transitions cannot use object-cache results; identifiers are validated and values prepared.
        return $wpdb->query($sql)===1;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh payment ledger reads and atomic state transitions cannot use object-cache results; identifiers are validated and values prepared.
    public static function touch($row) { global $wpdb;$wpdb->update(self::table(),['last_checked_at'=>current_time('mysql',true)],['id'=>(int)$row->id],['%s'],['%d']); }
}
