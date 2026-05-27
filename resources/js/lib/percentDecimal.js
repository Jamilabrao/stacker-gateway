/**
 * Normaliza percentual para até 4 casas (0–100), alinhado a App\Support\PercentDecimal.
 */
export function normalizePercentInput(value) {
    if (value === null || value === undefined || value === '') {
        return 0;
    }
    const n = parseFloat(String(value).trim().replace(',', '.'));
    if (!Number.isFinite(n)) {
        return 0;
    }
    return Math.round(n * 10000) / 10000;
}

/** Exibe percentual vindo do servidor sem ruído de float. */
export function formatPercentForInput(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return '';
    }
    const rounded = Math.round(n * 10000) / 10000;
    if (Number.isInteger(rounded)) {
        return String(rounded);
    }
    return String(rounded).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
}

/**
 * @param {Record<string, { percent?: unknown, fixed?: unknown }>} rules
 */
export function normalizeMerchantFeeRulesForSubmit(rules) {
    if (!rules || typeof rules !== 'object') {
        return rules;
    }
    const out = {};
    for (const [key, block] of Object.entries(rules)) {
        if (!block || typeof block !== 'object') {
            continue;
        }
        const row = {};
        if (block.percent !== '' && block.percent !== null && block.percent !== undefined) {
            row.percent = normalizePercentInput(block.percent);
        } else {
            row.percent = 0;
        }
        if (block.fixed !== '' && block.fixed !== null && block.fixed !== undefined) {
            const f = parseFloat(String(block.fixed).replace(',', '.'));
            row.fixed = Number.isFinite(f) ? Math.round(f * 100) / 100 : 0;
        } else {
            row.fixed = 0;
        }
        out[key] = row;
    }
    return out;
}

/**
 * Overrides opcionais (infoprodutor): só envia chaves com % ou fixo preenchidos.
 */
export function normalizeMerchantFeeOverridesForSubmit(fees) {
    if (!fees || typeof fees !== 'object') {
        return null;
    }
    const out = {};
    for (const [key, block] of Object.entries(fees)) {
        if (!block || typeof block !== 'object') {
            continue;
        }
        const hasPercent = block.percent !== '' && block.percent !== null && block.percent !== undefined;
        const hasFixed = block.fixed !== '' && block.fixed !== null && block.fixed !== undefined;
        if (!hasPercent && !hasFixed) {
            continue;
        }
        const row = {};
        if (hasPercent) {
            row.percent = normalizePercentInput(block.percent);
        }
        if (hasFixed) {
            const f = parseFloat(String(block.fixed).replace(',', '.'));
            row.fixed = Number.isFinite(f) ? Math.round(f * 100) / 100 : 0;
        }
        if (Object.keys(row).length) {
            out[key] = row;
        }
    }
    return Object.keys(out).length ? out : null;
}
