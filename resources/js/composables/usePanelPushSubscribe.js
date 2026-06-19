import { ref, onMounted, onUnmounted, computed } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';

const VAPID_STORAGE_KEY = 'panel_vapid_public';

const pushSubscribing = ref(false);
const pushRegistered = ref(false);
const lastPushError = ref(null);
const needsPermission = ref(false);
const pushNeedsResubscribe = ref(false);
const swUpdateApplied = ref(false);

let firebaseApp = null;
let firebaseMessaging = null;
let permissionCheckInterval = null;
let swLifecycleRegistered = false;
let mountCount = 0;

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
}

function getStoredVapidPublic() {
    if (typeof localStorage === 'undefined') return null;
    try {
        return localStorage.getItem(VAPID_STORAGE_KEY);
    } catch {
        return null;
    }
}

function setStoredVapidPublic(value) {
    if (typeof localStorage === 'undefined') return;
    try {
        if (value) {
            localStorage.setItem(VAPID_STORAGE_KEY, value);
        } else {
            localStorage.removeItem(VAPID_STORAGE_KEY);
        }
    } catch {}
}

function vapidKeyChanged(currentPublic) {
    if (!currentPublic) return false;
    const stored = getStoredVapidPublic();
    return stored !== null && stored !== currentPublic;
}

function mapSubscribeHttpError(error) {
    const status = error?.response?.status;
    if (status === 419) return 'csrf_expired';
    if (status === 422) return 'push_not_configured';
    if (status === 429) return 'rate_limited';
    if (status === 403) return 'push_forbidden';
    return 'subscription_sync_failed';
}

async function postPushSubscribe(payload) {
    try {
        const { data } = await axios.post('/painel/push-subscribe', payload);
        return { ok: !!data?.success, data };
    } catch (error) {
        return { ok: false, errorCode: mapSubscribeHttpError(error), error };
    }
}

async function loadFirebaseModules() {
    const { initializeApp } = await import('firebase/app');
    const { getMessaging, getToken, onMessage, isSupported } = await import('firebase/messaging');
    return { initializeApp, getMessaging, getToken, onMessage, isSupported };
}

function buildVersionedSwUrl(baseUrl, version) {
    if (!baseUrl) return '/painel-sw.js';
    if (!version) return baseUrl;
    const sep = baseUrl.includes('?') ? '&' : '?';
    return `${baseUrl}${sep}v=${encodeURIComponent(version)}`;
}

function resolvePanelSwUrl(pageProps) {
    const base = pageProps?.pwa_sw_url ?? '/painel-sw.js';
    const version = pageProps?.pwa_sw_version ?? null;
    return buildVersionedSwUrl(base, version);
}

function resolveFirebaseSwUrl(pageProps) {
    const version = pageProps?.pwa_sw_version ?? null;
    return buildVersionedSwUrl('/firebase-messaging-sw.js', version);
}

function scriptUrlForRegistration(reg) {
    return reg?.active?.scriptURL || reg?.installing?.scriptURL || reg?.waiting?.scriptURL || '';
}

async function unsubscribeRegistrationPush(reg) {
    try {
        const sub = await reg.pushManager?.getSubscription?.();
        if (sub) {
            await sub.unsubscribe();
        }
    } catch (_) {}
}

/**
 * Remove SW/inscrições conflitantes (legado scope /, painel-sw vs firebase-messaging-sw).
 */
async function cleanupConflictingPushRegistrations(activeProvider) {
    if (typeof navigator === 'undefined' || !navigator.serviceWorker?.getRegistrations) {
        return;
    }

    const origin = window.location.origin;
    const regs = await navigator.serviceWorker.getRegistrations();

    for (const reg of regs) {
        const scriptUrl = scriptUrlForRegistration(reg);
        const scope = reg?.scope || '';
        const isPainelSw = scriptUrl.includes('/painel-sw.js');
        const isFirebaseSw = scriptUrl.includes('/firebase-messaging-sw.js');
        const isRootScope = scope === `${origin}/`;
        const shouldRemove =
            (activeProvider === 'fcm' && isPainelSw) ||
            (activeProvider !== 'fcm' && isFirebaseSw) ||
            (isPainelSw && isRootScope);

        if (shouldRemove) {
            await unsubscribeRegistrationPush(reg);
            try {
                await reg.unregister();
            } catch (_) {}
        }
    }
}

