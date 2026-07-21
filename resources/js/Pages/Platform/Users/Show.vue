<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import WalletAdjustForm from '@/components/platform/WalletAdjustForm.vue';
import MerchantAdminNotesPanel from '@/components/platform/MerchantAdminNotesPanel.vue';
import { BadgeCheck, Shield, MessageCircle, MapPin, User as UserIcon } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const page = usePage();

const props = defineProps({
    merchant: { type: Object, required: true },
    profile: { type: Object, default: () => ({}) },
    wallet: { type: Object, default: null },
    withdrawals: { type: Array, default: () => [] },
    wallet_transactions: { type: Array, default: () => [] },
    effective_merchant_fees: { type: Array, default: () => [] },
    admin_notes: { type: Array, default: () => [] },
    platform_referral_commission_percent: { type: Number, default: 20 },
    revenue_breakdown: {
        type: Object,
        default: () => ({
            checkout: { gross: 0, count: 0, fees: 0 },
            api_pix: { gross: 0, count: 0, fees: 0 },
            total: { gross: 0, count: 0, fees: 0 },
        }),
    },
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

function kycStatusLabel(s) {
    const map = {
        not_submitted: 'Sem documentos',
        pending_review: 'Em análise',
        approved: 'Aprovado',
        rejected: 'Rejeitado',
    };
    return map[s] || s || '—';
}

function snap(value) {
    if (value === null || value === undefined || value === '') return '—';
    return value;
}

function amountClass(n) {
    const v = Number(n) || 0;
    if (v > 0) return 'text-emerald-700 dark:text-emerald-300';
    if (v < 0) return 'text-red-600 dark:text-red-400';
    return 'text-zinc-600 dark:text-zinc-400';
}

function formatFeePreview(percent, fixed) {
    const p = Number(percent) || 0;
    const f = Number(fixed) || 0;
    const parts = [];
    if (p > 0) {
        parts.push(`${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 4 }).format(p)}%`);
    }
    if (f > 0) {
        parts.push(`R$ ${new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(f)}`);
    }
    return parts.length ? parts.join(' + ') : '0%';
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
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <img
                        v-if="profile.avatar_url"
                        :src="profile.avatar_url"
                        :alt="merchant.name"
                        class="h-12 w-12 rounded-full border border-zinc-200 object-cover dark:border-zinc-600"
                    />
                    <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ merchant.name }}</h1>
                    <span
                        v-if="merchant.totp_enabled"
                        class="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200"
                        title="Autenticação em dois fatores ativa"
                    >
                        <Shield class="h-3.5 w-3.5" />
                        2FA ativo
                    </span>
                </div>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ profile.email || merchant.email }}
                    <span v-if="profile.username" class="text-zinc-400"> · @{{ profile.username }}</span>
                </p>
                <p class="text-xs text-zinc-500">
                    ID #{{ merchant.id }}
                    <span v-if="merchant.tenant_id"> · Tenant #{{ merchant.tenant_id }}</span>
                    <span v-if="profile.person_type_label"> · {{ profile.person_type_label }}</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    :href="`/plataforma/usuarios?edit=${merchant.id}`"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    Editar configurações
                </Link>
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

        <section class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 lg:col-span-1">
                <h2 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-zinc-500">
                    <UserIcon class="h-4 w-4" />
                    Contato
                </h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">E-mail</dt>
                        <dd class="mt-0.5 break-all font-medium text-zinc-900 dark:text-white">
                            <a
                                :href="`mailto:${profile.email || merchant.email}`"
                                class="text-[var(--color-primary)] hover:underline"
                            >
                                {{ snap(profile.email || merchant.email) }}
                            </a>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">WhatsApp</dt>
                        <dd class="mt-1 flex flex-wrap items-center gap-2 font-medium text-zinc-900 dark:text-white">
                            <span :class="profile.whatsapp ? '' : 'text-zinc-500'">
                                {{ snap(profile.whatsapp) }}
                            </span>
                            <a
                                v-if="profile.whatsapp_url"
                                :href="profile.whatsapp_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-500"
                                title="Abrir conversa no WhatsApp"
                            >
                                <MessageCircle class="h-3.5 w-3.5" />
                                WhatsApp
                            </a>
                        </dd>
                    </div>
                    <div v-if="profile.username">
                        <dt class="text-xs uppercase text-zinc-500">Usuário</dt>
                        <dd class="mt-0.5 font-mono text-zinc-800 dark:text-zinc-200">@{{ profile.username }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 lg:col-span-1">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Cadastro</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">{{ profile.person_type === 'pj' ? 'CNPJ' : 'CPF' }}</dt>
                        <dd class="mt-0.5 font-mono text-zinc-900 dark:text-white">{{ snap(profile.document) }}</dd>
                    </div>
                    <div v-if="profile.company_name">
                        <dt class="text-xs uppercase text-zinc-500">Razão social</dt>
                        <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ profile.company_name }}</dd>
                    </div>
                    <div v-if="profile.legal_representative_cpf">
                        <dt class="text-xs uppercase text-zinc-500">CPF representante</dt>
                        <dd class="mt-0.5 font-mono text-zinc-900 dark:text-white">{{ profile.legal_representative_cpf }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Nascimento</dt>
                        <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(profile.birth_date) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Faturamento mensal</dt>
                        <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(profile.monthly_revenue_label) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 lg:col-span-1">
                <h2 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-zinc-500">
                    <MapPin class="h-4 w-4" />
                    Endereço
                </h2>
                <p v-if="profile.address_line" class="mt-4 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
                    {{ profile.address_street }}, {{ profile.address_number }}
                    <span v-if="profile.address_complement"> — {{ profile.address_complement }}</span>
                    <br />
                    {{ profile.address_neighborhood }} — {{ profile.address_city }}/{{ profile.address_state }}
                    <br />
                    CEP {{ profile.address_zip }}
                </p>
                <p v-else class="mt-4 text-sm text-zinc-500">Endereço não informado.</p>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Conta e KYC</h2>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Status da conta</dt>
                        <dd class="mt-0.5 font-medium">{{ statusLabel(profile.account_status || merchant.account_status) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">KYC</dt>
                        <dd class="mt-0.5 font-medium">{{ kycStatusLabel(profile.kyc_status || merchant.kyc_status) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Cadastro em</dt>
                        <dd class="mt-0.5">{{ formatDate(profile.created_at || merchant.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Onboarding concluído</dt>
                        <dd class="mt-0.5">{{ formatDate(profile.seller_onboarded_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">KYC revisado em</dt>
                        <dd class="mt-0.5">{{ formatDate(profile.kyc_reviewed_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Termos aceitos</dt>
                        <dd class="mt-0.5">{{ formatDate(profile.terms_accepted_at) }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase text-zinc-500">Taxa de indicação</dt>
                        <dd class="mt-0.5 font-medium">
                            <template v-if="merchant.referral_commission_percent != null">
                                {{ Number(merchant.referral_commission_percent).toLocaleString('pt-BR', { maximumFractionDigits: 4 }) }}%
                                <span class="font-normal text-zinc-500"> (personalizada)</span>
                            </template>
                            <template v-else>
                                {{ Number(merchant.referral_commission_percent_effective ?? platform_referral_commission_percent ?? 0).toLocaleString('pt-BR', { maximumFractionDigits: 4 }) }}%
                                <span class="font-normal text-zinc-500"> (padrão da plataforma)</span>
                            </template>
                            <div class="mt-1">
                                <Link
                                    :href="`/plataforma/usuarios?edit=${merchant.id}`"
                                    class="text-xs text-[var(--color-primary)] underline"
                                >
                                    Editar no cadastro
                                </Link>
                            </div>
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase text-zinc-500">Indicado por</dt>
                        <dd class="mt-0.5">
                            <template v-if="merchant.referred_by">
                                <Link
                                    :href="`/plataforma/usuarios/${merchant.referred_by.id}`"
                                    class="font-medium text-[var(--color-primary)] hover:underline"
                                >
                                    {{ merchant.referred_by.name }}
                                </Link>
                                <span class="text-zinc-500"> · desde {{ formatDate(merchant.referred_by.referred_at) }}</span>
                                <div class="mt-1">
                                    <Link
                                        href="/plataforma/indique-e-ganhe?tab=indicacoes"
                                        class="text-xs text-zinc-500 underline"
                                    >
                                        Gerenciar em Indique e Ganhe
                                    </Link>
                                </div>
                            </template>
                            <template v-else>
                                —
                                <Link
                                    href="/plataforma/indique-e-ganhe?tab=indicacoes"
                                    class="ml-2 text-xs text-[var(--color-primary)] underline"
                                >
                                    Atribuir
                                </Link>
                            </template>
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="profile.kyc_rejection_reason"
                    class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
                >
                    <strong>Última rejeição KYC:</strong> {{ profile.kyc_rejection_reason }}
                </p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">PIX para saque</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div v-if="profile.payout_pix_label">
                        <dt class="text-xs uppercase text-zinc-500">Apelido</dt>
                        <dd class="mt-0.5">{{ profile.payout_pix_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Tipo da chave</dt>
                        <dd class="mt-0.5">{{ snap(profile.payout_pix_key_type_label) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-zinc-500">Chave PIX</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-zinc-900 dark:text-white">
                            {{ snap(profile.payout_pix_key) }}
                        </dd>
                    </div>
                    <div v-if="profile.payout_pix_owner_document">
                        <dt class="text-xs uppercase text-zinc-500">Titular (CPF/CNPJ)</dt>
                        <dd class="mt-0.5 font-mono text-xs">{{ profile.payout_pix_owner_document }}</dd>
                    </div>
                </dl>
                <p v-if="!profile.payout_pix_key" class="mt-4 text-sm text-zinc-500">Chave PIX não cadastrada.</p>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Faturamento por canal</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p
                        class="text-xs uppercase text-zinc-500"
                        title="Soma de checkout e API PIX (pedidos concluídos via gateway)"
                    >
                        Vendas totais
                    </p>
                    <p class="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">
                        {{ formatBRL(revenue_breakdown.total.gross) }}
                    </p>
                    <p class="mt-2 text-[11px] text-zinc-500">
                        {{ revenue_breakdown.total.count }} pedido{{ revenue_breakdown.total.count === 1 ? '' : 's' }}
                    </p>
                    <p class="mt-1 text-[11px] text-zinc-500" title="Taxas cobradas pela plataforma neste canal">
                        Taxas: {{ formatBRL(revenue_breakdown.total.fees) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p
                        class="text-xs uppercase text-zinc-500"
                        title="Vendas via links de checkout da plataforma"
                    >
                        Checkout (infoprodutos)
                    </p>
                    <p class="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">
                        {{ formatBRL(revenue_breakdown.checkout.gross) }}
                    </p>
                    <p class="mt-2 text-[11px] text-zinc-500">
                        {{ revenue_breakdown.checkout.count }} pedido{{ revenue_breakdown.checkout.count === 1 ? '' : 's' }}
                    </p>
                    <p class="mt-1 text-[11px] text-zinc-500" title="Taxas cobradas pela plataforma neste canal">
                        Taxas: {{ formatBRL(revenue_breakdown.checkout.fees) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800 sm:col-span-2 lg:col-span-1">
                    <p
                        class="text-xs uppercase text-zinc-500"
                        title="Cobranças criadas via API PIX (integração)"
                    >
                        API PIX
                    </p>
                    <p class="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">
                        {{ formatBRL(revenue_breakdown.api_pix.gross) }}
                    </p>
                    <p class="mt-2 text-[11px] text-zinc-500">
                        {{ revenue_breakdown.api_pix.count }} pedido{{ revenue_breakdown.api_pix.count === 1 ? '' : 's' }}
                    </p>
                    <p class="mt-1 text-[11px] text-zinc-500" title="Taxas cobradas pela plataforma neste canal">
                        Taxas: {{ formatBRL(revenue_breakdown.api_pix.fees) }}
                    </p>
                </div>
            </div>
        </section>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">Taxas efetivas</h2>
            <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                Valores em produção para este infoprodutor (overrides + herança da plataforma).
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-100 text-xs uppercase text-zinc-500 dark:border-zinc-700">
                        <tr>
                            <th class="px-3 py-2">Canal</th>
                            <th class="px-3 py-2 text-right">Taxa efetiva</th>
                            <th class="px-3 py-2 text-center">Override?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in effective_merchant_fees"
                            :key="row.key"
                            class="border-b border-zinc-50 dark:border-zinc-800"
                        >
                            <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ row.label }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-zinc-900 dark:text-white">
                                {{ formatFeePreview(row.percent, row.fixed) }}
                            </td>
                            <td class="px-3 py-2 text-center text-xs text-zinc-600 dark:text-zinc-400">
                                {{ row.has_override ? 'Sim' : 'Não' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-amber-200/80 bg-amber-50/30 p-6 shadow-sm dark:border-amber-900/40 dark:bg-amber-950/20">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">Observações internas</h2>
            <MerchantAdminNotesPanel
                :merchant-user-id="merchant.id"
                :initial-notes="admin_notes"
                :initial-count="admin_notes.length"
            />
        </section>

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
