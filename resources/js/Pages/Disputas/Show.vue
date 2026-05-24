<script setup>
import { useForm, usePage, Link } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import VendasTabs from '@/components/vendas/VendasTabs.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    dispute: { type: Object, required: true },
});

const page = usePage();

const form = useForm({
    text: '',
    attachments: [],
});

function formatBRL(cents) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(cents) || 0) / 100);
}

function onFiles(e) {
    form.attachments = Array.from(e.target.files || []);
}

function submitDefense() {
    form.post(`/vendas/disputas/${props.dispute.id}/defesa`, {
        forceFormData: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <VendasTabs />
        <Link href="/vendas/disputas" class="text-sm text-[var(--color-primary)] hover:underline">← Voltar às disputas</Link>

        <div
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/40"
        >
            {{ page.props.flash.success }}
        </div>
        <div
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900/50 dark:bg-red-950/40"
        >
            {{ page.props.flash.error }}
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <h1 class="text-lg font-semibold text-zinc-900 dark:text-white">Disputa MED — pedido #{{ dispute.order?.public_reference ?? dispute.order?.id }}</h1>
            <dl class="mt-4 grid gap-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-500">Valor contestado</dt>
                    <dd class="font-medium">{{ formatBRL(dispute.amount_cents) }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-500">Status</dt>
                    <dd>{{ dispute.status }}</dd>
                </div>
                <div v-if="dispute.txid" class="flex justify-between gap-4">
                    <dt class="text-zinc-500">TXID</dt>
                    <dd class="truncate font-mono text-xs">{{ dispute.txid }}</dd>
                </div>
                <div v-if="dispute.defended_at" class="flex justify-between gap-4">
                    <dt class="text-zinc-500">Defesa enviada em</dt>
                    <dd>{{ new Date(dispute.defended_at).toLocaleString('pt-BR') }}</dd>
                </div>
            </dl>
            <p v-if="dispute.defense_text" class="mt-4 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                {{ dispute.defense_text }}
            </p>
        </div>

        <form
            v-if="dispute.is_open"
            class="space-y-4 rounded-2xl border border-orange-200 bg-orange-50/50 p-6 dark:border-orange-900/50 dark:bg-orange-950/20"
            @submit.prevent="submitDefense"
        >
            <h2 class="font-medium text-zinc-900 dark:text-white">Enviar defesa à CajuPay</h2>
            <p class="text-xs text-zinc-600 dark:text-zinc-400">
                Descreva a entrega do produto/serviço. Até 10 anexos (PDF, JPG, PNG), 8 MiB cada.
            </p>
            <textarea
                v-model="form.text"
                rows="6"
                required
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                placeholder="Explique o motivo da venda e comprove a entrega..."
            />
            <p v-if="form.errors.text" class="text-xs text-red-600">{{ form.errors.text }}</p>
            <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-sm" @change="onFiles" />
            <p v-if="form.errors.attachments" class="text-xs text-red-600">{{ form.errors.attachments }}</p>
            <Button type="submit" :disabled="form.processing">Enviar defesa</Button>
        </form>

        <p v-else class="text-sm text-zinc-500">Esta disputa não aceita mais defesa.</p>
    </div>
</template>
