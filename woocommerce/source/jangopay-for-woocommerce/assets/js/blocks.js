/* Plain JavaScript (no build step); WooCommerce provides these globals. */
(function () {
  'use strict';
  if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings || !window.wp) return;
  const registry = window.wc.wcBlocksRegistry;
  const element = window.wp.element;
  const html = window.wp.htmlEntities;
  const data = window.wc.wcSettings.getSetting('jangopay_woocommerce_data', {});
  const label = html.decodeEntities(typeof data.title === 'string' ? data.title : 'JangoPay (USDT / USDC)');
  const content = function () { return element.createElement('div', {className:'jpwc-block-description'}, html.decodeEntities(typeof data.description === 'string' ? data.description : '')); };
  const icon = data.icon ? element.createElement('img', {
    src: data.icon, alt: '', 'aria-hidden': true, width: 28, height: 28,
    style: {objectFit:'contain', flexShrink:0},
    onError: function (event) { event.currentTarget.style.display = 'none'; }
  }) : null;
  registry.registerPaymentMethod({
    name: 'jangopay_woocommerce',
    label: element.createElement('span', {style:{display:'inline-flex', alignItems:'center', gap:'8px'}}, icon, label),
    ariaLabel: label,
    content: element.createElement(content),
    edit: element.createElement(content),
    canMakePayment: function ({cartTotals}) {
      return !!cartTotals && !!cartTotals.currency_code && cartTotals.currency_code.toUpperCase() === 'USD';
    },
    supports: {features: data.supports || ['products']}
  });
})();