function registerSwLifecycleOnce(getCheckExistingSubscription) {
    if (swLifecycleRegistered || typeof navigator === 'undefined' || !navigator.serviceWorker) {
        return;
    }
    swLifecycleRegistered = true;

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        swUpdateApplied.value = true;
        const check = getCheckExistingSubscription();
        if (typeof check === 'function') {
            check().catch(() => {});
        }
    });

    navigator.serviceWorker.ready.then((registration) => {
        registration.addEventListener('updatefound', () => {
            const installing = registration.installing;
            if (!installing) return;
            installing.addEventListener('statechange', () => {
                if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                    installing.postMessage({ type: 'SKIP_WAITING' });
                }
            });
        });
    }).catch(() => {});
}

/**
 * Registra SW e inscreve push do painel (VAPID ou Firebase conforme push_provider).
 * Estado compartilhado entre AppLayout, PwaInstallPrompt e NotificationsPanel.
 */
export function usePanelPushSubscribe() {
    const page = usePage();
    const pushEnabled = computed(() => !!page.props.push_enabled);
    const pushProvider = computed(() => page.props.push_provider ?? 'vapid');
    const vapidPublic = computed(() => page.props.vapid_public ?? null);
    const firebaseClientConfig = computed(() => page.props.firebase_client_config ?? null);
    const panelSwUrl = computed(() => resolvePanelSwUrl(page.props));
    const firebaseSwUrl = computed(() => resolveFirebaseSwUrl(page.props));

    function serializeSubscription(sub) {
        const p256dh = sub?.getKey?.('p256dh');
        const auth = sub?.getKey?.('auth');
        return {
            endpoint: sub?.endpoint,
            keys: {
                p256dh: p256dh ? btoa(String.fromCharCode.apply(null, new Uint8Array(p256dh))) : '',
                auth: auth ? btoa(String.fromCharCode.apply(null, new Uint8Array(auth))) : '',
            },
        };
    }

    async function syncVapidToServer(sub) {
        const payload = serializeSubscription(sub);
        if (!payload.endpoint || !payload.keys?.p256dh || !payload.keys?.auth) return false;
        const result = await postPushSubscribe(payload);
        if (result.ok) {
            setStoredVapidPublic(vapidPublic.value);
            pushNeedsResubscribe.value = false;
            return true;
        }
        if (result.errorCode) {
            lastPushError.value = result.errorCode;
        }
        return false;
    }

    async function syncFcmToServer(token) {
        if (!token) return false;
        const result = await postPushSubscribe({
            provider: 'fcm',
            fcm_token: token,
        });
        if (result.ok) {
            pushNeedsResubscribe.value = false;
            return true;
        }
        if (result.errorCode) {
            lastPushError.value = result.errorCode;
        }
        return false;
    }

    async function registerPanelSw() {
        if (typeof navigator === 'undefined' || !navigator.serviceWorker) return null;
        await cleanupConflictingPushRegistrations('vapid');
        try {
            return await navigator.serviceWorker.register(panelSwUrl.value, { scope: '/painel/' });
        } catch (e) {
            console.warn('Panel SW registration failed:', e);
            return null;
        }
    }

    async function registerFirebaseSw() {
        if (typeof navigator === 'undefined' || !navigator.serviceWorker) return null;
        await cleanupConflictingPushRegistrations('fcm');
        try {
            return await navigator.serviceWorker.register(firebaseSwUrl.value, { scope: '/painel/' });
        } catch (e) {
            console.warn('Firebase SW registration failed:', e);
            return null;
        }
    }

    async function ensureVapidSubscription(reg) {
        const existing = await reg.pushManager.getSubscription?.();
        const currentKey = vapidPublic.value;

        if (existing && currentKey && vapidKeyChanged(currentKey)) {
            try {
                await existing.unsubscribe();
            } catch (_) {}
        }

        let sub = await reg.pushManager.getSubscription?.();
        if (!sub) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(currentKey),
            });
        }

        return sub;
    }

    async function subscribeFcm() {
        const cfg = firebaseClientConfig.value;
        if (!cfg?.firebase || !cfg?.firebase_web_vapid_key) {
            lastPushError.value = 'push_not_configured';
            return false;
        }

        const { initializeApp, getMessaging, getToken, onMessage, isSupported } = await loadFirebaseModules();
        const supported = await isSupported();
        if (!supported) {
            lastPushError.value = 'fcm_not_supported';
            return false;
        }

        const reg = await registerFirebaseSw();
        if (!reg) {
            lastPushError.value = 'service_worker_registration_failed';
            return false;
        }

        if (!firebaseApp) {
            firebaseApp = initializeApp(cfg.firebase);
        }
        firebaseMessaging = getMessaging(firebaseApp, { serviceWorkerRegistration: reg });

        const token = await getToken(firebaseMessaging, {
            vapidKey: cfg.firebase_web_vapid_key,
            serviceWorkerRegistration: reg,
        });

        if (!token) {
            lastPushError.value = 'fcm_token_empty';
            return false;
        }

        onMessage(firebaseMessaging, () => {});

        return syncFcmToServer(token);
    }

    async function subscribeVapid() {
        if (typeof navigator === 'undefined' || !navigator.serviceWorker) {
            lastPushError.value = 'service_worker_unavailable';
            return false;
        }

        const reg = await registerPanelSw();
        if (!reg) {
            lastPushError.value = 'service_worker_registration_failed';
            return false;
        }

        if (!pushEnabled.value || !vapidPublic.value) {
            lastPushError.value = 'push_not_configured';
            return false;
        }

        if (!reg?.pushManager) {
            lastPushError.value = 'service_worker_not_found';
            return false;
        }

        try {
            const sub = await ensureVapidSubscription(reg);
            return syncVapidToServer(sub);
        } catch (e) {
            if (e?.name === 'NotAllowedError') {
                lastPushError.value = 'notification_permission_denied';
            } else {
                lastPushError.value = 'subscription_failed';
                console.warn('Panel push subscribe failed:', e);
            }
            return false;
        }
    }

    async function registerAndSubscribe() {
        if (pushSubscribing.value) return pushRegistered.value;

        const wasRegistered = pushRegistered.value;
        lastPushError.value = null;
        needsPermission.value = false;

        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            lastPushError.value = 'notification_permission_default';
            needsPermission.value = true;
            return false;
        }
        if (typeof Notification !== 'undefined' && Notification.permission === 'denied') {
            lastPushError.value = 'notification_permission_denied';
            return false;
        }
        if (!pushEnabled.value) {
            lastPushError.value = 'push_not_configured';
            return false;
        }
        if (wasRegistered && !pushNeedsResubscribe.value) return true;

        pushSubscribing.value = true;
        try {
            let synced = false;
            if (pushProvider.value === 'fcm') {
                synced = await subscribeFcm();
            } else {
                synced = await subscribeVapid();
            }
            pushRegistered.value = synced;
            if (!synced) {
                lastPushError.value = lastPushError.value || 'subscription_sync_failed';
            }
            return synced;
        } catch (e) {
            if (e?.name === 'NotAllowedError') {
                lastPushError.value = 'notification_permission_denied';
            } else {
                lastPushError.value = 'subscription_failed';
                console.warn('Panel push subscribe failed:', e);
            }
            pushRegistered.value = false;
            return false;
        } finally {
            pushSubscribing.value = false;
        }
    }

    async function checkExistingSubscription() {
        if (pushSubscribing.value) {
            return pushRegistered.value;
        }

        const previousRegistered = pushRegistered.value;
        needsPermission.value = false;

        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            needsPermission.value = pushEnabled.value;
            if (!previousRegistered) {
                pushRegistered.value = false;
            }
            return false;
        }
        if (typeof Notification !== 'undefined' && Notification.permission !== 'granted') {
            if (!previousRegistered) {
                pushRegistered.value = false;
            }
            return false;
        }
        if (!pushEnabled.value) {
            pushRegistered.value = false;
            return false;
        }

        try {
            const { data } = await axios.get('/painel/notifications', { params: { per_page: 1 } });
            if (data?.push_needs_resubscribe) {
                pushNeedsResubscribe.value = true;
            }
            if (data?.push_subscribed && !data?.push_needs_resubscribe) {
                pushRegistered.value = true;
                pushNeedsResubscribe.value = false;
                if (vapidPublic.value) {
                    setStoredVapidPublic(vapidPublic.value);
                }
                return true;
            }
            if (data?.push_needs_resubscribe) {
                return registerAndSubscribe();
            }
        } catch (_) {}

        try {
            if (pushProvider.value === 'fcm') {
                const cfg = firebaseClientConfig.value;
                if (!cfg?.firebase) return false;
                const { initializeApp, getMessaging, getToken, isSupported } = await loadFirebaseModules();
                if (!(await isSupported())) return false;
                const reg = await registerFirebaseSw();
                if (!reg) return false;
                if (!firebaseApp) firebaseApp = initializeApp(cfg.firebase);
                firebaseMessaging = getMessaging(firebaseApp, { serviceWorkerRegistration: reg });
                const token = await getToken(firebaseMessaging, {
                    vapidKey: cfg.firebase_web_vapid_key,
                    serviceWorkerRegistration: reg,
                });
                if (!token) return false;
                const synced = await syncFcmToServer(token);
                pushRegistered.value = synced;
                return synced;
            }

            await registerPanelSw();
            const reg = await navigator.serviceWorker.getRegistration('/painel/');
            if (!reg?.pushManager) return false;

            if (vapidPublic.value && vapidKeyChanged(vapidPublic.value)) {
                return registerAndSubscribe();
            }

            const existing = await reg.pushManager.getSubscription?.();
            if (existing) {
                const synced = await syncVapidToServer(existing);
                pushRegistered.value = synced;
                return synced;
            }
            return false;
        } catch (_) {
            return false;
        }
    }

    const notificationPermission = computed(() =>
        typeof Notification !== 'undefined' ? Notification.permission : 'default'
    );

    const subscribeInFlight = computed(() => pushSubscribing.value);

    const isStandalone = computed(() => {
        if (typeof window === 'undefined') return false;
        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true ||
            document.referrer.includes('android-app://')
        );
    });

    onMounted(() => {
        mountCount += 1;
        registerSwLifecycleOnce(checkExistingSubscription);

        if (!pushEnabled.value) {
            return;
        }
        if (isStandalone.value && notificationPermission.value === 'default') {
            needsPermission.value = true;
            permissionCheckInterval = setInterval(() => {
                if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
                    clearInterval(permissionCheckInterval);
                    permissionCheckInterval = null;
                    registerAndSubscribe();
                }
            }, 1500);
            setTimeout(() => {
                if (permissionCheckInterval) {
                    clearInterval(permissionCheckInterval);
                    permissionCheckInterval = null;
                }
            }, 60000);
            return;
        }
        registerAndSubscribe();
    });

    onUnmounted(() => {
        mountCount = Math.max(0, mountCount - 1);
        if (mountCount === 0 && permissionCheckInterval) {
            clearInterval(permissionCheckInterval);
            permissionCheckInterval = null;
        }
    });

    return {
        pushSubscribing,
        pushRegistered,
        lastPushError,
        needsPermission,
        pushNeedsResubscribe,
        swUpdateApplied,
        notificationPermission,
        isStandalone,
        pushProvider,
        registerAndSubscribe,
        checkExistingSubscription,
        subscribeInFlight,
    };
}
