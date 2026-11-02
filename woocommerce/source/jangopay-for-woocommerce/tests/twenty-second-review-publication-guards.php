<?php
/** Packaging and compatibility regression. The payment core remains unchanged. */
$root=dirname(__DIR__);
$passed=0;
function check($label,$ok) {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);$passed++;echo "PASS $label\n";}
$main=file_get_contents($root.'/jangopay-for-woocommerce.php');
$readme=file_get_contents($root.'/readme.txt');
$controller=file_get_contents($root.'/includes/class-jpwc-controller.php');
$money=file_get_contents($root.'/includes/class-jpwc-money.php');
$proof=file_get_contents($root.'/includes/class-jpwc-proof.php');
check('plugin header version matches runtime and stable tag',preg_match('/Version: ([0-9.]+)/',$main,$version)&&strpos($main,"define('JPWC_VERSION', '".$version[1]."')")!==false&&strpos($readme,'Stable tag: '.$version[1])!==false);
check('tested WordPress header based on native evidence',strpos($readme,'Tested up to: 7.1')!==false);
check('money direct file guard',strpos($money,"if (!defined('ABSPATH')) { exit; }")!==false);
check('proof direct file guard',strpos($proof,"if (!defined('ABSPATH')) {exit;}")!==false);
check('test-only direct access bypass removed',strpos($money,'JPWC_TESTING')===false&&strpos($proof,'JPWC_TESTING')===false);
check('list shape validated without WP compatibility analyzer confusion',strpos($proof,'array_values($pairs)!==$pairs')!==false);
check('hosted URL pinned',strpos($controller,"'https://app.jangopay.org/pay/'")!==false);
check('hosted redirect uses safe redirect',strpos($controller,'wp_safe_redirect($target, 303')!==false);
check('redirect host scope is cleaned',strpos($controller,"remove_filter('allowed_redirect_hosts', \$allowHosted)")!==false);
check('webhook runtime has no subprocess dependency',strpos(file_get_contents($root.'/includes/class-jpwc-webhook.php'),'proc_open(')===false);
echo "TOTAL $passed publication guard assertions passed (static source assertions, not native WordPress)\n";
