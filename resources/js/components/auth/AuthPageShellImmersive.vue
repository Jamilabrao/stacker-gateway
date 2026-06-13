<script setup>
import { computed } from 'vue';
import { useAuthBranding } from '@/composables/useAuthBranding';
import CookieConsentBanner from '@/components/legal/CookieConsentBanner.vue';

const { primary, appName, logoLight, logoDark, heroImage, heroTagline, branding } = useAuthBranding();

const props = defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    variant: { type: String, default: 'seller' },
    wide: { type: Boolean, default: false },
});

const cardClass = computed(() => (
    props.wide
        ? 'w-full max-w-lg'
        : 'w-full max-w-[440px]'
));

const immersiveLogo = computed(() => {
    const full = branding.value.app_logo;
    if (full && String(full).trim() !== '') {
        return full;
    }
    return null;
});
</script>

<template>
    <div class="immersive-root relative min-h-screen overflow-hidden">
        <div class="immersive-bg absolute inset-0" aria-hidden="true">
            <img :src="heroImage" alt="" class="immersive-bg-image h-full w-full object-cover" />
        </div>

        <div class="immersive-gradient absolute inset-0" aria-hidden="true" />
        <div class="immersive-vignette absolute inset-0" aria-hidden="true" />
        <div class="immersive-grain absolute inset-0" aria-hidden="true" />

        <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            <div class="immersive-orb immersive-orb-a absolute h-80 w-80 rounded-full blur-[100px]" />
            <div class="immersive-orb immersive-orb-b absolute h-64 w-64 rounded-full blur-[80px]" />
        </div>

        <div class="relative z-10 flex min-h-screen flex-col items-center justify-center px-5 py-10 sm:px-6">
            <div :class="cardClass">
                <div class="immersive-card rounded-3xl border border-white/60 bg-white/82 p-6 shadow-2xl backdrop-blur-2xl dark:border-white/10 dark:bg-black/45 sm:p-8">
                    <div class="mb-6 flex justify-center">
                        <img
                            v-if="immersiveLogo"
                            :src="immersiveLogo"
                            :alt="appName"
                            class="h-9 w-auto max-w-[180px] object-contain"
                        />
                        <template v-else>
                            <img :src="logoLight" :alt="appName" class="h-9 w-auto max-w-[180px] object-contain dark:hidden" />
                            <img :src="logoDark" :alt="appName" class="hidden h-9 w-auto max-w-[180px] object-contain dark:block" />
                        </template>
                    </div>

                    <div class="text-center">
                        <h1
                            v-if="title"
                            class="text-xl font-bold leading-tight tracking-tight text-zinc-900 sm:text-2xl dark:text-white"
                        >
                            {{ title }}
                        </h1>
                        <p v-if="subtitle" class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-white/55">
                            {{ subtitle }}
                        </p>
                        <p
                            v-if="heroTagline"
                            class="mt-3 text-xs font-medium uppercase tracking-[0.2em] text-zinc-400 dark:text-white/35"
                        >
                            {{ heroTagline }}
                        </p>
                    </div>

                    <div class="mt-7">
                        <slot />
                    </div>

                    <div
                        v-if="$slots.footer"
                        class="mt-6 text-center text-zinc-500 [&_a]:text-zinc-500 [&_a:hover]:text-zinc-800 dark:text-white/45 dark:[&_a]:text-white/45 dark:[&_a:hover]:text-white/70"
                    >
                        <slot name="footer" />
                    </div>
                </div>

                <p class="mt-5 text-center text-[11px] text-zinc-500 dark:text-white/35">
                    © {{ new Date().getFullYear() }} {{ appName }}. Todos os direitos reservados.
                </p>
            </div>
        </div>

        <CookieConsentBanner />
    </div>
</template>

<style scoped>
.immersive-root {
    --wl-primary: v-bind(primary);
}

.immersive-bg-image {
    animation: immersiveKenBurns 22s ease-in-out infinite alternate;
}

@keyframes immersiveKenBurns {
    from { transform: scale(1); }
    to { transform: scale(1.06); }
}

@media (prefers-reduced-motion: reduce) {
    .immersive-bg-image { animation: none; }
    .immersive-orb { animation: none !important; }
}

.immersive-gradient {
    background: linear-gradient(
        135deg,
        color-mix(in srgb, var(--wl-primary) 22%, transparent) 0%,
        color-mix(in srgb, #ffffff 40%, transparent) 50%,
        color-mix(in srgb, #ffffff 65%, transparent) 100%
    );
}

:global(.dark) .immersive-gradient {
    background: linear-gradient(
        135deg,
        color-mix(in srgb, var(--wl-primary) 38%, transparent) 0%,
        color-mix(in srgb, #000000 55%, transparent) 45%,
        color-mix(in srgb, #000000 75%, transparent) 100%
    );
}

.immersive-vignette {
    background: radial-gradient(ellipse at center, transparent 50%, rgba(255, 255, 255, 0.35) 100%);
}

:global(.dark) .immersive-vignette {
    background: radial-gradient(ellipse at center, transparent 35%, rgba(0, 0, 0, 0.65) 100%);
}

.immersive-grain {
    opacity: 0.04;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
}

.immersive-orb-a {
    top: 10%;
    left: 5%;
    background: color-mix(in srgb, var(--wl-primary) 22%, transparent);
    animation: immersiveFloatA 14s ease-in-out infinite alternate;
}

.immersive-orb-b {
    bottom: 15%;
    right: 8%;
    background: color-mix(in srgb, var(--wl-primary) 16%, transparent);
    animation: immersiveFloatB 18s ease-in-out infinite alternate;
}

@keyframes immersiveFloatA {
    from { transform: translate(0, 0); }
    to { transform: translate(30px, 20px); }
}

@keyframes immersiveFloatB {
    from { transform: translate(0, 0); }
    to { transform: translate(-25px, -15px); }
}

.immersive-card {
    box-shadow:
        0 25px 50px -12px rgba(0, 0, 0, 0.15),
        0 0 60px color-mix(in srgb, var(--wl-primary) 12%, transparent);
}

:global(.dark) .immersive-card {
    box-shadow:
        0 25px 50px -12px rgba(0, 0, 0, 0.55),
        0 0 80px color-mix(in srgb, var(--wl-primary) 18%, transparent);
}
</style>
