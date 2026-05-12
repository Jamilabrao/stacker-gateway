import DOMPurify from 'dompurify';

/**
 * Converte HTML (possivelmente vindo do backend) em texto puro.
 * Útil para labels de paginação do Laravel (ex.: &laquo;).
 */
export function htmlToText(html) {
    if (html == null) return '';
    const s = String(html);
    if (!s) return '';
    try {
        const doc = new DOMParser().parseFromString(s, 'text/html');
        return (doc.documentElement.textContent || '').trim();
    } catch {
        return s.replace(/<[^>]*>/g, '').trim();
    }
}

/**
 * Sanitiza HTML para uso com v-html. Padrão: allowlist conservadora.
 * Não permite handlers on* nem javascript: (DOMPurify remove).
 */
export function sanitizeHtmlAllowlist(html, extra = {}) {
    if (html == null) return '';
    const s = String(html);
    if (!s) return '';

    return DOMPurify.sanitize(s, {
        USE_PROFILES: { html: true },
        ALLOWED_TAGS: [
            'a',
            'b',
            'strong',
            'i',
            'em',
            'u',
            'p',
            'br',
            'ul',
            'ol',
            'li',
            'blockquote',
            'code',
            'pre',
            'span',
            'div',
            'hr',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'table',
            'thead',
            'tbody',
            'tr',
            'th',
            'td',
            'img',
        ],
        ALLOWED_ATTR: [
            'href',
            'target',
            'rel',
            'title',
            'class',
            'style',
            'alt',
            'src',
            'width',
            'height',
        ],
        ALLOW_DATA_ATTR: false,
        ...extra,
    });
}

