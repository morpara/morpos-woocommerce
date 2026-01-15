<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

// Register and enqueue the callback script
wp_register_script(
    'morpos-callback',
    MORPOS_GATEWAY_URL . 'assets/js/morpos-callback.js',
    [],
    MORPOS_GATEWAY_VERSION,
    true
);
wp_enqueue_script('morpos-callback');

// Add inline data before the script
$callback_data = wp_json_encode([
    'status' => $isSuccess ? 'success' : 'fail',
    'redirect_url' => $redirectUrl,
    'order_id' => $order_id,
    'order_status' => $order->get_status(),
]);
wp_add_inline_script(
    'morpos-callback',
    'var morposCallbackData = ' . $callback_data . ';',
    'before'
);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <title><?php esc_html_e('Processing…', 'morpos-for-woocommerce'); ?></title>
    <?php wp_head(); ?>
</head>
<body style="font-family:system-ui;margin:2rem">
    <h3><?php esc_html_e('Processing your order…', 'morpos-for-woocommerce'); ?></h3>
    <p><?php esc_html_e('Please wait while we process your order. You will be redirected shortly.', 'morpos-for-woocommerce'); ?></p>
    <?php wp_footer(); ?>
</body>
</html>
