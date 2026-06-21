import { router } from '@inertiajs/vue3';

/**
 * Redireciona após pagamento: URLs externas via location; internas via Inertia.
 */
export function navigateAfterCheckout(url) {
    if (!url || typeof url !== 'string') {
        return;
    }

    const trimmed = url.trim();
    if (trimmed === '') {
        return;
    }

    if (trimmed.startsWith('http://') || trimmed.startsWith('https://') || trimmed.startsWith('//')) {
        window.location.href = trimmed;
        return;
    }

    try {
        const parsed = new URL(trimmed, window.location.origin);
        if (parsed.origin !== window.location.origin) {
            window.location.href = parsed.href;
            return;
        }
    } catch (_) {
        window.location.href = trimmed;
        return;
    }

    router.visit(trimmed);
}
