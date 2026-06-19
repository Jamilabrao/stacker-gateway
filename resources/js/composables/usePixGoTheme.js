import { computed } from 'vue';
import { useBrandingThemePrimary } from '@/composables/useBrandingThemePrimary';

/**
 * Tema PixGO alinhado à cor primária da plataforma (--color-primary).
 */
export function usePixGoTheme() {
    const primary = useBrandingThemePrimary();

    const amountCardStyle = computed(() => ({
        boxShadow: `0 0 40px color-mix(in srgb, ${primary.value} 8%, transparent)`,
    }));

    const confettiColors = computed(() => [primary.value, primary.value]);

    return {
        primary,
        amountCardStyle,
        confettiColors,
    };
}
