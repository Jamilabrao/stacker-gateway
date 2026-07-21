<script setup>
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    tab: { type: String, default: 'config' },
    settings: { type: Object, required: true },
    referrals: { type: Array, default: () => [] },
    withdrawals: { type: Array, default: () => [] },
    sellers: { type: Array, default: () => [] },
    pending_withdrawals_count: { type: Number, default: 0 },
});

const settingsForm = useForm({
    enabled: !!props.settings.enabled,
    commission_percent: props.settings.commission_percent ?? 20,
    eligibility_days: props.settings.eligibility_days ?? 365,
    rules_html: props.settings.rules_html ?? '',
    min_withdrawal: props.settings.min_withdrawal ?? 50,
    cookie_days: props.settings.cookie_days ?? 30,
});

watch(
    () => props.settings,
    (s) => {
        settingsForm.enabled = !!s.enabled;
        settingsForm.commission_percent = s.commission_percent ?? 20;
        settingsForm.eligibility_days = s.eligibility_days ?? 365;
        settingsForm.rules_html = s.rules_html ?? '';
        settingsForm.min_withdrawal = s.min_withdrawal ?? 50;
        settingsForm.cookie_days = s.cookie_days ?? 30;
    },
    { deep: true }
);

const assignForm = useForm({
    referred_user_id: '',
    referred_by_user_id: '',
    force: false,
});

const stepUpOpen = ref(false);
const stepUpLoading = ref(false);
const stepUpAction = ref(null);
const stepUpRequirePin = ref(false);
const pendingPayload = ref(null);

function goTab(tab) {
    router.get('/plataforma/indique-e-ganhe', { tab }, { preserveState: true, preserveScroll: true, replace: true });
}

function formatBRL(value) {
    return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('pt-BR');
    } catch {
        return '—';
    }
}

function openStepUp(action, payload = null, requirePin = false) {
    stepUpAction.value = action;
    pendingPayload.value = payload;
    stepUpRequirePin.value = requirePin;
    stepUpOpen.value = true;
}

function onStepUpConfirm({ totp_code, manual_approval_pin }) {
    stepUpLoading.value = true;
    const extras = {
        totp_code: totp_code || undefined,
        manual_approval_pin: manual_approval_pin || undefined,
    };

    const done = {
        preserveScroll: true,
        onFinish: () => {
            stepUpLoading.value = false;
            stepUpOpen.value = false;
        },
    };

    if (stepUpAction.value === 'settings') {
        settingsForm.transform((data) => ({ ...data, ...extras })).put('/plataforma/indique-e-ganhe', done);
        return;
    }

    if (stepUpAction.value === 'assign') {
        assignForm
            .transform((data) => ({
                ...data,
                referred_user_id: Number(data.referred_user_id),
                referred_by_user_id: data.referred_by_user_id ? Number(data.referred_by_user_id) : null,
                force: !!data.force,
                ...extras,
            }))
            .post('/plataforma/indique-e-ganhe/atribuir', done);
        return;
    }

    if (stepUpAction.value === 'approve') {
        router.post(
            `/plataforma/indique-e-ganhe/saques/${pendingPayload.value}/aprovar`,
            extras,
            done
        );
        return;
    }

    if (stepUpAction.value === 'reject') {
        const reason = window.prompt('Motivo da rejeição (opcional):') || '';
        router.post(
            `/plataforma/indique-e-ganhe/saques/${pendingPayload.value}/rejeitar`,
            { ...extras, reason },
            done
        );
    }
}

function saveSettings() {
    openStepUp('settings');
}

function submitAssign() {
    openStepUp('assign');
}

