import { computed } from 'vue';
import { useLoginTemplate } from '@/composables/useLoginTemplate';
import { useAuthBranding } from '@/composables/useAuthBranding';

export function useAuthFormStyles() {
    const { isSpotlight, isImmersive } = useLoginTemplate();
    const { primary } = useAuthBranding();

    const authSkin = computed(() => {
        if (isImmersive.value) {
            return 'immersive';
        }
        if (isSpotlight.value) {
            return 'spotlight';
        }
        return 'default';
    });

    const inputClass = computed(() => {
        if (authSkin.value === 'immersive') {
            return 'wl-immersive-input block w-full rounded-xl border border-zinc-300/90 bg-white/70 px-4 py-3.5 text-sm text-zinc-900 placeholder-zinc-400 shadow-sm backdrop-blur-sm transition focus:border-[var(--wl-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--wl-primary)]/25 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder-white/35';
        }
        if (authSkin.value === 'spotlight') {
            return 'wl-spotlight-input block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 placeholder-zinc-400 shadow-sm transition focus:border-[var(--wl-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--wl-primary)]/25 dark:border-zinc-700/80 dark:bg-zinc-900/80 dark:text-white dark:placeholder-zinc-600';
        }
        return 'wl-input mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-zinc-900 placeholder-zinc-500 shadow-sm transition dark:border-zinc-600 dark:bg-zinc-900 dark:text-white dark:placeholder-zinc-500';
    });

    const labelClass = computed(() => {
        if (authSkin.value === 'immersive' || authSkin.value === 'spotlight') {
            return 'mb-1.5 block text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-white/50';
        }
        return 'block text-sm font-medium text-zinc-700 dark:text-zinc-300';
    });

    const linkClass = computed(() => {
        if (authSkin.value === 'immersive' || authSkin.value === 'spotlight') {
            return 'font-medium text-[var(--wl-primary)] hover:underline';
        }
        return 'wl-link font-medium hover:underline focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-zinc-900 rounded';
    });

    const mutedTextClass = computed(() => {
        if (authSkin.value === 'immersive') {
            return 'text-sm text-zinc-600 dark:text-white/55';
        }
        if (authSkin.value === 'spotlight') {
            return 'text-sm text-zinc-600 dark:text-zinc-400';
        }
        return 'text-sm text-zinc-600 dark:text-zinc-400';
    });

    const alertErrorClass = computed(() => {
        if (authSkin.value === 'immersive') {
            return 'rounded-xl border border-amber-400/30 bg-amber-500/15 px-4 py-3 text-sm text-amber-800 backdrop-blur-sm dark:text-amber-100';
        }
        if (authSkin.value === 'spotlight') {
            return 'rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-200';
        }
        return 'rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200';
    });

    const submitButtonClass = computed(() => {
        if (authSkin.value === 'immersive' || authSkin.value === 'spotlight') {
            return 'w-full rounded-xl py-3.5 text-sm font-bold transition hover:brightness-110 disabled:opacity-60';
        }
        return 'wl-submit w-full hover:!opacity-90';
    });

    const checkboxClass = computed(() => {
        if (authSkin.value === 'immersive') {
            return 'h-4 w-4 rounded border-zinc-300 bg-white text-[var(--wl-primary)] dark:border-white/25 dark:bg-white/10';
        }
        if (authSkin.value === 'spotlight') {
            return 'h-4 w-4 rounded border-zinc-300 bg-white text-[var(--wl-primary)] dark:border-zinc-600 dark:bg-zinc-900';
        }
        return 'wl-checkbox h-4 w-4 rounded border-zinc-300 dark:border-zinc-600';
    });

    const demoDividerClass = computed(() => {
        if (authSkin.value === 'spotlight') {
            return 'border-zinc-200 dark:border-zinc-800';
        }
        if (authSkin.value === 'immersive') {
            return 'border-zinc-200 dark:border-white/15';
        }
        return 'border-zinc-200 dark:border-zinc-700';
    });

    return {
        authSkin,
        isSpotlight,
        isImmersive,
        primary,
        inputClass,
        labelClass,
        linkClass,
        mutedTextClass,
        alertErrorClass,
        submitButtonClass,
        checkboxClass,
        demoDividerClass,
    };
}
