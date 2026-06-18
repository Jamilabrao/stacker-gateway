/** @type {Record<string, string>} ISO 3166-1 alpha-2 */
const LOCALE_COUNTRY = {
    pt_BR: 'BR',
    pt: 'BR',
    en: 'US',
    en_US: 'US',
    en_GB: 'GB',
    es: 'ES',
    es_ES: 'ES',
    es_MX: 'MX',
    fr: 'FR',
    fr_FR: 'FR',
    de: 'DE',
    de_DE: 'DE',
    it: 'IT',
    it_IT: 'IT',
};

/**
 * @param {string|null|undefined} locale
 * @returns {string} ISO country code (2 letters)
 */
export function localeToCountryCode(locale) {
    if (!locale) return '';
    const raw = String(locale).trim().replace(/-/g, '_');
    if (LOCALE_COUNTRY[raw]) return LOCALE_COUNTRY[raw];

    const parts = raw.split('_');
    if (parts.length >= 2 && parts[1].length === 2) {
        return parts[1].toUpperCase();
    }
    if (LOCALE_COUNTRY[parts[0]]) return LOCALE_COUNTRY[parts[0]];

    return parts[0].length === 2 ? parts[0].toUpperCase() : '';
}

/**
 * @param {string|null|undefined} countryCode
 * @returns {string}
 */
export function countryCodeToFlagEmoji(countryCode) {
    const code = String(countryCode || '').toUpperCase();
    if (code.length !== 2 || !/^[A-Z]{2}$/.test(code)) {
        return '🌐';
    }

    return [...code]
        .map((char) => String.fromCodePoint(127397 + char.charCodeAt(0)))
        .join('');
}

/**
 * @param {string|null|undefined} locale
 * @returns {string}
 */
export function localeToFlagEmoji(locale) {
    return countryCodeToFlagEmoji(localeToCountryCode(locale));
}

/**
 * @param {string|null|undefined} locale
 * @returns {string|null}
 */
export function localeFlagSrc(locale) {
    const country = localeToCountryCode(locale).toLowerCase();
    if (country.length !== 2) return null;

    return `/images/flags/${country}.svg`;
}
