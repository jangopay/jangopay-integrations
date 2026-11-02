/* JangoPay settings connection test and privileged order re-verification. */
(function () {
    'use strict';
    if (window.jpwcAdminBound) return;
    window.jpwcAdminBound = true;
    const config = window.JPWC_AdminConfig;
    if (!config || !config.ajaxUrl) return;

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.jpwc-reverify');
        if (!button || button.disabled) return;
        event.preventDefault();
        button.disabled = true;
        const label = button.parentNode.querySelector('.jpwc-reverify-result');
        if (label) label.textContent = ' Checking...';
        try {
            const body = new URLSearchParams({
                action: 'jpwc_reverify', order: button.dataset.order, nonce: button.dataset.nonce
            });
            const response = await fetch(config.ajaxUrl, {
                method: 'POST', credentials: 'same-origin', body
            });
            const json = await response.json();
            if (!response.ok || !json.success) throw Error('Verification unavailable');
            if (label) label.textContent = ' Checked: ' + json.data.state;
            window.location.reload();
        } catch (_) {
            if (label) label.textContent = ' Unable to verify. Retry or inspect logs.';
        } finally { button.disabled = false; }
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('#jpwc-test');
        if (!button || button.disabled) return;
        event.preventDefault();
        button.disabled = true;
        const output = document.getElementById('jpwc-test-output');
        if (output) output.textContent = ' Checking...';
        try {
            const body = new URLSearchParams({
                action: 'jpwc_connection', nonce: config.connectionNonce
            });
            const response = await fetch(config.ajaxUrl, {
                method: 'POST', credentials: 'same-origin', body
            });
            const json = await response.json();
            if (!response.ok || !json.success) throw Error('Connection unavailable');
            if (output) output.textContent = json.data.message || 'Connection succeeded';
        } catch (_) {
            if (output) output.textContent = 'Connection failed';
        } finally { button.disabled = false; }
    });
}());
