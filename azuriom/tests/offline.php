<?php
// Executable without Azuriom/Laravel: testing pure amount, asset and encrypt/decrypt primitives only.
namespace Illuminate\Http {
    class Request {
        public function __construct(private array $headers, private array $body) {}
        public function header(string $name): ?string { return $this->headers[$name] ?? null; }
        public function getContent(): string { return json_encode($this->body, JSON_THROW_ON_ERROR); }
        public function json(): object { return new class($this->body) {
            public function __construct(private array $body) {}
            public function all(): array { return $this->body; }
        }; }
    }
}
namespace {
    require __DIR__.'/../src/Support/Money.php';
    require __DIR__.'/../src/Support/Assets.php';
    require __DIR__.'/../src/Support/CheckoutFingerprint.php';
    require __DIR__.'/../src/Services/WebhookEnvelope.php';

    use Azuriom\Plugin\JangoPay\Support\Money;
    use Azuriom\Plugin\JangoPay\Support\Assets;
    use Azuriom\Plugin\JangoPay\Support\CheckoutFingerprint;
    use Azuriom\Plugin\JangoPay\Services\WebhookEnvelope;
    use Illuminate\Http\Request;

    $count = 0;
    function pass(bool $ok, string $message): void {
        global $count;
        $count++;
        if (!$ok) { throw new \RuntimeException('FAIL: '.$message); }
        echo 'PASS: '.$message.PHP_EOL;
    }
    function rejects(callable $cb): bool {
        try { $cb(); return false; } catch (\InvalidArgumentException|\RuntimeException $e) { return true; }
    }

    pass(Money::fromShop(12.34) === '12.34', 'Shop float normalized once');
    pass(Money::usdCents('12.34') === 1234, 'USD amount to integer cents');
    pass(Money::exactUsd('12.30', '12.3'), 'Equivalent decimal formats');
    pass(Money::exactUsd('3.00', '3.00000000'), 'Fixed-scale API amount with zero padding accepted');
    pass(Money::exactUsd('12.34', '12.34000000'), 'Fixed-scale cents remain exact');
    pass(Money::usdLedgerUnits('0.00500000') === 500000, 'Fractional-cent ledger fee is exact');
    pass(Money::usdLedgerUnits('0') === 0, 'Zero fee accepted in ledger arithmetic');
    pass(Money::usdLedgerUnits('2.99700000') + Money::usdLedgerUnits('0.00300000') === Money::usdLedgerUnits('3.00'), 'Net and fee sum without floating point');
    pass(rejects(fn () => Money::usdLedgerUnits('-0.01')), 'Negative ledger fee rejected');
    pass(rejects(fn () => Money::usdLedgerUnits('0.000000001')), 'Nonzero precision beyond ledger scale rejected');
    pass(rejects(fn () => Money::usdLedgerUnits('99999999999.99')), 'Ledger integer overflow rejected');
    pass(rejects(fn () => Money::usdCents('12.34000001')), 'Nonzero sub-cent precision rejected');
    pass(rejects(fn () => Money::usdCents('12.34500000')), 'Sub-cent value is not rounded');
    pass(!Money::exactUsd('12.30', '12.31'), 'Amount mismatch rejected');
    pass(rejects(fn () => Money::usdCents('12.301')), 'Excess USD precision rejected');
    pass(rejects(fn () => Money::usdCents('-1.00')), 'Negative USD rejected');
    pass(rejects(fn () => Money::usdCents('0')), 'Zero USD rejected');
    pass(Assets::parse('USDT:tron,USDC:ethereum,USDT:tron') === ['USDT:tron','USDC:ethereum'], 'Asset allowlist deduplicated');
    pass(rejects(fn () => Assets::parse('BTC:tron')), 'Unsupported coin rejected');
    pass(rejects(fn () => Assets::parse('USDT:tron,http://example.com')), 'Malformed chain rejected');

    $baseCart = ['items' => [['id'=>2, 'itemId'=>'Package-2', 'variables'=>['server'=>'alpha'], 'quantity'=>1]], 'coupons'=>[], 'giftcards'=>[]];
    $alternativeCart = $baseCart;
    $alternativeCart['items'][0]['variables']['server'] = 'beta';
    pass(CheckoutFingerprint::make($baseCart, '10.00', 'USD') !== CheckoutFingerprint::make($alternativeCart, '10.00', 'USD'), 'Different item variables create distinct checkout fingerprints');
    $couponCart = $baseCart;
    $couponCart['coupons'][] = 'testcoupon';
    pass(CheckoutFingerprint::make($baseCart, '10.00', 'USD') !== CheckoutFingerprint::make($couponCart, '10.00', 'USD'), 'Different coupons create distinct checkout fingerprints');
    pass(CheckoutFingerprint::make($baseCart, '10.00', 'USD') === CheckoutFingerprint::make($baseCart, '10.00', 'USD'), 'Identical carts create stable checkout fingerprints');

