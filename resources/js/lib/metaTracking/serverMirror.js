import { getCsrfToken } from '@/lib/csrfToken.js';

const MAX_EVENT_SOURCE_URL_LENGTH = 2048;

function truncateEventSourceUrl(url) {
    if (!url || typeof url !== 'string') return undefined;
    const trimmed = url.trim();
    if (trimmed === '') return undefined;
    return trimmed.length <= MAX_EVENT_SOURCE_URL_LENGTH
        ? trimmed
        : trimmed.slice(0, MAX_EVENT_SOURCE_URL_LENGTH);
}

/**
 * Mirror browser Meta events to server-side CAPI queue.
 */
export function mirrorMetaEventToServer({
    checkoutSessionToken,
    eventName,
    eventId,
    fbp,
    fbc,
    userAgent,
    eventSourceUrl,
    value,
    currency,
    contentIds,
    contentName,
}) {
    if (!checkoutSessionToken || !eventName || !eventId) return;

    const url = '/checkout/pixel/events';
    const csrf = getCsrfToken();
    const normalizedEventSourceUrl = truncateEventSourceUrl(eventSourceUrl);

    const payload = {
        checkout_session_token: checkoutSessionToken,
        event_name: eventName,
        event_id: eventId,
    };

    if (fbp) payload.fbp = fbp;
    if (fbc) payload.fbc = fbc;
    if (userAgent) payload.user_agent = userAgent;
    if (normalizedEventSourceUrl) payload.event_source_url = normalizedEventSourceUrl;
    if (value != null && !Number.isNaN(Number(value))) payload.value = Number(value);
    if (currency) payload.currency = currency;
    if (Array.isArray(contentIds) && contentIds.length) payload.content_ids = contentIds;
    if (contentName) payload.content_name = contentName;

    if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
        const fd = new FormData();
        Object.entries(payload).forEach(([k, v]) => {
            if (Array.isArray(v)) {
                v.forEach((item) => fd.append(`${k}[]`, String(item)));
            } else {
                fd.append(k, String(v));
            }
        });
        if (csrf) fd.append('_token', csrf);
        if (navigator.sendBeacon(url, fd)) {
            return;
        }
    }

    const body = new URLSearchParams();
    Object.entries(payload).forEach(([k, v]) => {
        if (Array.isArray(v)) {
            v.forEach((item) => body.append(`${k}[]`, String(item)));
        } else {
            body.append(k, String(v));
        }
    });
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
    } catch {
        /* ignore */
    }
}
