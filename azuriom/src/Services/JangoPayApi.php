<?php

namespace Azuriom\Plugin\JangoPay\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Small, deliberately narrow API adapter. No payout/exchange methods are exposed. */
class JangoPayApi
{
    private ?string $token = null;

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /** Require explicit operator authorization and complete verification settings before
     * creating a real payable invoice. Staging can use the same guard with a test merchant. */
    public function isCollectionReady(): bool
    {
        $path = (string) (getenv('JANGOPAY_RSA_PRIVATE_KEY_PATH') ?: '');
        if (getenv('JANGOPAY_ENABLE_PAYMENTS') !== '1'
            || trim((string) getenv('JANGOPAY_FINAL_INVOICE_STATUSES')) === ''
            || getenv('JANGOPAY_WEBHOOK_TAG_FORMAT') !== 'appended16'
            || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return false;
        }
        // A readable placeholder is not a valid RSA private key.
        $key = @openssl_pkey_get_private('file://'.$path);
        if ($key === false) return false;
        $details = openssl_pkey_get_details($key);
        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA;
    }

    private function apiKey(): string
    {
        return (string) (getenv('JANGOPAY_API_KEY') ?: '');
    }

    private function url(string $path): string
    {
        // Host is deliberately not merchant-configurable.
        $base = (string) (getenv('JANGOPAY_TEST_API_URL') ?: 'https://api.jangopay.org');
        if ($base !== 'https://api.jangopay.org' && getenv('APP_ENV') !== 'local') {
            throw new RuntimeException('Non-production API endpoint is only permitted with APP_ENV=local.');
        }
        if (! str_starts_with($base, 'https://') && ! (getenv('APP_ENV') === 'local' && str_starts_with($base, 'http://127.0.0.1:'))) {
            throw new RuntimeException('Unsafe API endpoint.');
        }
        return rtrim($base, '/').$path;
    }

    private function send(string $method, string $path, array $headers, ?array $body = null): array
    {
        $request = Http::acceptJson()->connectTimeout(4)->timeout(12)->withHeaders($headers);
        // No automatic retries for POSTs: their result may be unknown on timeout.
        $res = match ($method) {
            'POST' => $request->post($this->url($path), $body ?: new \stdClass()),
            'GET' => $request->get($this->url($path)),
            default => throw new RuntimeException('Unsupported HTTP method'),
        };
        $json = $res->json();
        if (! $res->successful() || ! is_array($json) || ($json['success'] ?? true) === false
            || (array_key_exists('code', (array) $json) && ($json['code'] ?? '') !== '0000')) {
            // API response may contain identifying data; never log or show it.
            throw new RuntimeException('JangoPay returned a non-success response (HTTP '.$res->status().').');
        }
        return $json;
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        if ($this->apiKey() === '') {
            throw new RuntimeException('JANGOPAY_API_KEY is not configured.');
        }
        $body = $this->send('POST', '/api/v1/auth/apikey/token', [
            'Authorization' => 'Basic '.$this->apiKey(),
            'Content-Type' => 'application/json',
        ], []);
        $token = $body['data']['accessToken'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('JangoPay token response is missing accessToken.');
        }
        return $this->token = $token;
    }

    private function authed(string $method, string $path, ?array $data = null): array
    {
        return $this->send($method, $path, [
            'Authorization' => 'Bearer '.$this->token(),
            'Content-Type' => 'application/json',
        ], $data);
    }

    public function listAssets(): array
    {
        $body = $this->authed('GET', '/api/v1/invoice/payment-assets');
        return is_array($body['items'] ?? null) ? $body['items'] : [];
    }

