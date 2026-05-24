<script setup>
import LegalPageLayout from '@/Layouts/LegalPageLayout.vue';

defineOptions({ layout: LegalPageLayout });

defineProps({
    title: { type: String, default: 'Política de Privacidade' },
    html: { type: String, default: '' },
    updated_at: { type: String, default: null },
});
</script>

<template>
    <div>
        <h1 class="mb-2 text-2xl font-bold tracking-tight">{{ title }}</h1>
        <p v-if="updated_at" class="mb-8 text-sm text-zinc-500">
            Última atualização: {{ new Date(updated_at).toLocaleDateString('pt-BR') }}
        </p>
        <article
            class="legal-document prose prose-zinc max-w-none dark:prose-invert prose-headings:scroll-mt-20 prose-a:text-[var(--color-primary)]"
            v-html="html"
        />
    </div>
</template>

<style scoped>
.legal-document :deep(h1) {
    display: none;
}
.legal-document :deep(h2) {
    margin-top: 1.75rem;
    font-size: 1.125rem;
    font-weight: 600;
}
.legal-document :deep(p),
.legal-document :deep(li) {
    font-size: 0.9375rem;
    line-height: 1.65;
}
</style>
