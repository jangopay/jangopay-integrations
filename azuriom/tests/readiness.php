<?php
// Checks the production collection readiness interlock without a network call.
require __DIR__.'/../src/Services/JangoPayApi.php';
use Azuriom\Plugin\JangoPay\Services\JangoPayApi;

function assertReady(bool $expected, string $label): void {
    if ((new JangoPayApi)->isCollectionReady() !== $expected) {
        throw new RuntimeException('FAIL: '.$label);
    }
    echo 'PASS: '.$label.PHP_EOL;
}
$old=[];
foreach (['JANGOPAY_ENABLE_PAYMENTS','JANGOPAY_FINAL_INVOICE_STATUSES','JANGOPAY_WEBHOOK_TAG_FORMAT','JANGOPAY_RSA_PRIVATE_KEY_PATH'] as $name) {
    $old[$name]=getenv($name);
    putenv($name);
}
assertReady(false,'Payment creation disabled by default');
putenv('JANGOPAY_ENABLE_PAYMENTS=1');
assertReady(false,'Payment disabled until invoice status is verified');
putenv('JANGOPAY_FINAL_INVOICE_STATUSES=confirmed');
assertReady(false,'Payment disabled until webhook framing is configured');
putenv('JANGOPAY_WEBHOOK_TAG_FORMAT=appended16');
assertReady(false,'Payment disabled without server-side RSA private key');
$key=tempnam(sys_get_temp_dir(),'jpaykey');
file_put_contents($key,"synthetic private-key placeholder\n");
try {
    putenv('JANGOPAY_RSA_PRIVATE_KEY_PATH='.$key);
    assertReady(false,'Unreadable or invalid PEM contents cannot unlock collection');
    $keypair=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    if ($keypair===false || !openssl_pkey_export($keypair,$pem)) throw new RuntimeException('Failed to create local RSA fixture');
    file_put_contents($key,$pem);
    assertReady(true,'Operator-enabled candidate opens with all config markers and a valid RSA key');
} finally {
    unlink($key);
    foreach ($old as $k=>$v) putenv($v===false?$k:($k.'='.$v));
}
