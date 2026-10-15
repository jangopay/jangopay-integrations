<div class="row g-3">
    <div class="col-12">
        <label class="form-label" for="jangopayAssets">Allowed USDT/USDC networks (validated against your merchant account)</label>
        <input class="form-control @error('allowed_assets') is-invalid @enderror" id="jangopayAssets"
            type="text" name="allowed_assets" maxlength="500"
            value="{{ old('allowed_assets', $gateway->data['allowed_assets'] ?? '') }}"
            placeholder="USDT:tron,USDC:ethereum" required>
        <div class="form-text">Only enter combinations confirmed by the JangoPay payment-assets endpoint. No exchange rates are calculated locally.</div>
        @error('allowed_assets')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
<div class="alert alert-info mt-3">
    <strong>JangoPay (USD only)</strong>
    <p class="mb-1">API key must be set as the server environment variable <code>JANGOPAY_API_KEY</code>.</p>
    <p class="mb-1">Webhook private key path: <code>JANGOPAY_RSA_PRIVATE_KEY_PATH</code> (server-only file).</p>
    <p class="mb-1">Merchant callback address: <code>{{ route('shop.payments.notification', ['gateway' => 'jangopay']) }}</code></p>
    <p class="mb-1">Payments default to blocked. To enable invoice creation after authentic integration tests, configure <code>JANGOPAY_ENABLE_PAYMENTS=1</code>, verified invoice final statuses, verified encrypted webhook framing and a valid RSA PEM private key.</p>
    <p class="mb-0">Payment review: <a href="{{ route('jangopay.admin.payments.index') }}">Open reconciliation screen</a></p>
</div>
