/**
 * Beacon leve do tracking interno — paralelo à UTMify/Meta/pixels.
 * Nunca bloqueia o checkout: usa sendBeacon / fetch keepalive e engole erros.
 */
const COOKIE_SESSION = 'gf_msid';
const COOKIE_VISITOR = 'gf_vid';

function readCookie(name) {
    if (typeof document === 'undefined') return null;
    const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
}

function writeCookie(name, value, days = 30) {
    if (typeof document === 'undefined') return;
    const maxAge = days * 86400;
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; samesite=lax`;
}

function uuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

function ensureIds() {
    let sessionKey = readCookie(COOKIE_SESSION);
    let visitorKey = readCookie(COOKIE_VISITOR);
    if (!sessionKey) {
        sessionKey = uuid();
        writeCookie(COOKIE_SESSION, sessionKey);
    }
    if (!visitorKey) {
        visitorKey = uuid();
        writeCookie(COOKIE_VISITOR, visitorKey);
    }
    return { sessionKey, visitorKey };
}

function trackingFromUrl(search = typeof window !== 'undefined' ? window.location.search : '') {
    const params = new URLSearchParams(search);
    const keys = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'fbclid', 'gclid', 'ttclid', 'src', 'sck', 'subid', 'subid2', 'subid3',
        'ref', 'campaign', 'campaign_code',
    ];
    const out = {};
    keys.forEach((k) => {
        const v = params.get(k);
        if (v) out[k] = v;
    });
    return out;
}

/**
 * @param {object} payload
 */
export function trackMetricsEvent(payload = {}) {
    try {
        if (typeof window === 'undefined') return;
        const { sessionKey, visitorKey } = ensureIds();
        const body = {
            event_name: payload.event_name || 'page_view',
            event_id: payload.event_id || uuid(),
            session_key: payload.session_key || sessionKey,
            visitor_key: payload.visitor_key || visitorKey,
            product_id: payload.product_id || undefined,
            tenant_id: payload.tenant_id || undefined,
            offer_id: payload.offer_id || undefined,
            plan_id: payload.plan_id || undefined,
            checkout_session_id: payload.checkout_session_id || undefined,
            affiliate_ref: payload.affiliate_ref || undefined,
            destination_url: payload.destination_url || window.location.href,
            referrer: payload.referrer || document.referrer || undefined,
            tracking: { ...trackingFromUrl(), ...(payload.tracking || {}) },
            properties: payload.properties || undefined,
        };

        const json = JSON.stringify(body);
        const url = '/api/metrics/collect';

        if (navigator.sendBeacon) {
            const blob = new Blob([json], { type: 'application/json' });
            navigator.sendBeacon(url, blob);
            return;
        }

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: json,
            keepalive: true,
            credentials: 'same-origin',
        }).catch(() => {});
    } catch (_) {
        // never break checkout
    }
}

export function getMetricsSessionKey() {
    return ensureIds().sessionKey;
}
