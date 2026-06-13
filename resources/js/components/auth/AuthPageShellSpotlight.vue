<script setup>
import { computed } from 'vue';
import { useAuthBranding } from '@/composables/useAuthBranding';
import CookieConsentBanner from '@/components/legal/CookieConsentBanner.vue';

const { primary, appName, logoLight, logoDark, heroImage, heroTagline, heroSubtagline, branding } = useAuthBranding();

const props = defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    variant: { type: String, default: 'seller' },
    wide: { type: Boolean, default: false },
});

const formColumnClass = computed(() => (
    props.wide
        ? 'lg:w-[40%] lg:max-w-[520px]'
        : 'lg:w-[38%] lg:min-w-[400px] lg:max-w-[480px]'
));

const spotlightLogo = computed(() => {
    const full = branding.value.app_logo;
    if (full && String(full).trim() !== '') {
        return full;
    }
    return null;
});
</script>

<template>
    <div class="spotlight-root relative flex min-h-screen flex-col bg-zinc-50 lg:flex-row dark:bg-[#0a0a0a]">
        <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            <div
                class="spotlight-glow-primary absolute rounded-full blur-[80px] lg:blur-[100px]"
                :class="'-left-16 -top-16 h-64 w-64 lg:left-[10%] lg:top-[35%] lg:h-96 lg:w-96 lg:-translate-x-1/2 lg:-translate-y-1/2'"
            />
            <div class="spotlight-glow-secondary absolute -bottom-20 -right-12 h-72 w-72 rounded-full blur-[90px] lg:hidden" />
        </div>

        <div
            class="relative z-10 flex min-h-screen flex-1 flex-col lg:max-h-screen lg:shrink-0"
            :class="formColumnClass"
        >
            <header class="flex shrink-0 items-center px-6 pt-6 sm:px-8 sm:pt-8 lg:px-10 lg:pt-10">
                <img
                    v-if="spotlightLogo"
                    :src="spotlightLogo"
                    :alt="appName"
                    class="h-8 w-auto max-w-[180px] object-contain object-left lg:h-9"
                />
                <template v-else>
                    <img :src="logoLight" :alt="appName" class="h-8 w-auto max-w-[180px] object-contain object-left dark:hidden lg:h-9" />
                    <img :src="logoDark" :alt="appName" class="hidden h-8 w-auto max-w-[180px] object-contain object-left dark:block lg:h-9" />
                </template>
            </header>

            <div class="flex flex-1 flex-col justify-center px-6 py-10 sm:px-8 lg:px-10 lg:py-12">
                <div class="w-full max-w-[420px]">
                    <div>
                        <h1
                            v-if="title"
                            class="text-[1.65rem] font-bold leading-tight tracking-tight text-zinc-900 sm:text-[1.85rem] lg:text-[2rem] dark:text-white"
                        >
                            {{ title }}
                        </h1>
                        <p v-if="subtitle" class="mt-3 max-w-[360px] text-sm leading-relaxed text-zinc-600 dark:text-zinc-500">
                            {{ subtitle }}
                        </p>
                    </div>

                    <div class="mt-8">
                        <slot />
                    </div>

                    <div
                        v-if="$slots.footer"
                        class="mt-6 text-zinc-600 [&_a]:text-zinc-600 [&_a:hover]:text-zinc-900 dark:text-zinc-500 dark:[&_a]:text-zinc-500 dark:[&_a:hover]:text-zinc-300"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>

            <p class="shrink-0 px-6 pb-6 text-[11px] text-zinc-500 sm:px-8 lg:px-10 lg:pb-8 dark:text-zinc-600">
                © {{ new Date().getFullYear() }} {{ appName }}. Todos os direitos reservados.
            </p>
        </div>

        <div class="relative hidden min-h-screen flex-1 overflow-hidden lg:block" aria-hidden="true">
            <div class="spotlight-hero-bg absolute inset-0" />
            <div class="spotlight-doodles absolute inset-0" />

            <img
                :src="heroImage"
                alt=""
                class="absolute inset-0 h-full w-full object-cover object-[75%_center]"
            />

            <div class="spotlight-hero-overlay absolute inset-0 mix-blend-multiply" />
            <div class="spotlight-hero-fade absolute inset-0" />
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_30%_20%,rgba(255,255,255,0.1),transparent_50%)]" />

            <div class="absolute bottom-0 left-0 right-0 z-10 p-10 pb-12">
                <p class="text-[11px] font-semibold uppercase tracking-[0.25em] text-white/60">
                    {{ appName }}
                </p>
                <p class="mt-3 max-w-lg text-3xl font-bold leading-tight text-white">
                    {{ heroTagline }}
                </p>
                <p class="mt-2 max-w-md text-sm text-white/80">
                    {{ heroSubtagline }}
                </p>
            </div>
        </div>

        <CookieConsentBanner />
    </div>
</template>

<style scoped>
.spotlight-root {
    --wl-primary: v-bind(primary);
}

.spotlight-glow-primary {
    background: color-mix(in srgb, var(--wl-primary) 18%, transparent);
}

@media (min-width: 1024px) {
    .spotlight-glow-primary {
        background: color-mix(in srgb, var(--wl-primary) 10%, transparent);
    }
}

.spotlight-glow-secondary {
    background: color-mix(in srgb, var(--wl-primary) 14%, transparent);
}

.spotlight-hero-bg {
    background: linear-gradient(
        to bottom right,
        color-mix(in srgb, var(--wl-primary) 42%, #0a0a0a),
        color-mix(in srgb, var(--wl-primary) 58%, #111111),
        color-mix(in srgb, var(--wl-primary) 32%, #050505)
    );
}

.spotlight-hero-overlay {
    background: linear-gradient(
        to bottom right,
        color-mix(in srgb, var(--wl-primary) 55%, transparent),
        color-mix(in srgb, var(--wl-primary) 35%, transparent),
        color-mix(in srgb, var(--wl-primary) 45%, transparent)
    );
}

.spotlight-hero-fade {
    background: linear-gradient(
        to top,
        color-mix(in srgb, var(--wl-primary) 22%, #0a0a0a) 0%,
        transparent 65%
    );
}

.spotlight-doodles {
    opacity: 0.35;
    background-image:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140' viewBox='0 0 140 140'%3E%3Cpath fill='none' stroke='white' stroke-opacity='0.35' stroke-width='2' d='M12 70 Q50 25 88 70 T132 70'/%3E%3C/svg%3E"),
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'%3E%3Cpolygon fill='white' fill-opacity='0.12' points='50,6 58,32 86,32 64,50 72,78 50,62 28,78 36,50 14,32 42,32'/%3E%3C/svg%3E"),
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='72' height='72' viewBox='0 0 72 72'%3E%3Cpath fill='none' stroke='white' stroke-opacity='0.2' stroke-width='2' d='M18 54 L24 18 L36 42 L48 18 L54 54 Z'/%3E%3C/svg%3E"),
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='48' height='48' viewBox='0 0 48 48'%3E%3Ccircle cx='24' cy='24' r='10' fill='none' stroke='white' stroke-opacity='0.18' stroke-width='2'/%3E%3C/svg%3E");
    background-size: 200px 200px, 140px 140px, 120px 120px, 80px 80px;
    background-position: 8% 12%, 92% 8%, 78% 68%, 22% 78%;
}
</style>
