<script setup>
import { useForm, usePage, Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    dispute: { type: Object, required: true },
});

const page = usePage();

const defenseForm = useForm({
    text: '',
    attachments: [],
});

const resolveForm = useForm({
    outcome: 'won',
    note: '',
});

function formatBRL(cents) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(cents) || 0) / 100);
}

function onFiles(e) {
    defenseForm.attachments = Array.from(e.target.files || []);
}

function submitDefense() {
    defenseForm.post(`/plataforma/disputas/${props.dispute.id}/defesa`, {
        forceFormData: true,
        preserveScroll: true,
    });
}

function generateDossier() {
    router.post(`/plataforma/disputas/${props.dispute.id}/gerar-dossie`, {}, { preserveScroll: true });
}

function submitResolve() {
    resolveForm.post(`/plataforma/disputas/${props.dispute.id}/resolver`, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <Link href="/plataforma/disputas" class="text-sm text-[var(--color-primary)] hover:underline">← Disputas MED</Link>

        <div
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ page.props.flash.success }}
        </div>
        <div
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            {{ page.props.flash.error }}
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900/60">
            <h1 class="text-lg font-semibold">Disputa #{{ dispute.id }}</h1>
            <dl class="mt-4 space-y-2 text-sm">
                <div><span class="text-zinc-500">Responsável:</span> {{ dispute.responsible_party === 'platform' ? 'Plataforma' : 'Infoprodutor' }}</div>
                <div><span class="text-zinc-500">Origem:</span> {{ dispute.order_origin ?? '—' }}</div>
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
                <div v-if="dispute.reason"><span class="text-zinc-500">Motivo:</span> {{ dispute.reason }}</div>
                <div v-if="dispute.outcome"><span class="text-zinc-500">Resultado:</span> {{ dispute.outcome }}</div>
                <div v-if="dispute.cajupay_dispute_id" class="font-mono text-xs text-zinc-500">{{ dispute.cajupay_dispute_id }}</div>
            </dl>
            <p v-if="dispute.defense_text" class="mt-4 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                <strong>Defesa:</strong> {{ dispute.defense_text }}
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                <Button type="button" variant="outline" @click="generateDossier">Gerar dossiê PDF</Button>
                <a
                    v-if="dispute.has_dossier"
                    :href="`/plataforma/disputas/${dispute.id}/dossie`"
                    class="inline-flex items-center rounded-lg border border-zinc-300 px-3 py-2 text-sm hover:bg-zinc-50 dark:border-zinc-600"
                >
                    Baixar dossiê
                </a>
            </div>
        </div>

        <form
            v-if="dispute.is_open"
            class="space-y-4 rounded-2xl border border-orange-200 bg-orange-50/50 p-6 dark:border-orange-900/50 dark:bg-orange-950/20"
            @submit.prevent="submitDefense"
        >
            <h2 class="font-medium">Enviar defesa ao gateway</h2>
            <textarea
                v-model="defenseForm.text"
                rows="5"
                required
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                placeholder="Texto da defesa..."
            />
            <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-sm" @change="onFiles" />
            <Button type="submit" :disabled="defenseForm.processing">Enviar defesa</Button>
        </form>

        <form
            v-if="dispute.is_open"
            class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900/60"
            @submit.prevent="submitResolve"
        >
            <h2 class="font-medium">Resolver MED manualmente</h2>
            <select v-model="resolveForm.outcome" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                <option value="won">Vitória (won)</option>
                <option value="lost">Derrota (lost)</option>
                <option value="cancelled">Cancelada</option>
            </select>
            <textarea
                v-model="resolveForm.note"
                rows="2"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                placeholder="Nota interna (opcional)"
            />
            <Button type="submit" variant="destructive" :disabled="resolveForm.processing">Confirmar resolução</Button>
        </form>
    </div>
</template>
