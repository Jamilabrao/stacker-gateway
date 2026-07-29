<script setup>
import LayoutDoc from '@/Layouts/LayoutDoc.vue';
import { ArrowLeft, Download, Sparkles } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: LayoutDoc });

const props = defineProps({
    baseUrl: { type: String, default: '' },
    llmBundleFilename: { type: String, default: 'plataforma-api-integracao-llm.md' },
});

/** Sempre relativo ao host atual — evita APP_URL/localhost apontando para outro endereço. */
const downloadUrl = '/docs/api-pagamentos/llm/download';

const downloading = ref(false);
const downloadError = ref('');

/**
 * O atributo HTML `download` falha com frequência atrás de Cloudflare/Caddy/redirect
 * (Chrome: "Site wasn't available"). Fetch + blob usa o mesmo origin e Content-Disposition.
 */
async function downloadLlmBundle() {
    downloadError.value = '';
    downloading.value = true;
    try {
        const res = await fetch(downloadUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'text/markdown, text/plain, */*' },
        });
        if (!res.ok) {
            let detail = `HTTP ${res.status}`;
            try {
                const text = (await res.text()).trim();
                if (text) {
                    const plain = text
                        .replace(/<[^>]+>/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim();
                    if (plain) {
                        detail = plain.slice(0, 180);
                    }
                }
            } catch (_) {
                // ignore body parse errors
            }
            throw new Error(detail);
        }
        const blob = await res.blob();
        const objectUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = objectUrl;
        a.download = props.llmBundleFilename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(objectUrl);
    } catch (err) {
        const msg = err instanceof Error && err.message ? err.message : '';
        downloadError.value = msg
            ? `Não foi possível baixar: ${msg}`
            : 'Não foi possível baixar automaticamente. Tente novamente ou abra o link direto.';
    } finally {
        downloading.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <Link
            href="/docs/api-pagamentos"
            class="mb-8 inline-flex items-center gap-2 text-sm font-medium text-zinc-400 transition hover:text-teal-300"
        >
            <ArrowLeft class="h-4 w-4" />
            Voltar à documentação
        </Link>

        <div
            class="relative overflow-hidden rounded-2xl border border-teal-500/20 bg-gradient-to-br from-teal-500/10 via-zinc-900 to-zinc-950 px-6 py-10 sm:px-10"
        >
            <div
                class="pointer-events-none absolute inset-0 opacity-30"
                style="
                    background-image: linear-gradient(rgba(20, 184, 166, 0.15) 1px, transparent 1px),
                        linear-gradient(90deg, rgba(20, 184, 166, 0.15) 1px, transparent 1px);
                    background-size: 32px 32px;
                "
            />
            <div class="relative">
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex items-center -space-x-1.5">
                        <span
                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-white/10 bg-zinc-900 p-1.5"
                        >
                            <img
                                src="/images/docs/openai-chatgpt.svg"
                                alt="ChatGPT"
                                class="h-full w-full object-contain brightness-0 invert"
                            />
                        </span>
                        <span
                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-white/10 bg-zinc-900 p-1.5"
                        >
                            <img
                                src="/images/docs/claude-ai.svg"
                                alt="Claude"
                                class="h-full w-full object-contain"
                            />
                        </span>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-teal-500/30 bg-teal-500/10 px-3 py-1 text-xs font-medium text-teal-300"
                    >
                        <Sparkles class="h-3.5 w-3.5" />
                        Hub de integração via IA
                    </span>
                </div>
                <h1 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">Integrar com IA</h1>
                <p class="mt-4 max-w-2xl text-base leading-relaxed text-zinc-400">
                    Baixe o pacote Markdown completo da API e do SDK para usar em
                    <strong class="text-zinc-200">ChatGPT</strong>,
                    <strong class="text-zinc-200">Claude Code</strong> ou
                    <strong class="text-zinc-200">Cursor</strong>. O arquivo inclui instruções para o modelo,
                    boas práticas de fluxo e segurança (webhook + reconciliação) e a referência completa dos
                    endpoints.
                </p>
            </div>
        </div>

        <div class="mt-8">
            <div
                class="rounded-xl border border-white/10 bg-zinc-900/50 p-6 transition hover:border-teal-500/30 hover:bg-zinc-900/80"
            >
                <div class="flex items-start gap-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-teal-500/15 text-teal-400"
                    >
                        <Download class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold text-white">Baixar documentação para IA</h2>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-400">
                            Pacote Markdown completo da API e integração. Ideal para janelas de contexto longas em
                            assistentes de código e chat.
                        </p>
                        <button
                            type="button"
                            class="mt-4 inline-flex items-center gap-2 rounded-lg bg-teal-500 px-4 py-2.5 text-sm font-semibold text-zinc-950 transition hover:bg-teal-400 disabled:cursor-wait disabled:opacity-70"
                            :disabled="downloading"
                            @click="downloadLlmBundle"
                        >
                            <Download class="h-4 w-4" />
                            {{ downloading ? 'Baixando…' : `Baixar ${llmBundleFilename}` }}
                        </button>
                        <p v-if="downloadError" class="mt-3 text-sm text-amber-300/90">
                            {{ downloadError }}
                        </p>
                        <p class="mt-3 text-xs text-zinc-500">
                            Se o download falhar,
                            <a :href="downloadUrl" class="text-teal-400 hover:underline" target="_blank" rel="noopener">
                                abra o arquivo em nova aba
                            </a>
                            .
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <p class="mt-8 text-center text-sm text-zinc-500">
            Prefere navegar na web?
            <Link href="/docs/api-pagamentos#confirmacao-pagamento-fallbacks" class="text-teal-400 hover:underline">
                Ver seção de confirmação e fallbacks
            </Link>
        </p>
    </div>
</template>
