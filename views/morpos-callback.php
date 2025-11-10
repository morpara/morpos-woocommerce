<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Processing…</title>
<script>
  (function () {
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({
        type: 'MORPOS_RESULT',
        status: <?php echo wp_json_encode($isSuccess ? 'success' : 'fail'); ?>,
        redirect_url: <?php echo wp_json_encode($redirectUrl); ?>,
        order_id: <?php echo wp_json_encode($order_id); ?>,
        order_status: <?php echo wp_json_encode($order->get_status()); ?>,
      }, window.location.origin);
    } else {
      window.location.href = <?php echo wp_json_encode($redirectUrl); ?>;
    }
  })();
</script>

<body style="font-family:system-ui;margin:2rem">
  <h3><?php esc_html_e('Processing your order…', 'morpos'); ?></h3>
  <p><?php esc_html_e('Please wait while we process your order. You will be redirected shortly.', 'morpos'); ?></p>
</body>