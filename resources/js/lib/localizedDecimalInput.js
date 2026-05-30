/**
 * Sanitiza digitação livre com separador decimal pt-BR (vírgula exibida).
 */
export function sanitizeLocalizedDecimalTyping(raw, maxDecimalPlaces = 4) {
    let s = String(raw ?? '').replace(/[^\d,.]/g, '');
    if (s === '' || s === ',' || s === '.') {
        return s.replace('.', ',');
    }

    const sepIdx = Math.max(s.indexOf(','), s.indexOf('.'));
    if (sepIdx >= 0) {
        const intPart = s.slice(0, sepIdx).replace(/[.,]/g, '');
        const decPart = s.slice(sepIdx + 1).replace(/[.,]/g, '').slice(0, maxDecimalPlaces);
        return `${intPart},${decPart}`;
    }

    return s;
}
