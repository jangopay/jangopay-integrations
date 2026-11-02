<?php
require __DIR__.'/fixtures/review-bootstrap.php';
$root=dirname(__DIR__);$main=file_get_contents($root.'/jangopay-for-woocommerce.php');$readme=file_get_contents($root.'/readme.txt');
verify('release version consistent',str_contains($main,'Version: 1.0.2')&&str_contains($main,"define('JPWC_VERSION', '1.0.2')")&&str_contains($readme,'Stable tag: 1.0.2'));
verify('public text domain matches slug',str_contains($main,'Text Domain: jangopay-for-woocommerce'));
verify('external service hosts disclosed',str_contains($readme,'https://api.jangopay.org')&&str_contains($readme,'https://app.jangopay.org'));
verify('API credential transmission disclosed',str_contains($readme,'API key is sent server-to-server'));
verify('no subprocess in merchant runtime',!str_contains(file_get_contents($root.'/includes/class-jpwc-webhook.php'),'proc_open(')&&!str_contains(file_get_contents($root.'/includes/class-jpwc-controller.php'),"function_exists('proc_open')"));
require $root.'/vendor/autoload.php';
verify('Composer package metadata autoloads with scoped dependencies',\Composer\InstalledVersions::getPrettyVersion('phpseclib/phpseclib')==='4.0.2');
$row=(object)['id'=>1];$rejected=false;try{JPWC_Ledger::cas($row,['reserved'],['state`=NULL --'=>'bad']);}catch(RuntimeException $e){$rejected=true;}verify('CAS rejects unknown SQL columns',$rejected);
$GLOBALS['wpdb']->prefix='wp_`;DROP TABLE x;';$rejected=false;try{JPWC_Ledger::table();}catch(RuntimeException $e){$rejected=true;}verify('ledger rejects invalid table identifiers',$rejected);
