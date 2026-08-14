<script setup>
import { computed } from 'vue';
import { parseCheckoutNotice } from '@/lib/checkoutNotice';

const props = defineProps({
    text: { type: String, default: '' },
    termsHref: { type: String, default: '/termos-de-uso' },
    privacyHref: { type: String, default: '/politica-privacidade' },
});

const parts = computed(() => parseCheckoutNotice(props.text));
</script>

<template>
    <p v-if="parts.length">
        <template v-for="(part, index) in parts" :key="index">
            <a
                v-if="part.type === 'termos'"
                :href="termsHref"
                target="_blank"
                rel="noopener noreferrer"
                class="underline hover:text-gray-600"
            >Termos</a>
            <a
                v-else-if="part.type === 'privacidade'"
                :href="privacyHref"
                target="_blank"
                rel="noopener noreferrer"
                class="underline hover:text-gray-600"
            >Privacidade</a>
            <br v-else-if="part.type === 'br'" />
            <template v-else>{{ part.value }}</template>
        </template>
    </p>
</template>