    // Offline synthetic envelope ONLY; does not certify JangoPay's production format.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$key || !openssl_pkey_export($key, $private)) throw new \RuntimeException('RSA key generation failed.');
    $tmp = tempnam(sys_get_temp_dir(), 'jangopay-fixture-');
    file_put_contents($tmp, $private);
    chmod($tmp, 0600);
    $public = openssl_pkey_get_details($key)['key'];
    $publicFile = tempnam(sys_get_temp_dir(), 'jangopay-pub-');
    file_put_contents($publicFile, $public);
    $aes = random_bytes(32); $nonce = random_bytes(12);
    $plain = json_encode(['event_type'=>'payment.completed','invoice_id'=>'fixture_invoice','txn_id'=>'fixture_txn','order_id'=>'fixture_order'], JSON_THROW_ON_ERROR);
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $aes, OPENSSL_RAW_DATA, $nonce, $tag);
    $cmd = ['openssl','pkeyutl','-encrypt','-pubin','-inkey',$publicFile,
            '-pkeyopt','rsa_padding_mode:oaep','-pkeyopt','rsa_oaep_md:sha256','-pkeyopt','rsa_mgf1_md:sha256'];
    $proc = proc_open($cmd, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    fwrite($pipes[0], $aes); fclose($pipes[0]);
    $encryptedKey = stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($proc)!==0) throw new \RuntimeException('Fixture RSA encrypt failed.');
    $headers = ['X-JangoPay-Event'=>'payment.completed', 'X-JangoPay-Encryption-Alg'=>'AES-256-GCM',
                'X-JangoPay-Key-Encryption'=>'RSA-OAEP-SHA256','Content-Type'=>'application/json'];
    $body = ['event_type'=>'payment.completed','algorithm'=>'AES-256-GCM','key_algorithm'=>'RSA-OAEP-SHA256',
             'encrypted_key'=>base64_encode($encryptedKey),'nonce'=>base64_encode($nonce),
             'ciphertext'=>base64_encode($ciphertext.$tag)];
    putenv('JANGOPAY_RSA_PRIVATE_KEY_PATH='.$tmp);
    putenv('JANGOPAY_WEBHOOK_TAG_FORMAT');
    $webhook = new WebhookEnvelope();
    pass(rejects(fn () => $webhook->decrypt(new Request($headers,$body))), 'Unverified tag format fail-closed');
    putenv('JANGOPAY_WEBHOOK_TAG_FORMAT=appended16');
    pass(($webhook->decrypt(new Request($headers,$body)))['invoice_id'] === 'fixture_invoice', 'OAEP SHA256 and AES GCM synthetic fixture decrypt');
    $officialPayload = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    unset($officialPayload['event_type']);
    $officialCipher = openssl_encrypt(json_encode($officialPayload, JSON_THROW_ON_ERROR), 'aes-256-gcm', $aes, OPENSSL_RAW_DATA, $nonce, $officialTag);
    $officialBody = $body;
    $officialBody['ciphertext'] = base64_encode($officialCipher.$officialTag);
    pass(($webhook->decrypt(new Request($headers,$officialBody)))['event_type'] === 'payment.completed', 'Official payload without redundant inner event type accepted');
    $officialPayload['event_type'] = 'payout.completed';
    $wrongCipher = openssl_encrypt(json_encode($officialPayload, JSON_THROW_ON_ERROR), 'aes-256-gcm', $aes, OPENSSL_RAW_DATA, $nonce, $wrongTag);
    $wrongBody = $body;
    $wrongBody['ciphertext'] = base64_encode($wrongCipher.$wrongTag);
    pass(rejects(fn () => $webhook->decrypt(new Request($headers,$wrongBody))), 'Conflicting inner event type rejected');
    $corrupt = $body;
    $bytes = base64_decode($corrupt['ciphertext']); $bytes[4] = chr(ord($bytes[4]) ^ 1);
    $corrupt['ciphertext'] = base64_encode($bytes);
    pass(rejects(fn () => $webhook->decrypt(new Request($headers,$corrupt))), 'Ciphertext tampering rejected');
    $headers['X-JangoPay-Event'] = 'payout.completed';
    pass(rejects(fn () => $webhook->decrypt(new Request($headers,$body))), 'Wrong webhook event rejected');
    unlink($tmp); unlink($publicFile);
    echo "Executed {$count} offline tests.\n";
}