    public function createInvoice(array $data): array
    {
        // Match the public PHP SDK's request serializer (top-level snake_case).
        // The nested extraInfo map retains merchant-defined metadata keys.
        $normalized = [];
        foreach ($data as $key => $value) {
            $name = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $key));
            $normalized[$name] = $value;
        }
        $body = $this->authed('POST', '/api/v1/invoice/create', $normalized);
        return is_array($body['invoice'] ?? null) ? $body['invoice'] : [];
    }

    public function invoice(string $id): array
    {
        $body = $this->authed('GET', '/api/v1/invoice/'.rawurlencode($id));
        // Current SDK returns the entire normalized object; envelope is not guaranteed.
        return is_array($body['invoice'] ?? null) ? $body['invoice'] : $body;
    }

    public function invoiceTransaction(string $id): array
    {
        $body = $this->authed('GET', '/api/v1/invoice/transaction/'.rawurlencode($id));
        return is_array($body['transaction'] ?? null) ? $body['transaction'] : $body;
    }

    /** Bounded search used only for unknown outcome recovery, NEVER as an idempotency guarantee. */
    public function findInvoiceByOrder(string $orderId): ?array
    {
        $match = null;
        for ($page = 1; $page <= 3; $page++) {
            $body = $this->authed('GET', '/api/v1/invoice/list?page='.$page.'&page_size=100');
            foreach ((array) ($body['invoices'] ?? []) as $invoice) {
                if (! is_array($invoice)) continue;
                if (self::field($invoice, 'order_id', 'orderId') !== $orderId) continue;
                if ($match !== null) throw new RuntimeException('Several remote invoices match a single order. Manual review required.');
                $match = $invoice;
            }
            $pages = self::field($body, 'total_pages', 'totalPages');
            if (is_numeric($pages) && $page >= (int) $pages) break;
            if (count((array) ($body['invoices'] ?? [])) === 0) break;
        }
        return $match;
    }

    /** Bounded merchant-side read-only search for a lost confirm-coin response.
     * Transaction-list filtering is deliberately not assumed; inspect real field
     * identity and refuse ambiguous matches. No payment can be created here. */
    public function findTransactionForInvoice(string $invoiceId): ?array
    {
        $candidate = null;
        for ($page = 1; $page <= 3; $page++) {
            $body = $this->authed('GET', '/api/v1/invoice/transactions?page='.$page.'&page_size=100');
            $items = (array) ($body['transactions'] ?? []);
            foreach ($items as $item) {
                if (! is_array($item) || self::field($item, 'invoice_id', 'invoiceId') !== $invoiceId) continue;
                $id = self::field($item, 'txn_id', 'txnId');
                if (! is_string($id) || $id === '') continue;
                if ($candidate !== null && self::field($candidate, 'txn_id', 'txnId') !== $id) {
                    throw new RuntimeException('More than one merchant transaction matches; manual review required.');
                }
                $candidate = $item;
            }
            $pages = self::field($body, 'total_pages', 'totalPages');
            if (is_numeric($pages) && $page >= (int) $pages) break;
            if ($items === []) break;
        }
        return $candidate;
    }

    public function paymentSession(string $id): array
    {
        // Read-only payer session; returns payment_session token, retained only server-side.
        return $this->send('GET', '/api/v1/invoice/'.rawurlencode($id).'/pay-state', []);
    }

    public function confirmCoin(string $id, string $currency, string $chain): array
    {
        $session = $this->paymentSession($id);
        $paymentSession = self::field($session, 'payment_session', 'paymentSession');
        if (! is_string($paymentSession) || $paymentSession === '') {
            throw new RuntimeException('Payment session is unavailable.');
        }
        $body = $this->send('POST', '/api/v1/invoice/'.rawurlencode($id).'/confirm-coin', [
            'Content-Type' => 'application/json',
        ], [
            'invoice_id' => $id, 'pay_currency' => $currency,
            'chain' => $chain, 'payment_session' => $paymentSession,
        ]);
        return is_array($body['transaction'] ?? null) ? $body['transaction'] : [];
    }

    public static function field(array $a, string $snake, ?string $camel = null): mixed
    {
        return $a[$snake] ?? $a[$camel ?? $snake] ?? null;
    }
}
