import { ensureFbcFromFbclid, getAttributionPayload, waitForFbp } from '@/lib/metaTracking/attribution.js';
import {
    getMetaEntries,
    initMetaPixels,
    trackPageView,
    trackMetaEvent,
    buildCheckoutEventPayload,
    buildPurchaseEventPayload,
} from '@/lib/metaTracking/browserPixel.js';
import { mirrorMetaEventToServer } from '@/lib/metaTracking/serverMirror.js';

function eventIdPageView(sessionToken) {
    return sessionToken ? `pv:${sessionToken}` : undefined;
}

function eventIdInitiateCheckout(sessionToken) {
    return sessionToken ? `chk:${sessionToken}` : undefined;
}

function eventIdPurchase(orderId) {
    return orderId ? `order:${orderId}` : undefined;
}

/** @type {Map<string, 'pending'|'done'>} */
const checkoutTrackingByToken = new Map();

function metaDebugEnabled() {
    try {
        return Boolean(window.__GETFY_META_TRACKING_DEBUG__);
    } catch {
        return false;
    }
}

function logMetaDebug(message, data) {
    if (!metaDebugEnabled()) return;
    if (typeof console !== 'undefined' && typeof console.debug === 'function') {
        console.debug(`[meta-tracking] ${message}`, data ?? '');
    }
}

/**
 * Fire PageView + InitiateCheckout (CAPI mirror first, browser pixel when fbq loads).
 */
export async function runCheckoutMetaTracking({
    pixels,
    checkoutSessionToken,
    value,
    currency = 'BRL',
    contentKey = '',
    contentName = '',
}) {
    const token = String(checkoutSessionToken || '').trim();
    if (!token) {
        return { ok: false, reason: 'missing_session_token' };
    }

    const state = checkoutTrackingByToken.get(token);
    if (state === 'done') {
        return { ok: false, reason: 'already_completed' };
    }
    if (state === 'pending') {
        return { ok: false, reason: 'in_progress' };
    }

    checkoutTrackingByToken.set(token, 'pending');

    ensureFbcFromFbclid();

    const metaEntries = getMetaEntries(pixels);
    if (!metaEntries.length) {
        checkoutTrackingByToken.delete(token);
        logMetaDebug('skipped', { reason: 'no_meta_pixels', token });
        return { ok: false, reason: 'no_meta_pixels' };
    }

    const pvEventId = eventIdPageView(token);
    const chkEventId = eventIdInitiateCheckout(token);
    const checkoutPayload = buildCheckoutEventPayload(value, currency, contentKey);

    const mirrorPromise = (async () => {
        await waitForFbp(1500);
        const attribution = getAttributionPayload();

        if (pvEventId) {
            mirrorMetaEventToServer({
                checkoutSessionToken: token,
                eventName: 'PageView',
                eventId: pvEventId,
                ...attribution,
                value: checkoutPayload.value,
                currency: checkoutPayload.currency,
                contentIds: checkoutPayload.content_ids,
                contentName: contentName || contentKey || undefined,
            });
        }

        if (chkEventId) {
            mirrorMetaEventToServer({
                checkoutSessionToken: token,
                eventName: 'InitiateCheckout',
                eventId: chkEventId,
                ...attribution,
                value: checkoutPayload.value,
                currency: checkoutPayload.currency,
                contentIds: checkoutPayload.content_ids,
                contentName: contentName || contentKey || undefined,
            });
        }
    })();

    const browserPromise = (async () => {
        const ready = await initMetaPixels(metaEntries);
        if (!ready) {
            return false;
        }

        if (pvEventId) {
            trackPageView(pvEventId);
        }
        if (chkEventId) {
            trackMetaEvent('InitiateCheckout', checkoutPayload, chkEventId);
        }

        return true;
    })();

    await mirrorPromise;

    const browserOk = await browserPromise;

    checkoutTrackingByToken.set(token, 'done');

    const result = {
        ok: true,
        browser: browserOk,
        reason: browserOk ? 'ok' : 'capi_only',
    };

    logMetaDebug('checkout events', { token, ...result });

    return result;
}

export async function fireMetaPurchaseReliable({
    pixels,
    value,
    currency = 'BRL',
    orderId,
    triggerType = 'approved',
    isOrderBump = false,
    settleDelayMs = 450,
}) {
    const metaEntries = getMetaEntries(pixels);
    if (metaEntries.length) {
        await initMetaPixels(metaEntries);
    }

    const shouldFireForEntry = (entry) => {
        if (isOrderBump && entry?.disable_order_bump_events) return false;
        if (triggerType === 'pix' && entry?.fire_purchase_on_pix === false) return false;
        if (triggerType === 'boleto' && entry?.fire_purchase_on_boleto === false) return false;
        return true;
    };

    const purchasePayload = buildPurchaseEventPayload(value, currency, orderId);
    const eventID = eventIdPurchase(orderId);

    if (typeof window.fbq === 'function') {
        metaEntries.forEach((entry) => {
            if (!entry.pixel_id || !shouldFireForEntry(entry)) return;
            window.fbq('track', 'Purchase', purchasePayload, eventID ? { eventID } : undefined);
        });
    }

    if (settleDelayMs > 0) {
        await new Promise((r) => setTimeout(r, settleDelayMs));
    }
}

export function useMetaCheckoutTracking() {
    return {
        runCheckoutMetaTracking,
        fireMetaPurchaseReliable,
    };
}
