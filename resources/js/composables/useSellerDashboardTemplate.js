import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const DEMO_TEMPLATE_KEY = 'demo_template_preview';

/** Estado compartilhado entre todas as instâncias do composable. */
const sharedPreviewOverride = ref(null);
let previewListenerAttached = false;

function readPreviewFromStorage() {
    if (typeof window === 'undefined') {
        return null;
    }
    const raw = localStorage.getItem(DEMO_TEMPLATE_KEY);
    if (raw === 'aurora' || raw === 'kawaii' || raw === 'default') {
        return raw;
    }
    return null;
}

function syncSharedPreview(demoEnabled) {
    if (!demoEnabled) {
        sharedPreviewOverride.value = null;
        return;
    }
    sharedPreviewOverride.value = readPreviewFromStorage();
}

function attachPreviewListener(getDemoEnabled) {
    if (previewListenerAttached || typeof window === 'undefined') {
        return;
    }
    previewListenerAttached = true;
    window.addEventListener('demo-template-preview-changed', () => {
        if (getDemoEnabled()) {
            sharedPreviewOverride.value = readPreviewFromStorage();
        }
    });
}

export function useSellerDashboardTemplate() {
    const page = usePage();

    const demoEnabled = () => !!page.props.demo_mode?.enabled;

    onMounted(() => {
        syncSharedPreview(demoEnabled());
        attachPreviewListener(demoEnabled);
    });

    const templateId = computed(() => {
        if (page.props.customer_panel) {
            return 'default';
        }

        if (demoEnabled()) {
            const preview = sharedPreviewOverride.value ?? readPreviewFromStorage();
            if (preview === 'aurora' || preview === 'kawaii' || preview === 'default') {
                return preview;
            }
        }

        const raw = page.props.seller_dashboard_template;
        if (raw === 'aurora') return 'aurora';
        if (raw === 'kawaii') return 'kawaii';
        return 'default';
    });

    const isAurora = computed(() => templateId.value === 'aurora');
    const isKawaii = computed(() => templateId.value === 'kawaii');
    const isDefault = computed(() => templateId.value === 'default');
    const isThemedShell = computed(() => isAurora.value || isKawaii.value);
    const themePrefix = computed(() => {
        if (isAurora.value) return 'aurora';
        if (isKawaii.value) return 'kawaii';
        return null;
    });
    const pageWrapperClass = computed(() => {
        if (isKawaii.value) return 'kawaii-page';
        if (isAurora.value) return 'aurora-page';
        return 'space-y-6';
    });

    return {
        templateId,
        isDefault,
        isAurora,
        isKawaii,
        isThemedShell,
        themePrefix,
        pageWrapperClass,
    };
}
