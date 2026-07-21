<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { Check, Copy, Gift, Users, Wallet, ScrollText } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    program: { type: Object, required: true },
    referral: { type: Object, required: true },
    wallet: { type: Object, required: true },
    referred_users: { type: Array, default: () => [] },
    commissions: { type: Array, default: () => [] },
    withdrawals: { type: Array, default: () => [] },
    pix_ready: { type: Boolean, default: false },
    stats: { type: Object, default: () => ({}) },
});

const copied = ref(false);
const activePanel = ref('indicados');

const withdrawForm = useForm({
    amount: '',
    notes: '',
});

const eligibilityLabel = computed(() => {
    const days = Number(props.program.eligibility_days ?? 0);
    if (days === 0) return 'Vitalício — você ganha enquanto o indicado vender';
    return `Durante ${days} dia${days === 1 ? '' : 's'} após o cadastro do indicado`;
});

function formatBRL(value) {
    return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString('pt-BR');
    } catch {
        return '—';
    }
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(props.referral.link);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 1800);
    } catch {
        const el = document.createElement('textarea');
        el.value = props.referral.link;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 1800);
    }
}

function submitWithdraw() {
    withdrawForm.post('/indique-e-ganhe/saque', {
        preserveScroll: true,
        onSuccess: () => {
            withdrawForm.reset('amount', 'notes');
        },
    });
}

