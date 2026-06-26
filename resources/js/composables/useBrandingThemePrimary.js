import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { normalizeThemePrimary } from '@/lib/emailCampaignBody';

const PLATFORM_FALLBACK = '#0050fc';

/**
 * Cor primária da marca para pré-visualizações (e-mail marketing, etc.).
 * Prioriza prop explícita, variável CSS do painel e props Inertia compartilhadas.
 */
export function useBrandingThemePrimary(explicitPrimary = null) {
    const page = usePage();

    return computed(() => {
        const cssPrimary =
            typeof document !== 'undefined'
                ? getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim()
                : '';

        const candidates = [
            explicitPrimary?.value ?? explicitPrimary,
            page.props.campaign_theme_primary,
            page.props.theme_primary,
            cssPrimary,
            page.props.appSettings?.theme_primary,
            page.props.public_branding?.theme_primary,
        ];

        for (const candidate of candidates) {
            const normalized = normalizeThemePrimary(candidate, null);
            if (normalized) {
                return normalized;
            }
        }

        return normalizeThemePrimary(null, PLATFORM_FALLBACK) ?? PLATFORM_FALLBACK;
    });
}
