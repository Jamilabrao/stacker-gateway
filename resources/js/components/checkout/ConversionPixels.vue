<script setup>
import { onMounted, watch } from 'vue';
import {
    getMetaEntries,
    initMetaPixels,
    trackMetaEvent,
    buildCheckoutEventPayload,
    buildPurchaseEventPayload,
} from '@/lib/metaTracking/browserPixel.js';
import { fireMetaPurchaseReliable } from '@/composables/useMetaCheckoutTracking.js';

const props = defineProps({
    pixels: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['ready', 'meta-ready']);

/** Evita reinicializar quando props.pixels oscila com o mesmo conteúdo. */
let lastPixelsFingerprint = '';

let gtagExternalScriptInserted = false;
const gtagConfiguredIds = new Set();
const tiktokLoadedPixelIds = new Set();

/** Permite apenas IDs alfanuméricos, hífen e underscore para evitar XSS. */
function isValidPixelId(id) {
    if (typeof id !== 'string' || id.length > 64) return false;
    return /^[a-zA-Z0-9_-]+$/.test(id);
}

function fingerprintPixels(pixels) {
    try {
        return JSON.stringify({
            meta: pixels?.meta,
            tiktok: pixels?.tiktok,
            google_ads: pixels?.google_ads,
            google_analytics: pixels?.google_analytics,
            custom_script: (pixels?.custom_script ?? []).map((x) => x?.id),
        });
    } catch {
        return '';
    }
}

function getTiktokEntries(p) {
    const m = p?.tiktok;
    if (!m?.enabled) return [];
    if (Array.isArray(m.entries)) {
        return m.entries.filter((e) => e && isValidPixelId(String(e.pixel_id || '').trim()));
    }
    if (m.pixel_id && isValidPixelId(String(m.pixel_id).trim())) {
        return [m];
    }
    return [];
}

function getGoogleAdsEntries(p) {
    const m = p?.google_ads;
    if (!m?.enabled) return [];
    if (Array.isArray(m.entries)) {
        return m.entries.filter((e) => e && isValidPixelId(String(e.conversion_id || '').trim()));
    }
    if (m.conversion_id && isValidPixelId(String(m.conversion_id).trim())) {
        return [m];
    }
    return [];
}

function getGaEntries(p) {
    const m = p?.google_analytics;
    if (!m?.enabled) return [];
    if (Array.isArray(m.entries)) {
        return m.entries.filter((e) => e && isValidPixelId(String(e.measurement_id || '').trim()));
    }
    if (m.measurement_id && isValidPixelId(String(m.measurement_id).trim())) {
        return [m];
    }
    return [];
}

function injectTiktokWithFirstPixel(pixelId) {
    const s = document.createElement('script');
    s.async = true;
    s.innerHTML = `!function (w, d, t) { w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)}; ttq.load('${pixelId}'); ttq.page(); }(window, document, 'ttq');`;
    document.head.appendChild(s);
}

function setupTiktokPixels(entries) {
    const ids = entries.map((e) => String(e.pixel_id).trim()).filter((id) => id && isValidPixelId(id));
    if (!ids.length) return;

    const loadRemaining = () => {
        if (typeof window.ttq?.load !== 'function') return;
        ids.forEach((id) => {
            if (!tiktokLoadedPixelIds.has(id)) {
                window.ttq.load(id);
                window.ttq.page();
                tiktokLoadedPixelIds.add(id);
            }
        });
    };

    if (typeof window.ttq?.load === 'function') {
        loadRemaining();
        return;
    }

    injectTiktokWithFirstPixel(ids[0]);
    tiktokLoadedPixelIds.add(ids[0]);

    const deadline = Date.now() + 10000;
    const iv = setInterval(() => {
        if (typeof window.ttq?.load === 'function') {
            clearInterval(iv);
            for (let i = 1; i < ids.length; i++) {
                const id = ids[i];
                if (!tiktokLoadedPixelIds.has(id)) {
                    window.ttq.load(id);
                    window.ttq.page();
                    tiktokLoadedPixelIds.add(id);
                }
            }
        } else if (Date.now() > deadline) {
            clearInterval(iv);
        }
    }, 40);
}

function setupGtag(pixels) {
    const ads = getGoogleAdsEntries(pixels);
    const ga = getGaEntries(pixels);
    const adIds = ads.map((e) => String(e.conversion_id).trim()).filter((id) => id && isValidPixelId(id));
    const gaIds = ga.map((e) => String(e.measurement_id).trim()).filter((id) => id && isValidPixelId(id));
    const allIds = [...adIds, ...gaIds];
    if (!allIds.length) return;

    const first = allIds[0];

    if (!gtagExternalScriptInserted) {
        window.dataLayer = window.dataLayer || [];
        const s = document.createElement('script');
        s.async = true;
        s.src = `https://www.googletagmanager.com/gtag/js?id=${first}`;
        document.head.appendChild(s);
        const inline = document.createElement('script');
        inline.innerHTML = 'window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag("js", new Date());';
        document.head.appendChild(inline);
        gtagExternalScriptInserted = true;
    }

    allIds.forEach((id) => {
        if (!gtagConfiguredIds.has(id)) {
            window.gtag('config', id);
            gtagConfiguredIds.add(id);
        }
    });
}

/** Domínios permitidos para script src em pixels customizados (evita XSS). */
const ALLOWED_SCRIPT_ORIGINS = ['https://www.googletagmanager.com', 'https://connect.facebook.net', 'https://analytics.tiktok.com', 'https://js.stripe.com'];

function isAllowedScriptSrc(src) {
    if (!src || typeof src !== 'string') return false;
    try {
        const u = new URL(src, location.origin);
        return ALLOWED_SCRIPT_ORIGINS.some((origin) => u.origin === origin || u.href.startsWith(origin + '/'));
    } catch {
        return false;
    }
}

function injectCustomScripts() {
    const items = props.pixels?.custom_script ?? [];
    if (!Array.isArray(items)) return;
    items.forEach((item) => {
        if (!item?.script || typeof item.script !== 'string') return;
        const s = document.createElement('div');
        s.innerHTML = item.script;
        const scripts = s.querySelectorAll('script');
        scripts.forEach((script) => {
            if (script.src && !isAllowedScriptSrc(script.src)) return;
            const newScript = document.createElement('script');
            if (script.src) newScript.src = script.src;
            if (!script.src && script.innerHTML) {
                return;
            }
            newScript.async = script.async ?? true;
            document.head.appendChild(newScript);
        });
        const nonScripts = s.childNodes;
        nonScripts.forEach((node) => {
            if (node.nodeType === 1 && node.tagName !== 'SCRIPT') {
                document.head.appendChild(node.cloneNode(true));
            }
        });
    });
}

async function initMetaAndEmitReady() {
    emit('meta-ready');
}

async function init() {
    const p = props.pixels || {};
    const fp = fingerprintPixels(p);
    if (fp === lastPixelsFingerprint) return;
    lastPixelsFingerprint = fp;

    tiktokLoadedPixelIds.clear();
    gtagConfiguredIds.clear();

    const tiktokEntries = getTiktokEntries(p);
    if (tiktokEntries.length) {
        setupTiktokPixels(tiktokEntries);
    }

    setupGtag(p);
    injectCustomScripts();

    emit('ready');

    await initMetaAndEmitReady();
}

onMounted(init);
watch(() => props.pixels, init, { deep: true });

function shouldFireForEntry(entry, triggerType, isOrderBump) {
    if (isOrderBump && entry?.disable_order_bump_events) return false;
    if (triggerType === 'pix' && entry?.fire_purchase_on_pix === false) return false;
    if (triggerType === 'boleto' && entry?.fire_purchase_on_boleto === false) return false;
    return true;
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitForTrackers(maxMs = 1200) {
    const startedAt = Date.now();
    while (Date.now() - startedAt < maxMs) {
        const hasMeta = typeof window.fbq === 'function';
        const hasTiktok = typeof window.ttq?.track === 'function';
        const hasGtag = typeof window.gtag === 'function';
        if (hasMeta || hasTiktok || hasGtag) {
            break;
        }
        await sleep(60);
    }
}

function safeStorageGet(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}
function safeStorageSet(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch (_) {}
}

function fireInitiateCheckout(value, currency = 'BRL', checkoutKey = '') {
    const key = (checkoutKey || '').trim();
    const eventID = key ? `chk:${key}` : undefined;
    const payload = buildCheckoutEventPayload(value, currency, key);
    getMetaEntries(props.pixels || {}).forEach(() => {
        trackMetaEvent('InitiateCheckout', payload, eventID);
    });
}

function firePurchase(value, currency = 'BRL', orderId = '', isOrderBump = false, triggerType = 'approved') {
    const p = props.pixels || {};
    const purchasePayload = buildPurchaseEventPayload(value, currency, orderId);
    const eventID = orderId ? `order:${orderId}` : undefined;
    let firedAny = false;

    if (p.meta?.enabled && window.fbq) {
        getMetaEntries(p).forEach((entry) => {
            if (!entry.pixel_id || !shouldFireForEntry(entry, triggerType, isOrderBump)) return;
            window.fbq('track', 'Purchase', purchasePayload, eventID ? { eventID } : undefined);
            firedAny = true;
        });
    }
    if (p.tiktok?.enabled && window.ttq?.track) {
        getTiktokEntries(p).forEach((entry) => {
            if (!entry.pixel_id || !shouldFireForEntry(entry, triggerType, isOrderBump)) return;
            window.ttq.track('CompletePayment', {
                value: purchasePayload.value,
                currency: purchasePayload.currency,
                content_id: orderId ? String(orderId) : '',
            });
            firedAny = true;
        });
    }
    if (p.google_ads?.enabled && window.gtag) {
        getGoogleAdsEntries(p).forEach((entry) => {
            if (!entry.conversion_id || !shouldFireForEntry(entry, triggerType, isOrderBump)) return;
            const sendTo = `${String(entry.conversion_id).trim()}/${String(entry.conversion_label || '').trim()}`.replace(/\/+$/, '');
            window.gtag('event', 'conversion', {
                send_to: sendTo,
                value: purchasePayload.value,
                currency: purchasePayload.currency,
                transaction_id: orderId ? String(orderId) : '',
            });
            firedAny = true;
        });
    }
    if (p.google_analytics?.enabled && window.gtag) {
        getGaEntries(p).forEach((entry) => {
            if (!entry.measurement_id || !shouldFireForEntry(entry, triggerType, isOrderBump)) return;
            window.gtag('event', 'purchase', {
                send_to: String(entry.measurement_id).trim(),
                value: purchasePayload.value,
                currency: purchasePayload.currency,
                transaction_id: orderId ? String(orderId) : '',
            });
            firedAny = true;
        });
    }

    return firedAny;
}

defineExpose({
    fireInitiateCheckout,
    firePurchase,
    async fireInitiateCheckoutReliable(value, currency = 'BRL', checkoutKey = '', settleDelayMs = 250) {
        const metaEntries = getMetaEntries(props.pixels || {});
        if (!metaEntries.length) return false;
        const ready = await initMetaPixels(metaEntries);
        if (!ready) return false;
        fireInitiateCheckout(value, currency, checkoutKey);
        if (settleDelayMs > 0) await sleep(settleDelayMs);
        return true;
    },
    async firePurchaseReliable(value, currency = 'BRL', orderId = '', isOrderBump = false, triggerType = 'approved', settleDelayMs = 450) {
        const oid = orderId ? String(orderId) : '';
        const dedupeKey = oid ? `px:purchase_sent:${oid}` : '';
        if (dedupeKey && safeStorageGet(dedupeKey) === '1') return;

        await fireMetaPurchaseReliable({
            pixels: props.pixels,
            value,
            currency,
            orderId: oid,
            isOrderBump,
            triggerType,
            settleDelayMs: 0,
        });

        await waitForTrackers(2200);
        const fired = firePurchase(value, currency, orderId, isOrderBump, triggerType);
        if (dedupeKey && fired) safeStorageSet(dedupeKey, '1');
        if (settleDelayMs > 0) await sleep(settleDelayMs);
    },
});
</script>

<template>
    <div class="hidden" aria-hidden="true" data-checkout="conversion-pixels" />
</template>
