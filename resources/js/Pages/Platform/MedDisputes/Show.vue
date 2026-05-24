<script setup>
import { Link } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';

defineOptions({ layout: LayoutPlatform });

defineProps({
    dispute: { type: Object, required: true },
});

function formatBRL(cents) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(cents) || 0) / 100);
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <Link href="/plataforma/disputas" class="text-sm text-[var(--color-primary)] hover:underline">← Disputas MED</Link>

        <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900/60">
            <h1 class="text-lg font-semibold">Disputa #{{ dispute.id }}</h1>
            <dl class="mt-4 space-y-2 text-sm">
                <div><span class="text-zinc-500">Infoprodutor:</span> {{ dispute.tenant?.name }} ({{ dispute.tenant?.email }})</div>
                <div>
                    <span class="text-zinc-500">Pedido:</span>
                    <a :href="`/plataforma/transacoes?status=all&q=${dispute.order?.id}`" class="text-[var(--color-primary)] hover:underline">
                        #{{ dispute.order?.id }}
                    </a>
                    — {{ dispute.order?.status }}
                </div>
                <div><span class="text-zinc-500">Valor:</span> {{ formatBRL(dispute.amount_cents) }}</div>
                <div><span class="text-zinc-500">Status disputa:</span> {{ dispute.status }}</div>
                <div v-if="dispute.outcome"><span class="text-zinc-500">Resultado:</span> {{ dispute.outcome }}</div>
                <div v-if="dispute.cajupay_dispute_id" class="font-mono text-xs text-zinc-500">{{ dispute.cajupay_dispute_id }}</div>
            </dl>
            <p v-if="dispute.defense_text" class="mt-4 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                <strong>Defesa:</strong> {{ dispute.defense_text }}
            </p>
        </div>
    </div>
</template>
