<?php
/** V1.0.21 controlled WordPress metadata and SQL-collation contract tests.
 * This script is not a real MariaDB test. MariaDB/CPT/HPOS native checks are
 * independently required before production release.
 */
require __DIR__.'/twentieth-review-database-collation.php';

class DbControl121 extends DbUnicode120 {
    public function get_var($sql) {
        if (is_array($sql) && str_contains($sql[0], 'CONVERT(%s USING')) {
            $key=(string)($sql[1][0]??'');
            // Native review confirmed two aliases, 0x00 and 0x01, compare
            // equal to _qty in the live utf8mb4_unicode_520_ci collation.
            // Additional control cases here are model tests, NOT native facts.
            if (str_contains($key,"\x00") || str_contains($key,"\x01") ||
                str_contains($key,"\x1F") || str_contains($key,"\x7F")) {
                ++$this->collationComparisons;
                $this->lastSQL=$sql[0];$this->lastParams=$sql[1];
                return in_array(str_replace(["\x00","\x01","\x1F","\x7F"],'',$key),
                    JPWC_META_CANONICAL_121,true) ? '1' : '0';
            }
        }
        return parent::get_var($sql);
    }
}
const JPWC_META_CANONICAL_121=['_qty','_product_id','_line_total'];
$GLOBALS['wpdb']=new DbControl121();
$GLOBALS['wpdb']->missingColumn=false;
$GLOBALS['wpdb']->prefix='wp_';
$GLOBALS['wpdb']->dbname='db121';
$before=json_encode($GLOBALS['meta119']);
foreach (["\x00","\x01"] as $byte) {
    $key='_q'.$byte.'ty';
    check119('blocked itemmeta update with ASCII control 0x'.bin2hex($byte),
        blocked120(fn()=>update_metadata('order_item',77,$key,9)));
    check119('blocked itemmeta add with ASCII control 0x'.bin2hex($byte),
        blocked120(fn()=>add_metadata('order_item',77,$key,9)));
    check119('blocked itemmeta delete with ASCII control 0x'.bin2hex($byte),
        blocked120(fn()=>delete_metadata('order_item',77,$key)));
    check119('blocked ID0 bulk deletion with ASCII control 0x'.bin2hex($byte),
        blocked120(fn()=>delete_metadata('order_item',0,$key,'',true),'bulk_item_meta_delete_not_supported'));
}
check119('control-key failures leave existing item metadata untouched',
    json_encode($GLOBALS['meta119'])===$before);
check119('control-byte classification actually consults SQL collation',
    $GLOBALS['wpdb']->collationComparisons===2 && $GLOBALS['wpdb']->columnReads===1);
check119('SQL receives unmodified control-byte metadata key',
    $GLOBALS['wpdb']->lastParams[0]==="_q\x01ty" && count($GLOBALS['wpdb']->lastParams)===9);
$GLOBALS['metaById119'][91]=(object)['order_item_id'=>77,'meta_key'=>"_q\x00ty",'meta_value'=>1];
check119('by-mid stored control-byte key cannot be updated',
    blocked120(fn()=>update_metadata_by_mid('order_item',91,20)));
check119('by-mid stored control-byte key cannot be deleted',
    blocked120(fn()=>delete_metadata_by_mid('order_item',91)));
check119('by-mid rename to control-byte alias is blocked',
    blocked120(fn()=>update_metadata_by_mid('order_item',11,9,"_q\x01ty")));
check119('by-mid rejected attempts preserve original metadata',
    $GLOBALS['metaById119'][91]->meta_value===1 && $GLOBALS['metaById119'][11]->meta_key==='note');
$beforeSql=$GLOBALS['wpdb']->collationComparisons;
check119('stock bookkeeping key never requires SQL classification',
    update_metadata('order_item',77,'_reduced_stock',5) &&
    $GLOBALS['wpdb']->collationComparisons===$beforeSql);
check119('conventional ASCII notes remain inexpensive',
    update_metadata('order_item',77,'short_note','hi')===false &&
    $GLOBALS['wpdb']->collationComparisons===$beforeSql);
check119('benign control-byte note remains writable after SQL returns 0',
    add_metadata('order_item',77,"_note\x01safe",'safe')!==false);
check119('extra ASCII control-byte aliases also require database classification',
    blocked120(fn()=>update_metadata('order_item',77,"_q\x1Fty",9)) &&
    blocked120(fn()=>delete_metadata('order_item',0,"_q\x7Fty",'',true),'bulk_item_meta_delete_not_supported'));
$beforeFail=json_encode($GLOBALS['meta119']);
$GLOBALS['wpdb']->prefix='wpmissing_';$GLOBALS['wpdb']->missingColumn=true;
check119('SQL schema unavailable fails closed for ASCII control byte',
    blocked120(fn()=>update_metadata('order_item',77,"_q\x00ty",9),'order_item_metadata_collation_unavailable'));
check119('classification failure does not write item metadata',
    json_encode($GLOBALS['meta119'])===$beforeFail);
$GLOBALS['wpdb']->prefix='wp_';$GLOBALS['wpdb']->missingColumn=false;
check119('after blocked writes all order advisory locks released',
    $GLOBALS['wpdb']->held===0 && $GLOBALS['wpdb']->locks===$GLOBALS['wpdb']->unlocks);
echo "V1.0.21 ASCII control tests PASS\n";
