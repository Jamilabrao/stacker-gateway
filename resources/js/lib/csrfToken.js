/**
 * CSRF token for checkout keepalive/beacon POSTs (meta mirror, purchase ack).
 * Prefers meta tag (always in app.blade.php); falls back to XSRF-TOKEN cookie.
 */
export function getCsrfToken() {
    if (typeof document !== 'undefined') {
        const meta = document.querySelector('meta[name="csrf-token"]');
        const fromMeta = meta?.getAttribute('content')?.trim();
        if (fromMeta) {
            return fromMeta;
        }
    }

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
