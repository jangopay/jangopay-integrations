<?php
/* Missing WooCommerce smoke: plugin must not fatal on load. */
define('ABSPATH','/none/');
$GLOBALS['hooks']=[];
function add_action($name,$callback){$GLOBALS['hooks'][$name][]=$callback;}
function add_filter($name,$callback){$GLOBALS['hooks'][$name][]=$callback;}
function register_activation_hook($file,$func){$GLOBALS['activation']=$func;}
function register_deactivation_hook($file,$func){$GLOBALS['deactivation']=$func;}
function plugin_dir_path($file){return dirname($file).'/';}
function plugin_dir_url($file){return 'https://test.local/plugins/jangopay-for-woocommerce/';}
function plugin_basename($file){return 'jangopay-for-woocommerce/'.basename($file);}
require dirname(__DIR__).'/jangopay-for-woocommerce.php';
if(empty($GLOBALS['hooks']['plugins_loaded']))throw new RuntimeException('No deferred bootstrap');
foreach($GLOBALS['hooks']['plugins_loaded'] as $cb)$cb();
if(empty($GLOBALS['hooks']['admin_notices']))throw new RuntimeException('Missing Woo dependency notice');
echo "PASS: boot without WooCommerce did not fatal, admin dependency notice registered\n";
