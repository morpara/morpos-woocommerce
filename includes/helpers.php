<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retrieve a value from an array with a default fallback.
 *
 * @param array $arr
 * @param string|int $key
 * @param mixed $default
 * @return mixed
 */
function morpos_array_get($arr, $key, $default = null)
{
    return isset($arr[$key]) ? $arr[$key] : $default;
}

/**
 * Generate a unique conversation ID.
 *
 * @return string
 */
function morpos_generate_conversation_id(): string
{
    return 'MSD' . wp_rand(10000000000000000, 99999999999999999);
}

/**
 * Signs the given fields using SHA-256 and Base64 encoding.
 *
 * @param array $fields
 * @return string
 */
function morpos_sign(array $fields): string
{
    $concatenated = implode(';', array_values($fields));
    $shaBin = hash('sha256', $concatenated, true);
    $b64 = base64_encode($shaBin);
    return strtoupper($b64);
}

/**
 * Detects the TLS capability of the server environment.
 *
 * @return array
 */
function morpos_detect_tls_capability(): array
{
    $openssl_text = defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null;
    $openssl_num = defined('OPENSSL_VERSION_NUMBER') ? OPENSSL_VERSION_NUMBER : null;
    $curl_info = function_exists('curl_version') ? curl_version() : null;

    // Derive minimal TLS supported via OpenSSL version heuristic
    // - < 1.0.1  → effectively no TLS 1.2
    // - 1.0.1+   → TLS 1.2 available
    // - 1.1.1+   → TLS 1.3 available (if backend supports)
    $min_tls = 'unknown';
    if ($openssl_num) {
        if ($openssl_num < 0x1000100f) {         // < 1.0.1
            $min_tls = '1.0';
        } elseif ($openssl_num < 0x1010100f) {   // < 1.1.1
            $min_tls = '1.2';
        } else {
            $min_tls = '1.3';
        }
    }

    $label_parts = [];
    if ($openssl_text) {
        $label_parts[] = $openssl_text;
        if ($min_tls !== 'unknown') {
            $label_parts[] = sprintf('(TLS %s)', $min_tls);
        }
    } elseif ($curl_info && !empty($curl_info['ssl_version'])) {
        $label_parts[] = $curl_info['ssl_version'];
    } elseif ($curl_info && ($curl_info['features'] & CURL_VERSION_SSL)) {
        $label_parts[] = __('SSL/TLS available (version unknown)', 'morpos-for-woocommerce');
    } else {
        $label_parts[] = __('No SSL/TLS detected', 'morpos-for-woocommerce');
    }

    return [
        'label' => implode(' ', $label_parts),
        'min_tls' => $min_tls,
    ];
}

/**
 * Encode binary using Crockford Base32 (no padding), uppercase.
 *
 * @param string $bin Raw binary.
 * @return string Base32 string (A-Z, 0-9 without I,L,O,U).
 */
function morpos_b32_encode(string $bin): string
{
    static $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // no I, L, O, U
    $bits = '';
    $len = strlen($bin);

    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    }

    $out = '';
    for ($i = 0, $bl = strlen($bits); $i < $bl; $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0');
        }

        $out .= $alphabet[bindec($chunk)];
    }

    return $out;
}

/**
 * Compute a 20-char conversation id for (order_id, attempt_seq).
 * Deterministic for the same inputs.
 *
 * @param int $order_id Woo order ID.
 * @param int $attempt_seq 0-based attempt number.
 * @return string 20-char token.
 */
function morpos_make_conversation_id20_for_attempt(int $order_id, int $attempt_seq): string
{
    $message = 'morpos:v1:' . $order_id . ':' . $attempt_seq;
    $mac = hash_hmac('sha256', $message, MORPOS_CONVERSATION_KEY, true); // raw
    return morpos_b32_encode(substr($mac, 0, 12)); // 96 bits -> 20 chars
}

