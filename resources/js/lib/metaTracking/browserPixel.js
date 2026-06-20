const META_FBEvents_SRC = 'https://connect.facebook.net/en_US/fbevents.js';
const META_FBEvents_SCRIPT_ID = 'getfy-meta-fbevents';

let metaLibLoadPromise = null;
let metaLibReady = false;
const metaInitedPixelIds = new Set();

function isValidPixelId(id) {
    if (typeof id !== 'string' || id.length > 64) return false;
    return /^[a-zA-Z0-9_-]+$/.test(id);
}

export function normalizeMetaPixelId(raw) {
    const digits = String(raw ?? '').replace(/\D/g, '');
    if (digits.length < 5 || digits.length > 20) return '';
    return digits;
}

export function getMetaEntries(pixels) {
    const m = pixels?.meta;
    if (!m?.enabled) return [];
    if (Array.isArray(m.entries)) {
        return m.entries
            .map((e) => {
                if (!e || typeof e !== 'object') return null;
                const pixelId = normalizeMetaPixelId(e.pixel_id);
                if (!pixelId || !isValidPixelId(pixelId)) return null;
                return { ...e, pixel_id: pixelId };
            })
            .filter(Boolean);
    }
    if (m.pixel_id) {
        const pixelId = normalizeMetaPixelId(m.pixel_id);
        if (pixelId && isValidPixelId(pixelId)) {
            return [{ ...m, pixel_id: pixelId }];
        }
    }
    return [];
}

function bootstrapMetaQueue() {
    if (window.fbq) return;
    const n = (window.fbq = function (...args) {
        if (n.callMethod) {
            n.callMethod(...args);
        } else {
            n.queue.push(args);
        }
    });
    if (!window._fbq) window._fbq = n;
    n.push = n;
    n.loaded = true;
    n.version = '2.0';
    n.queue = n.queue || [];
}

function ensureMetaLibLoaded() {
    bootstrapMetaQueue();
    if (metaLibReady) {
        return Promise.resolve();
    }
    if (metaLibLoadPromise) {
        return metaLibLoadPromise;
    }

    metaLibLoadPromise = new Promise((resolve, reject) => {
        let script = document.getElementById(META_FBEvents_SCRIPT_ID);
        const finish = () => {
            metaLibReady = true;
            resolve();
        };
        const fail = () => reject(new Error('meta_fbevents_load_failed'));

        if (!script) {
            script = document.createElement('script');
            script.id = META_FBEvents_SCRIPT_ID;
            script.async = true;
            script.src = META_FBEvents_SRC;
            script.onload = finish;
            script.onerror = fail;
            document.head.appendChild(script);
            return;
        }

        if (script.getAttribute('data-loaded') === '1') {
            finish();
            return;
        }

        script.addEventListener(
            'load',
            () => {
                script.setAttribute('data-loaded', '1');
                finish();
            },
            { once: true }
        );
        script.addEventListener('error', fail, { once: true });
    }).catch((err) => {
        metaLibLoadPromise = null;
        throw err;
    });

    return metaLibLoadPromise;
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitForMetaPixelInit(metaEntries, maxMs = 4200) {
    const ids = metaEntries.map((e) => String(e.pixel_id).trim()).filter((id) => id && isValidPixelId(id));
    if (!ids.length) return false;
    const startedAt = Date.now();
    while (Date.now() - startedAt < maxMs) {
        if (typeof window.fbq === 'function' && ids.every((id) => metaInitedPixelIds.has(id))) return true;
        await sleep(60);
    }
    return typeof window.fbq === 'function' && ids.every((id) => metaInitedPixelIds.has(id));
}

/**
 * Load fbevents.js and init all Meta pixel IDs.
 */
export async function initMetaPixels(metaEntries) {
    const ids = metaEntries.map((e) => String(e.pixel_id).trim()).filter((id) => id && isValidPixelId(id));
    if (!ids.length) return false;

    await ensureMetaLibLoaded().catch(() => {});

    if (typeof window.fbq !== 'function') {
        return false;
    }

    ids.forEach((id) => {
        if (!metaInitedPixelIds.has(id)) {
            window.fbq('init', id);
            metaInitedPixelIds.add(id);
        }
    });

    await waitForMetaPixelInit(metaEntries, 4200);

    return typeof window.fbq === 'function';
}

export function trackMetaEvent(eventName, payload = {}, eventId = undefined) {
    if (typeof window.fbq !== 'function') return false;
    const options = eventId ? { eventID: eventId } : undefined;
    window.fbq('track', eventName, payload, options);
    return true;
}

export function trackPageView(eventId = undefined) {
    if (typeof window.fbq !== 'function') return false;
    const options = eventId ? { eventID: eventId } : undefined;
    window.fbq('track', 'PageView', {}, options);
    return true;
}

export function buildCheckoutEventPayload(value, currency, contentKey) {
    const num = Number(value);
    const normalizedCurrency = typeof currency === 'string' && currency.trim() ? currency.trim().toUpperCase() : 'BRL';
    const key = (contentKey || '').trim();

    return {
        value: Number.isFinite(num) ? num : 0,
        currency: normalizedCurrency,
        content_type: 'product',
        num_items: 1,
        content_ids: key ? [key] : [],
        contents: key ? [{ id: key, quantity: 1 }] : [],
    };
}

export function buildPurchaseEventPayload(value, currency, orderId) {
    const num = Number(value);
    const normalizedCurrency = typeof currency === 'string' && currency.trim() ? currency.trim().toUpperCase() : 'BRL';
    const oid = orderId ? String(orderId) : '';

    return {
        value: Number.isFinite(num) ? num : 0,
        currency: normalizedCurrency,
        content_type: 'product',
        num_items: 1,
        content_ids: oid ? [oid] : [],
        contents: oid ? [{ id: oid, quantity: 1 }] : [],
    };
}

export { getMetaEntries as extractMetaEntries, metaInitedPixelIds };
