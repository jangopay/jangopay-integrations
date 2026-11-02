<?php
if (!defined('ABSPATH')) { exit; }
if (!interface_exists('JPWC_Guarded_Order_Contract', false)) {
    interface JPWC_Guarded_Order_Contract {}
}
/**
 * Coordinate JangoPay settlement with ordinary wc_get_order() / WC CRUD saves.
 *
 * Route ALL supported existing shop_order objects through guarded subclasses,
 * even before the payment ledger exists. Otherwise a long-lived order instance
 * loaded before invoice creation remains an unguarded native WooCommerce class
 * after another request creates the ledger (P1 pre-ledger stale object race).
 * Guarded-order save() uses the same short-lived order lock even before a ledger exists. This is required to serialize the first reservation with a save already in flight.
 *
 * IMPORTANT: WooCommerce catches Exceptions raised in its before-save hook and
 * STILL runs WC_Order::status_transition().  The admission check therefore MUST
 * execute outside WC_Order::save(), in JPWC_Guarded_Order::save().  Never
 * reinstall the former before/after_order_object_save admission hooks.
 *
 * Limit: direct new WC_Order($id), other plugins' custom order subclasses and
 * raw SQL/wp_update_post bypass the filtered order factory and are NOT covered.
 */
final class JPWC_Order_Guard {
    private static $depth = [];
    private static $saveLocks = [];
    private static $activeSaves = [];
    private static $fulfilling = [];
    private static $paymentObjects = [];
    // Item-object writes hold the same advisory lock through the actual Woo
    // datastore update/delete, not merely through the before-save hook.
    private static $itemLocks = [];
    private static $itemWrites = [];


