/**
 * MorPOS Receipt Page Script
 */
(function () {
    'use strict';

    // Data is passed via wp_localize_script as morposReceiptData
    if (typeof morposReceiptData === 'undefined') {
        return;
    }

    var frame = document.getElementById('morpos-frame');
    var html = morposReceiptData.html || '';
    var url = morposReceiptData.url || '';
    var fallbackUrl = morposReceiptData.fallbackUrl || '';
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
        var cleanup = function () {
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
            var redirectUrl = e.data.redirect_url || fallbackUrl;
            window.location.href = redirectUrl;
        }
    });
})();
