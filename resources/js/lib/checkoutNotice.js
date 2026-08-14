/**
 * Quebra o aviso do checkout em texto, quebras de linha e tokens {termos}/{privacidade}.
 * @param {string} text
 * @returns {Array<{ type: 'text'|'termos'|'privacidade'|'br', value?: string }>}
 */
export function parseCheckoutNotice(text) {
    const src = String(text || '');
    if (!src) return [];

    const parts = [];
    const re = /(\{termos\}|\{privacidade\}|\r\n|\n|\r)/gi;
    let last = 0;
    let match = re.exec(src);
    while (match) {
        if (match.index > last) {
            parts.push({ type: 'text', value: src.slice(last, match.index) });
        }
        const token = String(match[0]).toLowerCase();
        if (token === '{termos}') {
            parts.push({ type: 'termos' });
        } else if (token === '{privacidade}') {
            parts.push({ type: 'privacidade' });
        } else {
            parts.push({ type: 'br' });
        }
        last = match.index + match[0].length;
        match = re.exec(src);
    }
    if (last < src.length) {
        parts.push({ type: 'text', value: src.slice(last) });
    }

    return parts;
}
