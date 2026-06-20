import { ensureFbcFromFbclid, getAttributionPayload } from '@/lib/metaTracking/attribution.js';
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

let checkoutTrackingStarted = false;

/**
 * Fire PageView + InitiateCheckout (browser + CAPI mirror) once per checkout load.
 */
export async function runCheckoutMetaTracking({
    pixels,
    checkoutSessionToken,
    value,
    currency = 'BRL',
    contentKey = '',
    contentName = '',
}) {
    if (checkoutTrackingStarted) return { ok: false, reason: 'already_started' };
    checkoutTrackingStarted = true;

    ensureFbcFromFbclid();

    const metaEntries = getMetaEntries(pixels);
    if (!metaEntries.length) {
        checkoutTrackingStarted = false;
        return { ok: false, reason: 'no_meta_pixels' };
    }

    const ready = await initMetaPixels(metaEntries);
    if (!ready) {
        checkoutTrackingStarted = false;
        return { ok: false, reason: 'meta_not_ready' };
    }

    const attribution = getAttributionPayload();
    const pvEventId = eventIdPageView(checkoutSessionToken);
    const chkEventId = eventIdInitiateCheckout(checkoutSessionToken);
    const checkoutPayload = buildCheckoutEventPayload(value, currency, contentKey);

    if (pvEventId) {
        trackPageView(pvEventId);
        mirrorMetaEventToServer({
            checkoutSessionToken,
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
        trackMetaEvent('InitiateCheckout', checkoutPayload, chkEventId);
        mirrorMetaEventToServer({
            checkoutSessionToken,
            eventName: 'InitiateCheckout',
            eventId: chkEventId,
            ...attribution,
            value: checkoutPayload.value,
            currency: checkoutPayload.currency,
            contentIds: checkoutPayload.content_ids,
            contentName: contentName || contentKey || undefined,
        });
    }

    return { ok: true };
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
