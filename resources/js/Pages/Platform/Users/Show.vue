<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import WalletAdjustForm from '@/components/platform/WalletAdjustForm.vue';
import MerchantAdminNotesPanel from '@/components/platform/MerchantAdminNotesPanel.vue';
import MerchantProductsTab from '@/components/platform/MerchantProductsTab.vue';
import MerchantWalletMovementsTab from '@/components/platform/MerchantWalletMovementsTab.vue';
import Button from '@/components/ui/Button.vue';
import { BadgeCheck, Shield, MessageCircle, MapPin, User as UserIcon, ContactRound } from 'lucide-vue-next';
import { buildWhatsAppUrl } from '@/lib/whatsappUrl';

defineOptions({ layout: LayoutPlatform });

const page = usePage();

const props = defineProps({
    tab: { type: String, default: 'overview' },
    merchant: { type: Object, required: true },
    profile: { type: Object, default: () => ({}) },
    wallet: { type: Object, default: null },
    withdrawals: { type: Array, default: () => [] },
    wallet_transactions: { type: Object, default: null },
    wallet_filters: { type: Object, default: null },
    wallet_transaction_type_labels: { type: Object, default: () => ({}) },
    products_total: { type: Number, default: 0 },
    products: { type: Object, default: null },
    products_filters: { type: Object, default: null },
    products_summary: { type: Object, default: null },
    products_approval_enabled: { type: Boolean, default: false },
    products_type_options: { type: Array, default: () => [] },
    effective_merchant_fees: { type: Array, default: () => [] },
    admin_notes: { type: Array, default: () => [] },
    platform_referral_commission_percent: { type: Number, default: 20 },
    account_manager: { type: Object, default: null },
    account_managers_options: { type: Array, default: () => [] },
    revenue_breakdown: {
        type: Object,
        default: () => ({
            checkout: { gross: 0, count: 0, fees: 0 },
            api_pix: { gross: 0, count: 0, fees: 0 },
            total: { gross: 0, count: 0, fees: 0 },
        }),
    },
    achievements_progress: { type: Object, default: null },
    achievement_unlocks: { type: Array, default: () => [] },
});

const activeTab = computed(() => {
    if (['overview', 'products', 'wallet', 'achievements'].includes(props.tab)) return props.tab;
    return 'overview';
});

const unlockForms = ref({});
const unlockSavingId = ref(null);
const unlockError = ref('');

function initUnlockForms() {
    const forms = {};
    for (const u of props.achievement_unlocks || []) {
        forms[u.id] = {
            reward_status: u.reward_status || 'pending',
            note: '',
            reward_carrier: u.reward_carrier || '',
            reward_tracking_code: u.reward_tracking_code || '',
            reward_admin_notes: u.reward_admin_notes || '',
        };
    }
    unlockForms.value = forms;
}

initUnlockForms();

watch(
    () => props.achievement_unlocks,
    () => initUnlockForms(),
    { deep: true }
);

function nextRewardStatus(current) {
    if (current === 'pending') return 'in_production';
    if (current === 'in_production') return 'sent';
    return null;
}

function nextRewardStatusLabel(current) {
    const next = nextRewardStatus(current);
    if (next === 'in_production') return 'Marcar em produção';
    if (next === 'sent') return 'Marcar como enviado';
    return null;
}

async function updateUnlockStatus(unlock, targetStatus) {
    const form = unlockForms.value[unlock.id];
    if (!form) return;

    unlockSavingId.value = unlock.id;
    unlockError.value = '';

    try {
        await window.axios.put(`/plataforma/conquistas/unlocks/${unlock.id}/reward-status`, {
            reward_status: targetStatus,
            note: form.note?.trim() || undefined,
            reward_carrier: form.reward_carrier?.trim() || undefined,
            reward_tracking_code: form.reward_tracking_code?.trim() || undefined,
            reward_admin_notes: form.reward_admin_notes?.trim() || undefined,
        });
        router.reload({ preserveScroll: true });
    } catch (e) {
        unlockError.value = e?.response?.data?.message || 'Erro ao atualizar status da premiação.';
    } finally {
        unlockSavingId.value = null;
    }
}

function switchTab(tab) {
    router.get(
        `/plataforma/usuarios/${props.merchant.id}`,
        { tab },
        { preserveState: false, replace: true }
    );
}

const selectedManagerId = ref(
    props.account_manager?.id ? String(props.account_manager.id) : ''
);
const assignReason = ref('');
const assigningManager = ref(false);

function saveAccountManager() {
    assigningManager.value = true;
    router.post(
        `/plataforma/usuarios/${props.merchant.id}/gerente-conta`,
        {
            account_manager_id: selectedManagerId.value ? Number(selectedManagerId.value) : null,
            reason: assignReason.value?.trim() || undefined,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                assigningManager.value = false;
            },
        }
    );
}

