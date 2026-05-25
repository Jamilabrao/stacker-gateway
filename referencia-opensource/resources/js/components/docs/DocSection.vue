<script setup>
defineProps({
    id: { type: String, default: '' },
    title: { type: String, default: '' },
});
</script>

<template>
    <section
        v-if="id || $slots.default"
        :id="id"
        class="doc-section scroll-mt-28"
    >
        <h2 v-if="title" class="doc-section-title">
            {{ title }}
        </h2>
        <slot />
    </section>
</template>

<style scoped>
@reference "../../../css/app.css";

.doc-section-title {
    @apply mb-6 mt-16 border-t border-white/10 pt-8 text-xl font-semibold tracking-tight text-white;
}
.doc-section-title:first-child {
    @apply mt-8 border-t-0 pt-0;
}
</style>
