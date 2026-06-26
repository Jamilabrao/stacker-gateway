import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useAuthBranding() {
    const page = usePage();
    const branding = computed(() => page.props.public_branding ?? {});
    const primary = computed(() => branding.value.theme_primary || '#0050fc');
    const appName = computed(() => branding.value.app_name || 'Getfy');
    const logoLight = computed(() => branding.value.app_logo_icon || 'https://cdn.getfy.cloud/collapsed-logo.png');
    const logoDark = computed(() => branding.value.app_logo_icon_dark || logoLight.value);
    const heroImage = computed(() => branding.value.login_hero_image || 'https://cdn.getfy.cloud/login.webp');
    const heroTagline = computed(() => branding.value.login_hero_tagline || 'Sua plataforma para vender mais.');
    const heroSubtagline = computed(() => branding.value.login_hero_subtagline || 'Feita para quem escala de verdade.');

    return {
        branding,
        primary,
        appName,
        logoLight,
        logoDark,
        heroImage,
        heroTagline,
        heroSubtagline,
    };
}
