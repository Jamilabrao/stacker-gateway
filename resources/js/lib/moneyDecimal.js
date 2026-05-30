/**
 * Normaliza valores monetários (2 casas), alinhado a App\Support\MoneyDecimal.
 */
export function normalizeMoneyInput(value) {
    if (value === null || value === undefined || value === '') {
        return 0;
    }
    const n = parseFloat(String(value).trim().replace(/\s/g, '').replace(',', '.'));
    if (!Number.isFinite(n)) {
        return 0;
    }
    return Math.round(n * 100) / 100;
}

/** Exibe valor monetário no input (pt-BR): 1.5 → "1,50". */
export function formatMoneyForDisplayBr(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    return formatPriceForInput(value).replace('.', ',');
}

/** Preço para input: sempre 2 casas decimais, sem ruído de float. */
export function formatPriceForInput(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return '';
    }
    return (Math.round(n * 100) / 100).toFixed(2);
}
