<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_MorPOS extends WC_Payment_Gateway
{
    // IDs / constants
    private const GATEWAY_ID = 'morpos';

    // Currency code → ISO numeric map
    private const CURRENCY_CODE_TO_NUM = [
        'TRY' => '949',
        'USD' => '840',
        'EUR' => '978',
    ];

    // Payment form types
    public const FORM_TYPE_HOSTED = 'hosted';
    public const FORM_TYPE_EMBEDDED = 'embedded';

    // Props (persisted options)
    private string $client_id = '';
    private string $client_secret = '';
    private string $merchant_id = '';
    private string $submerchant_id = '';
    private string $api_key = '';
    private string $form_type = self::FORM_TYPE_HOSTED; // hosted|embedded
    private string $connection_status = 'setup';
    private bool $testmode = false;

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->method_title = __('MorPOS Payment Plugin', 'morpos');
        $this->method_description = __('MorPOS WooCommerce is a virtual POS plugin specially developed for e-commerce websites built on the WooCommerce infrastructure. With this plugin, you can easily accept payments from your customers via credit or debit card — either in full or in installments — and integrate seamlessly with all banks. It is easy to install and requires no technical knowledge.', 'morpos');
        $this->has_fields = true;
        $this->icon = MORPOS_GATEWAY_URL . 'assets/img/morpos-logo-small.png';

        $this->init_form_fields();
        $this->init_settings();
        $this->hydrate_options();

        $this->title = __('Credit and Bank Card', 'morpos');

        add_action('woocommerce_api_wc_gateway_' . $this->id, [$this, 'handle_return']);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_action('woocommerce_receipt_morpos', [$this, 'receipt_page']);
    }

    /**
     * Load persisted options into properties
     */
    private function hydrate_options(): void
    {
        $this->enabled = $this->get_option('enabled', 'no');

        $this->client_id = (string) $this->get_option('client_id', '');
        $this->client_secret = (string) $this->get_option('client_secret', '');
        $this->merchant_id = (string) $this->get_option('merchant_id', '');
        $this->submerchant_id = (string) $this->get_option('submerchant_id', '');
        $this->api_key = (string) $this->get_option('api_key', '');
        $this->form_type = (string) $this->get_option('form_type', self::FORM_TYPE_HOSTED);
        $this->connection_status = (string) $this->get_option('connection_status', 'setup');
        $this->testmode = $this->get_option('testmode', 'no') === 'yes';
    }

    /**
     * Admin options page
     */
    public function admin_options()
    {
        include MORPOS_GATEWAY_PATH . 'views/morpos-admin-options.php';
    }

    /**
     * Enqueue admin assets on gateway settings page.
     */
    public function enqueue_admin_assets($hook)
    {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }

        $is_gateway_page = isset($_GET['tab'], $_GET['section']) && $_GET['tab'] === 'checkout' && $_GET['section'] === $this->id;
        if (!$is_gateway_page) {
            return;
        }

        wp_enqueue_style(
            'morpos-admin',
            MORPOS_GATEWAY_URL . 'assets/css/morpos-admin.css',
            [],
            '1.0.0'
        );

        wp_register_script(
            'morpos-admin',
            MORPOS_GATEWAY_URL . 'assets/js/morpos-settings.js',
            ['jquery', 'wp-i18n'],
            '1.0.0',
            true
        );
        wp_set_script_translations(
            'morpos-admin',
            'morpos',
            MORPOS_GATEWAY_PATH . 'languages'
        );
        wp_enqueue_script('morpos-admin');

        wp_localize_script('morpos-admin', 'MorPOSAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'environment' => $this->get_option('environment', 'sandbox'),
            'nonce' => wp_create_nonce('morpos_admin'),
            'status' => $this->get_option('connection_status', 'setup'),
            'statusFieldId' => $this->get_field_key('connection_status'),
            'fields' => [
                'merchant_id' => $this->get_field_key('merchant_id'),
                'client_id' => $this->get_field_key('client_id'),
                'client_secret' => $this->get_field_key('client_secret'),
                'api_key' => $this->get_field_key('api_key'),
                'testmode' => $this->get_field_key('testmode'),
            ],
        ]);
    }

    /**
     * Initialize gateway settings form fields.
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Active/Inactive', 'morpos'),
                'type' => 'checkbox',
                'label' => __('Enable MorPOS', 'morpos'),
                'default' => 'no',
            ],
            'testmode' => [
                'title' => __('Test Mode', 'morpos'),
                'type' => 'checkbox',
                'label' => '<span class="morpos-warn">' . __('Enable Sandbox (testing only)', 'morpos') . '</span>',
                'default' => 'no',
                'desc_tip' => true,
                'description' => __('Sandbox endpoints are used when enabled.', 'morpos'),
                'class' => 'morpos-warn',
            ],
            'merchant_id' => [
                'title' => __('Merchant ID', 'morpos'),
                'type' => 'text',
                'placeholder' => __('Your Merchant ID', 'morpos'),
                'custom_attributes' => ['required' => 'required'],
            ],
            'client_id' => [
                'title' => __('Client ID', 'morpos'),
                'type' => 'text',
                'placeholder' => __('Your Client ID', 'morpos'),
                'custom_attributes' => ['required' => 'required'],
            ],
            'client_secret' => [
                'title' => __('Client Secret', 'morpos'),
                'type' => 'password',
                'placeholder' => __('Your Client Secret', 'morpos'),
                'custom_attributes' => ['required' => 'required'],
            ],
            'api_key' => [
                'title' => __('API Key', 'morpos'),
                'type' => 'password',
                'placeholder' => __('Your API Key', 'morpos'),
                'custom_attributes' => ['required' => 'required'],
            ],
            // 'submerchant_id' => [
            //     'title' => __('MorPOS SubMerchant ID', 'morpos'),
            //     'type' => 'text',
            //     'placeholder' => __('(If any) SubMerchant ID.', 'morpos'),
            // ],
            'form_type' => [
                'title' => __('Payment Method', 'morpos'),
                'type' => 'select',
                'options' => [
                    self::FORM_TYPE_HOSTED => __('Hosted Payment Page (Default)', 'morpos'),
                    self::FORM_TYPE_EMBEDDED => __('Embedded Payment', 'morpos'),
                ],
                'default' => self::FORM_TYPE_HOSTED,
            ],
            'connection_status' => [
                'title' => '',
                'type' => 'hidden',
                'default' => 'setup'
            ],
        ];
    }

    /**
     * Process admin options and test connection.
     *
     * @return bool
     */
    public function process_admin_options()
    {
        $post = $this->get_post_data();

        $merchant_id = $this->get_field_value('merchant_id', $this->form_fields['merchant_id'], $post);
        $client_id = $this->get_field_value('client_id', $this->form_fields['client_id'], $post);
        $client_secret = $this->get_field_value('client_secret', $this->form_fields['client_secret'], $post);
        $api_key = $this->get_field_value('api_key', $this->form_fields['api_key'], $post);
        $enabled = $this->get_field_value('enabled', $this->form_fields['enabled'], $post);
        $testmode = $this->get_field_value('testmode', $this->form_fields['testmode'], $post);

        $ok = false;
        if ('yes' === $enabled) {
            try {
                $api = new MorPOS_API_Client(
                    $client_id,
                    $client_secret,
                    $merchant_id,
                    '',
                    $api_key,
                    $testmode === 'yes' ? 'sandbox' : 'production'
                );
                $result = $api->make_test_connection();
                $ok = $result['ok'] === true && isset($result['data']) && isset($result['data']['responseCode']) && $result['data']['responseCode'] === 'B0000';
            } catch (\Exception $e) {
                $ok = false;
                $err = $e->getMessage();
            }

            // Connection failed
            if (!$ok) {
                WC_Admin_Settings::add_error(
                    sprintf(__('Connection failed: %s', 'morpos'), isset($err) ? esc_html($err) : __('Invalid credentials', 'morpos'))
                );
            }
        }

        // Proceed to actually save the settings
        $saved = parent::process_admin_options();
        if ($saved) {
            WC_Admin_Settings::add_message(__('Connection successful.', 'morpos'));
        }

        // Update connection status
        $settings = get_option('woocommerce_' . $this->id . '_settings', []);
        $settings['connection_status'] = $ok ? 'ok' : 'fail';
        update_option('woocommerce_' . $this->id . '_settings', $settings);

        return $saved;
    }

    /**
     * Output receipt page with embedded payment form.
     *
     * @param int $order_id The ID of the order.
     * @return void
     */
    public function receipt_page($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Handle URL parameters from embedded payment callback
        if (isset($_GET['notice_type']) && isset($_GET['notice_message'])) {
            $notice_type = sanitize_text_field($_GET['notice_type']);
            $notice_message = sanitize_text_field(urldecode($_GET['notice_message']));

            $notice_type = $notice_type === 'success' ? 'success' : 'error';
            wc_add_notice(esc_html($notice_message), $notice_type);
        
            wp_safe_redirect(remove_query_arg(['notice_type', 'notice_message']));
            exit;
        }

        $payment = $this->create_payment($order_id);
        if (isset($payment['error'])) {
            wc_print_notice(esc_html($payment['error']), 'error');
        }

        $html = isset($payment['html']) && !empty($payment['html']) ? $payment['html'] : null;
        $url = isset($payment['url']) && !empty($payment['url']) ? $payment['url'] : null;
        $logo_url = MORPOS_GATEWAY_URL . 'assets/img/morpos-logo.png';
        $provider_name = $this->method_title;
        $form_type = $this->form_type;

        include MORPOS_GATEWAY_PATH . 'views/morpos-receipt.php';
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id The ID of the order to process.
     * @return array
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'morpos'), 'error');
            return ['result' => 'failure'];
        }

        // Embedded → go to order pay page where receipt hook injects the form
        if ($this->form_type === self::FORM_TYPE_EMBEDDED) {
            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        // Hosted → get redirect URL from API
        $payment = $this->create_payment($order_id);
        if (isset($payment['error'])) {
            wc_add_notice(esc_html($payment['error']), 'error');
            return ['result' => 'failure'];
        }

        if ($payment === null || !isset($payment['url']) || empty($payment['url'])) {
            return ['result' => 'failure'];
        }

        $order->update_status('pending', __('MorPOS: Hosted Payment initiated, customer redirected.', 'morpos'));
        $order->save();

        return [
            'result' => 'success',
            'redirect' => $payment['url']
        ];
    }

    /**
     * Create payment via MorPOS API.
     *
     * @param int $order_id The ID of the order to create payment for.
     * @return ?array
     * Returns:
     *  - Hosted: redirect URL string
     *  - Embedded: HTML form string
     *  - null on error (with notice + log)
     */
    private function create_payment($order_id): ?array
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['error' => __('Order not found.', 'morpos')];
        }

        // Generate new unique conversation ID for this attempt
        $conversationId = morpos_start_new_attempt($order);

        $api = new MorPOS_API_Client(
            $this->client_id,
            $this->client_secret,
            $this->merchant_id,
            $this->submerchant_id,
            $this->api_key,
            $this->testmode ? 'sandbox' : 'production'
        );

        $order_key = $order->get_order_key();

        $returnUrl = add_query_arg([
            'wc-api' => 'wc_gateway_' . $this->id,
            'order_id' => $order_id,
            'order_key' => $order_key,
            'form_type' => $this->form_type,
            'timestamp' => time(),
        ], home_url('/'));

        $failUrl = $returnUrl;

        $total = number_format((float) $order->get_total(), 2, '.', '');
        $currency = $order->get_currency();
        $currencyCode = $this->currency_iso_numeric_from_code($currency ?? 'TRY');

        $language = get_locale() === 'tr_TR' ? 'tr' : 'en';

        $payment = $api->create_payment([
            'conversationId' => $conversationId,
            'paymentMethod' => $this->form_type === self::FORM_TYPE_EMBEDDED ? 'EMBEDDEDPAYMENT' : 'HOSTEDPAYMENT',
            'paymentInstrumentType' => 'CARD',
            'language' => $language,
            'transactionType' => 'SALE',
            'installmentCount' => 0,
            'amount' => $total,
            'currencyCode' => $currencyCode,
            'returnUrl' => $returnUrl,
            'failUrl' => $failUrl,
            'order' => $order,
            'apiKey' => $this->api_key,
        ]);

        if (!$payment['ok']) {
            MorPOS_Logger::log('Payment initiation failed: ' . ($payment['error'] ?? ('HTTP ' . $payment['http'] ?? 'Unknown error')));
            return ['error' => $payment['message'] ?? __('An error occurred while initiating the payment. Please try again. If the problem persists, contact support.', 'morpos')];
        }

        $data = morpos_array_get($payment, 'data', []);
        $redirect_url = morpos_array_get($data, 'returnUrl');
        $payment_form = morpos_array_get($data, 'paymentFormContent');

        // Embedded flow – requires the form
        if ($this->form_type === self::FORM_TYPE_EMBEDDED) {
            if (!$payment_form) {
                MorPOS_Logger::log('Embedded payment form not found in API response.' . var_export($data, true));
                return ['error' => __('Embedded payment form could not be obtained. Please try again. If the problem persists, contact support.', 'morpos')];
            }

            return ['html' => $payment_form];
        }

        // Hosted flow – requires redirect URL
        if (!$redirect_url) {
            MorPOS_Logger::log('Payment redirect URL could not be obtained from API response.' . var_export($data, true));
            return ['error' => __('Payment redirect URL could not be obtained. Please try again. If the problem persists, contact support.', 'morpos')];
        }

        return ['url' => $redirect_url];
    }

    /**
     * Collect return parameters safely.
     */
    private function morpos_collect_return_params(): array
    {
        $params = [];

        foreach ($_REQUEST as $key => $val) {
            if (is_array($val)) {
                $params[$key] = [];
                foreach ($val as $sub_k => $sub_v) {
                    if (is_array($sub_v)) {
                        continue;
                    }
                    $params[$key][$sub_k] = sanitize_text_field(wp_unslash((string) $sub_v));
                }
                continue;
            }

            $params[$key] = sanitize_text_field(wp_unslash((string) $val));
        }

        return $params;
    }

    /**
     * Convert ISO numeric code to currency code.
     *
     * @param string|null $currency
     * @return string
     */
    private function currency_code_from_iso_numeric(?string $currency): string
    {
        $map = array_flip(self::CURRENCY_CODE_TO_NUM);
        return $map[$currency] ?? '';
    }

    /**
     * Convert currency code to ISO numeric code.
     *
     * @param string|null $code
     * @return string
     */
    private function currency_iso_numeric_from_code(?string $code): string
    {
        return self::CURRENCY_CODE_TO_NUM[$code] ?? '';
    }

    /**
     * Determine if the MorPOS response indicates a successful transaction.
     *
     * @param array $payload
     * @return bool
     */
    private function morpos_is_success(array $payload): bool
    {
        $resultCode = morpos_array_get($payload, 'ResultCode', morpos_array_get($payload, 'resultCode', ''));
        $message = morpos_array_get($payload, 'Message', morpos_array_get($payload, 'message', ''));

        // Check result code and message
        if ($resultCode !== 'B0000' || $message !== 'Approved') {
            return false;
        }

        // // Verify signature
        // $signFromPayload = morpos_array_get($payload, 'Sign', morpos_array_get($payload, 'sign', ''));
        // if (empty($signFromPayload)) {
        //     return false;
        // }

        // $signFields = [
        //     ['ConversationId', 'conversationId'],
        //     ['OrderId', 'orderId'],
        //     ['PaymentId', 'paymentId'],
        //     ['BankUniqueReferenceNumber', 'bankUniqueReferenceNumber'],
        //     ['TransactionDate', 'transactionDate'],
        //     ['Currency', 'currency'],
        //     ['AuthCode', 'authCode'],
        //     ['PaymentInstrumentType', 'paymentInstrumentType'],
        //     ['MaskedCardNumber', 'maskedCardNumber'],
        //     ['CardType', 'cardType'],
        //     ['InstallmentCount', 'installmentCount'],
        //     ['Amount', 'amount'],
        //     ['PayFacCommissionAmount', 'payFacCommissionAmount'],
        //     ['PayFacCommissionRate', 'payFacCommissionRate'],
        // ];

        // $signValues = [];
        // foreach ($signFields as [$key1, $key2]) {
        //     $signValues[$key1] = morpos_array_get($payload, $key1, morpos_array_get($payload, $key2, ''));
        // }

        // $signValues['apiKey'] = $this->api_key;

        // $sign = morpos_sign($signValues);
        // if ($signFromPayload !== $sign) {
        //     return false;
        // }

        $conversationId = morpos_array_get($payload, 'ConversationId', morpos_array_get($payload, 'conversationId', ''));

        // Double-check payment status via API
        $api = new MorPOS_API_Client(
            $this->client_id,
            $this->client_secret,
            $this->merchant_id,
            $this->submerchant_id,
            $this->api_key,
            $this->testmode ? 'sandbox' : 'production'
        );

        $checkResult = $api->check_payment([
            'conversationId' => $conversationId,
        ]);

        if (!$checkResult['ok']) {
            return false;
        }

        $checkData = morpos_array_get($checkResult, 'data', []);
        $checkResponseCode = morpos_array_get($checkData, 'responseCode');
        $checkResponseDescription = morpos_array_get($checkData, 'responseDescription');

        if ($checkResponseCode !== 'B0000' || $checkResponseDescription !== 'Approved') {
            return false;
        }

        return true;
    }

    /**
     * Handle return from MorPOS payment gateway.
     */
    public function handle_return()
    {
        $order_id = absint($_REQUEST['order_id'] ?? 0);
        $order_key = sanitize_text_field($_REQUEST['order_key'] ?? '');
        $form_type = sanitize_text_field($_REQUEST['form_type'] ?? '');

        $order = wc_get_order($order_id);

        // Redirect if order not found
        if (!$order) {
            wc_add_notice(__('Order not found.', 'morpos'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Redirect if order key mismatch
        if ($order_key && $order->get_order_key() !== $order_key) {
            wc_add_notice(__('Order key could not be verified.', 'morpos'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $payload = $this->morpos_collect_return_params();

        $resultCode = morpos_array_get($payload, 'ResultCode', morpos_array_get($payload, 'resultCode', ''));
        $message = morpos_array_get($payload, 'Message', morpos_array_get($payload, 'message', ''));
        $conversationId = morpos_array_get($payload, 'ConversationId', morpos_array_get($payload, 'conversationId', ''));
        $paymentId = morpos_array_get($payload, 'PaymentId', morpos_array_get($payload, 'paymentId', ''));
        $bankRef = morpos_array_get($payload, 'BankUniqueReferenceNumber', morpos_array_get($payload, 'bankUniqueReferenceNumber', ''));
        $amountStr = morpos_array_get($payload, 'Amount', morpos_array_get($payload, 'amount', ''));
        $currencyNum = morpos_array_get($payload, 'Currency', morpos_array_get($payload, 'currency', ''));
        $currencyIso = $this->currency_code_from_iso_numeric($currencyNum);
        $installment = morpos_array_get($payload, 'InstallmentCount', morpos_array_get($payload, 'installmentCount', ''));
        $cardMasked = morpos_array_get($payload, 'MaskedCardNumber', morpos_array_get($payload, 'maskedCardNumber', ''));

        // Verify conversation ID
        if (!morpos_validate_conversation_id20_any($order, $conversationId)) {
            wc_add_notice(__('Conversation ID could not be verified.', 'morpos'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        foreach ($payload as $k => $v) {
            $order->update_meta_data('_morpos_' . sanitize_key($k), $v);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $noteDump = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $order->add_order_note(__('MorPOS return parameters:', 'morpos') . "\n" . $noteDump);
        }

        $isSuccess = $this->morpos_is_success($payload);
        $redirectUrl = null;

        // Build short summary, including card and installment only if they exist
        $shortParts = [];

        if (!empty($cardMasked)) {
            $shortParts[] = sprintf(__('Card', 'morpos') . ': %s', $cardMasked);
        }

        if (!empty($installment)) {
            $shortParts[] = sprintf(__('Installment', 'morpos') . ': %s', $installment);
        }

        $shortParts[] = sprintf('ResultCode: %s', $resultCode ?: '—');
        $shortParts[] = sprintf('Message: %s', $message ?: '—');

        if (!empty($amountStr)) {
            $shortParts[] = sprintf('Amount: %s %s', $amountStr ?: '—', $currencyIso ?: '—');
        }

        $shortParts[] = sprintf('PaymentId: %s', $paymentId ?: '—');
        $shortParts[] = sprintf('ConversationId: %s', $conversationId ?: '—');

        $short = implode("\n", $shortParts);

        $redirectUrl = '';
        $noticeType = '';
        $noticeMessage = '';

        if ($isSuccess) {
            $transaction_id = $paymentId ?: ($bankRef ?: '');
            $order->payment_complete($transaction_id);

            $order->add_order_note(__('MorPOS: Payment SUCCESSFUL.', 'morpos') . "\n" . $short);
            $order->save();

            $noticeMessage = __('Payment successful.', 'morpos');
            $noticeType = 'success';
            $redirectUrl = $this->get_return_url($order);
        } else {
            $order->update_status('failed', __('MorPOS: Payment FAILED.', 'morpos') . "\n" . $short);
            $order->save();

            $humanMsg = $message ?: __('Payment failed. Please try again.', 'morpos');

            $noticeMessage = __('Payment failed', 'morpos') . ': ' . esc_html($humanMsg);
            $noticeType = 'error';
            $redirectUrl = $form_type === self::FORM_TYPE_EMBEDDED
                ? $order->get_checkout_payment_url(true)
                : wc_get_checkout_url();
        }

        if ($form_type === self::FORM_TYPE_HOSTED) {
            wc_add_notice($noticeMessage, $noticeType);
            wp_safe_redirect($redirectUrl);
            exit;
        }

        $redirectUrl = add_query_arg([
            'notice_type' => $noticeType,
            'notice_message' => urlencode($noticeMessage),
        ], $redirectUrl);

        include MORPOS_GATEWAY_PATH . 'views/morpos-callback.php';
        exit;
    }

    /**
     * Render Requirements table (place this where you output the status pills).
     * Adjust the $targets array to match your policy.
     */
    protected function render_requirements_table()
    {
        $targets = [
            'php' => [
                'required' => WC_MorPOS_Loader::MINIMUM_PHP_VERSION,
                'recommended' => WC_MorPOS_Loader::RECOMMENDED_PHP_VERSION,
            ],
            'wp' => [
                'required' => WC_MorPOS_Loader::MINIMUM_WP_VERSION,
                'recommended' => WC_MorPOS_Loader::RECOMMENDED_WP_VERSION,
            ],
            'wc' => [
                'required' => WC_MorPOS_Loader::MINIMUM_WC_VERSION,
                'recommended' => WC_MorPOS_Loader::RECOMMENDED_WC_VERSION,
            ],
            'tls' => [
                'required' => WC_MorPOS_Loader::MINIMUM_TLS_VERSION,
                'recommended' => WC_MorPOS_Loader::RECOMMENDED_TLS_VERSION,
            ],
        ];

        $current = [
            'php' => PHP_VERSION,
            'wp' => get_bloginfo('version'),
            'wc' => defined('WC_VERSION') ? WC_VERSION : null,
            'tls' => morpos_detect_tls_capability(),
        ];

        $ver_status = function ($cur, $req, $rec) {
            if ($cur === null) {
                return ['class' => 'morpos-danger', 'hint' => __('Not detected', 'morpos')];
            }

            if (version_compare($cur, $req, '<')) {
                return ['class' => 'morpos-danger', 'hint' => __('Below required', 'morpos')];
            }

            if (version_compare($cur, $rec, '<')) {
                return ['class' => 'morpos-warning', 'hint' => __('Allowed but discouraged', 'morpos')];
            }

            return ['class' => 'morpos-ok', 'hint' => __('Meets recommended', 'morpos')];
        };

        $tls_status = function ($current, $required, $recommended) {
            if (!$current || $current['min_tls'] === 'unknown') {
                return ['class' => 'morpos-danger', 'hint' => __('Unable to verify TLS support', 'morpos')];
            }

            if (version_compare($current['min_tls'], $required, '<')) {
                return ['class' => 'morpos-danger', 'hint' => __('Does not meet minimum TLS requirements', 'morpos')];
            }

            if (version_compare($current['min_tls'], $recommended, '<')) {
                return ['class' => 'morpos-warning', 'hint' => __('Works, but TLS 1.3 is recommended', 'morpos')];
            }

            return ['class' => 'morpos-ok', 'hint' => __('Meets recommended', 'morpos')];
        };

        $rows = [
            [
                'label' => __('PHP', 'morpos'),
                'cur' => esc_html($current['php']),
                'req' => esc_html($targets['php']['required'] . '+'),
                'rec' => esc_html($targets['php']['recommended'] . '+'),
                'status' => $ver_status($current['php'], $targets['php']['required'], $targets['php']['recommended']),
            ],
            [
                'label' => __('WordPress', 'morpos'),
                'cur' => esc_html($current['wp']),
                'req' => esc_html($targets['wp']['required'] . '+'),
                'rec' => esc_html($targets['wp']['recommended'] . '+'),
                'status' => $ver_status($current['wp'], $targets['wp']['required'], $targets['wp']['recommended']),
            ],
            [
                'label' => __('WooCommerce', 'morpos'),
                'cur' => esc_html($current['wc'] ?: __('Not installed', 'morpos')),
                'req' => esc_html($targets['wc']['required'] . '+'),
                'rec' => esc_html($targets['wc']['recommended'] . '+'),
                'status' => $ver_status($current['wc'], $targets['wc']['required'], $targets['wc']['recommended']),
            ],
            [
                'label' => __('TLS', 'morpos'),
                'cur' => esc_html($current['tls'] ? $current['tls']['label'] : __('Unknown', 'morpos')),
                'req' => 'TLS ' . esc_html($targets['tls']['required']) . '+',
                'rec' => 'TLS ' . esc_html($targets['tls']['recommended']) . '+',
                'status' => $tls_status($current['tls'], $targets['tls']['required'], $targets['tls']['recommended']),
            ],
        ];

        include MORPOS_GATEWAY_PATH . 'views/morpos-requirements-table.php';
    }
}
