<script setup>
import { ref, computed } from 'vue';
import { Scale } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import { sanitizeHtmlAllowlist } from '@/lib/sanitizeHtml';

const props = defineProps({
    form: { type: Object, required: true },
    legalDefaults: {
        type: Object,
        default: () => ({ privacy: '', terms: '' }),
    },
});

const previewDoc = ref(null);

function restorePrivacy() {
    if (!confirm('Restaurar o texto padrão da Política de Privacidade? Alterações não salvas serão perdidas.')) return;
    props.form.legal_privacy_policy_html = props.legalDefaults.privacy ?? '';
}

function restoreTerms() {
    if (!confirm('Restaurar o texto padrão dos Termos de Uso? Alterações não salvas serão perdidas.')) return;
    props.form.legal_terms_of_use_html = props.legalDefaults.terms ?? '';
}

function openPreview(type) {
    previewDoc.value = type;
}

function closePreview() {
    previewDoc.value = null;
}

const previewHtmlSafe = computed(() => {
    let raw = '';
    if (previewDoc.value === 'privacy') {
        raw = props.form.legal_privacy_policy_html || '';
    } else if (previewDoc.value === 'terms') {
        raw = props.form.legal_terms_of_use_html || '';
    }
    return sanitizeHtmlAllowlist(raw);
});
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
            >
                <Scale class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">LGPD e documentos legais</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Edite a Política de Privacidade e os Termos de Uso exibidos publicamente. Use
                    <code v-pre class="text-xs">{{privacy_contact_email}}</code> na política para inserir o e-mail do encarregado.
                </p>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                E-mail do encarregado / privacidade
            </label>
            <input
                v-model="form.legal_privacy_contact_email"
                type="email"
                class="w-full max-w-md rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                placeholder="privacidade@exemplo.com"
            />
            <p v-if="form.errors.legal_privacy_contact_email" class="mt-1 text-sm text-red-600">
                {{ form.errors.legal_privacy_contact_email }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <label class="flex cursor-pointer items-center gap-3">
                <input v-model="form.legal_cookie_banner_enabled" type="checkbox" class="h-4 w-4 rounded border-zinc-300" />
                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Exibir banner de cookies</span>
            </label>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Política de Privacidade</h3>
                    <div class="flex flex-wrap gap-2">
                        <a
                            href="/politica-privacidade"
                            target="_blank"
                            rel="noopener"
                            class="text-xs font-medium text-[var(--color-primary)] hover:underline"
                        >
                            Ver página
                        </a>
                        <button type="button" class="text-xs text-zinc-500 hover:text-zinc-800" @click="openPreview('privacy')">
                            Pré-visualizar
                        </button>
                        <button type="button" class="text-xs text-zinc-500 hover:text-zinc-800" @click="restorePrivacy">
                            Restaurar padrão
                        </button>
                    </div>
                </div>
                <textarea
                    v-model="form.legal_privacy_policy_html"
                    rows="16"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-xs leading-relaxed dark:border-zinc-600 dark:bg-zinc-950"
                />
                <p v-if="form.errors.legal_privacy_policy_html" class="mt-1 text-sm text-red-600">
                    {{ form.errors.legal_privacy_policy_html }}
                </p>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Termos de Uso</h3>
                    <div class="flex flex-wrap gap-2">
                        <a
                            href="/termos-de-uso"
                            target="_blank"
                            rel="noopener"
                            class="text-xs font-medium text-[var(--color-primary)] hover:underline"
                        >
                            Ver página
                        </a>
                        <button type="button" class="text-xs text-zinc-500 hover:text-zinc-800" @click="openPreview('terms')">
                            Pré-visualizar
                        </button>
                        <button type="button" class="text-xs text-zinc-500 hover:text-zinc-800" @click="restoreTerms">
                            Restaurar padrão
                        </button>
                    </div>
                </div>
                <textarea
                    v-model="form.legal_terms_of_use_html"
                    rows="16"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-xs leading-relaxed dark:border-zinc-600 dark:bg-zinc-950"
                />
                <p v-if="form.errors.legal_terms_of_use_html" class="mt-1 text-sm text-red-600">
                    {{ form.errors.legal_terms_of_use_html }}
                </p>
            </div>
        </div>

        <div
            v-if="previewDoc"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closePreview"
        >
            <div class="max-h-[85vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white p-6 dark:bg-zinc-900">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-semibold text-zinc-900 dark:text-white">
                        {{ previewDoc === 'privacy' ? 'Prévia — Privacidade' : 'Prévia — Termos' }}
                    </h3>
                    <Button type="button" variant="outline" size="sm" @click="closePreview">Fechar</Button>
                </div>
                <article class="prose prose-sm max-w-none dark:prose-invert" v-html="previewHtmlSafe" />
            </div>
        </div>
    </section>
</template>
