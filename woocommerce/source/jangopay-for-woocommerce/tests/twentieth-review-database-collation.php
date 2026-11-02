<?php
/** Database collation contract tests for the V1.0.20 itemmeta guard.
 * Runs isolated WordPress metadata filter semantics against actual JPWC_Items.
 * The database driver is a controlled model, not a MariaDB connection.
 */
require __DIR__.'/nineteenth-review-metadata-collation-bulk.php';

class DbUnicode120 extends Db119 {
    public $columnReads=0;
    public $collationComparisons=0;
    public $lastSQL='';
    public $lastParams=[];
    public $missingColumn=false;
    public $dbCollation='utf8mb4_unicode_520_ci';
    public function get_row($sql) {
        ++$this->columnReads;
        if($this->missingColumn)return null;
        if(!is_string($sql) || !str_contains($sql,'SHOW FULL COLUMNS FROM `wp_woocommerce_order_itemmeta` WHERE Field = \'meta_key\''))
            throw new RuntimeException('wrong_collation_discovery_sql');
        return (object)['Collation'=>$this->dbCollation];
    }
    public function get_var($sql) {
        if(is_array($sql) && str_contains($sql[0],'CONVERT(%s USING')) {
            ++$this->collationComparisons;
            $this->lastSQL=$sql[0];
            $this->lastParams=$sql[1];
            if(!str_contains($this->lastSQL,'CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_520_ci'))
                throw new RuntimeException('wrong_native_collation_sql');
            $target=(string)$sql[1][0];
            $aliases=['_qťy','_próduct_id','_line_tótal'];
            return in_array($target,$aliases,true)?'1':'0';
        }
        return parent::get_var($sql);
    }
}
$GLOBALS['wpdb']=new DbUnicode120();
function blocked120(callable $fn,string $reason='invoiced_order_item_metadata_write_rejected') {
    try {$fn();return false;}catch(RuntimeException $e){return $e->getMessage()===$reason;}
}
$before=json_encode($GLOBALS['meta119']);
foreach(['_qťy','_próduct_id','_line_tótal'] as $key) {
    check119('accented collation equivalent update blocked '.$key,blocked120(fn()=>update_metadata('order_item',77,$key,9)));
    check119('accented collation equivalent addition blocked '.$key,blocked120(fn()=>add_metadata('order_item',77,$key,9)));
    check119('accented collation equivalent deletion blocked '.$key,blocked120(fn()=>delete_metadata('order_item',77,$key)));
    check119('accented collation equivalent bulk ID0 deletion blocked '.$key,blocked120(fn()=>delete_metadata('order_item',0,$key,'',true),'bulk_item_meta_delete_not_supported'));
}
check119('accented checks cannot corrupt original protected values',json_encode($GLOBALS['meta119'])===$before);
check119('database collation is introspected exactly once',$GLOBALS['wpdb']->columnReads===1);
check119('database decides aliases, rather than PHP transliteration',$GLOBALS['wpdb']->collationComparisons===3);
check119('SQL binds actual protected keys, not an approximated whitelist',count($GLOBALS['wpdb']->lastParams)===9 && $GLOBALS['wpdb']->lastParams[0]==='_line_tótal' && in_array('_line_total',$GLOBALS['wpdb']->lastParams,true));

$GLOBALS['metaById119'][12]=(object)['order_item_id'=>77,'meta_key'=>'_qťy','meta_value'=>1];
check119('accented actual by-mid key update blocked',blocked120(fn()=>update_metadata_by_mid('order_item',12,9)));
check119('accented actual by-mid key delete blocked',blocked120(fn()=>delete_metadata_by_mid('order_item',12)));
check119('unicode alias as proposed by-mid name blocked',blocked120(fn()=>update_metadata_by_mid('order_item',11,9,'_qťy')));

$GLOBALS['meta119'][77]['_comentário']='old';
check119('unrelated unicode merchant note is writable',update_metadata('order_item',77,'_comentário','updated')===true && $GLOBALS['meta119'][77]['_comentário']==='updated');
check119('noncritical unicode is compared by database',$GLOBALS['wpdb']->collationComparisons===4);
check119('no leaked advisory locks from blocked unicode writes',$GLOBALS['wpdb']->held===0 && $GLOBALS['wpdb']->locks===$GLOBALS['wpdb']->unlocks);

// Changing the prefix simulates a separate WP database/table with metadata
// schema introspection failure. Unknown collation must never fail open.
$GLOBALS['wpdb']->prefix='wp2_';
$GLOBALS['wpdb']->missingColumn=true;
check119('unknown schema refuses unicode metadata rather than bypassing',blocked120(fn()=>update_metadata('order_item',77,'_qťy',9),'order_item_metadata_collation_unavailable'));
$GLOBALS['wpdb']->prefix='wp_';
check119('critical conventional stock key remains classified without a schema query',blocked120(fn()=>update_metadata('order_item',77,'_QTY',9)));
echo "V1.0.20 collation tests PASS\n";