const statusLabel = {
    pending: 'Pendente',
    processing: 'Processando',
    paid: 'Pago',
    rejected: 'Rejeitado',
};
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6 pb-10">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Indique e Ganhe</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Configure comissões sobre a taxa da plataforma, acompanhe indicações e aprove saques do saldo de referral.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-full px-4 py-1.5 text-sm font-medium"
                :class="tab === 'config' ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200'"
                @click="goTab('config')"
            >
                Configuração
            </button>
            <button
                type="button"
                class="rounded-full px-4 py-1.5 text-sm font-medium"
                :class="tab === 'indicacoes' ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200'"
                @click="goTab('indicacoes')"
            >
                Indicações
            </button>
            <button
                type="button"
                class="rounded-full px-4 py-1.5 text-sm font-medium"
                :class="tab === 'saques' ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200'"
                @click="goTab('saques')"
            >
                Saques
                <span
                    v-if="pending_withdrawals_count > 0"
                    class="ml-1 rounded-full bg-white/20 px-1.5 text-xs"
                >{{ pending_withdrawals_count }}</span>
            </button>
        </div>

        <section v-if="tab === 'config'" class="space-y-5 rounded-2xl bg-white p-6 shadow-sm dark:bg-zinc-900/80">
            <label class="flex items-center gap-3 text-sm text-zinc-900 dark:text-white">
                <input v-model="settingsForm.enabled" type="checkbox" class="h-4 w-4 rounded border-zinc-300 dark:border-zinc-600" />
                Programa ativo (aparece no menu do seller)
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">% da taxa da plataforma</label>
                    <input
                        v-model.number="settingsForm.commission_percent"
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">Dias de elegibilidade (0 = vitalício)</label>
                    <input
                        v-model.number="settingsForm.eligibility_days"
                        type="number"
                        min="0"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">Saque mínimo (R$)</label>
                    <input
                        v-model.number="settingsForm.min_withdrawal"
                        type="number"
                        step="0.01"
                        min="0.01"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">Dias do cookie ?ref=</label>
                    <input
                        v-model.number="settingsForm.cookie_days"
                        type="number"
                        min="1"
                        max="365"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    />
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">Texto das regras (seller)</label>
                <textarea
                    v-model="settingsForm.rules_html"
                    rows="6"
                    class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    placeholder="Explique claramente como funciona o programa…"
                />
            </div>

            <Button type="button" :disabled="settingsForm.processing" @click="saveSettings">
                Salvar configurações
            </Button>
        </section>

        <section v-else-if="tab === 'indicacoes'" class="space-y-6">
            <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900/80">
                <h2 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Atribuir indicação</h2>
                <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="submitAssign">
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500 dark:text-zinc-400">Indicado</label>
                        <select
                            v-model="assignForm.referred_user_id"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            required
                        >
                            <option value="">Selecione…</option>
                            <option v-for="s in sellers" :key="'r-' + s.id" :value="s.id">{{ s.name }} ({{ s.email }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500 dark:text-zinc-400">Indicador (vazio = remover)</label>
                        <select
                            v-model="assignForm.referred_by_user_id"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        >
                            <option value="">— Remover —</option>
                            <option v-for="s in sellers" :key="'b-' + s.id" :value="s.id">{{ s.name }} ({{ s.email }})</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-3">
                        <label class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                            <input v-model="assignForm.force" type="checkbox" class="rounded" />
                            Forçar (se já houver comissões)
                        </label>
                        <Button type="submit" :disabled="assignForm.processing">Atribuir</Button>
                    </div>
                </form>
                <p v-if="assignForm.errors.referred_by_user_id" class="mt-2 text-xs text-red-500">{{ assignForm.errors.referred_by_user_id }}</p>
            </div>

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900/80">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-zinc-50/80 text-xs uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3">Indicado</th>
                            <th class="px-4 py-3">Indicador</th>
                            <th class="px-4 py-3">Desde</th>
                            <th class="px-4 py-3 text-right">Gerado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-if="!referrals.length">
                            <td colspan="4" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">Nenhuma indicação cadastrada.</td>
                        </tr>
                        <tr v-for="r in referrals" :key="r.id">
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ r.name }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ r.email }}</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                                <template v-if="r.referrer">{{ r.referrer.name }}</template>
                                <template v-else>—</template>
                            </td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ formatDate(r.referred_at) }}</td>
                            <td class="px-4 py-3 text-right font-medium text-zinc-900 dark:text-white">{{ formatBRL(r.earned) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-else class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900/80">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-zinc-50/80 text-xs uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3">Seller</th>
                        <th class="px-4 py-3">Valor</th>
                        <th class="px-4 py-3">PIX</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Data</th>
                        <th class="px-4 py-3 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-if="!withdrawals.length">
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">Nenhum saque de indicação.</td>
                    </tr>
                    <tr v-for="w in withdrawals" :key="w.id">
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ w.user?.name }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ w.user?.email }}</div>
                        </td>
                        <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ formatBRL(w.amount) }}</td>
                        <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                            <div>{{ w.pix_snapshot?.pix_key || '—' }}</div>
                            <div>{{ w.pix_snapshot?.pix_key_type_label || w.pix_snapshot?.pix_key_type || '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ statusLabel[w.status] || w.status }}</td>
                        <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ formatDate(w.created_at) }}</td>
                        <td class="px-4 py-3 text-right">
                            <div v-if="w.status === 'pending' || w.status === 'processing'" class="flex justify-end gap-2">
                                <Button type="button" size="sm" @click="openStepUp('approve', w.id, true)">Pagar</Button>
                                <Button type="button" size="sm" variant="outline" @click="openStepUp('reject', w.id, true)">Rejeitar</Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <PlatformStepUpModal
            :open="stepUpOpen"
            :loading="stepUpLoading"
            :require-totp="true"
            :require-pin="stepUpRequirePin"
            title="Confirmar ação"
            description="Confirme sua identidade para alterar o programa Indique e Ganhe."
            @close="stepUpOpen = false"
            @confirm="onStepUpConfirm"
        />
    </div>
</template>
