(function (wp, wc) {
  const registry = wc?.wcBlocksRegistry;
  const settingsApi = wc?.wcSettings;
  if (!registry || !settingsApi || !wp?.element) return;
  
  const { __ } = wp.i18n;
  if (!__) {
    console.error('MorPOS: i18n is required.');
    return;
  }

  const { createElement: h } = wp.element;
  const s = settingsApi.getSetting('morpos_data', {}) || {};

  const morposLogoUrl = s.logoUrl;
  const cardsStripUrl = s.cardsStripUrl;
  const testOn = !!s.testmode;

  const Content = () =>
    h('div', { className: 'morpos-content' },
      h('p', { className: 'morpos-desc' }, __('You can make a payment using your credit or debit card.', 'morpos')),
      testOn && h('div', { className: 'morpos-alert' },
        h('span', { className: 'morpos-alert-ico' }, '!'),
        h('div', null,
          h('strong', null, __('Test Mode Enabled.', 'morpos')),
          h('span', null, __('Transactions are simulated and not charged until test mode is disabled.', 'morpos'))
        )
      )
    );

  const Label = () =>
    h('span', { className: 'morpos-label-wrap' },
      h('div', { className: 'morpos-label-left' },
        h('img', { src: morposLogoUrl, alt: __('MorPOS', 'morpos'), loading: 'lazy', style: { height: '22px', borderRadius: '4px' } }),
        h('span', null, __('Credit and Bank Card', 'morpos'))
      ),
      h('div', { className: 'morpos-label-logos' },
        h('img', { src: cardsStripUrl, alt: __('Supported Cards', 'morpos'), loading: 'lazy' })
      )
    );

  registry.registerPaymentMethod({
    name: 'morpos',
    label: h(Label),
    ariaLabel: __('Credit and Bank Card', 'morpos'),
    content: h(Content),
    edit: h(Content),
    canMakePayment: () => true,
    supports: { features: s.supports || ['products'] },
  });
})(window.wp, window.wc);
