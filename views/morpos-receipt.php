<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}
?>
<?php if ($html): ?>
<section class="morpos-mini" role="status" aria-live="polite">
  <img
    class="morpos-logo"
    src="<?php echo esc_url($logo_url); ?>"
    alt="<?php echo esc_attr($provider_name); ?>"
  />

  <h2 class="morpos-title">
    <?php echo esc_html__('Almost there', 'morpos'); ?>
  </h2>
  <p class="morpos-lead">
    <?php echo esc_html__('We’ve received your order. Please complete the payment to finish your purchase.', 'morpos'); ?>
  </p>

  <p class="morpos-meta">
    <span class="morpos-order">
      <?php echo esc_html__('Order number:', 'woocommerce'); ?>
      #<?php echo esc_html($order->get_order_number()); ?>
    </span>
  </p>

  <div class="morpos-iframe-wrap">
    <iframe
      id="morpos-frame"
      class="morpos-iframe"
      title="<?php echo esc_attr($provider_name); ?>"
      loading="eager"
      allow="payment *; clipboard-write; fullscreen"
      sandbox="allow-forms allow-scripts allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
      referrerpolicy="no-referrer-when-downgrade"
    ></iframe>
  </div>

  <p class="morpos-note">
    <?php echo wp_kses_post(__('<strong>Do not close this window</strong> until payment succeeds and you’re redirected.', 'morpos')); ?>
  </p>
</section>
<?php endif; ?>

<style>
  :root {
    --morpos-ink: #0f172a;
    --morpos-muted: #475569;
    --morpos-line: #e5e7eb;
    --morpos-bg: #fff;
  }

  section.morpos-mini {
    max-width: 540px;
    margin: 24px auto;
    padding: 24px 32px;
    text-align: center;
    background: var(--morpos-bg);
    border: 1px solid var(--morpos-line);
    border-radius: 14px;
    box-shadow: 0 8px 18px rgba(2, 6, 23, .06);
  }

  img.morpos-logo {
    height: 40px;
    width: auto;
    display: block;
    margin: 0 auto 10px;
  }

  .morpos-title {
    margin: 6px 0 4px;
    font: 700 22px/1.2 system-ui, -apple-system, Segoe UI, Roboto, Arial;
    color: var(--morpos-ink)
  }

  .morpos-lead {
    margin: 0 0 8px;
    color: var(--morpos-muted)
  }

  .morpos-meta {
    margin: 0 0 16px;
    color: var(--morpos-ink);
    font-weight: 700
  }

  .morpos-order {
    color: var(--morpos-ink);
    font-weight: 700
  }

  .morpos-note {
    font-size: 13px;
    color: #713f12;
    background: #fffbeb;
    border: 1px solid #fde68a;
    padding: 8px 10px;
    border-radius: 8px;
    margin-top: 12px
  }

  /* Iframe */
  .morpos-iframe-wrap {
    width: 100%;
    height: 620px;
    max-height: calc(100% - 40px);
    border-radius: 10px;
    overflow: hidden;
    background: #fff;
  }

  .morpos-iframe {
    width: 100%;
    height: 100%;
    border: 0;
    display: block
  }

  /* Make checkout full width to accommodate iframe */
  .woocommerce-checkout main .woocommerce {
    max-width: 100vw;
  }
</style>

<script>
  (function () {
    var frame = document.getElementById('morpos-frame');
    var html = <?php echo wp_json_encode(isset($html) ? $html : ''); ?>;
    var url = <?php echo wp_json_encode(isset($url) ? $url : ''); ?>;
    var blobUrl = null;
    
    if (typeof html === 'string' && html.length) {
      try {
        // Create a blob from the HTML content with UTF-8 encoding
        var blob = new Blob([html], { type: 'text/html; charset=utf-8' });
        blobUrl = URL.createObjectURL(blob);
        
        if (frame) {
          frame.src = blobUrl;
        }
      } catch (e) {
        console.error('MorPOS: Failed to create blob, falling back to srcdoc');
        // Fallback to original method if blob creation fails
        if (frame && 'srcdoc' in frame) {
          frame.srcdoc = html;
        } else {
          var d = frame.contentWindow.document;
          d.open();
          d.write(html);
          d.close();
        }
      }
    } else if (typeof url === 'string' && url.length) {
      try {
        var urlObj = new URL(url, window.location.origin);
        if (urlObj.protocol === 'http:' || urlObj.protocol === 'https:') {
          window.location.assign(url);
        } else {
          console.warn('MorPOS: Invalid protocol for redirect');
        }
      } catch (e) {
        console.error('MorPOS: Invalid URL');
      }
    }
    
    // Clean up blob URL when iframe is unloaded to prevent memory leaks
    if (blobUrl) {
      var cleanup = function() {
        URL.revokeObjectURL(blobUrl);
        window.removeEventListener('beforeunload', cleanup);
      };
      window.addEventListener('beforeunload', cleanup);
    }

    window.addEventListener('message', function (e) {
      // If the message is not from the morpos iframe, ignore it
      if (frame && frame.contentWindow && e.source !== frame.contentWindow) {
        return;
      }

      if (e.data && e.data.type === 'MORPOS_RESULT') {
        var fallbackUrl = <?php echo wp_json_encode($order->get_checkout_order_received_url()); ?>;
        var redirectUrl = e.data.redirect_url || fallbackUrl;
        window.location.href = redirectUrl;
      }
    });
  })();
</script>