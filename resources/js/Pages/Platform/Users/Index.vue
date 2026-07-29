<script setup>
import { ref, computed, reactive, watch, onMounted } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import FeeFixedInput from '@/components/ui/FeeFixedInput.vue';
import FeePercentInput from '@/components/ui/FeePercentInput.vue';
import MerchantAdminNotesPanel from '@/components/platform/MerchantAdminNotesPanel.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { UserPlus, Trash2, Pencil, X, Eye, BadgeCheck, MessageSquare, Search, Shield } from 'lucide-vue-next';
import {
    formatPercentForInput,
    normalizeMerchantFeeOverridesForSubmit,
    normalizeMerchantSettlementOverridesForSubmit,
} from '@/lib/percentDecimal';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    users: { type: Array, default: () => [] },
    q: { type: String, default: null },
    status: { type: String, default: null },
    status_options: { type: Array, default: () => [] },
    edit_user_id: { type: Number, default: null },
    gateways: { type: Array, default: () => [] },
    platform_gateway_order: {
        type: Object,
        default: () => ({ pix: [], card: [], boleto: [], pix_auto: [] }),
    },
    platform_merchant_fees: { type: Array, default: () => [] },
    platform_referral_commission_percent: { type: Number, default: 20 },
    platform_charge_limits: {
        type: Object,
        default: () => ({ api_pix_minimum_charge_brl: 0.01, platform_minimum_charge_brl: 0 }),
    },
    platform_api_pix_enabled: { type: Boolean, default: true },
    cajupay_accounts: { type: Array, default: () => [] },
});

const page = usePage();
const platformTotpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));

/** Exibe WhatsApp no modal no formato BR (sem DDI 55). */
function formatPhoneForInput(phone) {
    const digits = String(phone ?? '').replace(/\D/g, '');
    if (!digits) {
        return '';
    }
    let local = digits;
    if (local.startsWith('55') && local.length >= 12) {
        local = local.slice(2);
    }
    local = local.slice(0, 11);
    if (local.length <= 2) return local;
    if (local.length <= 6) return `(${local.slice(0, 2)}) ${local.slice(2)}`;
    if (local.length <= 10) return `(${local.slice(0, 2)}) ${local.slice(2, 6)}-${local.slice(6)}`;
    return `(${local.slice(0, 2)}) ${local.slice(2, 7)}-${local.slice(7)}`;
}

const searchQ = ref(props.q ?? '');
const statusFilter = ref(props.status ?? '');

watch(
    () => props.q,
    (v) => {
        searchQ.value = v ?? '';
    }
);

watch(
    () => props.status,
    (v) => {
        statusFilter.value = v ?? '';
    }
);

function applySearch() {
    const q = searchQ.value?.trim() || undefined;
    const status = statusFilter.value?.trim() || undefined;
    router.get('/plataforma/usuarios', { q, status }, { preserveState: true, replace: true });
}

function clearFilters() {
    searchQ.value = '';
    statusFilter.value = '';
    router.get('/plataforma/usuarios', {}, { preserveState: true, replace: true });
}

const editUser = ref(null);
const savedFeeOverrides = ref(null);
const savedSettlementOverrides = ref(null);
const deletingId = ref(null);
const selectedIds = ref([]);
const bulkDeleteOpen = ref(false);
const bulkDeleteLoading = ref(false);
const bulkStepUpOpen = ref(false);
const bulkDeleteForce = ref(false);
const feesDirty = ref(false);
const settlementDirty = ref(false);
const gatewayOrderDirty = ref(false);
const cajupayAccountDirty = ref(false);
const limitsDirty = ref(false);
const apiPixDirty = ref(false);
const initialGatewayPrimary = ref({});
const adminNotesCountByUser = ref({});

function platformFeesMap() {
    const map = {};
    for (const row of props.platform_merchant_fees || []) {
        map[row.key] = { percent: Number(row.percent) || 0, fixed: Number(row.fixed) || 0 };
    }
    for (const k of feeRuleKeys) {
        if (!map[k]) {
            map[k] = { percent: 0, fixed: 0 };
        }
    }
    return map;
}

function hasCustomFees(u) {
    const fees = u?.merchant_fees;
    return fees && typeof fees === 'object' && Object.keys(fees).length > 0;
}

function hasCustomSettlement(u) {
    const s = u?.merchant_settlement_overrides;
    return s && typeof s === 'object' && Object.keys(s).length > 0;
}

function hasCustomChargeLimits(u) {
    const cl = u?.charge_limits;
    if (!cl) return false;
    return cl.api_pix_minimum_charge_brl != null || cl.platform_minimum_charge_brl != null;
}

function hasCustomApiPix(u) {
    return u?.api_pix_mode && u.api_pix_mode !== 'inherit';
}

function feeRowHasSavedOverride(key) {
    return overrideBlockIsExplicit(savedFeeOverrides.value, key);
}

function feeRowHasDraftOverride(key) {
    const block = editForm.merchant_fees?.[key];
    return overrideBlockIsExplicit({ [key]: block }, key);
}

function overrideBlockIsExplicit(rawOverrides, key) {
    const block = rawOverrides?.[key];
    if (!block || typeof block !== 'object') {
        return false;
    }
    const hasPercent = block.percent !== '' && block.percent !== null && block.percent !== undefined;
    const hasFixed = block.fixed !== '' && block.fixed !== null && block.fixed !== undefined;
    return hasPercent || hasFixed;
}

