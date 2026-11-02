/* JangoPay read-only order-status polling. The server supplies authorized order data. */
(function () {
    'use strict';
    const config = window.JPWC_CheckoutConfig;
    if (!config || !config.ajaxUrl || !config.params) return;
    let stopped = false;
    async function poll() {
        if (stopped) return;
        try {
            const query = new URLSearchParams(config.params);
            const response = await fetch(config.ajaxUrl + '?' + query.toString(), {
                credentials: 'same-origin', cache: 'no-store'
            });
            if (!response.ok) return;
            const result = await response.json();
            if (!result.success || !result.data) return;
            if (result.data.paid && result.data.state === 'completed') {
                stopped = true;
                window.location.replace(config.receivedUrl);
                return;
            }
            if (result.data.state !== config.initialState) {
                stopped = true;
                window.location.reload();
            }
        } catch (_) { /* Local status polling never authorizes payment. */ }
    }
    const interval = window.setInterval(function () {
        if (stopped) { window.clearInterval(interval); return; }
        poll();
    }, 15000);
}());
