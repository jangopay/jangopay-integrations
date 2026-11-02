<?php
/** A missing WooCommerce factory must fail closed for existing product items,
 * never silently skip delete protection. No fake global item reader is defined.
 */
define('ABSPATH','/fake/');
require dirname(__DIR__).'/includes/class-jpwc-items.php';
class FactoryMissing117 {
    function get_type(){return 'line_item';}
    function get_id(){return 8;}
}
$checks=0;
foreach ([static fn()=>JPWC_Items::beforeItemSave(new FactoryMissing117()), static fn()=>JPWC_Items::beforeDelete(8)] as $call) {
    $reason=null;
    try{$call();}catch(RuntimeException $e){$reason=$e->getMessage();}
    if($reason!=='order_item_factory_unavailable')throw new RuntimeException('FAIL factory absence must be explicit');
    echo 'PASS '.++$checks.' refuses missing factory without bypass' . "\n";
}
