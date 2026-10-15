<?php

namespace Azuriom\Plugin\JangoPay\Services;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Candidate envelope: RSA-OAEP(SHA256/MGF1-SHA256) -> AES-256-GCM.
 * Actual ciphertext/tag framing MUST be checked against a real redacted vector.
 * Explicitly disabled unless JANGOPAY_WEBHOOK_TAG_FORMAT=appended16.
 */
class WebhookEnvelope
{
    public function decrypt(Request $request): array
    {
        if ($request->header('X-JangoPay-Event') !== 'payment.completed'
            || $request->header('X-JangoPay-Encryption-Alg') !== 'AES-256-GCM'
            || $request->header('X-JangoPay-Key-Encryption') !== 'RSA-OAEP-SHA256') {
            throw new RuntimeException('Unexpected webhook headers.');
        }
        if (strtolower((string) $request->header('Content-Type')) !== 'application/json'
            && ! str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/json;')) {
            throw new RuntimeException('Unexpected content type.');
        }
        if (strlen($request->getContent()) > 32768) throw new RuntimeException('Envelope too large.');
        $raw = $request->json()->all();
        if (($raw['event_type'] ?? null) !== 'payment.completed'
            || ($raw['algorithm'] ?? null) !== 'AES-256-GCM'
            || ($raw['key_algorithm'] ?? null) !== 'RSA-OAEP-SHA256') {
            throw new RuntimeException('Webhook envelope mismatch.');
        }
        if (getenv('JANGOPAY_WEBHOOK_TAG_FORMAT') !== 'appended16') {
            // Do not guess authentication-tag framing from a partial documentation snippet.
            throw new RuntimeException('Webhook framing has not been integration-tested.');
        }
        $encryptedKey = $this->b64($raw['encrypted_key'] ?? null, 1024);
        $nonce = $this->b64($raw['nonce'] ?? null, 32);
        $cipher = $this->b64($raw['ciphertext'] ?? null, 32768);
        if (strlen($nonce) !== 12 || strlen($cipher) < 17) {
            throw new RuntimeException('Invalid GCM envelope lengths.');
        }
        $privateKey = (string) (getenv('JANGOPAY_RSA_PRIVATE_KEY_PATH') ?: '');
        if ($privateKey === '' || ! is_file($privateKey) || ! is_readable($privateKey)) {
            throw new RuntimeException('Webhook key unavailable.');
        }
        $key = $this->rsaDecryptSha256($encryptedKey, $privateKey);
        if (strlen($key) !== 32) {
            throw new RuntimeException('Invalid AES key.');
        }
        $tag = substr($cipher, -16);
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if (! is_string($plain)) throw new RuntimeException('GCM authentication failed.');
        $body = json_decode($plain, true);
        if (! is_array($body) || (array_key_exists('event_type', $body) && $body['event_type'] !== 'payment.completed')) {
            throw new RuntimeException('Invalid webhook payload.');
        }
        return $body + ['event_type' => $raw['event_type']];
    }

    private function b64(mixed $value, int $maxBytes): string
    {
        if (! is_string($value) || strlen($value) > ($maxBytes * 2) || $value === '') {
            throw new RuntimeException('Invalid base64 field.');
        }
        $decoded = base64_decode($value, true);
        if (! is_string($decoded) || strlen($decoded) > $maxBytes) {
            throw new RuntimeException('Invalid base64 bytes.');
        }
        return $decoded;
    }

    private function rsaDecryptSha256(string $encrypted, string $keyPath): string
    {
        if (! function_exists('proc_open')) throw new RuntimeException('RSA OAEP SHA256 decrypt unavailable.');
        // PHP openssl_private_decrypt(OPENSSL_PKCS1_OAEP_PADDING) defaults to SHA-1.
        // Use the documented OpenSSL pkeyutl OAEP-SHA256 options instead.
        $command = [
            'openssl', 'pkeyutl', '-decrypt', '-inkey', $keyPath,
            '-pkeyopt', 'rsa_padding_mode:oaep', '-pkeyopt', 'rsa_oaep_md:sha256',
            '-pkeyopt', 'rsa_mgf1_md:sha256',
        ];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($process)) throw new RuntimeException('OpenSSL executable unavailable.');
        fwrite($pipes[0], $encrypted);
        fclose($pipes[0]);
        $result = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        // Do not emit potentially sensitive OpenSSL diagnostics.
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || ! is_string($result)) throw new RuntimeException('RSA decrypt failed.');
        return $result;
    }
}
