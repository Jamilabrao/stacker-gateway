/**
 * Destino de hard-navigate quando a sessão/CSRF expirou (HTTP 419).
 * Evita window.location.reload() silencioso, que parece "login não funcionou".
 */
export function resolveLoginPathForExpiredSession(pathname = '/', search = '') {
    const path = typeof pathname === 'string' && pathname !== '' ? pathname : '/';

    if (path.startsWith('/plataforma')) {
        return '/plataforma/login?expired=1';
    }

    const memberMatch = path.match(/^\/m\/([a-zA-Z0-9]{6,16})(?:\/|$)/);
    if (memberMatch) {
        return `/m/${memberMatch[1]}/login?expired=1`;
    }

    if (/\/login\/?$/.test(path) || path === '/login') {
        const params = new URLSearchParams(typeof search === 'string' ? search.replace(/^\?/, '') : '');
        params.set('expired', '1');
        params.set('_', String(Date.now()));
        const qs = params.toString();
        return `${path.split('?')[0]}?${qs}`;
    }

    return '/login?expired=1';
}
