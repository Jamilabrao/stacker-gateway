/**
 * Copia texto para a área de transferência (HTTPS + fallback para HTTP/PWA).
 */
export async function copyTextToClipboard(text) {
    const value = String(text ?? '').trim();
    if (value === '') {
        return false;
    }

    if (typeof navigator !== 'undefined' && navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(value);
            return true;
        } catch {
            // fallback abaixo
        }
    }

    try {
        const el = document.createElement('textarea');
        el.value = value;
        el.setAttribute('readonly', '');
        el.style.position = 'fixed';
        el.style.top = '0';
        el.style.left = '0';
        el.style.width = '2em';
        el.style.height = '2em';
        el.style.padding = '0';
        el.style.border = 'none';
        el.style.outline = 'none';
        el.style.opacity = '0';
        document.body.appendChild(el);
        el.focus();
        el.select();
        el.setSelectionRange(0, value.length);
        const ok = document.execCommand('copy');
        document.body.removeChild(el);
        return ok;
    } catch {
        return false;
    }
}
