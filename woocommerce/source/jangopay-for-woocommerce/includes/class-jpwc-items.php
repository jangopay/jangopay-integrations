<?php
if (!defined('ABSPATH')) { exit; }
/**
 * A narrow, immutable payment-delivery snapshot. This is NOT a generic order
 * edit lock. It binds the invoice to the actual merchandise and entitlements
 * independently of the order's USD total.
 */
final class JPWC_Items {
    private static function decimal($value) {
        if (!is_numeric($value)) throw new RuntimeException('order_line_amount_invalid');
        return sprintf('%.8F', (float)$value);
    }
    public static function fingerprint($order) {
        if (!is_object($order) || !method_exists($order, 'get_items'))
            throw new RuntimeException('order_items_unavailable');
        $items = $order->get_items('line_item');
        if (!is_array($items)) throw new RuntimeException('order_items_invalid');
        $rows = [];
        foreach ($items as $item) {
            foreach (['get_id','get_product_id','get_variation_id','get_quantity','get_total','get_subtotal','get_name'] as $method)
                if (!is_object($item) || !method_exists($item,$method)) throw new RuntimeException('order_line_invalid');
            $rows[] = [
                'id'=>(int)$item->get_id(),
                'product'=>(int)$item->get_product_id(),
                'variation'=>(int)$item->get_variation_id(),
                'quantity'=>self::decimal($item->get_quantity()),
                'total'=>self::decimal($item->get_total()),
                'subtotal'=>self::decimal($item->get_subtotal()),
                'name'=>(string)$item->get_name(),
            ];
        }
        // IDs are stable across the life of a persisted WooCommerce order.
        usort($rows,static fn($a,$b)=>$a['id']<=>$b['id']);
        $data=wp_json_encode(['v'=>1,'items'=>$rows]);
        if (!is_string($data)) throw new RuntimeException('order_items_serialization_failed');
        return hash('sha256',$data);
    }
    /** Legacy invoices with no recorded item hash are not automatically payable. */
    public static function assertRow($row,$order) {
        if (!is_object($row) || !property_exists($row,'items_hash'))
            throw new RuntimeException('order_items_snapshot_unavailable');
        if (!is_string($row->items_hash) || !preg_match('/^[a-f0-9]{64}$/D',$row->items_hash))
            throw new RuntimeException('order_items_snapshot_missing');
        if (!hash_equals($row->items_hash,self::fingerprint($order)))
            throw new RuntimeException('order_items_changed');
    }
    /** WooCommerce order-item objects write *after* the before-save hook.
     * Keep the shared order lock until the matching after-save hook, including
     * no-ledger orders, so first reservation cannot overtake a pending write.
     */
    /** Use WooCommerce's real order-item factory. There is no public
     * wc_get_order_item() global function in supported WooCommerce versions.
     * Do not silently bypass deletion or invent a compatibility alias.
     */
    private static function storedLineItem($id) {
        $id=(int)$id;
        if($id<=0)return null;
        if(!class_exists('WC_Order_Factory') || !is_callable(['WC_Order_Factory','get_order_item']))
            throw new RuntimeException('order_item_factory_unavailable');
        $item=WC_Order_Factory::get_order_item($id);
        return is_object($item) ? $item : null;
    }
    public static function beforeItemSave($item) {
        if (!is_object($item) || !method_exists($item,'get_type') || $item->get_type()!=='line_item')return;
        $id=(int)$item->get_id();
        $source=self::storedLineItem($id);
        $sourceOrder=$source && method_exists($source,'get_order_id')?(int)$source->get_order_id():0;
        $target=method_exists($item,'get_order_id')?(int)$item->get_order_id():0;
        $token='save:'.spl_object_id($item);
        JPWC_Order_Guard::beginItemWrite($token,[$sourceOrder,$target]);
        try {
            // The initial factory read only selects which order locks to take.
            // It is not authoritative after waiting for a competing writer.
            // Re-read under the locks before testing invoice/item identity.
            $fresh=$id ? self::storedLineItem($id) : null;
            $freshOrder=$fresh && method_exists($fresh,'get_order_id')?(int)$fresh->get_order_id():0;
            if($freshOrder!==$sourceOrder || ($source && !$fresh) ||
               ($fresh && (!method_exists($fresh,'get_type') || $fresh->get_type()!=='line_item')))
                throw new RuntimeException('order_item_owner_changed_during_write');
            foreach(array_unique(array_filter([$sourceOrder,$target])) as $orderId) {
                $row=JPWC_Ledger::get($orderId);
                if(!$row)continue;
                if(!$fresh || $sourceOrder!==$target || !self::sameLine($fresh,$item))
                    throw new RuntimeException('invoiced_order_item_write_rejected');
            }
        }catch(Throwable $e){JPWC_Order_Guard::endItemWrite($token);throw $e;}
    }
    public static function afterItemSave($item) {
        if(is_object($item))JPWC_Order_Guard::endItemWrite('save:'.spl_object_id($item));
    }
    private static function sameLine($a,$b) {
        foreach(['get_id','get_product_id','get_variation_id','get_quantity','get_total','get_subtotal','get_name'] as $m)
            if(!method_exists($a,$m)||!method_exists($b,$m))return false;
        return (int)$a->get_id()===(int)$b->get_id() &&
            (int)$a->get_product_id()===(int)$b->get_product_id() &&
            (int)$a->get_variation_id()===(int)$b->get_variation_id() &&
            self::decimal($a->get_quantity())===self::decimal($b->get_quantity()) &&
            self::decimal($a->get_total())===self::decimal($b->get_total()) &&
            self::decimal($a->get_subtotal())===self::decimal($b->get_subtotal()) &&
            (string)$a->get_name()===(string)$b->get_name();
    }
    public static function beforeDelete($itemId) {
        $item=self::storedLineItem((int)$itemId);
        if(!$item || !method_exists($item,'get_type') || $item->get_type()!=='line_item')return;
        $oid=(int)$item->get_order_id();
        $token='delete:'.(int)$itemId;
        JPWC_Order_Guard::beginItemWrite($token,[$oid]);
        try {
            $fresh=self::storedLineItem((int)$itemId);
            if(!$fresh || !method_exists($fresh,'get_order_id') ||
               (int)$fresh->get_order_id()!==$oid ||
               !method_exists($fresh,'get_type') || $fresh->get_type()!=='line_item')
                throw new RuntimeException('order_item_owner_changed_during_delete');
            if(JPWC_Ledger::get($oid))throw new RuntimeException('invoiced_order_item_delete_rejected');
        }catch(Throwable $e){JPWC_Order_Guard::endItemWrite($token);throw $e;}
    }
    public static function afterDelete($itemId) {JPWC_Order_Guard::endItemWrite('delete:'.(int)$itemId);}

