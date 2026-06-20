const FBC_STORAGE_KEY = 'getfy_meta_fbclid';

function getCookie(name) {
    if (typeof document === 'undefined') return null;
    const escaped = String(name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const m = document.cookie ? document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)')) : null;
    if (!m) return null;
    try {
        return decodeURIComponent(m[1]);
    } catch {
        return m[1];
    }
}

function setCookie(name, value, maxAgeSeconds = 7776000) {
    if (typeof document === 'undefined') return;
    const secure = typeof window !== 'undefined' && window.location?.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAgeSeconds}; SameSite=Lax${secure}`;
}

export function getFbp() {
    return getCookie('_fbp');
}

export function getFbc() {
    return getCookie('_fbc');
}

export function getFbclidFromUrl() {
    if (typeof window === 'undefined') return null;
    const fbclid = new URLSearchParams(window.location.search).get('fbclid');
    return fbclid && String(fbclid).trim() !== '' ? String(fbclid).trim() : null;
}

/**
 * Meta standard: create _fbc from fbclid when landing from ads.
 */
export function ensureFbcFromFbclid() {
    const existing = getFbc();
    if (existing) return existing;

    const fbclid = getFbclidFromUrl();
    if (!fbclid) {
        try {
            const stored = sessionStorage.getItem(FBC_STORAGE_KEY);
            if (stored) {
                setCookie('_fbc', stored);
                return stored;
            }
        } catch {
            /* ignore */
        }
        return null;
    }

    const fbc = `fb.1.${Date.now()}.${fbclid}`;
    setCookie('_fbc', fbc);
    try {
        sessionStorage.setItem(FBC_STORAGE_KEY, fbc);
    } catch {
        /* ignore */
    }

    return fbc;
}

export function getAttributionPayload() {
    ensureFbcFromFbclid();

    return {
        fbp: getFbp() || undefined,
        fbc: getFbc() || undefined,
        user_agent: typeof navigator !== 'undefined' ? navigator.userAgent || undefined : undefined,
        event_source_url: typeof window !== 'undefined' ? window.location.href : undefined,
    };
}