function computeEffectiveFeesPreview(draftOverrides) {
    const effective = platformFeesMap();
    if (draftOverrides && typeof draftOverrides === 'object') {
        for (const k of feeRuleKeys) {
            const block = draftOverrides[k];
            if (!block || typeof block !== 'object') {
                continue;
            }
            if (block.percent !== undefined && block.percent !== null) {
                effective[k].percent = Number(block.percent) || 0;
            }
            if (block.fixed !== undefined && block.fixed !== null) {
                effective[k].fixed = Number(block.fixed) || 0;
            }
        }
        // Só herda quando o pai foi customizado (espelha EffectiveMerchantFees).
        if (!overrideBlockIsExplicit(draftOverrides, 'api_pix') && overrideBlockIsExplicit(draftOverrides, 'pix')) {
            effective.api_pix = { ...effective.pix };
        }
        if (!overrideBlockIsExplicit(draftOverrides, 'pixgo') && overrideBlockIsExplicit(draftOverrides, 'pix')) {
            effective.pixgo = { ...effective.pix };
        }
        if (!overrideBlockIsExplicit(draftOverrides, 'apple_pay') && overrideBlockIsExplicit(draftOverrides, 'card')) {
            effective.apple_pay = { ...effective.card };
        }
        if (!overrideBlockIsExplicit(draftOverrides, 'google_pay') && overrideBlockIsExplicit(draftOverrides, 'card')) {
            effective.google_pay = { ...effective.card };
        }
    }
    return feeOverrideRows.map((row) => ({
        key: row.key,
        label: row.label,
        percent: effective[row.key]?.percent ?? 0,
        fixed: effective[row.key]?.fixed ?? 0,
    }));
}

const effectiveFeesPreview = computed(() => {
    if (!isEditModalOpen.value) {
        return [];
    }
    const draft = normalizeMerchantFeeOverridesForSubmit(editForm.merchant_fees);
    return computeEffectiveFeesPreview(draft);
});

function defaultFeeOverrides() {
    return {
        pix: { percent: '', fixed: '' },
        api_pix: { percent: '', fixed: '' },
        pixgo: { percent: '', fixed: '' },
        card: { percent: '', fixed: '' },
        apple_pay: { percent: '', fixed: '' },
        google_pay: { percent: '', fixed: '' },
        boleto: { percent: '', fixed: '' },
        withdrawal: { percent: '', fixed: '' },
    };
}

const feeOverrideRows = [
    { key: 'pix', label: 'PIX (checkout)' },
    { key: 'api_pix', label: 'PIX (API)', inheritHint: 'Se vazio, herda de PIX (checkout)' },
    { key: 'pixgo', label: 'PixGo', inheritHint: 'Se vazio, herda de PIX (checkout)' },
    { key: 'card', label: 'Cartão' },
    { key: 'apple_pay', label: 'Apple Pay', inheritHint: 'Se vazio, herda de Cartão' },
    { key: 'google_pay', label: 'Google Pay', inheritHint: 'Se vazio, herda de Cartão' },
    { key: 'boleto', label: 'Boleto' },
    { key: 'withdrawal', label: 'Saque' },
];

const feeRuleKeys = ['pix', 'api_pix', 'pixgo', 'card', 'apple_pay', 'google_pay', 'boleto', 'withdrawal'];

const settlementOverrideRows = [
    { key: 'pix', label: 'PIX' },
    { key: 'card', label: 'Cartão' },
    { key: 'apple_pay', label: 'Apple Pay' },
    { key: 'google_pay', label: 'Google Pay' },
    { key: 'boleto', label: 'Boleto' },
];

function mergeFeeOverrides(raw) {
    const d = defaultFeeOverrides();
    if (!raw || typeof raw !== 'object') return d;
    for (const k of ['pix', 'api_pix', 'pixgo', 'card', 'apple_pay', 'google_pay', 'boleto', 'withdrawal']) {
        if (raw[k] && typeof raw[k] === 'object') {
            if (raw[k].percent != null && raw[k].percent !== '') {
                d[k].percent = formatPercentForInput(raw[k].percent);
            }
            if (raw[k].fixed != null && raw[k].fixed !== '') d[k].fixed = raw[k].fixed;
        }
    }
    return d;
}

function defaultSettlementOverrides() {
    return {
        pix: { days_to_available: '', reserve_percent: '', reserve_hold_days: '' },
        card: { days_to_available: '', reserve_percent: '', reserve_hold_days: '' },
        apple_pay: { days_to_available: '', reserve_percent: '', reserve_hold_days: '' },
        google_pay: { days_to_available: '', reserve_percent: '', reserve_hold_days: '' },
        boleto: { days_to_available: '', reserve_percent: '', reserve_hold_days: '' },
    };
}

function mergeSettlementOverrides(raw) {
    const d = defaultSettlementOverrides();
    if (!raw || typeof raw !== 'object') return d;
    for (const k of ['pix', 'card', 'apple_pay', 'google_pay', 'boleto']) {
        if (raw[k] && typeof raw[k] === 'object') {
            if (raw[k].days_to_available != null && raw[k].days_to_available !== '') {
                d[k].days_to_available = raw[k].days_to_available;
            }
            if (raw[k].reserve_percent != null && raw[k].reserve_percent !== '') {
                d[k].reserve_percent = raw[k].reserve_percent;
            }
            if (raw[k].reserve_hold_days != null && raw[k].reserve_hold_days !== '') {
                d[k].reserve_hold_days = raw[k].reserve_hold_days;
            }
        }
    }
    return d;
}

/** Todos os adquirentes do registo que suportam o método (como na config global); `is_connected` indica se há credencial conectada em algum lugar. */
function gatewaysForSelectMethod(method) {
    return (props.gateways || []).filter((g) => Array.isArray(g.methods) && g.methods.includes(method));
}

const gatewayOrderRows = [
    { key: 'pix', label: 'PIX' },
    { key: 'card', label: 'Cartão' },
    { key: 'boleto', label: 'Boleto' },
    { key: 'pix_auto', label: 'PIX automático' },
];

const showPixAutoRow = computed(() =>
    (props.gateways || []).some((g) => Array.isArray(g.methods) && g.methods.includes('pix_auto'))
);

const merchantGatewayPrimary = reactive({
    pix: '',
    card: '',
    boleto: '',
    pix_auto: '',
});

watch(
    merchantGatewayPrimary,
    (current) => {
        if (!editUser.value) {
            return;
        }
        const initial = initialGatewayPrimary.value;
        const methods = ['pix', 'card', 'boleto', 'pix_auto'];
        gatewayOrderDirty.value = methods.some((m) => (current[m] || '') !== (initial[m] || ''));
    },
    { deep: true }
);

/**
 * Mesma ideia da aba Financeiro → Adquirentes: lista completa com redundância (principal primeiro).
 * @param {string} method
 * @param {string} primarySlug
 */
