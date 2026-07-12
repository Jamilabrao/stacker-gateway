<script setup>
import { useSidebar } from '@/composables/useSidebar';

const { toggleMobileSidebar, isMobileOpen } = useSidebar();
</script>

<template>
    <div
        v-if="isMobileOpen"
        class="fixed inset-0 z-[99998] touch-manipulation bg-zinc-900/50 lg:hidden"
        aria-hidden="true"
        role="presentation"
        @click="toggleMobileSidebar"
        @touchend.prevent="toggleMobileSidebar"
    />
</template>
