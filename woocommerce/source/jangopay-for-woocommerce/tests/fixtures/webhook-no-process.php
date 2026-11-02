<?php
// Local synthetic vector worker; never included in the submission ZIP.
define('ABSPATH','/nonexistent-wordpress-install/');
define('JANGOPAY_WC_WEBHOOK_FRAMING','appended16');
$dir=$argv[1];
class JPWC_Controller {public static function keyPath(){global $dir;return $dir.'/priv.pem';}}
require dirname(__DIR__,2).'/includes/class-jpwc-webhook.php';
$v=json_decode(file_get_contents($dir.'/vector.json'),true);
if(function_exists('proc_open')||function_exists('exec')||function_exists('shell_exec'))throw new RuntimeException('Process functions must be disabled');
$data=JPWC_Webhook::decrypt($v['headers'],json_encode($v['envelope']));
if(($data['invoice_id']??'')!=='INV-123')throw new RuntimeException('No-process decryption failed');
echo "PASS runtime decrypts with process functions disabled\n";
