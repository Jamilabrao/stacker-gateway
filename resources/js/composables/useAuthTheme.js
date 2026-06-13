import { computed, watch, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { resolvePanelTheme } from '@/composables/usePanelColorScheme';

const DEFAULT_SCHEME = { mode: 'dark', locked: false };

/**
 * Tema das telas de autenticação — segue apenas panel_color_scheme (ignora localStorage do painel).
 */
export function useAuthTheme() {
    const page = usePage();
    const scheme = computed(() => page.props.public_branding?.panel_color_scheme ?? DEFAULT_SCHEME);

    const theme = computed(() => resolvePanelTheme(scheme.value, null));

    function applyAuthTheme() {
        if (typeof document === 'undefined') {
            return;
        }
        document.documentElement.classList.toggle('dark', theme.value === 'dark');
    }

    watch(scheme, () => applyAuthTheme(), { deep: true, immediate: true });

    onMounted(() => {
        applyAuthTheme();
    });

    const isDark = computed(() => theme.value === 'dark');
    const isLight = computed(() => theme.value === 'light');

    return {
        scheme,
        theme,
        isDark,
        isLight,
    };
}
