<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import QrcodeVue from 'qrcode.vue';
import confetti from 'canvas-confetti';
import { ArrowLeft, Check, Copy, Zap } from 'lucide-vue-next';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import { copyTextToClipboard } from '@/lib/copyText';
import { usePixGoTheme } from '@/composables/usePixGoTheme';

defineOptions({ layout: LayoutInfoprodutor });

const { confettiColors } = usePixGoTheme();

const POLL_INTERVAL_MS = 2500;

const props = defineProps({
    token: { type: String, required: true },
    order_id: { type: Number, required: true },
    qrcode: { type: String, default: null },
    copy_paste: { type: String, default: '' },
    amount_formatted: { type: String, default: 'R$ 0,00' },
    amount: { type: Number, default: 0 },
    status: { type: String, default: 'pending' },
    created_at: { type: Number, required: true },
    expiry_seconds: { type: Number, default: 86400 },
    sidebar_label: { type: String, default: 'PixGO' },
});

const status = ref(props.status === 'completed' ? 'completed' : 'pending');
const copyButtonText = ref('Copiar');
const feedback = ref('');
let pollInterval = null;
let timerInterval = null;

const endTime = computed(() => (props.created_at + props.expiry_seconds) * 1000);
const timeLeft = ref(props.expiry_seconds);

