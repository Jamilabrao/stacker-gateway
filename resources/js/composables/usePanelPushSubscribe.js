import { ref, onMounted, onUnmounted, computed } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
}

let firebaseApp = null;
let firebaseMessaging = null;

async function loadFirebaseModules() {
    const { initializeApp } = await import('firebase/app');
    const { getMessaging, getToken, onMessage, isSupported } = await import('firebase/messaging');
    return { initializeApp, getMessaging, getToken, onMessage, isSupported };
}

/**
 * Registra SW e inscreve push do painel (VAPID ou Firebase conforme push_provider).
 */
export function usePanelPushSubscribe() {
    const page = usePage();
    const pushEnabled = computed(() => !!page.props.push_enabled);
    const pushProvider = computed(() => page.props.push_provider ?? 'vapid');
    const vapidPublic = computed(() => page.props.vapid_public ?? null);
    const firebaseClientConfig = computed(() => page.props.firebase_client_config ?? null);
    const pushSubscribing = ref(false);
    const pushRegistered = ref(false);
    const lastPushError = ref(null);

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
        const { data } = await axios.post('/painel/push-subscribe', payload);
        return !!data?.success;
    }

    async function syncFcmToServer(token) {
        if (!token) return false;
        const { data } = await axios.post('/painel/push-subscribe', {
            provider: 'fcm',
            fcm_token: token,
        });
        return !!data?.success;
    }

    async function registerFirebaseSw() {
        if (typeof navigator === 'undefined' || !navigator.serviceWorker) return null;
        try {
            return await navigator.serviceWorker.register('/firebase-messaging-sw.js', { scope: '/painel/' });
        } catch (e) {
            console.warn('Firebase SW registration failed:', e);
            return null;
        }
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

        onMessage(firebaseMessaging, () => {
            // foreground: centro de notificações / reload opcional
        });

        return syncFcmToServer(token);
    }

    async function subscribeVapid() {
        if (typeof navigator === 'undefined' || !navigator.serviceWorker) {
            lastPushError.value = 'service_worker_unavailable';
            return false;
        }

        try {
            await navigator.serviceWorker.register('/painel-sw.js', { scope: '/painel/' });
        } catch (e) {
            lastPushError.value = 'service_worker_registration_failed';
            return false;
        }

        if (!pushEnabled.value || !vapidPublic.value) {
            lastPushError.value = 'push_not_configured';
            return false;
        }

        const reg = await navigator.serviceWorker.getRegistration('/painel/');
        if (!reg?.pushManager) {
            lastPushError.value = 'service_worker_not_found';
            return false;
        }

        const existing = await reg.pushManager.getSubscription?.();
        if (existing) {
            return syncVapidToServer(existing);
        }

        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidPublic.value),
        });
        return syncVapidToServer(sub);
    }

    async function registerAndSubscribe() {
        lastPushError.value = null;
        pushRegistered.value = false;

        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            lastPushError.value = 'notification_permission_default';
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
        if (pushSubscribing.value) return false;
        if (pushRegistered.value) return true;

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
            return false;
        } finally {
            pushSubscribing.value = false;
        }
    }

    async function checkExistingSubscription() {
        lastPushError.value = null;
        pushRegistered.value = false;
        if (typeof Notification !== 'undefined' && Notification.permission !== 'granted') return false;
        if (!pushEnabled.value) return false;

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

            await navigator.serviceWorker.register('/painel-sw.js', { scope: '/painel/' });
            const reg = await navigator.serviceWorker.getRegistration('/painel/');
            const existing = await reg?.pushManager?.getSubscription?.();
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

    const isStandalone = computed(() => {
        if (typeof window === 'undefined') return false;
        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true ||
            document.referrer.includes('android-app://')
        );
    });

    let permissionCheckInterval = null;

    onMounted(() => {
        if (!pushEnabled.value) {
            return;
        }
        if (isStandalone.value && notificationPermission.value === 'default') {
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
        if (permissionCheckInterval) clearInterval(permissionCheckInterval);
    });

    return {
        pushSubscribing,
        pushRegistered,
        lastPushError,
        notificationPermission,
        isStandalone,
        pushProvider,
        registerAndSubscribe,
        checkExistingSubscription,
    };
}
