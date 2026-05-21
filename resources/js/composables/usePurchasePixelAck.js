function getCsrfToken() {
    const match = typeof document !== 'undefined' && document.cookie
        ? document.cookie.match(/XSRF-TOKEN=([^;]+)/)
        : null;
    if (!match) return '';
    try {
        return decodeURIComponent(match[1]);
    } catch {
        return match[1];
    }
}

/**
 * Registra no servidor que o browser tentou disparar Purchase (diagnóstico + keepalive antes do redirect).
 */
export function sendPurchasePixelAck({ orderId, checkoutSessionToken = '', token = '', triggerType = 'approved' }) {
    if (!orderId || !checkoutSessionToken) return;

    const url = '/checkout/pixel/purchase-ack';
    const csrf = getCsrfToken();

    if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
        const fd = new FormData();
        fd.append('order_id', String(orderId));
        fd.append('checkout_session_token', checkoutSessionToken);
        if (token) fd.append('token', token);
        fd.append('trigger_type', triggerType);
        if (csrf) fd.append('_token', csrf);
        if (navigator.sendBeacon(url, fd)) {
            return;
        }
    }

    const body = new URLSearchParams();
    body.append('order_id', String(orderId));
    body.append('checkout_session_token', checkoutSessionToken);
    if (token) body.append('token', token);
    body.append('trigger_type', triggerType);
    if (csrf) body.append('_token', csrf);

    try {
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrf ? { 'X-XSRF-TOKEN': csrf } : {}),
            },
            body: body.toString(),
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {});
    } catch (_) {
        /* ignore */
    }
}
