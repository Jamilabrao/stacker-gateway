<script setup>
import { onUnmounted, watch } from 'vue';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';
import { useThemedPageHeading } from '@/composables/useThemedPageHeading';

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
});

const { isThemedShell } = useSellerDashboardTemplate();
const { setHeading, clearHeading } = useThemedPageHeading();

watch(
    () => [props.title, props.subtitle, isThemedShell.value],
    () => {
        if (isThemedShell.value) {
            setHeading({ title: props.title, subtitle: props.subtitle });
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    if (isThemedShell.value) {
        clearHeading();
    }
});
</script>

<template>
    <header v-if="!isThemedShell">
        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
            {{ title }}
        </h1>
        <p v-if="subtitle" class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {{ subtitle }}
        </p>
    </header>
</template>
