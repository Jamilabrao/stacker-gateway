<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    slug: { type: String, required: true },
    module: { type: Object, required: true },
    compact: { type: Boolean, default: false },
});

const open = ref(false);
const loading = ref(false);
const error = ref('');
const orderId = ref(null);
const qrcode = ref('');
const copyPaste = ref('');
const amount = ref(null);
const copied = ref(false);
let pollTimer = null;

const formattedAmount = computed(() => {
    const value = Number(amount.value ?? props.module?.renewal_amount ?? 0);
    if (!Number.isFinite(value) || value <= 0) return '';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
});

const qrcodeSrc = computed(() => {
    const q = (qrcode.value || '').trim();
    if (!q) return '';
    if (q.startsWith('data:')) return q;
    if (/^https?:\/\//i.test(q)) return q;
    if (q.startsWith('//')) return `https:${q}`;
    return `data:image/png;base64,${q}`;
});

const pixUrl = computed(() => `/m/${props.slug}/modulo/${props.module.id}/renovar-pix`);

watch(() => props.module?.id, () => {
    close();
});

onUnmounted(() => stopPolling());

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function close() {
    open.value = false;
    loading.value = false;
    error.value = '';
    stopPolling();
}

async function startRenewal() {
    open.value = true;
    loading.value = true;
    error.value = '';
    copied.value = false;
    try {
        const { data } = await axios.post(pixUrl.value, {}, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        orderId.value = data.order_id;
        qrcode.value = data.qrcode || '';
        copyPaste.value = data.copy_paste || '';
        amount.value = data.amount ?? props.module?.renewal_amount;
        startPolling();
    } catch (e) {
        error.value = e?.response?.data?.message || 'Não foi possível gerar o PIX. Tente novamente.';
    } finally {
        loading.value = false;
    }
}

function startPolling() {
    stopPolling();
    if (!orderId.value) return;
    pollTimer = setInterval(async () => {
        try {
            const { data } = await axios.get(`${pixUrl.value}/${orderId.value}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (data?.paid || data?.status === 'completed') {
                stopPolling();
                router.reload({ preserveScroll: true });
            }
        } catch (_) {
            // continua tentando até o usuário fechar
        }
    }, 3000);
}

async function copyCode() {
    if (!copyPaste.value) return;
    try {
        await navigator.clipboard.writeText(copyPaste.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch (_) {
        copied.value = false;
    }
}
</script>

<template>
    <div>
        <Button
            type="button"
            :size="compact ? 'sm' : 'default'"
            class="mt-2"
            @click.stop.prevent="startRenewal"
        >
            Renovar acesso{{ formattedAmount ? ` · ${formattedAmount}` : '' }}
        </Button>

        <div
            v-if="open"
            class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4"
            @click.self="close"
        >
            <div class="w-full max-w-sm rounded-xl bg-zinc-900 p-5 text-white shadow-xl">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold">Renovar {{ module.title }}</p>
                        <p v-if="formattedAmount" class="mt-0.5 text-xs text-zinc-400">{{ formattedAmount }} via PIX</p>
                    </div>
                    <button type="button" class="text-zinc-400 hover:text-white" @click="close">✕</button>
                </div>
                <p v-if="loading" class="py-6 text-center text-sm text-zinc-400">Gerando PIX…</p>
                <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>
                <div v-else class="space-y-3">
                    <img
                        v-if="qrcodeSrc"
                        :src="qrcodeSrc"
                        alt="QR Code PIX"
                        class="mx-auto h-48 w-48 rounded-lg bg-white p-2 object-contain"
                    />
                    <p class="text-center text-xs text-zinc-400">Escaneie o QR ou copie o código. O acesso libera automaticamente após o pagamento.</p>
                    <button
                        v-if="copyPaste"
                        type="button"
                        class="w-full truncate rounded-lg bg-zinc-800 px-3 py-2 text-left text-xs text-zinc-200"
                        @click="copyCode"
                    >
                        {{ copied ? 'Copiado!' : copyPaste }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