    public static function boot() {
        add_filter('woocommerce_order_class', [__CLASS__, 'orderClass'], 9999, 3);
        add_action('shutdown', [__CLASS__, 'releaseAll'], 99999);
        add_action('woocommerce_before_order_item_object_save', ['JPWC_Items','beforeItemSave'], PHP_INT_MAX, 1);
        add_action('woocommerce_before_delete_order_item', ['JPWC_Items','beforeDelete'], PHP_INT_MAX, 1);
        add_action('woocommerce_after_order_item_object_save', ['JPWC_Items','afterItemSave'], PHP_INT_MAX, 1);
        add_action('woocommerce_delete_order_item', ['JPWC_Items','afterDelete'], PHP_INT_MAX, 1);
        // WooCommerce's wc_update_order_item_meta() bypasses WC_Order_Item::save.
        // Intercept only snapshot-critical keys at the WP metadata prefilter;
        // perform the real WP metadata operation *inside* the order lock.
        add_filter('update_order_item_metadata', ['JPWC_Items','updateMeta'], PHP_INT_MAX, 5);
        add_filter('add_order_item_metadata', ['JPWC_Items','addMeta'], PHP_INT_MAX, 5);
        add_filter('delete_order_item_metadata', ['JPWC_Items','deleteMeta'], PHP_INT_MAX, 5);
        // WordPress also exposes a metadata-by-ID API, used by data stores and
        // third-party integrations. It bypasses the ordinary metadata filters.
        add_filter('update_order_item_metadata_by_mid', ['JPWC_Items','updateMetaByMid'], PHP_INT_MAX, 4);
        add_filter('delete_order_item_metadata_by_mid', ['JPWC_Items','deleteMetaByMid'], PHP_INT_MAX, 2);
    }
    /**
     * Route the two first-party WooCommerce order classes from the first factory
     * read, not only after a JangoPay ledger appears. This closes the window in
     * which an object obtained before invoice creation can later bypass the
     * JangoPay save admission check. With no ledger, payment-specific
     * checks are skipped, but the order remains locked through native save.
     * Preserve Woo Admin's override; never rewrite refunds or other subclasses.
     * Unknown order subclasses remain blocked before creating an invoice.
     */
    public static function orderClass($class, $type, $id) {
        if ($type !== 'shop_order' || (int)$id <= 0)
            return $class;
        $normalized=ltrim((string)$class, '\\');
        if ($normalized==='WC_Order')
            return 'JPWC_Guarded_Order';
        if ($normalized==='Automattic\\WooCommerce\\Admin\\Overrides\\Order') {
            if (!class_exists('JPWC_Guarded_Admin_Order',false))
                require_once JPWC_DIR.'includes/class-jpwc-guarded-admin-order.php';
            if (class_exists('JPWC_Guarded_Admin_Order',false))
                return 'JPWC_Guarded_Admin_Order';
        }
        return $class;
    }
    /**
     * Fail closed before remote invoice creation if another order factory owns
     * the order class. We can guard first-party WooCommerce base/override only.
     */
    public static function supportsOrder($order) {
        if (!is_object($order))return false;
        if ($order instanceof JPWC_Guarded_Order_Contract)
            return true;
        if (!class_exists('WC_Order') || !($order instanceof WC_Order))
            return false;
        $class=ltrim(get_class($order), '\\');
        return $class==='WC_Order' || $class==='Automattic\\WooCommerce\\Admin\\Overrides\\Order';
    }
    public static function isGuarded($order) {
        return is_object($order) && $order instanceof JPWC_Guarded_Order_Contract;
    }
    public static function applies($id) {
        return (int)$id > 0 && (bool)JPWC_Ledger::get((int)$id);
    }
    private static function name($id) {
        global $wpdb;
        $scope=(string)$wpdb->dbname.'|'.(string)$wpdb->prefix.'|'.(function_exists('get_current_blog_id')?(string)get_current_blog_id():'1');
        return 'jpwc:'.substr(hash('sha256',$scope),0,24).':'.(int)$id;
    }
    private static function take($id) {
        global $wpdb;
        $id=(int)$id;
        if($id<=0)throw new RuntimeException('invalid_order_lock_id');
        if(isset(self::$depth[$id])){self::$depth[$id]++;return;}
        if(!is_object($wpdb)||!method_exists($wpdb,'prepare')||!method_exists($wpdb,'get_var'))
            throw new RuntimeException('order_lock_database_unavailable');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped advisory locks require direct uncached SQL; lock names are prepared values.
        $ok=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)',self::name($id),3));
        if((string)$ok!=='1')throw new RuntimeException('order_lock_not_acquired');
        self::$depth[$id]=1;
    }
    /**
     * One lock namespace for ALL existing standard shop-order saves, the first
     * ledger admission and final payment delivery. No provider/network I/O may
     * occur while this lock is held. Callers must not bypass this for reserve.
     */
    public static function synchronized($id, callable $callback) {
        self::take($id);
        try { return $callback(); }
        finally { self::drop($id); }
    }
    /**
     * A same-request WooCommerce hook may re-enter checkout while the order's
     * outer save still has staged, uncommitted data. MySQL GET_LOCK is
     * reentrant per connection: reject this case instead of treating it as a
     * second independent process blocked by the advisory lock.
     */
    public static function synchronizeReservation($id, callable $callback) {
        return self::synchronized($id, static function() use ($id,$callback) {
            if(!empty(self::$activeSaves[(int)$id]) || !empty(self::$itemWrites[(int)$id]))
                throw new RuntimeException('order_save_in_progress');
            return $callback();
        });
    }
    /** Acquire all item-owner order locks in stable order; return only after
     * the matching post-save/delete hook. A datastore exception has no matching
     * hook, so conservatively retain the locks to request shutdown.
     */
    public static function beginItemWrite($token, array $ids) {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
        sort($ids,SORT_NUMERIC);
        $held=[];
        try {
            foreach($ids as $id){self::take($id);$held[]=$id;}
            foreach($held as $id)self::$itemWrites[$id]=(self::$itemWrites[$id]??0)+1;
            self::$itemLocks[(string)$token][]=$held;
        }catch(Throwable $e){foreach(array_reverse($held) as $id)self::drop($id);throw $e;}
    }
    public static function endItemWrite($token) {
        $token=(string)$token;
        if(empty(self::$itemLocks[$token]))return;
        $ids=array_pop(self::$itemLocks[$token]);
        if(!self::$itemLocks[$token])unset(self::$itemLocks[$token]);
        foreach(array_reverse($ids) as $id){
            if(--self::$itemWrites[$id]===0)unset(self::$itemWrites[$id]);
            self::drop($id);
        }
    }
    /** A metadata prefilter can safely short-circuit the outer metadata API
     * and execute the original native operation while holding the order lock.
     * Unlike before/after hooks, this always has a finally on error/no-op.
     */
    public static function itemMetadataWrite($id, callable $operation) {
        $id=(int)$id;
        return self::synchronized($id,static function()use($id,$operation){
            self::$itemWrites[$id]=(self::$itemWrites[$id]??0)+1;
            try{return $operation();}
            finally{if(--self::$itemWrites[$id]===0)unset(self::$itemWrites[$id]);}
        });
    }
    public static function isFulfilling($id) {return !empty(self::$fulfilling[(int)$id]);}
    private static function drop($id) {
        global $wpdb;
        $id=(int)$id;
        if(!isset(self::$depth[$id]))return;
        if(--self::$depth[$id]>0)return;
        unset(self::$depth[$id]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped advisory locks require direct uncached SQL; lock names are prepared values.
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::name($id)));
    }
    public static function releaseAll() {
        foreach(array_keys(self::$depth) as $id)
            while(isset(self::$depth[$id]))self::drop($id);
        self::$saveLocks=[];
        self::$activeSaves=[];
        self::$itemLocks=[];
        self::$itemWrites=[];
        self::$fulfilling=[];
        self::$paymentObjects=[];
    }
    private static function fingerprint($data) {
        if(!is_array($data))throw new RuntimeException('order_snapshot_unavailable');
        foreach(['status','total','currency','payment_method'] as $field)
            if(!array_key_exists($field,$data))throw new RuntimeException('order_snapshot_incomplete');
        return [(string)$data['status'],JPWC_Money::usd((string)$data['total'],false),strtoupper((string)$data['currency']),(string)$data['payment_method']];
    }
    /**
     * Called BEFORE WC_Order::save, not inside its swallowed-exception block.
     * Check the unmodified baseline of a potentially stale WC_Order object.
     */
    public static function beforeSave($order) {
        if(!is_object($order)||!method_exists($order,'get_id'))return;
        $id=(int)$order->get_id();
        if($id<=0)return;
        // Do not check JPWC_Ledger::get() BEFORE GET_LOCK: a concurrent first
        // reservation could commit during WooCommerce's later datastore save.
        self::take($id);
        try {
            $row=JPWC_Ledger::get($id);
            if(!$row) {
                // An ordinary order (or a JangoPay order not yet reserved)
                // still needs to keep this lock through core status hooks so
                // reserve() can only see a committed order state.
                self::$saveLocks[spl_object_id($order)][]=$id;
                self::$activeSaves[$id]=(self::$activeSaves[$id]??0)+1;
                return;
            }
            if (property_exists($row,'items_hash')) {
                JPWC_Items::assertRow($row,$order);
            }
            // The verifier's payment_complete object may change order status.
            // Core HPOS download-permission writes use another order instance:
            // allow those only if they do NOT stage changes to the critical
            // status/amount/currency/gateway fingerprint. Object identity alone
            // is not a reason to block a benign internal metadata save.
            if($row->state==='delivery_claimed' && empty(self::$fulfilling[$id]))
                throw new RuntimeException('order_delivery_in_progress');
            if(!method_exists($order,'get_data'))throw new RuntimeException('order_baseline_unavailable');
            $baseline=self::fingerprint($order->get_data());
            if($row->state==='delivery_claimed' &&
               (self::$paymentObjects[$id]??null)!==spl_object_id($order) &&
               self::stagedFingerprint($order)!==$baseline)
                throw new RuntimeException('order_unapproved_change_during_delivery');
            $actual=JPWC_Controller::freshOrder($id);
            if(!$actual || self::fingerprint($actual->get_data())!==$baseline)
                throw new RuntimeException('order_concurrent_change_detected');
            $oid=spl_object_id($order);
            self::$saveLocks[$oid][]=$id;
            self::$activeSaves[$id]=(self::$activeSaves[$id]??0)+1;
        }catch(Throwable $e){self::drop($id);throw $e;}
    }
    public static function afterSave($order) {
        if(!is_object($order))return;
        $oid=spl_object_id($order);
        if(empty(self::$saveLocks[$oid]))return;
        $id=array_pop(self::$saveLocks[$oid]);
        if(!self::$saveLocks[$oid])unset(self::$saveLocks[$oid]);
        if(--self::$activeSaves[$id]===0)unset(self::$activeSaves[$id]);
        self::drop($id);
    }
    private static function stagedFingerprint($order) {
        return [
            (string)$order->get_status('edit'),
            JPWC_Money::usd((string)$order->get_total('edit'),false),
            strtoupper((string)$order->get_currency('edit')),
            (string)$order->get_payment_method('edit')
        ];
    }
    /**
     * A datastore exception can be swallowed by WC_Abstract_Order::save().
     * Before allowing WC_Order::status_transition, confirm that the newly
     * staged critical properties were really committed to the database.
     */
    public static function assertCommitted($order) {
        $id=(int)$order->get_id();
        if(!self::applies($id))return;
        $row=JPWC_Ledger::get($id);
        if ($row && property_exists($row,'items_hash')) JPWC_Items::assertRow($row,$order);
        $expected=self::stagedFingerprint($order);
        $actual=JPWC_Controller::freshOrder($id);
        if(!$actual || self::fingerprint($actual->get_data())!==$expected)
            throw new RuntimeException('order_save_not_committed');
    }
    /** Hold lock across fresh order read, payment_complete and all its hooks. */
    public static function fulfill($orderId,$callback) {
        $id=(int)$orderId;
        self::take($id);
        self::$fulfilling[$id]=(self::$fulfilling[$id]??0)+1;
        try {
            return $callback(static function($order)use($id){
                if(!is_object($order)||!method_exists($order,'get_id')||(int)$order->get_id()!==$id)
                    throw new RuntimeException('payment_object_mismatch');
                if(class_exists('WC_Order') && !self::isGuarded($order))
                    throw new RuntimeException('payment_order_not_guarded');
                self::$paymentObjects[$id]=spl_object_id($order);
            });
        } finally {
            unset(self::$paymentObjects[$id]);
            if(--self::$fulfilling[$id]===0)unset(self::$fulfilling[$id]);
            self::drop($id);
        }
    }
}
