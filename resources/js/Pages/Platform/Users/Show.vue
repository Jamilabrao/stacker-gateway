<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import WalletAdjustForm from '@/components/platform/WalletAdjustForm.vue';
import { BadgeCheck } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const page = usePage();

const props = defineProps({
    merchant: { type: Object, required: true },
    wallet: { type: Object, default: null },
    withdrawals: { type: Array, default: () => [] },
    wallet_transactions: { type: Array, default: () => [] },
});

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}

function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('pt-BR');
}

function withdrawalStatusLabel(s) {
    const map = { pending: 'Pendente', paid: 'Pago', rejected: 'Rejeitado' };
    return map[s] || s || '—';
}

function bucketLabel(b) {
    const map = { pix: 'PIX', card: 'Cartão', boleto: 'Boleto' };
    return map[b] || b || '—';
}

function statusLabel(s) {
    const map = {
        approved: 'Aprovado',
        pending: 'Pendente',
        rejected: 'Rejeitado',
        suspended: 'Suspenso',
        blocked: 'Bloqueado',
    };
    return map[s] || s || '—';
}

function amountClass(n) {
    const v = Number(n) || 0;
    if (v > 0) return 'text-emerald-700 dark:text-emerald-300';
    if (v < 0) return 'text-red-600 dark:text-red-400';
    return 'text-zinc-600 dark:text-zinc-400';
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <Link
                    href="/plataforma/usuarios"
                    class="text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
                >
                    ← Infoprodutores
                </Link>
                <h1 class="mt-2 text-xl font-semibold text-zinc-900 dark:text-white">{{ merchant.name }}</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ merchant.email }}</p>
                <p v-if="merchant.document" class="text-sm text-zinc-500">{{ merchant.document }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    :href="`/plataforma/verificacoes-kyc/usuario/${merchant.id}`"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <BadgeCheck class="h-4 w-4" />
                    Ver KYC
                </Link>
                <Link
                    href="/plataforma/saques"
                    class="inline-flex items-center rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    Todos os saques
                </Link>
            </div>
        </div>

        <p
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs uppercase text-zinc-500" title="Pedidos concluídos via gateway (exclui aprovação manual)">
                    Vendas totais
                </p>
                <p class="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">
                    {{ formatBRL(merchant.vendas_totais) }}
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs uppercase text-zinc-500">Disponível</p>
                <p
                    class="mt-1 text-lg font-semibold tabular-nums"
                    :class="amountClass(wallet?.available_total)"
                >
                    {{ formatBRL(wallet?.available_total) }}
                </p>
                <p class="mt-2 text-[11px] text-zinc-500">
                    PIX {{ formatBRL(wallet?.available_pix) }} · Cartão {{ formatBRL(wallet?.available_card) }} · Boleto
                    {{ formatBRL(wallet?.available_boleto) }}
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs uppercase text-zinc-500">Pendente (liquidação)</p>
                <p class="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">
                    {{ formatBRL(wallet?.pending_total) }}
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs uppercase text-zinc-500">MED (contestação)</p>
                <p class="mt-1 text-lg font-semibold tabular-nums text-amber-700 dark:text-amber-300">
                    {{ formatBRL(wallet?.med_total) }}
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs uppercase text-zinc-500">Saque efetivo (PIX)</p>
                <p class="mt-1 text-lg font-semibold tabular-nums">
                    {{ formatBRL(wallet?.effective_withdrawal_pix) }}
                </p>
                <p class="mt-1 text-[11px] text-zinc-500">Conta: {{ statusLabel(merchant.account_status) }}</p>
            </div>
        </div>

        <div
            v-if="wallet?.wallet_admin?.admin_withdrawal_blocked || wallet?.wallet_admin?.admin_blocked_amount"
            class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <strong>Bloqueio administrativo:</strong>
            <span v-if="wallet.wallet_admin.admin_withdrawal_blocked"> saque bloqueado.</span>
            <span v-if="wallet.wallet_admin.admin_blocked_amount">
                Reserva {{ formatBRL(wallet.wallet_admin.admin_blocked_amount) }}.
            </span>
            <span v-if="wallet.wallet_admin.admin_block_note"> {{ wallet.wallet_admin.admin_block_note }}</span>
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">Ajuste manual de saldo</h2>
            <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                Credite ou debite o saldo disponível. Valores negativos são permitidos. O motivo fica registrado no extrato.
            </p>
            <WalletAdjustForm :user-id="merchant.id" />
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="border-b border-zinc-200 px-6 py-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-700">
                Histórico de saques
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-100 text-xs uppercase text-zinc-500 dark:border-zinc-700">
                        <tr>
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3">Data</th>
                            <th class="px-4 py-3">Valor</th>
                            <th class="px-4 py-3">Líquido</th>
                            <th class="px-4 py-3">Canal</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="withdrawals.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-zinc-500">Nenhum saque registrado.</td>
                        </tr>
                        <tr
                            v-for="w in withdrawals"
                            :key="w.id"
                            class="border-b border-zinc-50 dark:border-zinc-800"
                        >
                            <td class="px-4 py-3 tabular-nums">{{ w.id }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-400">
                                {{ formatDate(w.created_at) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">{{ formatBRL(w.amount) }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ formatBRL(w.net_amount) }}</td>
                            <td class="px-4 py-3">{{ bucketLabel(w.bucket) }}</td>
                            <td class="px-4 py-3">{{ withdrawalStatusLabel(w.status) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="border-b border-zinc-200 px-6 py-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-700">
                Movimentações da carteira
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-100 text-xs uppercase text-zinc-500 dark:border-zinc-700">
                        <tr>
                            <th class="px-4 py-3">Data</th>
                            <th class="px-4 py-3">Tipo</th>
                            <th class="px-4 py-3">Canal</th>
                            <th class="px-4 py-3 text-right">Líquido</th>
                            <th class="px-4 py-3">Ref.</th>
                            <th class="px-4 py-3">Obs.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="wallet_transactions.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-zinc-500">Nenhuma movimentação.</td>
                        </tr>
                        <tr
                            v-for="t in wallet_transactions"
                            :key="t.id"
                            class="border-b border-zinc-50 dark:border-zinc-800"
                        >
                            <td class="px-4 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-400">
                                {{ formatDate(t.created_at) }}
                            </td>
                            <td class="px-4 py-3">{{ t.type_label }}</td>
                            <td class="px-4 py-3">{{ bucketLabel(t.bucket) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums" :class="amountClass(t.amount_net)">
                                {{ formatBRL(t.amount_net) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-zinc-500">
                                <span v-if="t.order_id">Pedido #{{ t.order_id }}</span>
                                <span v-else-if="t.withdrawal_id">Saque #{{ t.withdrawal_id }}</span>
                                <span v-else>—</span>
                            </td>
                            <td class="max-w-[200px] truncate px-4 py-3 text-xs text-zinc-500" :title="t.note || ''">
                                {{ t.note || '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
