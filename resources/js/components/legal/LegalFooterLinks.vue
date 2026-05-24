<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const legal = computed(() => page.props.legal ?? {});
const privacyUrl = computed(() => legal.value.privacy_url || '/politica-privacidade');
const termsUrl = computed(() => legal.value.terms_url || '/termos-de-uso');
</script>

<template>
    <p class="text-center text-xs text-zinc-500 dark:text-zinc-400">
        <Link :href="termsUrl" class="hover:underline" target="_blank" rel="noopener">Termos de Uso</Link>
        <span class="mx-1.5">·</span>
        <Link :href="privacyUrl" class="hover:underline" target="_blank" rel="noopener">Política de Privacidade</Link>
    </p>
</template>
