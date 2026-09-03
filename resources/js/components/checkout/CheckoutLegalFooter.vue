<script setup>
import { Shield } from 'lucide-vue-next';
import CheckoutPlatformNotice from './CheckoutPlatformNotice.vue';

defineProps({
    logoUrl: { type: String, default: '' },
    appName: { type: String, default: '' },
    notice: { type: String, default: '' },
    showSecureLine: { type: Boolean, default: true },
});
</script>

<template>
    <div data-checkout="legal-footer">
        <div v-if="logoUrl" class="mb-4 flex justify-center" data-checkout="platform-logo">
            <img
                :src="logoUrl"
                :alt="appName"
                class="h-8 w-auto max-w-[180px] object-contain"
                loading="lazy"
            />
        </div>
        <p v-if="showSecureLine" class="flex items-center justify-center gap-2 text-sm text-gray-500">
            <Shield class="h-4 w-4 shrink-0" aria-hidden="true" />
            Compra 100% segura
        </p>
        <p class="text-center text-xs text-gray-400" :class="showSecureLine ? 'mt-2' : ''">
            Este site é protegido pelo reCAPTCHA do Google
        </p>
        <p class="mt-2 text-center text-xs text-gray-400">
            Copyright © {{ new Date().getFullYear() }}. Todos os direitos reservados.
        </p>
        <CheckoutPlatformNotice
            v-if="notice"
            data-checkout="platform-notice"
            class="mt-3 text-center text-xs leading-relaxed text-gray-400"
            :text="notice"
        />
        <p v-else class="mt-3 text-center text-xs text-gray-400">
            <a href="/termos-de-uso" target="_blank" rel="noopener" class="underline hover:text-gray-600">Termos</a>
            <span class="mx-1">·</span>
            <a href="/politica-privacidade" target="_blank" rel="noopener" class="underline hover:text-gray-600">Privacidade</a>
        </p>
    </div>
</template>
