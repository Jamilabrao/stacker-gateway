import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';

const DEFAULT_SCHEME = { mode: 'dark', locked: false };

function readStoredTheme() {
    try {
        const stored = localStorage.getItem('theme');
        if (stored === 'light' || stored === 'dark') {
            return stored;
        }
    } catch (_) {
        // ignore
    }

    return null;
}

function systemPrefersDark() {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function resolvePanelTheme(scheme, storedTheme = null) {
    const policy = {
        mode: scheme?.mode === 'system' || scheme?.mode === 'light' || scheme?.mode === 'dark'
            ? scheme.mode
            : DEFAULT_SCHEME.mode,
        locked: !!scheme?.locked,
    };

    if (policy.locked) {
        if (policy.mode === 'system') {
            return systemPrefersDark() ? 'dark' : 'light';
        }

        return policy.mode === 'dark' ? 'dark' : 'light';
    }

    if (storedTheme === 'light' || storedTheme === 'dark') {
        return storedTheme;
    }

    if (policy.mode === 'system') {
        return systemPrefersDark() ? 'dark' : 'light';
    }

    return policy.mode === 'dark' ? 'dark' : 'light';
}

export function usePanelColorScheme() {
    const page = usePage();
    const scheme = computed(() => page.props.public_branding?.panel_color_scheme ?? DEFAULT_SCHEME);
    const showToggler = computed(() => !scheme.value.locked);
    const theme = ref('light');

    let mediaQuery = null;
    let onSystemChange = null;

    function applyTheme(value) {
        theme.value = value;
        document.documentElement.classList.toggle('dark', value === 'dark');
    }

    function syncFromPolicy() {
        const stored = scheme.value.locked ? null : readStoredTheme();
        applyTheme(resolvePanelTheme(scheme.value, stored));
    }

    function setTheme(value) {
        if (scheme.value.locked) {
            return;
        }

        applyTheme(value);
        try {
            localStorage.setItem('theme', value);
        } catch (_) {
            // ignore
        }
    }

    onMounted(() => {
        syncFromPolicy();

        if (scheme.value.mode === 'system' && !scheme.value.locked && typeof window !== 'undefined' && window.matchMedia) {
            mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            onSystemChange = () => {
                if (readStoredTheme()) {
                    return;
                }
                syncFromPolicy();
            };
            mediaQuery.addEventListener('change', onSystemChange);
        }
    });

    onBeforeUnmount(() => {
        if (mediaQuery && onSystemChange) {
            mediaQuery.removeEventListener('change', onSystemChange);
        }
        mediaQuery = null;
        onSystemChange = null;
    });

    return {
        scheme,
        showToggler,
        theme,
        setTheme,
        applyTheme: syncFromPolicy,
    };
}
