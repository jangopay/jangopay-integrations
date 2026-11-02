<?php
// Real scoped v4 crypto, with isolated WooCommerce, ledger and merchant HTTP fixtures.
require __DIR__.'/fixtures/review-bootstrap.php';
require dirname(__DIR__).'/includes/class-jpwc-webhook.php';
require dirname(__DIR__).'/vendor/autoload.php';

verify('actual private phpseclib4 is loaded', \Composer\InstalledVersions::getPrettyVersion('phpseclib/phpseclib') === '4.0.2'
    && class_exists('JangoPayWooVendor\\phpseclib4\\Crypt\\PublicKeyLoader')
    && !class_exists('phpseclib4\\Crypt\\PublicKeyLoader')
    && !class_exists('JangoPayWooVendor\\phpseclib3\\Crypt\\PublicKeyLoader'));
$gateway = new JPWC_Gateway();
$order = $GLOBALS['orders'][7];
$created = $gateway->process_payment(7);
verify('unpaid hosted order starts on hold', $created['result'] === 'success' && $order->status === 'on-hold' && !$order->paid);
$row = JPWC_Ledger::get(7);
$GLOBALS['merchantId'] = $row->merchant_order_id;

$publicFile = tempnam(sys_get_temp_dir(), 'jpwc-v4-pub-');
register_shutdown_function(static function () use ($publicFile) { if (is_file($publicFile)) unlink($publicFile); });
$private = openssl_pkey_get_private(file_get_contents($GLOBALS['keyfile']));
file_put_contents($publicFile, openssl_pkey_get_details($private)['key']);
$aes = random_bytes(32);
$process = proc_open(['openssl', 'pkeyutl', '-encrypt', '-pubin', '-inkey', $publicFile,
    '-pkeyopt', 'rsa_padding_mode:oaep', '-pkeyopt', 'rsa_oaep_md:sha256', '-pkeyopt', 'rsa_mgf1_md:sha256'],
    [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
fwrite($pipes[0], $aes); fclose($pipes[0]);
$wrapped = stream_get_contents($pipes[1]); fclose($pipes[1]);
stream_get_contents($pipes[2]); fclose($pipes[2]);
if (proc_close($process) !== 0) throw new RuntimeException('Independent OAEP encryption failed');
$nonce = random_bytes(12);
$cipher = openssl_encrypt(json_encode(['invoice_id'=>$row->invoice_id,'txn_id'=>'T1']), 'aes-256-gcm', $aes, OPENSSL_RAW_DATA, $nonce, $tag);
$envelope = ['event_type'=>'payment.completed','algorithm'=>'AES-256-GCM','key_algorithm'=>'RSA-OAEP-SHA256',
    'encrypted_key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode($cipher.$tag)];
$headers = ['x-jangopay-event'=>'payment.completed','x-jangopay-encryption-alg'=>'AES-256-GCM','x-jangopay-key-encryption'=>'RSA-OAEP-SHA256'];
$bad = $envelope;
$bad['ciphertext'] = base64_encode($cipher.str_repeat('X',16));
$callsBefore = $GLOBALS['httpCalls'];
$rejected = false;
try {
    $event = JPWC_Webhook::decrypt($headers, json_encode($bad));
    JPWC_Controller::verifyRow(JPWC_Ledger::get(7), $event['txn_id']);
} catch (RuntimeException $e) { $rejected = true; }
verify('tampered encrypted notification is rejected', $rejected);
verify('rejected notification cannot pay or contact merchant verifier', $order->status === 'on-hold' && !$order->paid
    && $order->delivery === 0 && JPWC_Ledger::get(7)->state === 'awaiting_payment' && $GLOBALS['httpCalls'] === $callsBefore);
$event = JPWC_Webhook::decrypt($headers, json_encode($envelope));
verify('v4 decrypts independently encrypted order identity', $event['invoice_id'] === $row->invoice_id && $event['txn_id'] === 'T1');
verify('merchant-verified decrypted payment completes', JPWC_Controller::verifyRow(JPWC_Ledger::get(7), $event['txn_id']) === 'completed');
verify('paid order and ledger complete once', $order->paid && $order->status === 'processing' && $order->delivery === 1
    && JPWC_Ledger::get(7)->state === 'completed');
verify('duplicate decrypted payment cannot repeat completion', JPWC_Controller::verifyRow(JPWC_Ledger::get(7), $event['txn_id']) === 'completed' && $order->delivery === 1);
