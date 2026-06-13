import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useLoginTemplate() {
    const page = usePage();

    const templateId = computed(() => {
        const raw = page.props.public_branding?.login_template;
        if (raw === 'spotlight' || raw === 'immersive') {
            return raw;
        }
        return 'default';
    });

    const isDefault = computed(() => templateId.value === 'default');
    const isSpotlight = computed(() => templateId.value === 'spotlight');
    const isImmersive = computed(() => templateId.value === 'immersive');
    const isModernLogin = computed(() => isSpotlight.value || isImmersive.value);

    return {
        templateId,
        isDefault,
        isSpotlight,
        isImmersive,
        isModernLogin,
    };
}
