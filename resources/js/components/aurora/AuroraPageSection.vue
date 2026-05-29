<script setup>
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

defineProps({
    flush: { type: Boolean, default: false },
});

const { isAurora, isKawaii, isThemedShell } = useSellerDashboardTemplate();
</script>

<template>
    <section
        v-if="isAurora"
        class="aurora-page-section"
        :class="flush ? 'aurora-page-section-flush' : ''"
    >
        <slot />
    </section>
    <section
        v-else-if="isKawaii"
        class="kawaii-page-section"
    >
        <slot />
    </section>
    <div v-else>
        <slot />
    </div>
</template>