function formatPhoneBr(phone) {
    const digits = String(phone ?? '').replace(/\D/g, '');
    if (digits.length < 10) {
        return null;
    }
    let local = digits;
    if (local.startsWith('55') && local.length >= 12) {
        local = local.slice(2);
    }
    if (local.length === 11) {
        return `(${local.slice(0, 2)}) ${local.slice(2, 7)}-${local.slice(7)}`;
    }
    if (local.length === 10) {
        return `(${local.slice(0, 2)}) ${local.slice(2, 6)}-${local.slice(6)}`;
    }
    return String(phone);
}

const whatsappDisplay = computed(
    () => props.profile?.whatsapp || props.profile?.phone || formatPhoneBr(props.merchant?.phone) || null
);
const whatsappUrl = computed(
    () => props.profile?.whatsapp_url || buildWhatsAppUrl(props.merchant?.phone || props.profile?.whatsapp || props.profile?.phone)
);

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
                    :href="`/plataforma/usuarios/${merchant.id}/edit`"
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
        <p
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ page.props.flash.error }}
        </p>

        <nav class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700" aria-label="Abas do infoprodutor">
            <button
                type="button"
                class="rounded-t-lg px-4 py-2.5 text-sm font-medium transition"
                :class="
                    activeTab === 'overview'
                        ? 'border-b-2 border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'
                "
                @click="switchTab('overview')"
            >
                Visão geral
            </button>
            <button
                type="button"
                class="rounded-t-lg px-4 py-2.5 text-sm font-medium transition"
                :class="
                    activeTab === 'products'
                        ? 'border-b-2 border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'
                "
                @click="switchTab('products')"
            >
                Produtos ({{ products_total }})
            </button>
            <button
                type="button"
                class="rounded-t-lg px-4 py-2.5 text-sm font-medium transition"
                :class="
                    activeTab === 'wallet'
                        ? 'border-b-2 border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'
                "
                @click="switchTab('wallet')"
            >
                Movimentação
            </button>
            <button
                type="button"
                class="rounded-t-lg px-4 py-2.5 text-sm font-medium transition"
                :class="
                    activeTab === 'achievements'
                        ? 'border-b-2 border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'
                "
                @click="switchTab('achievements')"
            >
                Conquistas e premiações
            </button>
        </nav>

        <MerchantProductsTab
            v-if="activeTab === 'products'"
            :merchant-id="merchant.id"
            :products="products"
            :filters="products_filters"
            :summary="products_summary"
            :approval-enabled="products_approval_enabled"
            :type-options="products_type_options"
            :products-total="products_total"
        />

        <MerchantWalletMovementsTab
            v-if="activeTab === 'wallet'"
            :merchant-id="merchant.id"
            :wallet-transactions="wallet_transactions"
            :filters="wallet_filters"
            :type-labels="wallet_transaction_type_labels"
        />

        <template v-if="activeTab === 'achievements'">
            <p
                v-if="unlockError"
                class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
            >
                {{ unlockError }}
            </p>

            <section
                v-if="achievements_progress"
                class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50"
            >
                <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Progresso de conquistas</h2>
                </div>
                <div class="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-xs uppercase text-zinc-500">Faturamento válido</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">
                            {{ formatBRL(achievements_progress.current_value ?? achievements_progress.total_valid_sales) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-zinc-500">Progresso</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">
                            {{ achievements_progress.progress_percent ?? 0 }}%
                        </p>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                            <div
                                class="h-full rounded-full bg-[var(--color-primary)]"
                                :style="{ width: `${Math.min(100, achievements_progress.progress_percent ?? 0)}%` }"
                            />
                        </div>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-zinc-500">Próxima meta</p>
                        <p class="mt-1 font-medium text-zinc-900 dark:text-white">
                            {{ achievements_progress.all_completed ? 'Todas concluídas' : (achievements_progress.next_achievement?.name || '—') }}
                        </p>
                        <p v-if="!achievements_progress.all_completed && achievements_progress.remaining != null" class="mt-1 text-sm text-zinc-500">
                            Faltam {{ formatBRL(achievements_progress.remaining) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-zinc-500">Próximo prêmio</p>
                        <p class="mt-1 font-medium text-zinc-900 dark:text-white">
                            {{ achievements_progress.next_achievement?.reward_name || '—' }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Desbloqueios e premiações</h2>
                </div>
                <div v-if="achievement_unlocks.length === 0" class="px-6 py-8 text-center text-sm text-zinc-500">
                    Nenhuma conquista desbloqueada ainda.
                </div>
                <div v-else class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <div v-for="unlock in achievement_unlocks" :key="unlock.id" class="p-6">
                        <div class="flex flex-wrap gap-4">
                            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800">
                                <img v-if="unlock.image" :src="unlock.image" :alt="unlock.name" class="h-12 w-12 object-contain" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ unlock.name }}</p>
                                <p class="mt-1 text-sm text-zinc-500">
                                    Meta {{ formatBRL(unlock.threshold) }} · Valor na conquista {{ formatBRL(unlock.metric_value_at_unlock) }}
                                </p>
                                <p class="mt-1 text-sm text-zinc-500">Desbloqueado em {{ formatDate(unlock.unlocked_at) }}</p>
                                <p v-if="unlock.reward_name" class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    Prêmio: <span class="font-medium">{{ unlock.reward_name }}</span>
                                </p>
                                <p class="mt-1 text-sm">
                                    Status:
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ unlock.reward_status_label }}</span>
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="unlock.reward_name && unlockForms[unlock.id]"
                            class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40"
                        >
                            <div v-if="unlock.reward_status === 'sent'" class="mb-4 grid gap-3 sm:grid-cols-3">
                                <div>
                                    <p class="text-xs uppercase text-zinc-500">Transportadora</p>
                                    <p class="mt-0.5 text-sm">{{ snap(unlock.reward_carrier) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase text-zinc-500">Rastreio</p>
                                    <p class="mt-0.5 font-mono text-sm">{{ snap(unlock.reward_tracking_code) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase text-zinc-500">Enviado em</p>
                                    <p class="mt-0.5 text-sm">{{ formatDate(unlock.reward_sent_at) }}</p>
                                </div>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div v-if="unlock.reward_status === 'in_production' || nextRewardStatus(unlock.reward_status) === 'sent'">
                                    <label class="mb-1 block text-xs font-medium text-zinc-500">Transportadora</label>
                                    <input
                                        v-model="unlockForms[unlock.id].reward_carrier"
                                        type="text"
                                        class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                        placeholder="Ex.: Correios, Jadlog"
                                    />
                                </div>
                                <div v-if="unlock.reward_status === 'in_production' || nextRewardStatus(unlock.reward_status) === 'sent'">
                                    <label class="mb-1 block text-xs font-medium text-zinc-500">Código de rastreio</label>
                                    <input
                                        v-model="unlockForms[unlock.id].reward_tracking_code"
                                        type="text"
                                        class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                    />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-1 block text-xs font-medium text-zinc-500">Notas internas</label>
                                    <textarea
                                        v-model="unlockForms[unlock.id].reward_admin_notes"
                                        rows="2"
                                        class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                    />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-1 block text-xs font-medium text-zinc-500">Observação da alteração</label>
                                    <input
                                        v-model="unlockForms[unlock.id].note"
                                        type="text"
                                        class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                        placeholder="Opcional"
                                    />
                                </div>
                            </div>

                            <div v-if="nextRewardStatus(unlock.reward_status)" class="mt-4">
                                <Button
                                    type="button"
                                    size="sm"
                                    :disabled="unlockSavingId === unlock.id"
                                    @click="updateUnlockStatus(unlock, nextRewardStatus(unlock.reward_status))"
                                >
                                    {{
                                        unlockSavingId === unlock.id
                                            ? 'Salvando…'
                                            : nextRewardStatusLabel(unlock.reward_status)
                                    }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </template>

        <template v-if="activeTab === 'overview'">
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
                            <span :class="whatsappDisplay ? '' : 'text-zinc-500'">
                                {{ snap(whatsappDisplay) }}
                            </span>
                            <a
                                v-if="whatsappUrl"
                                :href="whatsappUrl"
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
                                    :href="`/plataforma/usuarios/${merchant.id}/edit`"
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
            <h2 class="mb-1 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-zinc-500">
                <ContactRound class="h-4 w-4" />
                Gerente de conta
            </h2>
            <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                Contato de suporte atribuído a este infoprodutor (visível no dashboard do seller).
            </p>
            <div v-if="account_manager" class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-zinc-100 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900/50">
                <img
                    v-if="account_manager.avatar_url"
                    :src="account_manager.avatar_url"
                    alt=""
                    class="h-12 w-12 rounded-full object-cover"
                />
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-zinc-900 dark:text-white">{{ account_manager.name }}</p>
                    <p class="text-xs text-zinc-500">{{ account_manager.email }} · {{ account_manager.phone_display }}</p>
                </div>
                <Link
                    v-if="account_manager.id"
                    :href="`/plataforma/gerentes-conta/${account_manager.id}`"
                    class="text-sm text-[var(--color-primary)] hover:underline"
                >
                    Ver carteira
                </Link>
            </div>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="min-w-[200px] flex-1">
                    <label class="mb-1 block text-xs font-medium text-zinc-500">Gerente</label>
                    <select
                        v-model="selectedManagerId"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    >
                        <option value="">Sem gerente</option>
                        <option v-for="opt in account_managers_options" :key="opt.id" :value="String(opt.id)">
                            {{ opt.name }}
                        </option>
                    </select>
                </div>
                <div class="min-w-[200px] flex-1">
                    <label class="mb-1 block text-xs font-medium text-zinc-500">Motivo (opcional)</label>
                    <input
                        v-model="assignReason"
                        type="text"
                        maxlength="500"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                        placeholder="Motivo da alteração"
                    />
                </div>
                <Button type="button" :disabled="assigningManager" @click="saveAccountManager">
                    {{ assigningManager ? 'Salvando…' : 'Salvar vínculo' }}
                </Button>
            </div>
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
            <div class="border-t border-zinc-100 px-6 py-3 dark:border-zinc-700">
                <button
                    type="button"
                    class="text-sm font-medium text-[var(--color-primary)] hover:underline"
                    @click="switchTab('wallet')"
                >
                    Ver movimentações da carteira →
                </button>
            </div>
        </section>
        </template>
    </div>
</template>