const qrcodeSrc = computed(() => {
    const q = (props.qrcode || '').trim();
    if (!q) return '';
    if (q.startsWith('data:')) return q;
    if (/^https?:\/\//i.test(q)) return q;
    if (q.startsWith('//')) return `https:${q}`;
    return `data:image/png;base64,${q}`;
});

const showQrFromCopyPaste = computed(
    () => !qrcodeSrc.value && (props.copy_paste || '').trim().length > 0
);

const timerDisplay = computed(() => {
    const left = timeLeft.value;
    const h = Math.floor(left / 3600);
    const m = Math.floor((left % 3600) / 60);
    const s = left % 60;
    if (h > 0) {
        return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
});

function updateTimer() {
    const left = Math.max(0, Math.floor((endTime.value - Date.now()) / 1000));
    timeLeft.value = left;
    if (left <= 0 && pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

async function checkStatus() {
    try {
        const { data } = await axios.get('/pixgo/status', { params: { token: props.token } });
        if (data.status === 'completed') {
            status.value = 'completed';
            feedback.value = 'Pagamento confirmado!';
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
            confetti({ particleCount: 80, spread: 60, origin: { y: 0.65 }, colors: confettiColors.value });
        } else if (data.expired) {
            status.value = 'expired';
            feedback.value = 'Tempo esgotado. Gere uma nova cobrança.';
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }
        return data;
    } catch {
        return { status: 'pending' };
    }
}

async function copyPixCode() {
    const code = (props.copy_paste || '').trim();
    if (!code) return;
    const ok = await copyTextToClipboard(code);
    if (ok) {
        copyButtonText.value = 'Copiado!';
        setTimeout(() => (copyButtonText.value = 'Copiar'), 2000);
    } else {
        copyButtonText.value = 'Selecione e copie';
        selectPixCode();
        setTimeout(() => (copyButtonText.value = 'Copiar'), 2500);
    }
}

const pixCodeEl = ref(null);

function selectPixCode() {
    const el = pixCodeEl.value;
    if (!el) return;
    const range = document.createRange();
    range.selectNodeContents(el);
    const sel = window.getSelection();
    if (!sel) return;
    sel.removeAllRanges();
    sel.addRange(range);
}

function newSale() {
    router.visit('/pixgo');
}

function goBack() {
    router.visit('/pixgo');
}

onMounted(() => {
    if (status.value === 'completed') return;
    updateTimer();
    timerInterval = setInterval(updateTimer, 1000);
    pollInterval = setInterval(() => {
        if (status.value !== 'pending') return;
        checkStatus();
    }, POLL_INTERVAL_MS);
});

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval);
    if (pollInterval) clearInterval(pollInterval);
});
</script>

<template>
    <Head :title="`${sidebar_label} — Cobrança`" />

    <div class="flex min-h-[calc(100dvh-0px)] flex-col text-zinc-100 lg:min-h-[calc(100dvh-1rem)]">
        <header class="border-b border-zinc-800/80 px-4 py-4">
            <div class="mx-auto flex max-w-md items-center gap-3">
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-900 hover:text-white"
                    aria-label="Voltar"
                    @click="goBack"
                >
                    <ArrowLeft class="h-5 w-5" />
                </button>
                <div class="flex items-center gap-2">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-[var(--color-primary)] text-white">
                        <Zap class="h-4 w-4" />
                    </span>
                    <div>
                        <p class="text-base font-bold text-white">{{ sidebar_label }}</p>
                        <p class="text-xs text-zinc-500">Aguardando pagamento</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto flex w-full max-w-md flex-1 flex-col items-center px-4 py-8">
            <div v-if="status === 'completed'" class="flex w-full flex-col items-center py-12 text-center">
                <span class="flex h-20 w-20 items-center justify-center rounded-full bg-[var(--color-primary)]/20 text-[var(--color-primary)]">
                    <Check class="h-10 w-10" />
                </span>
                <h1 class="mt-6 text-2xl font-bold text-white">Pagamento recebido!</h1>
                <p class="mt-2 text-lg text-[var(--color-primary)]">{{ amount_formatted }}</p>
                <button
                    type="button"
                    class="mt-10 w-full rounded-xl bg-[var(--color-primary)] py-4 text-lg font-bold text-white transition hover:opacity-90"
                    @click="newSale"
                >
                    Nova venda
                </button>
            </div>

            <div v-else-if="status === 'expired'" class="flex w-full flex-col items-center py-12 text-center">
                <p class="text-lg text-zinc-400">Cobrança expirada</p>
                <button
                    type="button"
                    class="mt-8 w-full rounded-xl bg-[var(--color-primary)] py-4 font-bold text-white transition hover:opacity-90"
                    @click="newSale"
                >
                    Nova venda
                </button>
            </div>

            <template v-else>
                <div class="w-full rounded-2xl border border-[var(--color-primary)]/30 bg-zinc-950 p-6 text-center">
                    <p class="text-xs font-semibold uppercase tracking-widest text-[var(--color-primary)]/80">Valor</p>
                    <p class="mt-2 text-3xl font-bold text-[var(--color-primary)]">{{ amount_formatted }}</p>
                    <p class="mt-2 text-xs text-zinc-500">Expira em {{ timerDisplay }}</p>
                </div>

                <div class="mt-8 flex flex-col items-center">
                    <div v-if="qrcodeSrc" class="rounded-2xl bg-white p-4 shadow-lg">
                        <img :src="qrcodeSrc" alt="QR Code PIX" class="h-52 w-52 object-contain" />
                    </div>
                    <div v-else-if="showQrFromCopyPaste" class="rounded-2xl bg-white p-4 shadow-lg">
                        <QrcodeVue :value="copy_paste" :size="208" level="M" />
                    </div>
                    <p v-else class="text-sm text-zinc-500">QR Code indisponível — use o código abaixo.</p>
                </div>

                <div v-if="copy_paste" class="mt-6 w-full">
                    <p class="mb-2 text-center text-xs text-zinc-500">Pix copia e cola</p>
                    <div
                        class="cursor-pointer rounded-xl border border-zinc-800 bg-zinc-950 p-3"
                        role="button"
                        tabindex="0"
                        @click="selectPixCode"
                        @keydown.enter.prevent="copyPixCode"
                    >
                        <p ref="pixCodeEl" class="break-all text-center text-xs leading-relaxed text-zinc-300 select-all">{{ copy_paste }}</p>
                    </div>
                    <button
                        type="button"
                        class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-[var(--color-primary)]/40 py-3 text-sm font-medium text-[var(--color-primary)] transition hover:bg-[var(--color-primary)]/10"
                        @click="copyPixCode"
                    >
                        <Copy class="h-4 w-4" />
                        {{ copyButtonText }}
                    </button>
                </div>

                <p class="mt-8 animate-pulse text-center text-sm text-zinc-500">
                    Aguardando confirmação do pagamento…
                </p>
                <p v-if="feedback" class="mt-2 text-center text-sm text-[var(--color-primary)]">{{ feedback }}</p>
            </template>
        </main>
    </div>
</template>
