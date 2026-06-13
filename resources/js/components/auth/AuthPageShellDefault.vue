<script setup>
import CookieConsentBanner from '@/components/legal/CookieConsentBanner.vue';
import { useAuthBranding } from '@/composables/useAuthBranding';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    variant: { type: String, default: 'seller' },
    wide: { type: Boolean, default: false },
});

const { primary, appName, logoLight, logoDark, heroImage } = useAuthBranding();
</script>

<template>
    <div class="wl-root flex min-h-screen">
        <div
            class="flex w-full flex-col justify-center px-8 py-12"
            :class="wide ? 'lg:min-w-[420px] lg:w-[42%]' : 'lg:min-w-[360px] lg:w-[30%]'"
        >
            <div class="text-center">
                <img
                    :src="logoLight"
                    :alt="appName"
                    class="mx-auto mb-10 h-12 w-auto object-contain dark:hidden"
                />
                <img
                    :src="logoDark"
                    :alt="appName"
                    class="mx-auto mb-10 hidden h-12 w-auto object-contain dark:block"
                />
                <h1 v-if="title" class="text-2xl font-bold text-zinc-900 dark:text-white">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ subtitle }}</p>
            </div>

            <slot />

            <template v-if="$slots.footer">
                <slot name="footer" />
            </template>
        </div>

        <CookieConsentBanner />

        <div
            class="relative hidden overflow-hidden bg-zinc-100 dark:bg-zinc-900 lg:flex lg:flex-1 lg:items-center lg:justify-center"
            aria-hidden="true"
        >
            <div
                class="hero-gradient absolute inset-0"
                :style="{
                    background: `linear-gradient(to bottom right, color-mix(in srgb, ${primary} 20%, transparent), transparent, rgba(14, 165, 233, 0.1))`,
                }"
            />
            <img :src="heroImage" alt="" class="absolute inset-0 h-full w-full object-cover" />
            <div class="absolute inset-0 bg-gradient-to-t from-black/25 via-black/5 to-transparent" />
        </div>
    </div>
</template>

<style scoped>
.wl-root {
    --wl-primary: v-bind(primary);
}
</style>
