/**
 * Permite apenas URLs http/https (bloqueia javascript:, data:, etc.).
 */
export function isAllowedHttpUrl(url) {
    if (url == null || typeof url !== 'string') {
        return false;
    }
    const trimmed = url.trim();
    if (trimmed === '' || trimmed === '#') {
        return false;
    }
    const lower = trimmed.toLowerCase();
    for (const bad of ['javascript:', 'data:', 'vbscript:', 'file:']) {
        if (lower.startsWith(bad)) {
            return false;
        }
    }
    try {
        const parsed = new URL(trimmed);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch {
        return false;
    }
}

export function safeHttpHref(url, fallback = '#') {
    return isAllowedHttpUrl(url) ? String(url).trim() : fallback;
}

export function safeHttpSrc(url) {
    return isAllowedHttpUrl(url) ? String(url).trim() : '';
}
