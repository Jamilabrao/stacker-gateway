<script setup>
import { ref } from 'vue';
import { Copy } from 'lucide-vue-next';

defineProps({
    label: { type: String, default: 'json' },
});

const codeRef = ref(null);
const copied = ref(false);

async function copyCode() {
    const el = codeRef.value;
    const text = el?.textContent?.trim() ?? '';
    if (!text) return;
    let ok = false;
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);
            ok = true;
        } catch {}
    }
    if (!ok) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            ok = document.execCommand('copy');
            document.body.removeChild(ta);
        } catch {}
    }
    if (ok) {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    }
}
</script>

<template>
    <div class="doc-code relative my-6 overflow-hidden rounded-xl border border-white/10 bg-zinc-950/80">
        <span class="doc-code-label">
            {{ label }}
        </span>
        <button
            type="button"
            class="doc-code-copy"
            title="Copiar"
            @click="copyCode"
        >
            <Copy v-if="!copied" class="h-3.5 w-3.5" />
            <span v-else class="text-emerald-400">Copiado!</span>
        </button>
        <pre class="doc-code-pre"><code ref="codeRef" class="block overflow-x-auto p-4 pt-10 pr-12 text-sm text-zinc-300"><slot /></code></pre>
    </div>
</template>

<style scoped>
@reference "../../../css/app.css";

.doc-code-label {
    @apply absolute left-3 top-2.5 z-[1] rounded bg-white/10 px-2 py-0.5 font-mono text-xs uppercase tracking-wider text-zinc-500;
}
.doc-code-copy {
    @apply absolute right-3 top-2.5 z-[1] flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-xs font-medium text-zinc-400 transition hover:bg-white/10 hover:text-white focus:outline-none;
}
.doc-code-pre {
    @apply m-0 bg-transparent p-0 font-mono;
    font-family: ui-monospace, 'JetBrains Mono', monospace;
}
</style>
