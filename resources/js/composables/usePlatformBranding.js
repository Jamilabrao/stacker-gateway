import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Branding da plataforma (admin / personalização).
 * Usa appSettings quando logado; public_branding em páginas públicas (ex.: docs).
 */
export function usePlatformBranding() {
    const page = usePage();

    const branding = computed(() => {
        const settings = page.props.appSettings;
        if (settings && typeof settings === 'object') {
            return settings;
        }

        return page.props.public_branding ?? {};
    });

    const appName = computed(() => branding.value.app_name || 'Stacker');

    const hasLogoFull = computed(
        () => !!(branding.value.app_logo || branding.value.app_logo_dark)
    );

    const hasLogoIcon = computed(
        () => !!(branding.value.app_logo_icon || branding.value.app_logo_icon_dark)
    );

    return {
        branding,
        appName,
        hasLogoFull,
        hasLogoIcon,
    };
}
