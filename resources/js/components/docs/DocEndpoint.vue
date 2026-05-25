<script setup>
import { computed } from 'vue';

const props = defineProps({
    method: { type: String, required: true },
    path: { type: String, required: true },
    description: { type: String, default: '' },
});

const methodClass = computed(() =>
    props.method?.toUpperCase() === 'GET' ? 'doc-endpoint-method-get' : 'doc-endpoint-method-post'
);
</script>

<template>
    <div class="doc-endpoint my-10 rounded-xl border border-white/10 bg-white/[0.02] border-l-4 border-l-teal-500/50">
        <div class="doc-endpoint-header flex flex-wrap items-center gap-2 border-b border-white/10 px-5 py-3">
            <span
                class="doc-endpoint-method rounded px-2 py-0.5 text-xs font-bold uppercase tracking-wider"
                :class="methodClass"
            >
                {{ method }}
            </span>
            <span class="font-mono text-sm text-zinc-300">{{ path }}</span>
        </div>
        <div class="px-5 py-6">
            <p v-if="description" class="mb-6 text-zinc-400 leading-relaxed">{{ description }}</p>
            <slot />
        </div>
    </div>
</template>

<style scoped>
.doc-endpoint-method-post {
    background-color: rgb(16 185 129 / 0.2);
    color: #6ee7b7;
}
.doc-endpoint-method-get {
    background-color: rgb(14 165 233 / 0.2);
    color: #7dd3fc;
}
</style>
