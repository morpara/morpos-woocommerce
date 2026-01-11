/**
 * MorPOS Callback Page Script
 */
(function () {
    'use strict';

    // Data is passed via wp_localize_script as morposCallbackData
    if (typeof morposCallbackData === 'undefined') {
        return;
    }

    if (window.parent && window.parent !== window) {
        window.parent.postMessage({
            type: 'MORPOS_RESULT',
            status: morposCallbackData.status,
            redirect_url: morposCallbackData.redirect_url,
            order_id: morposCallbackData.order_id,
            order_status: morposCallbackData.order_status,
        }, window.location.origin);
    } else {
        window.location.href = morposCallbackData.redirect_url;
    }
})();
