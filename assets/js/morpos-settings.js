const { __ } = wp.i18n;

jQuery(function ($) {
  function setStatus(s) { // 'setup' | 'ok' | 'fail'
    $('.morpos-connection .pill').css('opacity', .35);
    if (s === 'ok') {
      $('.pill-ok').css('opacity', 1);
    } else if (s === 'fail') {
      $('.pill-fail').css('opacity', 1);
    } else {
      $('.pill-setup').css('opacity', 1);
    }

    const id = MorPOSAdmin.statusFieldId;
    if (id) $('#' + id).val(s);
  }

  function readField(id) {
    return $('#' + id).val() || '';
  }

  $('.morpos-test-btn').on('click', function () {
    const $btn = $(this);
    $btn.prop('disabled', true).text(__('Testing…', 'morpos'));

    const credentials = {
      merchant_id: readField(MorPOSAdmin.fields.merchant_id),
      client_id: readField(MorPOSAdmin.fields.client_id),
      client_secret: readField(MorPOSAdmin.fields.client_secret),
      api_key: readField(MorPOSAdmin.fields.api_key)
    };

    $.post(MorPOSAdmin.ajaxUrl, {
      action: 'morpos_test_connection',
      nonce: MorPOSAdmin.nonce,
      credentials: credentials,
      testmode: $('#'+MorPOSAdmin.fields.testmode).is(':checked') ? 'yes' : 'no'
    })
    .done(function (res) {
      if (res.success) {
        const s = res.data.status === 'ok' ? 'ok' : 'fail';
        setStatus(s);
        wp.data && wp.data.dispatch('core/notices')?.createNotice(
          s === 'ok' ? 'success' : 'error',
          res.data.message || (s === 'ok' ? __('Connection successful.', 'morpos') : __('Connection failed.', 'morpos')),
          { isDismissible: true }
        );
      } else {
        setStatus('fail');
        wp.data && wp.data.dispatch('core/notices')?.createNotice(
          'error',
          res.data?.message || __('Connection error.', 'morpos'),
          { isDismissible: true }
        );
      }
    })
    .fail(function () {
      setStatus('fail');
      wp.data && wp.data.dispatch('core/notices')?.createNotice(
        'error',
        __('Could not reach the server.', 'morpos'),
        { isDismissible: true }
      );
    })
    .always(function () {
      $btn.prop('disabled', false).text(__('Test Connection', 'morpos'));
    });
  });

  setStatus(MorPOSAdmin.status || 'setup');
});

jQuery(function($){
  var $form   = $('#mainform');
  var $anchor = $('#morpos-submit-anchor');
  var $submit = $form.find('p.submit').first();

  if ($anchor.length && $submit.length) {
    $submit.insertAfter($anchor);
  }
});
