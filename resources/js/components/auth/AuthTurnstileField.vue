<script setup>
import CheckoutTurnstile from '@/components/checkout/CheckoutTurnstile.vue';

defineProps({
    config: { type: Object, default: () => ({ enabled: false, site_key: '' }) },
    modelValue: { type: String, default: '' },
    error: { type: String, default: '' },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div v-if="config?.enabled && config?.site_key" class="pt-1">
        <CheckoutTurnstile
            :site-key="config.site_key"
            :model-value="modelValue"
            appearance="always"
            theme="auto"
            size="normal"
            @update:model-value="$emit('update:modelValue', $event)"
        />
        <p v-if="error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>
</template>
