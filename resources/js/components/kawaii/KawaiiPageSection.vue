<script setup>
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

defineProps({
    title: { type: String, default: '' },
});

const { isKawaii } = useSellerDashboardTemplate();
</script>

<template>
    <section
        v-if="isKawaii"
        class="kawaii-card p-5"
    >
        <h2 v-if="title" class="kawaii-fg text-sm font-extrabold">{{ title }}</h2>
        <slot />
    </section>
    <div v-else>
        <slot />
    </div>
</template>
