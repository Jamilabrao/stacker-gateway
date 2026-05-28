import { ref, computed, watch, isRef } from 'vue';

/** Preferência explícita do comprador (não grava mais sugestão automática nas chaves antigas). */
const LOCALE_MANUAL_KEY = 'checkout_locale_v2';
const CURRENCY_MANUAL_KEY = 'checkout_currency_v2';

const SUPPORTED_LOCALES = ['pt_BR', 'en', 'es'];

/** Regiões ISO (language tag) que usamos para EUR no fallback do navegador. */
const EUR_REGIONS = new Set([
    'AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK',
]);

function u(v) {
    return isRef(v) ? v.value : v;
}

function readLs(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeLs(key, val) {
    try {
        if (val) localStorage.setItem(key, val);
    } catch (_) {}
}

function normalizeLocale(s) {
    const v = String(s || '').trim();
    return SUPPORTED_LOCALES.includes(v) ? v : 'pt_BR';
}

/** Quando o servidor não tem país (localhost, falha de GeoIP), infere idioma pelo navegador. */
function inferLocaleFromNavigator() {
    if (typeof navigator === 'undefined') return null;
    const raw = (navigator.language || '').trim();
    if (!raw) return null;
    const lower = raw.toLowerCase();
    if (lower.startsWith('pt')) return 'pt_BR';
    if (lower.startsWith('es')) return 'es';
    if (lower.startsWith('en')) return 'en';
    return null;
}

function normalizeCountryCode(value) {
    const v = String(value || '').trim().toUpperCase();
    return /^[A-Z]{2}$/.test(v) ? v : null;
}

function inferCountryFromNavigator() {
    if (typeof navigator === 'undefined') return null;
    const raw = String(navigator.language || '').trim();
    if (!raw) return null;
    const parts = raw.split('-');
    if (parts.length < 2) return null;
    return normalizeCountryCode(parts[1]);
}

function inferLocaleFromCountry(country) {
    const code = normalizeCountryCode(country);
    if (!code) return null;
    if (code === 'BR') return 'pt_BR';
    if (['ES', 'MX', 'AR', 'CO', 'CL', 'PE'].includes(code)) return 'es';
    return 'en';
}

function inferCurrencyFromCountry(country) {
    const code = normalizeCountryCode(country);
    if (!code) return null;
    if (code === 'BR') return 'BRL';
    if (EUR_REGIONS.has(code)) return 'EUR';
    return 'USD';
}

/** Fallback de moeda pelo `navigator.language` (ex.: pt-BR → BRL, de-DE → EUR). */
function inferCurrencyFromNavigator() {
    if (typeof navigator === 'undefined') return null;
    const tag = (navigator.language || '').trim();
    const m = tag.match(/^([a-z]{2})-([A-Z]{2})/i);
    const lang = (m?.[1] || tag.slice(0, 2)).toLowerCase();
    const region = (m?.[2] || '').toUpperCase();
    if (region === 'BR' || lang === 'pt') return 'BRL';
    if (region && EUR_REGIONS.has(region)) return 'EUR';
    if (region) return 'USD';
    if (lang === 'es' || lang === 'en') return 'USD';
    return null;
}

/**
 * @param {Object} options
 * @param {Record<string, Record<string, string>>|import('vue').Ref} options.translations
 * @param {Array<{ code: string, symbol: string, label: string, rate_to_brl: number }>|import('vue').Ref} options.currencies
 * @param {string|import('vue').Ref} [options.suggestedLocale]
 * @param {string|import('vue').Ref} [options.suggestedCurrency]
 * @param {string|null|import('vue').Ref} [options.suggestedCountryCode]
 * @param {string} [options.storageKey]
 */
export function useCheckoutLocale(options = {}) {
    const {
        translations = {},
        currencies = [],
        suggestedLocale = 'pt_BR',
        suggestedCurrency = 'BRL',
        suggestedCountryCode = null,
        storageKey = 'default',
    } = options;

    const sk = storageKey || 'default';
    const manualLocaleKey = `${LOCALE_MANUAL_KEY}_${sk}`;
    const manualCurrencyKey = `${CURRENCY_MANUAL_KEY}_${sk}`;

    function currencyCodes() {
        const arr = Array.isArray(u(currencies)) ? u(currencies) : [];
        return arr.map((c) => c?.code).filter(Boolean);
    }

    function pickCurrencyCode(suggested) {
        const codes = currencyCodes();
        if (!codes.length) return 'BRL';
        const s = String(suggested || 'BRL').trim().toUpperCase();
        if (codes.includes(s)) return s;
        if (codes.includes('BRL')) return 'BRL';
        return codes[0];
    }

    function readManualLocale() {
        const m = readLs(manualLocaleKey);
        return m && SUPPORTED_LOCALES.includes(m) ? m : null;
    }

    function readManualCurrency() {
        const codes = currencyCodes();
        const m = readLs(manualCurrencyKey);
        return m && codes.includes(m) ? m : null;
    }

    const userChoseLocale = ref(!!readManualLocale());
    const userChoseCurrency = ref(!!readManualCurrency());

    function effectiveSuggestedLocale() {
        const server = normalizeLocale(u(suggestedLocale));
        const country = normalizeCountryCode(u(suggestedCountryCode)) || inferCountryFromNavigator();
        return inferLocaleFromCountry(country) || inferLocaleFromNavigator() || server;
    }

    function effectiveSuggestedCurrency() {
        const server = String(u(suggestedCurrency) || 'BRL').trim().toUpperCase();
        const country = normalizeCountryCode(u(suggestedCountryCode)) || inferCountryFromNavigator();
        return pickCurrencyCode(inferCurrencyFromCountry(country) || inferCurrencyFromNavigator() || server);
    }

    function resolveLocale() {
        if (userChoseLocale.value) {
            const m = readManualLocale();
            return m ? normalizeLocale(m) : normalizeLocale(effectiveSuggestedLocale());
        }
        return normalizeLocale(effectiveSuggestedLocale());
    }

    function resolveCurrency() {
        if (userChoseCurrency.value) {
            const m = readManualCurrency();
            return m ? pickCurrencyCode(m) : pickCurrencyCode(effectiveSuggestedCurrency());
        }
        return pickCurrencyCode(effectiveSuggestedCurrency());
    }

    const locale = ref(resolveLocale());
    const currency = ref(resolveCurrency());

    function syncFromSuggestions() {
        if (!userChoseLocale.value) {
            locale.value = normalizeLocale(effectiveSuggestedLocale());
        }
        if (!userChoseCurrency.value) {
            currency.value = pickCurrencyCode(effectiveSuggestedCurrency());
        }
    }

    watch(
        () => [
            u(suggestedLocale),
            u(suggestedCurrency),
            u(suggestedCountryCode),
            currencyCodes().join(','),
        ],
        () => {
            syncFromSuggestions();
        }
    );

    function setLocale(v) {
        if (!SUPPORTED_LOCALES.includes(v)) return;
        userChoseLocale.value = true;
        locale.value = v;
        writeLs(manualLocaleKey, v);
    }

    function setCurrency(v) {
        const codes = currencyCodes();
        if (!codes.includes(v)) return;
        userChoseCurrency.value = true;
        currency.value = v;
        writeLs(manualCurrencyKey, v);
    }

    function t(key) {
        const loc = locale.value || 'pt_BR';
        const pool = u(translations);
        const byLocale = pool[loc] || pool.pt_BR || {};
        return byLocale[key] != null ? byLocale[key] : key;
    }

    const currencyList = computed(() => (Array.isArray(u(currencies)) ? u(currencies) : []));

    const currentCurrencyObj = computed(
        () =>
            currencyList.value.find((c) => c.code === currency.value) ||
            currencyList.value[0] || { code: 'BRL', symbol: 'R$', label: 'Real', rate_to_brl: 1 }
    );

    /** Converte preço em BRL para a moeda selecionada (price_brl * rate_to_brl). */
    function priceInCurrency(priceBrl) {
        const n = Number(priceBrl);
        if (Number.isNaN(n)) return 0;
        const obj = currentCurrencyObj.value;
        const rate = Number(obj.rate_to_brl) || 1;
        return Math.round(n * rate * 100) / 100;
    }

    function formatPrice(value, currencyCode) {
        const code = currencyCode || currency.value || 'BRL';
        const localeForFormat = code === 'BRL' ? 'pt-BR' : code === 'EUR' ? 'de-DE' : 'en-US';
        return new Intl.NumberFormat(localeForFormat, {
            style: 'currency',
            currency: code,
        }).format(value);
    }

    return {
        locale,
        setLocale,
        currency,
        setCurrency,
        t,
        currencies: currencyList,
        currentCurrencyObj,
        priceInCurrency,
        formatPrice,
        supportedLocales: SUPPORTED_LOCALES,
    };
}
