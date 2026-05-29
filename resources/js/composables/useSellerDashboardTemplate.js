import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useSellerDashboardTemplate() {
    const page = usePage();
    const templateId = computed(() => {
        if (page.props.customer_panel) {
            return 'default';
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
