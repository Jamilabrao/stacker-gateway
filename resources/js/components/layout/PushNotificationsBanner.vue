<script setup>
import { computed, ref } from 'vue';
import { Bell, X } from 'lucide-vue-next';
import { usePage } from '@inertiajs/vue3';
import { usePanelPushSubscribe } from '@/composables/usePanelPushSubscribe';

const page = usePage();
const pushEnabled = computed(() => !!page.props.push_enabled);

const {
    pushRegistered,
    pushNeedsResubscribe,
    swUpdateApplied,
    needsPermission,
    registerAndSubscribe,
    pushSubscribing,
    notificationPermission,
} = usePanelPushSubscribe();

const dismissed = ref(false);
const activating = ref(false);

const showSwUpdate = computed(() => swUpdateApplied.value && !dismissed.value);

const showResubscribe = computed(() => {
    if (!pushEnabled.value || dismissed.value) return false;
    if (notificationPermission.value !== 'granted') return false;
    return pushNeedsResubscribe.value || (!pushRegistered.value && !needsPermission.value);
});

const showNeedsPermission = computed(() => {
    if (!pushEnabled.value || dismissed.value) return false;
    return needsPermission.value && notificationPermission.value === 'default';
});

async function reactivate() {
    activating.value = true;
    try {
        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            const result = await Notification.requestPermission();
            if (result !== 'granted') return;
        }
        await registerAndSubscribe();
    } catch (_) {}
    activating.value = false;
}

function dismiss() {
    dismissed.value = true;
    swUpdateApplied.value = false;
}
</script>

<template>
    <div v-if="showSwUpdate" class="mb-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">
        <div class="flex items-start justify-between gap-3">
            <p>Atualização do app aplicada — notificações revalidadas.</p>
            <button type="button" class="shrink-0 rounded p-1 hover:bg-sky-100 dark:hover:bg-sky-900" aria-label="Fechar" @click="dismiss">
                <X class="h-4 w-4" />
            </button>
        </div>
    </div>

    <div
        v-else-if="showNeedsPermission || showResubscribe"
        class="mb-3 flex flex-col gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="flex items-start gap-2">
            <Bell class="mt-0.5 h-4 w-4 shrink-0" />
            <p>
                <template v-if="showNeedsPermission">
                    Ative as notificações para receber avisos de vendas e mensagens da plataforma.
                </template>
                <template v-else>
                    Suas notificações push estão pausadas. Reative para voltar a receber vendas e avisos.
                </template>
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button
                type="button"
                class="rounded-lg bg-[var(--color-primary)] px-3 py-1.5 text-xs font-medium text-white hover:opacity-90 disabled:opacity-60"
                :disabled="activating || pushSubscribing"
                @click="reactivate"
            >
                {{ activating || pushSubscribing ? 'Aguarde...' : 'Reativar notificações' }}
            </button>
            <button type="button" class="rounded-lg px-2 py-1.5 text-xs text-amber-800 hover:underline dark:text-amber-200" @click="dismiss">
                Agora não
            </button>
        </div>
    </div>
</template>
