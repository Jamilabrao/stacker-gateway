<script setup>
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
});

const { isKawaii } = useSellerDashboardTemplate();
</script>

<template>
    <div v-if="isKawaii" class="kawaii-stat-card">
        <p class="kawaii-stat-card-label">{{ label }}</p>
        <p class="kawaii-stat-card-value">{{ value }}</p>
    </div>
    <slot v-else />
</template>
