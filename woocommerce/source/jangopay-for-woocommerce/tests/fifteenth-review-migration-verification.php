<?php
/** V1.0.16 ledger-schema migration failure verification, mocked DDL. */
$root=sys_get_temp_dir().'/jpwc-migration-'.getmypid().'/';
@mkdir($root.'wp-admin/includes',0777,true);
file_put_contents($root.'wp-admin/includes/upgrade.php','<?php // deliberately empty; dbDelta is mocked in test');
define('ABSPATH',$root);
$GLOBALS['options']=['jpwc_schema_version'=>'1.0.0'];$GLOBALS['passed']=0;
function ckMig($label,$test){if(!$test)throw new RuntimeException('FAIL '.$label);echo 'PASS '.++$GLOBALS['passed'].' '.$label."\n";}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
function dbDelta($sql){++$GLOBALS['wpdb']->attempts;if($GLOBALS['wpdb']->ddlWorks)$GLOBALS['wpdb']->column=true;return [];}
class MigDb {
    public $prefix='wp_',$column=false,$ddlWorks=false,$attempts=0;
    function get_charset_collate(){return 'DEFAULT CHARACTER SET utf8mb4';}
    function prepare($sql,...$args){return [$sql,$args];}
    function get_var($args){return $this->column?'items_hash':null;}
}
$GLOBALS['wpdb']=new MigDb();
require dirname(__DIR__).'/includes/class-jpwc-ledger.php';
$exception=null;try{JPWC_Ledger::install();}catch(RuntimeException $e){$exception=$e->getMessage();}
ckMig('unapplied DDL is rejected',$exception==='payment_ledger_schema_incomplete');
ckMig('failed upgrade does not advance schema version',get_option('jpwc_schema_version')==='1.0.0');
$GLOBALS['wpdb']->ddlWorks=true;
ckMig('retry succeeds',JPWC_Ledger::install()===true);
ckMig('successful migration commits version',get_option('jpwc_schema_version')==='1.0.1');
ckMig('idempotent migration retry succeeds',JPWC_Ledger::install()===true);
// Version is set, but the physical column was absent because a prior buggy
// plugin revision prematurely advanced the option: first reservation must fail.
$GLOBALS['wpdb']->column=false;
$order=new class {function get_id(){return 5;}};
$error=null;try{JPWC_Ledger::reserve($order,300,['USDT:bsc']);}catch(RuntimeException $e){$error=$e->getMessage();}
ckMig('stale success option does not authorize new invoice',$error==='payment_ledger_schema_not_ready');
@unlink($root.'wp-admin/includes/upgrade.php');@rmdir($root.'wp-admin/includes');@rmdir($root.'wp-admin');@rmdir($root);
echo "NOTE: migration tests use simulated dbDelta failure, not live MySQL ALTER\n";