    /** Product-critical native itemmeta keys used by WC_Order_Item_Product.
     * Stock bookkeeping and download permission flags must remain writable.
     */
    private const CRITICAL_META = [
        '_product_id','_variation_id','_qty','_line_subtotal','_line_total',
        '_line_subtotal_tax','_line_tax','_line_total_tax'
    ];
    /**
     * Metadata comparisons are performed with the actual SQL column collation.
     * With accent-insensitive collations, e.g. utf8mb4_unicode_520_ci,
     * _qťy and _qty compare equal in native WordPress metadata queries.
     * Do not try to imitate the database with PHP transliteration tables.
     *
     * The ASCII fast path preserves normal stock/meta performance. Unusual
     * Unicode keys use the metadata column's exact collation; if it cannot be
     * determined, fail closed rather than permit an unverifiable mutation.
     */
    private static $itemMetaCollation = [];
    private static $unicodeCriticalCache = [];
    private static function criticalKey($key) {
        if (!is_string($key)) return false;
        if (in_array(strtolower(rtrim($key, ' ')), self::CRITICAL_META, true)) return true;
        // Only conventional ASCII identifier keys are eligible for a fast
        // negative result. ASCII also includes NUL, SOH and other control
        // bytes: utf8mb4_unicode_520_ci may treat those as ignorable and match
        // "_q\x00ty" or "_q\x01ty" to the protected "_qty" column value.
        // Route controls, DEL, punctuation and Unicode to the actual column
        // collation instead of approximating MySQL comparison rules in PHP.
        if (preg_match('/^[A-Za-z0-9_]+$/D', $key)) return false;
        return self::criticalKeyAccordingToDatabase($key);
    }
    private static function criticalKeyAccordingToDatabase($key) {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix) ||
            !method_exists($wpdb, 'get_row') || !method_exists($wpdb, 'get_var') ||
            !method_exists($wpdb, 'prepare'))
            throw new RuntimeException('order_item_metadata_collation_unavailable');
        $table=(string)$wpdb->prefix.'woocommerce_order_itemmeta';
        $schemaScope=(string)($wpdb->dbname??'').'|'.$table;
        // The prefix belongs to WordPress, but it is still an SQL identifier.
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table))
            throw new RuntimeException('order_item_metadata_table_invalid');
        if (!isset(self::$itemMetaCollation[$schemaScope])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema/key results are request-cached; table/charset/collation tokens are strictly validated and key values are prepared.
            $column=$wpdb->get_row("SHOW FULL COLUMNS FROM `{$table}` WHERE Field = 'meta_key'");
            $collation=is_object($column) && isset($column->Collation) ? (string)$column->Collation : '';
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $collation))
                throw new RuntimeException('order_item_metadata_collation_unavailable');
            $charset=explode('_',$collation,2)[0];
            // A charset is an SQL token, not a bound value. Only accept names
            // that are part of the supported MySQL/MariaDB charset family.
            if (!in_array($charset,['utf8mb4','utf8mb3','utf8','latin1','ascii','ucs2','utf16','utf16le','utf32','gbk','big5'],true))
                throw new RuntimeException('order_item_metadata_charset_unsupported');
            self::$itemMetaCollation[$schemaScope]=[$charset,$collation];
        }
        [$charset,$collation]=self::$itemMetaCollation[$schemaScope];
        $cacheId=$schemaScope.'|'.$collation.'|'.$key;
        if (array_key_exists($cacheId,self::$unicodeCriticalCache))
            return self::$unicodeCriticalCache[$cacheId];
        $params=array_merge([$key],self::CRITICAL_META);
        $placeholders=implode(',',array_fill(0,count(self::CRITICAL_META),'%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema/key results are request-cached; table/charset/collation tokens are strictly validated and key values are prepared.
        $query=$wpdb->prepare("SELECT (CONVERT(%s USING {$charset}) COLLATE {$collation} IN ({$placeholders}))", ...$params);
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Schema/key results are request-cached; table/charset/collation tokens are strictly validated and key values are prepared.
        $result=$wpdb->get_var($query);
        if ($result===null || $result===false || !in_array((string)$result,['0','1'],true))
            throw new RuntimeException('order_item_metadata_collation_comparison_failed');
        self::$unicodeCriticalCache[$cacheId]=((string)$result==='1');
        return self::$unicodeCriticalCache[$cacheId];
    }
    private static $nativeMetaBypass = [];
    private static function metadata($previous,$operation,$itemId,$key,$value=null,$extra=null) {
        // delete_all ignores object_id, including the valid 0 sentinel. Check
        // before resolving any item owner or exiting for an unknown item ID.
        // Block globally: there is no safe per-order lock for this bulk API.
        if ($operation==='delete' && (bool)$extra && self::criticalKey($key))
            throw new RuntimeException('bulk_item_meta_delete_not_supported');
        $bypass=$operation.':'.(int)$itemId.':'.(string)$key;
        if(!empty(self::$nativeMetaBypass[$bypass])) {
            // Consume only the one intentional inner API invocation. Another
            // critical metadata write triggered by a WP hook must be guarded.
            --self::$nativeMetaBypass[$bypass];
            if(!self::$nativeMetaBypass[$bypass])unset(self::$nativeMetaBypass[$bypass]);
            return $previous;
        }
        if($previous!==null || !self::criticalKey($key))return $previous;
        if(!function_exists('wc_get_order_id_by_order_item_id'))return $previous;
        $orderId=(int)wc_get_order_id_by_order_item_id((int)$itemId);
        if($orderId<=0)return $previous;
        return JPWC_Order_Guard::itemMetadataWrite($orderId,static function()use($operation,$itemId,$key,$value,$extra,$orderId,$bypass){
            // The source owner can move while GET_LOCK waits. Never apply a
            // write after locking a different order than its current owner.
            if((int)wc_get_order_id_by_order_item_id((int)$itemId)!==$orderId)
                throw new RuntimeException('order_item_metadata_owner_changed_during_write');
            if(JPWC_Ledger::get($orderId))
                throw new RuntimeException('invoiced_order_item_metadata_write_rejected');
            self::$nativeMetaBypass[$bypass]=(self::$nativeMetaBypass[$bypass]??0)+1;
            try {
                if($operation==='update')return update_metadata('order_item',(int)$itemId,$key,$value,$extra);
                if($operation==='add')return add_metadata('order_item',(int)$itemId,$key,$value,(bool)$extra);
                return delete_metadata('order_item',(int)$itemId,$key,$value,(bool)$extra);
            }finally{unset(self::$nativeMetaBypass[$bypass]);}
        });
    }
    public static function updateMeta($check,$id,$key,$value,$prev='') {
        return self::metadata($check,'update',$id,$key,$value,$prev);
    }
    public static function addMeta($check,$id,$key,$value,$unique=false) {
        return self::metadata($check,'add',$id,$key,$value,$unique);
    }
    public static function deleteMeta($check,$id,$key,$value='',$all=false) {
        return self::metadata($check,'delete',$id,$key,$value,$all);
    }
    /** WordPress metadata-by-ID functions have their OWN short-circuit filters.
     * They never call update_order_item_metadata/delete_order_item_metadata,
     * so the key-based guard above cannot protect these writes.
     *
     * Read the metadata's actual order_item_id, acquire that ORDER lock, then
     * read it again. Never assume a meta_id equals an order item ID.
     */
    private static function metadataByMid($previous,$operation,$metaId,$value=null,$replacementKey=false) {
        if($previous!==null)return $previous;
        $metaId=(int)$metaId;
        if($metaId<=0)return $previous;
        $bypass='mid:'.$operation.':'.$metaId;
        if(!empty(self::$nativeMetaBypass[$bypass])) {
            --self::$nativeMetaBypass[$bypass];
            if(!self::$nativeMetaBypass[$bypass])unset(self::$nativeMetaBypass[$bypass]);
            return $previous;
        }
        if(!function_exists('get_metadata_by_mid') || !function_exists('wc_get_order_id_by_order_item_id'))
            throw new RuntimeException('order_item_metadata_factory_unavailable');
        $meta=get_metadata_by_mid('order_item',$metaId);
        if(!is_object($meta))return $previous;
        $itemId=isset($meta->order_item_id)?(int)$meta->order_item_id:0;
        $originalKey=isset($meta->meta_key)?(string)$meta->meta_key:'';
        $nextKey=$operation==='update' && is_string($replacementKey) ? $replacementKey : $originalKey;
        if(!self::criticalKey($originalKey) &&
           !self::criticalKey($nextKey))return $previous;
        if($itemId<=0)throw new RuntimeException('order_item_metadata_owner_missing');
        $orderId=(int)wc_get_order_id_by_order_item_id($itemId);
        if($orderId<=0)throw new RuntimeException('order_item_metadata_owner_missing');
        return JPWC_Order_Guard::itemMetadataWrite($orderId,static function() use
            ($operation,$metaId,$value,$replacementKey,$itemId,$originalKey,$orderId,$bypass) {
            $fresh=get_metadata_by_mid('order_item',$metaId);
            if(!is_object($fresh) || (int)($fresh->order_item_id??0)!==$itemId ||
               (string)($fresh->meta_key??'')!==$originalKey ||
               (int)wc_get_order_id_by_order_item_id($itemId)!==$orderId)
                throw new RuntimeException('order_item_metadata_changed_during_write');
            if(JPWC_Ledger::get($orderId))
                throw new RuntimeException('invoiced_order_item_metadata_write_rejected');
            self::$nativeMetaBypass[$bypass]=(self::$nativeMetaBypass[$bypass]??0)+1;
            try {
                if($operation==='update')return update_metadata_by_mid('order_item',$metaId,$value,$replacementKey);
                return delete_metadata_by_mid('order_item',$metaId);
            } finally {unset(self::$nativeMetaBypass[$bypass]);}
        });
    }
    public static function updateMetaByMid($check,$metaId,$value,$metaKey=false) {
        return self::metadataByMid($check,'update',$metaId,$value,$metaKey);
    }
    public static function deleteMetaByMid($check,$metaId) {
        return self::metadataByMid($check,'delete',$metaId);
    }

}
