import './bootstrap';
import { config as inertiaConfig } from '@inertiajs/core';

inertiaConfig.set('prefetch.hoverDelay', 200);

// Migração: versões antigas registravam /painel-sw.js com scope "/" e isso pode interceptar checkout + scripts de terceiros (Meta Pixel).
// Remove inscrição push e o registro legado (scope raiz). Registro do SW ativo fica em usePanelPushSubscribe.
if (typeof window !== 'undefined' && typeof navigator !== 'undefined' && navigator.serviceWorker?.getRegistrations) {
    try {
        navigator.serviceWorker.getRegistrations().then(async (regs) => {
            const origin = window.location.origin;
            for (const reg of regs) {
                const scriptUrl = reg?.active?.scriptURL || reg?.installing?.scriptURL || reg?.waiting?.scriptURL || '';
                const scope = reg?.scope || '';
                const isPainelSw = typeof scriptUrl === 'string' && scriptUrl.includes('/painel-sw.js');
                const isRootScope = typeof scope === 'string' && scope === `${origin}/`;
                if (isPainelSw && isRootScope) {
                    try {
                        const sub = await reg.pushManager?.getSubscription?.();
                        if (sub) {
                            await sub.unsubscribe();
                        }
                    } catch (_) {}
                    try {
                        await reg.unregister();
                    } catch (_) {}
                }
            }
        });
    } catch (_) {}
}

import { createInertiaApp, usePage, router } from '@inertiajs/vue3';
import { createApp as createVueApp, h } from 'vue';
import { watchEffect } from 'vue';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';

// Envia o CSRF da meta em toda visita Inertia (evita 419 após sessão longa ou abas antigas)
router.on('before', (event) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (token) {
        event.detail.visit.headers = {
            ...event.detail.visit.headers,
            'X-CSRF-TOKEN': token,
        };
    }
});

router.on('invalid', (event) => {
    if (event.detail.response?.status === 419) {
        event.preventDefault();
        window.location.reload();
    }
});

// Sincroniza a meta csrf-token com o token da página atual (evita 419 em gateways e outras requisições axios)
const CsrfSync = {
    setup() {
        const page = usePage();
        watchEffect(() => {
            const token = page.props.csrf_token;
            if (token && typeof document !== 'undefined') {
                const meta = document.querySelector('meta[name="csrf-token"]');
                if (meta && meta.getAttribute('content') !== token) meta.setAttribute('content', token);
            }
        });
        return () => null;
    },
};

const appName = import.meta.env.VITE_APP_NAME || 'Infoprodutor';

const el = document.getElementById('app');
const dataPage = el?.getAttribute('data-page');
let initialPage = null;
try {
    initialPage = dataPage ? JSON.parse(dataPage) : null;
} catch (_) {}
const defaultProps = {
    auth: { user: null },
    flash: { success: null, error: null },
    platform: null,
};
if (!initialPage?.component) {
    initialPage = {
        component: 'Welcome',
        props: { ...defaultProps },
        url: '/',
        version: null,
    };
} else if (initialPage.props) {
    initialPage.props = { ...defaultProps, ...initialPage.props };
    if (!initialPage.props.flash || typeof initialPage.props.flash !== 'object') {
        initialPage.props.flash = { success: null, error: null };
    }
}

const pluginPagesGlob = import.meta.glob('./PluginPages/**/*.vue');

// Criar primeiro admin: em bundle principal para não depender de chunk (evita 404 em deploy sem build novo)
const createFirstAdminPage = import.meta.glob('./Pages/Auth/CreateFirstAdmin.vue', { eager: true })['./Pages/Auth/CreateFirstAdmin.vue'];

function resolvePluginPage(name) {
    if (!name.startsWith('Plugin/')) return null;
    const path = `./PluginPages/${name.slice(7).replace(/\//g, '/')}.vue`;
    const loader = pluginPagesGlob[path];
    return loader ? loader() : null;
}

createInertiaApp({
    id: 'app',
    page: initialPage,
    title: (title) => title || appName,
    resolve: (name) => {
        const pluginPage = resolvePluginPage(name);
        if (pluginPage) return pluginPage;
        if (name === 'Auth/CreateFirstAdmin' && createFirstAdminPage) {
            return Promise.resolve(createFirstAdminPage);
        }
        return resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue')
        );
    },
    setup({ el, App, props, plugin }) {
        const vueApp = createVueApp({
            render: () => h('div', { class: 'contents' }, [h(App, props), h(CsrfSync)]),
        });
        vueApp.use(plugin);
        vueApp.use(createPinia());
        vueApp.mount(el);
    },
    progress: {
        delay: 200,
        color: '#0ea5e9',
    },
});
