<script setup>
import { ref } from 'vue';
import { Copy } from 'lucide-vue-next';

defineProps({
    label: { type: String, default: 'json' },
});

const codeRef = ref(null);
const copied = ref(false);

async function copyCode() {
    const text = codeRef.value?.textContent?.trim() ?? '';
    if (!text) return;

    let ok = false;
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);
            ok = true;
        } catch {
            /* fallback */
        }
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
        } catch {
            /* ignore */
        }
    }
    if (ok) {
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    }
}
</script>

<template>
    <div class="doc-code relative my-6 overflow-hidden rounded-xl border border-white/10 bg-zinc-950/80">
        <span class="doc-code-label">{{ label }}</span>
        <button type="button" class="doc-code-copy" title="Copiar" @click="copyCode">
            <span v-if="copied" class="text-emerald-400">Copiado!</span>
            <Copy v-else class="h-3.5 w-3.5" />
        </button>
        <pre class="doc-code-pre">
            <code ref="codeRef" class="block overflow-x-auto p-4 pt-10 pr-12 text-sm text-zinc-300"><slot /></code>
        </pre>
    </div>
</template>

<style scoped>
.doc-code-label {
    position: absolute;
    top: 0.625rem;
    left: 0.75rem;
    z-index: 1;
    border-radius: 0.25rem;
    background: rgb(255 255 255 / 0.1);
    padding: 0.125rem 0.5rem;
    font-family: ui-monospace, monospace;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
    color: #71717a;
    text-transform: uppercase;
}
.doc-code-copy {
    position: absolute;
    top: 0.625rem;
    right: 0.75rem;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 0.375rem;
    border-radius: 0.5rem;
    border: 1px solid rgb(255 255 255 / 0.1);
    background: rgb(255 255 255 / 0.05);
    padding: 0.375rem 0.625rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: #a1a1aa;
}
.doc-code-copy:hover {
    background: rgb(255 255 255 / 0.1);
    color: #fff;
}
.doc-code-pre {
    margin: 0;
    padding: 0;
    font-family: ui-monospace, 'JetBrains Mono', monospace;
    background: transparent;
}
</style>