function buildGatewayOrderListForMerchant(method, primarySlug) {
    if (!primarySlug) {
        return null;
    }
    const u = editUser.value;
    const platformPrev = (props.platform_gateway_order && props.platform_gateway_order[method]) || [];
    const merchantPrev = (u?.merchant_gateway_order && u.merchant_gateway_order[method]) || [];
    const prev = merchantPrev.length ? merchantPrev : platformPrev;
    const available = gatewaysForSelectMethod(method).map((g) => g.slug);
    if (available.length === 0) {
        return null;
    }
    if (!available.includes(primarySlug)) {
        const filtered = prev.filter((s) => available.includes(s));
        return filtered.length ? filtered : [...available];
    }
    const rest = [];
    const seen = new Set([primarySlug]);
    for (const s of prev) {
        if (!seen.has(s) && available.includes(s)) {
            rest.push(s);
            seen.add(s);
        }
    }
    for (const s of available) {
        if (!seen.has(s)) {
            rest.push(s);
            seen.add(s);
        }
    }
    return [primarySlug, ...rest];
}

function syncMerchantPrimaryFromUser(u) {
    const pOrder = props.platform_gateway_order || {};
    for (const method of ['pix', 'card', 'boleto', 'pix_auto']) {
        const slugs = gatewaysForSelectMethod(method).map((g) => g.slug);
        if (!slugs.length) {
            merchantGatewayPrimary[method] = '';
            continue;
        }
        const mo = u.merchant_gateway_order?.[method];
        const hasMerchantOverride = Array.isArray(mo) && mo.length > 0;
        if (!hasMerchantOverride) {
            merchantGatewayPrimary[method] = '';
            continue;
        }
        const first = mo.find((s) => slugs.includes(s));
        merchantGatewayPrimary[method] = first || '';
    }
}

const editForm = useForm({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    account_status: 'approved',
    admin_withdrawal_blocked: false,
    admin_blocked_amount: '',
    admin_block_until: '',
    admin_block_note: '',
    merchant_fees: defaultFeeOverrides(),
    merchant_settlement_overrides: defaultSettlementOverrides(),
    referral_commission_percent: '',
    api_pix_mode: 'inherit',
    med_zero_enabled: false,
    api_pix_minimum_charge_brl: '',
    platform_minimum_charge_brl: '',
    use_platform_api_pix_minimum: true,
    use_platform_platform_minimum: true,
    cajupay_account_id: '',
});

const showCajuPayAccountField = computed(() => {
    const methods = ['pix', 'card'];
    return methods.some((m) =>
        (props.gateways || []).some((g) => g.slug === 'cajupay' && Array.isArray(g.methods) && g.methods.includes(m))
    );
});

const feePercentRefs = {};
const feeFixedRefs = {};

function setFeePercentRef(key, el) {
    if (el) {
        feePercentRefs[key] = el;
    } else {
        delete feePercentRefs[key];
    }
}

function setFeeFixedRef(key, el) {
    if (el) {
        feeFixedRefs[key] = el;
    } else {
        delete feeFixedRefs[key];
    }
}

function flushFeeInputs() {
    for (const row of feeOverrideRows) {
        feePercentRefs[row.key]?.commit?.();
        feeFixedRefs[row.key]?.commit?.();
    }
}

function updateMerchantFeeField(key, field, value) {
    feesDirty.value = true;
    editForm.merchant_fees = {
        ...editForm.merchant_fees,
        [key]: {
            ...editForm.merchant_fees[key],
            [field]: value,
        },
    };
}

