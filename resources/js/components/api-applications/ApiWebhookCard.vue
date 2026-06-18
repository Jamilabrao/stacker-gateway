<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import {
    Copy, Check, ChevronDown, ChevronUp, Pause, Play, Pencil, RefreshCw, Trash2, Loader2,
} from 'lucide-vue-next';

const props = defineProps({
    applicationId: { type: [Number, String], required: true },
    webhook: { type: Object, required: true },
    eventCatalog: { type: Object, default: () => ({}) },
    recentDeliveries: { type: Array, default: () => [] },
});

const emit = defineEmits(['edit', 'rotated-secret']);

const copyDone = ref(false);
const rotating = ref(false);
const expanded = ref(false);
const loadingMore = ref(false);
const deliveries = ref([...props.recentDeliveries]);
const meta = ref({ page: 1, last_page: 1 });

const eventLabel = computed(() => {
    const events = props.webhook.events;
    if (!events || !events.length) return 'Todos os eventos';
    if (events.length <= 2) return events.join(', ');
    return `${events.length} eventos selecionados`;
});

const maskedSecret = computed(() => {
    if (!props.webhook.has_secret) return '—';
    return 'whsec_********************';
});

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function copyUrl() {
    if (!props.webhook.url) return;
    try {
        await navigator.clipboard.writeText(props.webhook.url);
        copyDone.value = true;
        setTimeout(() => { copyDone.value = false; }, 2000);
    } catch {
        copyDone.value = false;
    }
}

function togglePause() {
    router.patch(`/aplicacoes-api/${props.applicationId}/webhook`, {
        webhook_enabled: !props.webhook.is_active,
    }, { preserveScroll: true });
}

function removeWebhook() {
    if (!confirm('Remover este endpoint? As entregas deixarão de ser enviadas.')) return;
    router.patch(`/aplicacoes-api/${props.applicationId}/webhook`, {
        clear_webhook: true,
    }, { preserveScroll: true });
}

async function rotateSecret() {
    if (!confirm('Rotacionar o signing secret? Atualize seu servidor imediatamente.')) return;
    rotating.value = true;
    try {
        const { data } = await axios.post(
            `/aplicacoes-api/${props.applicationId}/webhook/rotate-secret`,
            {},
            { headers: { 'X-CSRF-TOKEN': getCsrfToken() } },
        );
        emit('rotated-secret', data.webhook_secret);
    } finally {
        rotating.value = false;
    }
}

async function toggleDeliveries() {
    expanded.value = !expanded.value;
    if (expanded.value && deliveries.value.length === 0) {
        await fetchDeliveries(1);
    }
}

async function fetchDeliveries(page = 1) {
    loadingMore.value = true;
    try {
        const { data } = await axios.get(`/aplicacoes-api/${props.applicationId}/webhook/deliveries`, {
            params: { page, per_page: 15 },
        });
        if (page === 1) {
            deliveries.value = data.deliveries || [];
        } else {
            deliveries.value = [...deliveries.value, ...(data.deliveries || [])];
        }
        meta.value = data.meta || meta.value;
    } finally {
        loadingMore.value = false;
    }
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

function statusClass(status) {
    if (status === 'delivered') return 'text-emerald-600 dark:text-emerald-400';
    if (status === 'failed') return 'text-red-600 dark:text-red-400';
    return 'text-amber-600 dark:text-amber-400';
}
</script>

<template>
    <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 dark:border-zinc-700 dark:bg-zinc-900/50">
        <div class="flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1 space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="webhook.is_active
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
                            : 'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300'"
                    >
                        {{ webhook.is_active ? 'Ativo' : 'Pausado' }}
                    </span>
                </div>

                <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-950">
                    <code class="min-w-0 flex-1 truncate text-sm font-mono text-zinc-800 dark:text-zinc-200">{{ webhook.url }}</code>
                    <button type="button" class="shrink-0 rounded p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="copyUrl">
                        <Check v-if="copyDone" class="h-4 w-4 text-emerald-600" />
                        <Copy v-else class="h-4 w-4" />
                    </button>
                </div>

                <span class="inline-flex rounded-full bg-zinc-200/80 px-2.5 py-1 text-xs text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300">
                    {{ eventLabel }}
                </span>

                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Signing secret: <span class="font-mono">{{ maskedSecret }}</span>
                    · ID: <span class="font-mono">{{ applicationId }}</span>
                </p>
            </div>

            <div class="flex shrink-0 flex-row flex-wrap gap-2 lg:flex-col">
                <Button type="button" variant="outline" size="sm" class="inline-flex items-center gap-2" @click="togglePause">
                    <Pause v-if="webhook.is_active" class="h-4 w-4" />
                    <Play v-else class="h-4 w-4" />
                    {{ webhook.is_active ? 'Pausar' : 'Ativar' }}
                </Button>
                <Button type="button" variant="outline" size="sm" class="inline-flex items-center gap-2" @click="emit('edit')">
                    <Pencil class="h-4 w-4" />
                    Editar
                </Button>
                <Button type="button" variant="outline" size="sm" class="inline-flex items-center gap-2" :disabled="rotating" @click="rotateSecret">
                    <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': rotating }" />
                    Rotacionar secret
                </Button>
                <Button type="button" variant="outline" size="sm" class="inline-flex items-center gap-2 text-red-600 hover:text-red-700 dark:text-red-400" @click="removeWebhook">
                    <Trash2 class="h-4 w-4" />
                    Remover
                </Button>
            </div>
        </div>

        <button
            type="button"
            class="flex w-full items-center justify-between border-t border-zinc-200 px-5 py-3 text-sm font-medium text-zinc-600 hover:bg-zinc-100/80 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800/50"
            @click="toggleDeliveries"
        >
            Últimas entregas
            <ChevronUp v-if="expanded" class="h-4 w-4" />
            <ChevronDown v-else class="h-4 w-4" />
        </button>

        <div v-if="expanded" class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
            <div v-if="loadingMore && !deliveries.length" class="flex justify-center py-6">
                <Loader2 class="h-5 w-5 animate-spin text-zinc-400" />
            </div>
            <ul v-else-if="deliveries.length" class="divide-y divide-zinc-200 dark:divide-zinc-700">
                <li v-for="d in deliveries" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                    <span class="font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ d.event }}</span>
                    <span class="text-xs text-zinc-500">{{ formatDate(d.created_at) }}</span>
                    <span class="text-xs font-medium" :class="statusClass(d.status)">
                        {{ d.status }}
                        <template v-if="d.last_status_code"> · HTTP {{ d.last_status_code }}</template>
                    </span>
                </li>
            </ul>
            <p v-else class="py-4 text-center text-sm text-zinc-500">Nenhuma entrega registrada ainda.</p>
            <Button
                v-if="meta.page < meta.last_page"
                type="button"
                variant="outline"
                size="sm"
                class="mt-3 w-full"
                :disabled="loadingMore"
                @click="fetchDeliveries(meta.page + 1)"
            >
                Carregar mais
            </Button>
        </div>
    </div>
</template>