/**
 * Retrieve a WC_Order object from an order or ID.
 *
 * @param WC_Order|int $orderOrID Order or ID.
 * @return WC_Order|null Order object or null if not found.
 */
function morpos_get_order($orderOrID): ?WC_Order
{
    if ($orderOrID instanceof WC_Order) {
        return $orderOrID;
    }

    return wc_get_order((int) $orderOrID);
}

/**
 * Get the current attempt sequence for an order (defaults to 0 if missing).
 *
 * @param WC_Order|int $orderOrID Order or ID.
 * @return int Current attempt sequence (0-based).
 */
function morpos_get_attempt_seq($orderOrID): int
{
    $order = morpos_get_order($orderOrID);
    if (!$order) {
        return 0;
    }

    $seq = (int) $order->get_meta('_morpos_attempt_seq');

    return max(0, $seq);
}

/**
 * Generate a NEW unique conversation id by incrementing the attempt counter.
 *
 * @param WC_Order $order Woo order object.
 * @return string New 20-char conversation id (also stored on order).
 */
function morpos_start_new_attempt(WC_Order $order): string
{
    $seq = morpos_get_attempt_seq($order) + 1; // bump attempt seq
    $order_id = (int) $order->get_id();
    $token = morpos_make_conversation_id20_for_attempt($order_id, $seq);

    // Load history, append, persist.
    $history = $order->get_meta('_morpos_conversation_ids');
    if (!is_array($history)) {
        $history = [];
    }

    $history[] = $token;

    $order->update_meta_data('_morpos_attempt_seq', $seq);
    $order->update_meta_data('_morpos_conversation_ids', array_values(array_unique($history)));
    $order->update_meta_data('_morpos_conversation_id20', $token); // convenience: latest
    $order->save();

    return $token;
}

/**
 * Get the latest (most recent) conversation id for the order, if any.
 *
 * @param WC_Order|int $order Order or ID.
 * @return string|null Latest 20-char token or null if none.
 */
function morpos_get_latest_conversation_id20($orderOrID): ?string
{
    $order = morpos_get_order($orderOrID);
    if (!$order) {
        return null;
    }

    $cid = $order->get_meta('_morpos_conversation_id20');
    if (!is_string($cid) || strlen($cid) !== 20) {
        return null;
    }

    return $cid;
}

/**
 * Check whether a provided token matches ANY known attempt for this order.
 *
 * @param WC_Order|int $orderOrID Order or ID.
 * @param string $provided 20-char token (case-insensitive).
 * @return bool True if token exists in history for this order.
 */
function morpos_validate_conversation_id20_any($orderOrID, string $provided): bool
{
    if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{20}$/i', $provided)) {
        return false;
    }

    $order = morpos_get_order($orderOrID);
    if (!$order) {
        return false;
    }

    $history = $order->get_meta('_morpos_conversation_ids');
    if (!is_array($history) || empty($history)) {
        return false;
    }

    $provided = strtoupper($provided);
    foreach ($history as $token) {
        if (is_string($token) && hash_equals(strtoupper($token), $provided)) {
            return true;
        }
    }

    return false;
}

/**
 * Add a WooCommerce notice and safely redirect.
 *
 * Ensures the WC session is properly initialized and saved before redirect,
 * which is critical when called from WC API endpoints where the session
 * might not be fully initialized.
 *
 * @param string $message Notice message.
 * @param string $type    Notice type: 'success', 'error', or 'notice'.
 * @param string $url     Redirect URL.
 * @return never
 */
function morpos_add_notice_and_redirect(string $message, string $type, string $url): void
{
    // Ensure WC session is initialized and will persist the notice
    if (function_exists('WC') && WC()->session && !WC()->session->has_session()) {
        WC()->session->set_customer_session_cookie(true);
    }

    wc_add_notice($message, $type);

    // Force session to save before redirect
    if (function_exists('WC') && WC()->session) {
        WC()->session->save_data();
    }

    wp_safe_redirect($url);
    exit;
}
