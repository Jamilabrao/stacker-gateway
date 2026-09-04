import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export { getVideoProviderType, videoEmbedUrl } from '@/lib/memberVideoEmbed';

export function cn(...inputs) {
    return twMerge(clsx(inputs));
}

/**
 * Formata valor monetário em formato compacto (K = mil, M = milhão).
 * Ex: 1300 → 1.3K, 10000 → 10K, 1500000 → 1.5M
 * @param {number} value - Valor em reais
 * @returns {string}
 */
export function formatCompactCurrency(value) {
    const n = Number(value) || 0;
    if (n >= 1_000_000) {
        const m = n / 1_000_000;
        return (m % 1 === 0 ? m : m.toFixed(1)) + 'M';
    }
    if (n >= 1_000) {
        const k = n / 1_000;
        return (k % 1 === 0 ? k : k.toFixed(1)) + 'K';
    }
    return String(Math.round(n));
}

/**
 * Formata texto de descrição de aula: preserva quebras de linha e transforma URLs em links.
 * Escapa HTML para evitar XSS.
 * @param {string} text - Texto puro (pode conter \n e URLs)
 * @returns {string} HTML seguro para usar com v-html
 */
export function formatLessonDescription(text) {
    if (text == null || typeof text !== 'string') return '';
    let s = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    s = s.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    s = s.replace(/\n/g, '<br>\n');
    const urlRegex = /(https?:\/\/[^\s<>"']+)|(www\.[^\s<>"']+\.[^\s<>"']+)/gi;
    s = s.replace(urlRegex, (match) => {
        const href = match.startsWith('www.') ? `https://${match}` : match;
        const cleanHref = href.replace(/[.,;:!?)]+$/, '');
        return `<a href="${cleanHref.replace(/"/g, '&quot;')}" target="_blank" rel="noopener noreferrer" class="text-[var(--ma-primary)] hover:underline">${match}</a>`;
    });
    return s;
}
