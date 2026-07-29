<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import LayoutDoc from '@/Layouts/LayoutDoc.vue';
import DocApiPlayground from '@/components/docs/DocApiPlayground.vue';
import { FlaskConical, ListOrdered } from 'lucide-vue-next';

defineOptions({ layout: LayoutDoc });

const props = defineProps({
    baseUrl: { type: String, default: '' },
});

const initialOpId = computed(() => {
    const params = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
    return params.get('op') ?? '';
});
</script>

<template>
    <div class="py-6 lg:py-8">
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <Link
                href="/docs/api-pagamentos"
                class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm font-medium text-zinc-400 transition hover:border-teal-500/30 hover:bg-white/10 hover:text-white"
            >
                ← Documentação
            </Link>
            <Link
                href="/aplicacoes-api"
                class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm font-medium text-zinc-400 transition hover:border-teal-500/30 hover:bg-white/10 hover:text-white"
            >
                Chaves da API
            </Link>
        </div>

        <div class="mb-6">
            <div class="flex items-center gap-2">
                <FlaskConical class="h-6 w-6 text-teal-400" />
                <h1 class="text-2xl font-bold text-white">Testar API</h1>
            </div>
            <p class="mt-2 max-w-3xl text-sm leading-relaxed text-zinc-400">
                Playground interativo para PIX e saques. As credenciais ficam apenas nesta aba do navegador
                (<code class="rounded bg-white/10 px-1">sessionStorage</code>) — use somente em ambiente de testes.
            </p>
        </div>

        <div class="mb-6 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200/90">
            Obtenha o par <strong>Public key + Secret key</strong> em
            <Link href="/aplicacoes-api" class="underline hover:text-amber-100">Chaves da API</Link>.
            Deixe <strong>IPs permitidos</strong> vazio ou inclua seu IP.
            <Link href="/docs/api-pagamentos#erros-comuns" class="ml-1 underline hover:text-amber-100">Erros comuns →</Link>
        </div>

        <div class="mb-8 grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-white/10 bg-white/[0.02] p-4">
                <div class="mb-2 flex items-center gap-2 text-sm font-semibold text-teal-300">
                    <ListOrdered class="h-4 w-4" />
                    Fluxo PIX
                </div>
                <ol class="list-decimal space-y-1 pl-5 text-sm text-zinc-400">
                    <li>Preencha credenciais</li>
                    <li><strong class="text-zinc-300">POST /payments/pix</strong> — criar cobrança</li>
                    <li>Copie o <code class="rounded bg-white/10 px-1">copy_paste</code> da resposta</li>
                    <li><strong class="text-zinc-300">GET /payments/{order_id}</strong> — consultar status</li>
                </ol>
            </div>
            <div class="rounded-xl border border-white/10 bg-white/[0.02] p-4">
                <div class="mb-2 flex items-center gap-2 text-sm font-semibold text-teal-300">
                    <ListOrdered class="h-4 w-4" />
                    Fluxo saque
                </div>
                <ol class="list-decimal space-y-1 pl-5 text-sm text-zinc-400">
                    <li><strong class="text-zinc-300">GET /balance</strong> — ver saldo</li>
                    <li><strong class="text-zinc-300">PUT /payout-destination</strong> — validar chave PIX (não altera master)</li>
                    <li><strong class="text-zinc-300">POST /withdrawals</strong> — solicitar saque</li>
                    <li><strong class="text-zinc-300">GET /withdrawals/{id}</strong> — acompanhar</li>
                </ol>
            </div>
        </div>

        <DocApiPlayground :base-url="baseUrl" :initial-op-id="initialOpId" />
    </div>
</template>