function markSettlementDirty() {
    settlementDirty.value = true;
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

const isEditModalOpen = computed(() => editUser.value !== null);

function openEditModal(u) {
    editUser.value = u;
    savedFeeOverrides.value = u.merchant_fees ?? null;
    savedSettlementOverrides.value = u.merchant_settlement_overrides ?? null;
    feesDirty.value = false;
    settlementDirty.value = false;
    gatewayOrderDirty.value = false;
    cajupayAccountDirty.value = false;
    limitsDirty.value = false;
    apiPixDirty.value = false;
    const wa = u.wallet_admin;
    const cl = u.charge_limits || {};
    editForm.defaults({
        name: u.name,
        email: u.email,
        phone: formatPhoneForInput(u.phone),
        password: '',
        password_confirmation: '',
        account_status: u.account_status || 'approved',
        admin_withdrawal_blocked: !!(wa && wa.admin_withdrawal_blocked),
        admin_blocked_amount:
            wa && wa.admin_blocked_amount != null && wa.admin_blocked_amount !== '' ? String(wa.admin_blocked_amount) : '',
        admin_block_until: formatBlockUntilForInput(wa?.admin_block_until),
        admin_block_note: wa?.admin_block_note || '',
        merchant_fees: mergeFeeOverrides(u.merchant_fees),
        merchant_settlement_overrides: mergeSettlementOverrides(u.merchant_settlement_overrides),
        referral_commission_percent:
            u.referral_commission_percent != null && u.referral_commission_percent !== ''
                ? String(u.referral_commission_percent)
                : '',
        api_pix_mode: u.api_pix_mode || 'inherit',
        med_zero_enabled: !!u.med_zero_enabled,
        api_pix_minimum_charge_brl:
            cl.api_pix_minimum_charge_brl != null ? String(cl.api_pix_minimum_charge_brl) : '',
        platform_minimum_charge_brl:
            cl.platform_minimum_charge_brl != null ? String(cl.platform_minimum_charge_brl) : '',
        use_platform_api_pix_minimum: cl.api_pix_minimum_charge_brl == null,
        use_platform_platform_minimum: cl.platform_minimum_charge_brl == null,
        cajupay_account_id: u.cajupay_account_id != null ? String(u.cajupay_account_id) : '',
    });
    editForm.reset();
    syncMerchantPrimaryFromUser(u);
    initialGatewayPrimary.value = {
        pix: merchantGatewayPrimary.pix,
        card: merchantGatewayPrimary.card,
        boleto: merchantGatewayPrimary.boleto,
        pix_auto: merchantGatewayPrimary.pix_auto,
    };
    editForm.clearErrors();
}

function onAdminNotesCountChanged(userId, count) {
    adminNotesCountByUser.value = { ...adminNotesCountByUser.value, [userId]: count };
}

function closeEditModal() {
    editUser.value = null;
    if (props.edit_user_id) {
        router.get('/plataforma/usuarios', { q: props.q || undefined }, { preserveState: true, replace: true });
    }
}

function restoreFeesDefaults() {
    feesDirty.value = true;
    editForm.merchant_fees = defaultFeeOverrides();
}

function restoreSettlementDefaults() {
    settlementDirty.value = true;
    editForm.merchant_settlement_overrides = defaultSettlementOverrides();
}

function markLimitsDirty() {
    limitsDirty.value = true;
}

function markApiPixDirty() {
    apiPixDirty.value = true;
}

const effectiveApiPixMinimumPreview = computed(() => {
    if (!isEditModalOpen.value) return null;
    if (editForm.use_platform_api_pix_minimum) {
        return Number(props.platform_charge_limits?.api_pix_minimum_charge_brl) || 0;
    }
    const v = parseFloat(editForm.api_pix_minimum_charge_brl);
    return Number.isFinite(v) ? v : Number(props.platform_charge_limits?.api_pix_minimum_charge_brl) || 0;
});

const effectivePlatformMinimumPreview = computed(() => {
    if (!isEditModalOpen.value) return null;
    if (editForm.use_platform_platform_minimum) {
        return Number(props.platform_charge_limits?.platform_minimum_charge_brl) || 0;
    }
    const v = parseFloat(editForm.platform_minimum_charge_brl);
    return Number.isFinite(v) ? v : Number(props.platform_charge_limits?.platform_minimum_charge_brl) || 0;
});

onMounted(() => {
    if (props.edit_user_id) {
        const u = props.users.find((row) => row.id === props.edit_user_id);
        if (u) {
            openEditModal(u);
        }
    }
});

function submitEdit() {
    if (!editUser.value) return;
    flushFeeInputs();
    editForm
        .transform((data) => {
            const order = {};
            for (const m of ['pix', 'card', 'boleto', 'pix_auto']) {
                const p = merchantGatewayPrimary[m];
                if (!p) {
                    continue;
                }
                const built = buildGatewayOrderListForMerchant(m, p);
                if (built && built.length) {
                    order[m] = built;
                }
            }

            const payload = { ...data };
            const refPct = String(data.referral_commission_percent ?? '').trim();
            payload.referral_commission_percent = refPct === '' ? null : Number(refPct.replace(',', '.'));

            if (gatewayOrderDirty.value) {
                payload.merchant_gateway_order = Object.keys(order).length ? order : null;
            } else {
                delete payload.merchant_gateway_order;
            }
            if (feesDirty.value) {
                payload.merchant_fees = normalizeMerchantFeeOverridesForSubmit(data.merchant_fees);
            } else {
                delete payload.merchant_fees;
            }
            if (settlementDirty.value) {
                payload.merchant_settlement_overrides = normalizeMerchantSettlementOverridesForSubmit(
                    data.merchant_settlement_overrides
                );
            } else {
                delete payload.merchant_settlement_overrides;
            }
            if (apiPixDirty.value) {
                payload.api_pix_mode = data.api_pix_mode || 'inherit';
                payload.med_zero_enabled = !!data.med_zero_enabled;
            } else {
                delete payload.api_pix_mode;
                delete payload.med_zero_enabled;
            }
            if (limitsDirty.value) {
                payload.use_platform_api_pix_minimum = !!data.use_platform_api_pix_minimum;
                payload.use_platform_platform_minimum = !!data.use_platform_platform_minimum;
                if (data.use_platform_api_pix_minimum) {
                    payload.api_pix_minimum_charge_brl = '';
                } else {
                    payload.api_pix_minimum_charge_brl = data.api_pix_minimum_charge_brl;
                }
                if (data.use_platform_platform_minimum) {
                    payload.platform_minimum_charge_brl = '';
                } else {
                    payload.platform_minimum_charge_brl = data.platform_minimum_charge_brl;
                }
            } else {
                delete payload.api_pix_minimum_charge_brl;
                delete payload.platform_minimum_charge_brl;
                delete payload.use_platform_api_pix_minimum;
                delete payload.use_platform_platform_minimum;
            }
            if (cajupayAccountDirty.value) {
                payload.cajupay_account_id = data.cajupay_account_id ? Number(data.cajupay_account_id) : null;
            } else {
                delete payload.cajupay_account_id;
            }
            return payload;
        })
        .put(`/plataforma/usuarios/${editUser.value.id}`, {
            preserveScroll: true,
            onSuccess: () => closeEditModal(),
        });
}

function destroyUser(id) {
    if (!confirm('Excluir este infoprodutor? Esta ação não pode ser desfeita.')) return;
    deletingId.value = id;
    router.delete(`/plataforma/usuarios/${id}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
        },
    });
}

const selectedCount = computed(() => selectedIds.value.length);

const allVisibleSelected = computed(() => {
    if (!props.users.length) return false;
    return props.users.every((u) => selectedIds.value.includes(u.id));
});

const bulkDeleteTargets = computed(() =>
    props.users.filter((u) => selectedIds.value.includes(u.id))
);

const bulkDeleteResult = computed(() => page.props.flash?.bulk_delete_result ?? null);

function toggleUserSelection(id) {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((rowId) => rowId !== id);
        return;
    }
    selectedIds.value = [...selectedIds.value, id];
}

function toggleSelectAllVisible() {
    if (allVisibleSelected.value) {
        const visibleIds = new Set(props.users.map((u) => u.id));
        selectedIds.value = selectedIds.value.filter((id) => !visibleIds.has(id));
        return;
    }
    const merged = new Set([...selectedIds.value, ...props.users.map((u) => u.id)]);
    selectedIds.value = [...merged];
}

function selectPendingWithoutSales() {
    const ids = props.users
        .filter((u) => (u.account_status || 'approved') === 'pending' && Number(u.vendas_totais || 0) === 0)
        .map((u) => u.id);
    selectedIds.value = [...new Set([...selectedIds.value, ...ids])];
}

function openBulkDeleteModal(force = false) {
    if (!selectedIds.value.length) return;
    bulkDeleteForce.value = force;
    bulkDeleteOpen.value = true;
}

function closeBulkDeleteModal() {
    bulkDeleteOpen.value = false;
    bulkDeleteLoading.value = false;
}

function closeBulkStepUp() {
    bulkStepUpOpen.value = false;
    bulkDeleteLoading.value = false;
}

function requestBulkDelete() {
    if (!selectedIds.value.length) return;
    if (platformTotpEnabled.value) {
        bulkDeleteOpen.value = false;
        bulkStepUpOpen.value = true;
        return;
    }
    submitBulkDelete();
}

function onBulkStepUpConfirm(payload) {
    bulkDeleteLoading.value = true;
    submitBulkDelete(payload.totp_code);
}

function submitBulkDelete(totpCode = '') {
    bulkDeleteLoading.value = true;
    router.post(
        '/plataforma/usuarios/excluir-em-massa',
        {
            ids: selectedIds.value,
            confirm: true,
            force: bulkDeleteForce.value,
            totp_code: totpCode || undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedIds.value = [];
                closeBulkDeleteModal();
            },
            onFinish: () => {
                bulkDeleteLoading.value = false;
                bulkStepUpOpen.value = false;
            },
        }
    );
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
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

function formatBlockUntilForInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Infoprodutores</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Saldo, documento e status da conta</p>
            </div>
            <Link
                href="/plataforma/usuarios/create"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-zinc-900 px-4 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
            >
                <UserPlus class="h-4 w-4" />
                Novo infoprodutor
            </Link>
        </div>

        <form class="flex flex-wrap items-center gap-2" @submit.prevent="applySearch">
            <div class="relative min-w-[200px] flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                <input
                    v-model="searchQ"
                    type="search"
                    placeholder="Nome, e-mail, documento ou ID"
                    class="w-full rounded-xl border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
            </div>
            <select
                v-model="statusFilter"
                class="min-w-[11rem] rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                @change="applySearch"
            >
                <option value="">Todos os status</option>
                <option v-for="opt in status_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
            <button
                type="submit"
                class="rounded-xl bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white transition hover:opacity-90"
            >
                Pesquisar
            </button>
            <button
                v-if="searchQ || statusFilter"
                type="button"
                class="rounded-xl border border-zinc-300 px-4 py-2 text-sm text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                @click="clearFilters"
            >
                Limpar
            </button>
        </form>

        <p
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>

        <p
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ page.props.flash.error }}
        </p>

        <div
            v-if="bulkDeleteResult"
            class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-800 dark:border-zinc-700 dark:bg-zinc-900/60 dark:text-zinc-200"
        >
            <p class="font-medium">Resultado da exclusão em massa</p>
            <p v-if="bulkDeleteResult.deleted?.length" class="mt-2 text-xs">
                Excluídos: {{ bulkDeleteResult.deleted.join(', ') }}
            </p>
            <ul v-if="bulkDeleteResult.skipped?.length" class="mt-2 list-inside list-disc space-y-1 text-xs">
                <li v-for="row in bulkDeleteResult.skipped" :key="`${row.id}-${row.reason}`">
                    #{{ row.id }} — {{ row.reason }}
                </li>
            </ul>
        </div>

        <div
            v-if="selectedCount > 0"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900/60"
        >
            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ selectedCount }} selecionado(s)</span>
            <button
                type="button"
                class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
                @click="openBulkDeleteModal(false)"
            >
                Excluir selecionados ({{ selectedCount }})
            </button>
            <button
                type="button"
                class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-white dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                @click="selectPendingWithoutSales"
            >
                Selecionar pendentes sem vendas
            </button>
            <button
                type="button"
                class="rounded-lg px-3 py-1.5 text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                @click="selectedIds = []"
            >
                Limpar seleção
            </button>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/60">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/80 dark:text-zinc-400">
                    <tr>
                        <th class="w-10 px-3 py-3">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-zinc-300"
                                :checked="allVisibleSelected"
                                :disabled="!users.length"
                                @change="toggleSelectAllVisible"
                            />
                        </th>
                        <th class="px-4 py-3">Nome</th>
                        <th class="px-4 py-3">E-mail</th>
                        <th class="px-4 py-3">Documento</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right" title="Pedidos concluídos via gateway (exclui aprovação manual)">
                            Vendas totais
                        </th>
                        <th class="px-4 py-3 text-right">Saldo</th>
                        <th class="px-4 py-3 text-right">Pendente</th>
                        <th class="px-4 py-3 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users" :key="u.id" class="border-b border-zinc-100 dark:border-zinc-800">
                        <td class="px-3 py-3">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-zinc-300"
                                :checked="selectedIds.includes(u.id)"
                                @change="toggleUserSelection(u.id)"
                            />
                        </td>
                        <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                            <span>{{ u.name }}</span>
                            <span
                                v-if="u.totp_enabled"
                                class="ml-2 inline-flex items-center gap-1 rounded-md bg-emerald-100 px-1.5 py-0.5 text-[10px] font-medium text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200"
                                title="Autenticação em dois fatores ativa"
                            >
                                <Shield class="h-3 w-3" />
                                2FA
                            </span>
                            <span
                                v-if="(adminNotesCountByUser[u.id] ?? u.admin_notes_count) > 0"
                                class="ml-2 inline-flex items-center gap-1 rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 dark:bg-amber-950/50 dark:text-amber-200"
                                title="Observações internas"
                            >
                                <MessageSquare class="h-3 w-3" />
                                {{ adminNotesCountByUser[u.id] ?? u.admin_notes_count }}
                            </span>
                            <span
                                v-if="hasCustomFees(u)"
                                class="ml-2 inline-flex rounded-md bg-violet-100 px-1.5 py-0.5 text-[10px] font-medium text-violet-800 dark:bg-violet-950/50 dark:text-violet-200"
                                title="Taxas personalizadas"
                            >
                                Taxas custom
                            </span>
                            <span
                                v-if="hasCustomSettlement(u)"
                                class="ml-2 inline-flex rounded-md bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium text-sky-800 dark:bg-sky-950/50 dark:text-sky-200"
                                title="Liquidação personalizada"
                            >
                                Liquidação custom
                            </span>
                            <span
                                v-if="u.med_zero_enabled"
                                class="ml-2 inline-flex rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-900 dark:bg-amber-950/50 dark:text-amber-200"
                                title="MED Zero ativo"
                            >
                                MED Zero
                            </span>
                        </td>
                        <td class="max-w-[200px] truncate px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ u.email }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ u.document || '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-800">{{ statusLabel(u.account_status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-zinc-900 dark:text-white">
                            {{ formatBRL(u.vendas_totais) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ formatBRL(u.saldo_disponivel) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-zinc-500">{{ formatBRL(u.saldo_pix) }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-1">
                                <Link
                                    :href="`/plataforma/usuarios/${u.id}`"
                                    class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-[var(--color-primary)] dark:hover:bg-zinc-800"
                                    title="Ver infoprodutor"
                                >
                                    <Eye class="h-4 w-4" />
                                </Link>
                                <Link
                                    :href="`/plataforma/verificacoes-kyc/usuario/${u.id}`"
                                    class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800"
                                    title="Ver KYC"
                                >
                                    <BadgeCheck class="h-4 w-4" />
                                </Link>
                                <button
                                    type="button"
                                    class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800"
                                    title="Editar"
                                    @click="openEditModal(u)"
                                >
                                    <Pencil class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40"
                                    title="Excluir"
                                    :disabled="deletingId === u.id"
                                    @click="destroyUser(u.id)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!users.length">
                        <td colspan="9" class="px-4 py-10 text-center text-zinc-500">
                            {{ q ? 'Nenhum infoprodutor encontrado.' : 'Nenhum infoprodutor cadastrado.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Modal editar -->
        <div
            v-if="isEditModalOpen"
            class="fixed inset-0 z-[200000] flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
        >
            <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Editar infoprodutor</h3>
                    <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="closeEditModal">
                        <X class="h-5 w-5" />
                    </button>
                </div>
                <form class="space-y-4" @submit.prevent="submitEdit">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome</label>
                        <input v-model="editForm.name" type="text" required class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" />
                        <p v-if="editForm.errors.name" class="mt-1 text-sm text-red-600">{{ editForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail</label>
                        <input v-model="editForm.email" type="email" required class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" />
                        <p v-if="editForm.errors.email" class="mt-1 text-sm text-red-600">{{ editForm.errors.email }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">WhatsApp / telefone</label>
                        <input
                            :value="editForm.phone"
                            type="tel"
                            inputmode="tel"
                            placeholder="(11) 99999-9999"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"
                            @input="editForm.phone = formatPhoneForInput($event.target.value)"
                        />
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Exibido no contato do infoprodutor com link para o WhatsApp.</p>
                        <p v-if="editForm.errors.phone" class="mt-1 text-sm text-red-600">{{ editForm.errors.phone }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nova senha (opcional)</label>
                        <input v-model="editForm.password" type="password" minlength="8" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" />
                        <p v-if="editForm.errors.password" class="mt-1 text-sm text-red-600">{{ editForm.errors.password }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Confirmar senha</label>
                        <input v-model="editForm.password_confirmation" type="password" minlength="8" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" />
                    </div>
                    <div class="rounded-xl border border-amber-200/80 bg-amber-50/50 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
                        <p class="mb-3 text-sm font-medium text-zinc-800 dark:text-zinc-200">Conta e acesso ao painel</p>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Status da conta</label>
                        <select
                            v-model="editForm.account_status"
                            class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        >
                            <option value="approved">Aprovado</option>
                            <option value="pending">Pendente</option>
                            <option value="rejected">Rejeitado</option>
                            <option value="suspended">Suspenso (não acessa o painel)</option>
                            <option value="blocked">Bloqueado (não acessa o painel)</option>
                        </select>
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            Suspenso ou bloqueado: o infoprodutor e a equipe não conseguem entrar no painel do vendedor.
                        </p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <p class="mb-3 text-sm font-medium text-zinc-800 dark:text-zinc-200">Saldo e saques</p>
                        <p v-if="editUser" class="mb-3 text-xs text-zinc-600 dark:text-zinc-400">
                            Disponível (total): <strong class="text-zinc-800 dark:text-zinc-200">{{ formatBRL(editUser.saldo_disponivel) }}</strong>
                            · PIX pendente: <strong class="text-zinc-800 dark:text-zinc-200">{{ formatBRL(editUser.saldo_pix) }}</strong>
                            · MED (contestação, ref. carteira):
                            <strong class="text-zinc-800 dark:text-zinc-200">{{ formatBRL(editUser.med_total ?? 0) }}</strong>
                        </p>
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <input v-model="editForm.admin_withdrawal_blocked" type="checkbox" class="rounded border-zinc-300" />
                            Bloquear todos os saques (administrativo)
                        </label>
                        <div class="mt-3">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Valor adicional bloqueado (R$)</label>
                            <input
                                v-model="editForm.admin_blocked_amount"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0,00"
                                class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"
                            />
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Reduz o saldo disponível para saque neste valor (por carteira ao solicitar). MED já retira valor do disponível automaticamente.
                            </p>
                        </div>
                        <div class="mt-3">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Bloqueio automático até (opcional)</label>
                            <input
                                v-model="editForm.admin_block_until"
                                type="datetime-local"
                                class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"
                            />
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Após esta data/hora, bloqueio total e valor extra são limpos automaticamente.</p>
                        </div>
                        <div class="mt-3">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Observação interna (opcional)</label>
                            <input
                                v-model="editForm.admin_block_note"
                                type="text"
                                maxlength="500"
                                class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"
                            />
                        </div>
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <p class="mb-3 text-sm font-medium text-zinc-800 dark:text-zinc-200">Indique e Ganhe</p>
                        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
                            Percentual da taxa da plataforma que este infoprodutor recebe quando um indicado dele vende.
                            Deixe em branco para usar o padrão da plataforma
                            ({{ Number(platform_referral_commission_percent || 0).toLocaleString('pt-BR', { maximumFractionDigits: 4 }) }}%).
                        </p>
                        <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400">
                            Taxa de indicação (%)
                        </label>
                        <input
                            v-model="editForm.referral_commission_percent"
                            type="text"
                            inputmode="decimal"
                            placeholder="Herdar padrão"
                            class="mt-1 w-full max-w-xs rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"
                        />
                        <p v-if="editForm.errors.referral_commission_percent" class="mt-1 text-xs text-red-500">
                            {{ editForm.errors.referral_commission_percent }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Taxas (opcional)</p>
                            <button
                                type="button"
                                class="text-xs font-medium text-[var(--color-primary)] hover:underline"
                                @click="restoreFeesDefaults"
                            >
                                Restaurar padrões da plataforma
                            </button>
                        </div>
                        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                            Sobrescreve os padrões em Financeiro → Taxas. PIX tem taxas separadas para API (REST ou link de checkout pela API) e PixGo; cartão e boleto usam as linhas Cartão / Boleto. Deixe em branco para herdar.
                            Percentual de 0 a 100 (ex.: <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">2,5</code> = 2,5%). Fixo em reais (ex.: <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">1,50</code> = R$ 1,50).
                        </p>
                        <div class="hidden gap-2 text-xs font-medium uppercase tracking-wide text-zinc-500 sm:grid sm:grid-cols-[minmax(0,1.1fr)_1fr_1fr] dark:text-zinc-400">
                            <span>Canal</span>
                            <span>Percentual (%)</span>
                            <span>Valor fixo (R$)</span>
                        </div>
                        <div class="space-y-3 text-sm">
                            <div
                                v-for="row in feeOverrideRows"
                                :key="row.key"
                                class="grid gap-2 rounded-lg p-2 sm:grid-cols-[minmax(0,1.1fr)_1fr_1fr] sm:items-center"
                                :class="
                                    feeRowHasSavedOverride(row.key) || feeRowHasDraftOverride(row.key)
                                        ? 'bg-violet-50/80 ring-1 ring-violet-200/80 dark:bg-violet-950/20 dark:ring-violet-800/50'
                                        : ''
                                "
                            >
                                <div>
                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ row.label }}</span>
                                    <p v-if="row.inheritHint" class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                        {{ row.inheritHint }}
                                    </p>
                                </div>
                                <FeePercentInput
                                    :ref="(el) => setFeePercentRef(row.key, el)"
                                    :model-value="editForm.merchant_fees[row.key].percent"
                                    allow-empty
                                    @update:model-value="(v) => updateMerchantFeeField(row.key, 'percent', v)"
                                />
                                <FeeFixedInput
                                    :ref="(el) => setFeeFixedRef(row.key, el)"
                                    :model-value="editForm.merchant_fees[row.key].fixed"
                                    allow-empty
                                    @update:model-value="(v) => updateMerchantFeeField(row.key, 'fixed', v)"
                                />
                            </div>
                        </div>
                        <div class="mt-4 rounded-lg border border-zinc-100 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Taxa efetiva após salvar</p>
                            <div class="space-y-1 text-xs">
                                <div
                                    v-for="row in effectiveFeesPreview"
                                    :key="'prev-' + row.key"
                                    class="flex justify-between gap-2 text-zinc-700 dark:text-zinc-300"
                                >
                                    <span>{{ row.label }}</span>
                                    <span class="tabular-nums text-zinc-900 dark:text-white">
                                        {{ formatFeePreview(row.percent, row.fixed) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Liquidação (opcional)</p>
                            <button
                                type="button"
                                class="text-xs font-medium text-[var(--color-primary)] hover:underline"
                                @click="restoreSettlementDefaults"
                            >
                                Restaurar padrões da plataforma
                            </button>
                        </div>
                        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                            Sobrescreve Financeiro → Liquidação. Deixe em branco para herdar da plataforma.
                        </p>
                        <div class="space-y-3 text-sm">
                            <div
                                v-for="row in settlementOverrideRows"
                                :key="'set-' + row.key"
                                class="grid gap-2 sm:grid-cols-[100px_1fr_1fr_1fr] sm:items-center"
                            >
                                <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ row.label }}</span>
                                <input
                                    v-model="editForm.merchant_settlement_overrides[row.key].days_to_available"
                                    type="number"
                                    min="0"
                                    max="365"
                                    step="1"
                                    placeholder="Dias D+N"
                                    class="rounded-lg border border-zinc-300 px-2 py-1.5 dark:border-zinc-600 dark:bg-zinc-800"
                                    @input="markSettlementDirty"
                                />
                                <input
                                    v-model="editForm.merchant_settlement_overrides[row.key].reserve_percent"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    placeholder="Reserva %"
                                    class="rounded-lg border border-zinc-300 px-2 py-1.5 dark:border-zinc-600 dark:bg-zinc-800"
                                    @input="markSettlementDirty"
                                />
                                <input
                                    v-model="editForm.merchant_settlement_overrides[row.key].reserve_hold_days"
                                    type="number"
                                    min="0"
                                    max="365"
                                    step="1"
                                    placeholder="Extra reserva (dias)"
                                    class="rounded-lg border border-zinc-300 px-2 py-1.5 dark:border-zinc-600 dark:bg-zinc-800"
                                    @input="markSettlementDirty"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <p class="mb-3 text-sm font-medium text-zinc-800 dark:text-zinc-200">API PIX</p>
                        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
                            Controla se este infoprodutor pode usar a API PIX (REST, checkout hospedado e chaves de API).
                            Padrão global: {{ platform_api_pix_enabled ? 'habilitada' : 'desabilitada' }} em Financeiro → Taxas.
                        </p>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Modo</label>
                        <select
                            v-model="editForm.api_pix_mode"
                            class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            @change="markApiPixDirty"
                        >
                            <option value="inherit">Herdar plataforma</option>
                            <option value="enabled">Habilitada</option>
                            <option value="disabled">Desabilitada</option>
                        </select>
                        <p v-if="editUser" class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            Efetivo após salvar:
                            <strong class="text-zinc-700 dark:text-zinc-200">
                                {{
                                    editForm.api_pix_mode === 'enabled'
                                        ? 'Habilitada'
                                        : editForm.api_pix_mode === 'disabled'
                                          ? 'Desabilitada'
                                          : platform_api_pix_enabled
                                            ? 'Habilitada (herda plataforma)'
                                            : 'Desabilitada (herda plataforma)'
                                }}
                            </strong>
                        </p>
                        <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <input
                                v-model="editForm.med_zero_enabled"
                                type="checkbox"
                                class="rounded border-zinc-300"
                                @change="markApiPixDirty"
                            />
                            MED Zero — plataforma assume MED de API PIX (sem retenção no infoprodutor)
                        </label>
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <p class="mb-3 text-sm font-medium text-zinc-800 dark:text-zinc-200">Limites de cobrança (opcional)</p>
                        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                            Sobrescreve Financeiro → Limites. Vazio ou «usar padrão» herda os valores globais
                            (API PIX: {{ formatBRL(platform_charge_limits.api_pix_minimum_charge_brl) }},
                            plataforma: {{ formatBRL(platform_charge_limits.platform_minimum_charge_brl) }}).
                        </p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Ticket mínimo API PIX (R$)
                                </label>
                                <input
                                    v-model="editForm.api_pix_minimum_charge_brl"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :disabled="editForm.use_platform_api_pix_minimum"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800"
                                    @input="markLimitsDirty"
                                />
                                <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                                    <input
                                        v-model="editForm.use_platform_api_pix_minimum"
                                        type="checkbox"
                                        class="rounded border-zinc-300"
                                        @change="markLimitsDirty"
                                    />
                                    Usar padrão da plataforma
                                </label>
                                <p class="mt-1 text-xs text-zinc-500">Efetivo: {{ formatBRL(effectiveApiPixMinimumPreview) }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Ticket mínimo plataforma (R$)
                                </label>
                                <input
                                    v-model="editForm.platform_minimum_charge_brl"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :disabled="editForm.use_platform_platform_minimum"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800"
                                    @input="markLimitsDirty"
                                />
                                <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                                    <input
                                        v-model="editForm.use_platform_platform_minimum"
                                        type="checkbox"
                                        class="rounded border-zinc-300"
                                        @change="markLimitsDirty"
                                    />
                                    Usar padrão da plataforma
                                </label>
                                <p class="mt-1 text-xs text-zinc-500">Efetivo: {{ formatBRL(effectivePlatformMinimumPreview) }}</p>
                            </div>
                        </div>
                    </div>
                    <div v-if="showCajuPayAccountField" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <p class="mb-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">Conta CajuPay</p>
                        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
                            Direciona pagamentos, saques e API PIX deste infoprodutor para uma conta específica. Deixe em padrão para usar a conta global.
                        </p>
                        <select
                            v-model="editForm.cajupay_account_id"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            @change="cajupayAccountDirty = true"
                        >
                            <option value="">Padrão da plataforma</option>
                            <option v-for="acc in props.cajupay_accounts" :key="acc.id" :value="String(acc.id)">
                                {{ acc.name }}{{ acc.is_default ? ' (padrão global)' : '' }}
                            </option>
                        </select>
                    </div>
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <p class="mb-3 text-sm font-medium text-zinc-800 dark:text-zinc-200">Ordem de adquirentes (opcional)</p>
                        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                            Escolha o adquirente principal por forma de pagamento (entre os já conectados na plataforma). «Padrão da
                            plataforma» herda a ordem de Financeiro → Adquirentes.
                        </p>
                        <div class="space-y-3 text-sm">
                            <template v-for="row in gatewayOrderRows" :key="'go-' + row.key">
                                <div
                                    v-if="row.key !== 'pix_auto' || showPixAutoRow"
                                    class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3"
                                >
                                    <label class="w-40 shrink-0 font-medium text-zinc-700 dark:text-zinc-300">{{ row.label }}</label>
                                    <select
                                        v-model="merchantGatewayPrimary[row.key]"
                                        class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    >
                                        <option value="">Padrão da plataforma</option>
                                        <option v-for="g in gatewaysForSelectMethod(row.key)" :key="g.slug" :value="g.slug">
                                            {{ g.name }}{{ g.is_connected ? '' : ' (não conectado)' }}
                                        </option>
                                    </select>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div v-if="editUser" class="rounded-xl border border-amber-200/80 bg-amber-50/40 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
                        <p class="mb-3 text-sm font-medium text-zinc-800 dark:text-zinc-200">Observações internas</p>
                        <MerchantAdminNotesPanel
                            :merchant-user-id="editUser.id"
                            compact
                            :initial-count="editUser.admin_notes_count || 0"
                            @count-changed="(n) => onAdminNotesCountChanged(editUser.id, n)"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" @click="closeEditModal">Cancelar</Button>
                        <Button type="submit" :disabled="editForm.processing">Salvar</Button>
                    </div>
                </form>
            </div>
        </div>

        <div
            v-if="bulkDeleteOpen"
            class="fixed inset-0 z-[200001] flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="closeBulkDeleteModal"
        >
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Excluir contas selecionadas</h3>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Esta ação não pode ser desfeita. Contas com saldo ou pedidos pagos/em disputa serão ignoradas.
                </p>
                <ul class="mt-4 max-h-48 space-y-1 overflow-y-auto text-sm text-zinc-700 dark:text-zinc-300">
                    <li v-for="u in bulkDeleteTargets" :key="u.id">
                        #{{ u.id }} — {{ u.name }} ({{ u.email }})
                    </li>
                </ul>
                <div class="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="secondary" :disabled="bulkDeleteLoading" @click="closeBulkDeleteModal">
                        Cancelar
                    </Button>
                    <Button type="button" :disabled="bulkDeleteLoading" @click="requestBulkDelete">
                        Excluir {{ selectedCount }} conta(s)
                    </Button>
                </div>
            </div>
        </div>

        <PlatformStepUpModal
            :open="bulkStepUpOpen"
            title="Confirmar exclusão em massa"
            description="Informe o código 2FA para excluir as contas selecionadas."
            confirm-label="Excluir contas"
            :loading="bulkDeleteLoading"
            @close="closeBulkStepUp"
            @confirm="onBulkStepUpConfirm"
        />
    </div>
</template>