const statusLabel = {
    available: 'Disponível',
    pending: 'Pendente',
    reversed: 'Estornada',
    paid: 'Pago',
    rejected: 'Rejeitado',
    processing: 'Processando',
};
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-8 pb-10">
        <section class="relative overflow-hidden rounded-2xl bg-white p-6 shadow-sm dark:bg-zinc-900/80 sm:p-8">
            <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-[var(--color-primary)]/10 blur-2xl" />
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-xl space-y-3">
                    <div class="inline-flex items-center gap-2 text-sm font-medium text-[var(--color-primary)]">
                        <Gift class="h-4 w-4" />
                        Indique e Ganhe
                    </div>
                    <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white sm:text-3xl">
                        Indique sellers e ganhe {{ program.commission_percent }}% da taxa da plataforma
                    </h1>
                    <p class="text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
                        Cada vez que alguém se cadastrar pelo seu link e vender na plataforma, você recebe uma fatia da taxa cobrada — em saldo separado do financeiro de vendas.
                    </p>
                </div>
                <div class="w-full max-w-md space-y-2">
                    <label class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Seu link</label>
                    <div class="flex gap-2">
                        <input
                            :value="referral.link"
                            readonly
                            class="min-w-0 flex-1 rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        />
                        <Button type="button" class="shrink-0 gap-2" @click="copyLink">
                            <Check v-if="copied" class="h-4 w-4" />
                            <Copy v-else class="h-4 w-4" />
                            {{ copied ? 'Copiado' : 'Copiar' }}
                        </Button>
                    </div>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        Código: <span class="font-mono text-zinc-800 dark:text-zinc-200">{{ referral.code }}</span>
                    </p>
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900/80">
                <div class="mb-2 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    <Wallet class="h-3.5 w-3.5" /> Saldo disponível
                </div>
                <p class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ formatBRL(wallet.available_balance) }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Já sacado: {{ formatBRL(wallet.lifetime_withdrawn) }}</p>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900/80">
                <div class="mb-2 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    <Gift class="h-3.5 w-3.5" /> Total ganho
                </div>
                <p class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ formatBRL(wallet.lifetime_earned) }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ program.commission_percent }}% da taxa da plataforma</p>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900/80">
                <div class="mb-2 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    <Users class="h-3.5 w-3.5" /> Indicados
                </div>
                <p class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ stats.referred_count ?? referred_users.length }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ stats.active_count ?? 0 }} ativos na janela</p>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <div class="space-y-4 rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900/80 sm:p-6">
                <div class="flex items-center gap-2 text-zinc-900 dark:text-white">
                    <ScrollText class="h-4 w-4 text-[var(--color-primary)]" />
                    <h2 class="text-base font-semibold">Regras</h2>
                </div>
                <ul class="space-y-2 text-sm text-zinc-500 dark:text-zinc-400">
                    <li>
                        Comissão:
                        <strong class="text-zinc-900 dark:text-white">{{ program.commission_percent }}%</strong>
                        da taxa da plataforma em cada venda do indicado.
                    </li>
                    <li>
                        Janela:
                        <strong class="text-zinc-900 dark:text-white">{{ eligibilityLabel }}</strong>.
                    </li>
                    <li>
                        Saque mínimo:
                        <strong class="text-zinc-900 dark:text-white">{{ formatBRL(program.min_withdrawal) }}</strong>
                        (saldo próprio de indicações).
                    </li>
                </ul>
                <div
                    v-if="program.rules"
                    class="whitespace-pre-wrap rounded-xl bg-zinc-50 px-4 py-3 text-sm leading-relaxed text-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-200"
                >
                    {{ program.rules }}
                </div>
            </div>

            <div class="space-y-4 rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-900/80 sm:p-6">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Solicitar saque</h2>
                <p v-if="!pix_ready" class="text-sm text-amber-700 dark:text-amber-400">
                    Cadastre uma chave PIX em
                    <a href="/financeiro?tab=seus-dados" class="underline">Financeiro</a>
                    antes de sacar.
                </p>
                <form class="space-y-3" @submit.prevent="submitWithdraw">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">Valor (R$)</label>
                        <input
                            v-model="withdrawForm.amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            :disabled="!pix_ready"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        />
                        <p v-if="withdrawForm.errors.amount" class="mt-1 text-xs text-red-500">{{ withdrawForm.errors.amount }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">Observação (opcional)</label>
                        <input
                            v-model="withdrawForm.notes"
                            type="text"
                            maxlength="500"
                            :disabled="!pix_ready"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        />
                    </div>
                    <Button type="submit" class="w-full" :disabled="!pix_ready || withdrawForm.processing">
                        {{ withdrawForm.processing ? 'Enviando…' : 'Solicitar saque' }}
                    </Button>
                </form>
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-full px-4 py-1.5 text-sm font-medium transition"
                    :class="activePanel === 'indicados' ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200'"
                    @click="activePanel = 'indicados'"
                >
                    Indicados
                </button>
                <button
                    type="button"
                    class="rounded-full px-4 py-1.5 text-sm font-medium transition"
                    :class="activePanel === 'comissoes' ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200'"
                    @click="activePanel = 'comissoes'"
                >
                    Comissões
                </button>
                <button
                    type="button"
                    class="rounded-full px-4 py-1.5 text-sm font-medium transition"
                    :class="activePanel === 'saques' ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200'"
                    @click="activePanel = 'saques'"
                >
                    Saques
                </button>
            </div>

            <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900/80">
                <div v-if="activePanel === 'indicados'" class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-zinc-50/80 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Nome</th>
                                <th class="px-4 py-3 font-medium">Cadastro</th>
                                <th class="px-4 py-3 font-medium">Janela</th>
                                <th class="px-4 py-3 font-medium text-right">Gerado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="!referred_users.length">
                                <td colspan="4" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    Nenhum indicado ainda. Compartilhe seu link.
                                </td>
                            </tr>
                            <tr v-for="u in referred_users" :key="u.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ u.name }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ u.email }}</div>
                                </td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ formatDate(u.referred_at) }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="u.eligible
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
                                            : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'"
                                    >
                                        {{ u.eligible ? 'Ativa' : 'Encerrada' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-medium text-zinc-900 dark:text-white">{{ formatBRL(u.earned) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else-if="activePanel === 'comissoes'" class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-zinc-50/80 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Pedido</th>
                                <th class="px-4 py-3 font-medium">Indicado</th>
                                <th class="px-4 py-3 font-medium">Taxa</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium text-right">Comissão</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="!commissions.length">
                                <td colspan="5" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    Nenhuma comissão registrada.
                                </td>
                            </tr>
                            <tr v-for="c in commissions" :key="c.id">
                                <td class="px-4 py-3 text-zinc-900 dark:text-white">#{{ c.order_id }}</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ c.referred_name || '—' }}</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ formatBRL(c.platform_fee) }}</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ statusLabel[c.status] || c.status }}</td>
                                <td class="px-4 py-3 text-right font-medium text-zinc-900 dark:text-white">{{ formatBRL(c.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-zinc-50/80 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">ID</th>
                                <th class="px-4 py-3 font-medium">Data</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="!withdrawals.length">
                                <td colspan="4" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    Nenhum saque solicitado.
                                </td>
                            </tr>
                            <tr v-for="w in withdrawals" :key="w.id">
                                <td class="px-4 py-3 text-zinc-900 dark:text-white">#{{ w.id }}</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ formatDate(w.created_at) }}</td>
                                <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                                    {{ statusLabel[w.status] || w.status }}
                                    <span v-if="w.failed_reason" class="block text-xs text-red-500">{{ w.failed_reason }}</span>
                                </td>
                                <td class="px-4 py-3 text-right font-medium text-zinc-900 dark:text-white">{{ formatBRL(w.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</template>
