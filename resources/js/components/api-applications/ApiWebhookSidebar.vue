<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { X } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    applicationId: { type: [Number, String], required: true },
    webhook: { type: Object, default: () => ({}) },
    eventCatalog: { type: Object, default: () => ({}) },
    webhookSecretMask: { type: String, default: '' },
});

const emit = defineEmits(['close', 'saved']);

const allEventKeys = computed(() => {
    const keys = [];
    for (const group of Object.values(props.eventCatalog || {})) {
        for (const event of Object.keys(group.events || {})) {
            keys.push(event);
        }
    }
    return keys;
});

const form = useForm({
    webhook_url: '',
    webhook_secret: '',
    webhook_events: [] ,
    webhook_enabled: true,
    clear_webhook: false,
});

const allEventsSelected = ref(true);

watch(
    () => [props.open, props.webhook],
    () => {
        if (!props.open) return;
        const w = props.webhook || {};
        form.webhook_url = w.url ?? '';
        form.webhook_secret = w.has_secret ? props.webhookSecretMask : '';
        form.webhook_enabled = w.is_active !== false;
        form.clear_webhook = false;
        const events = Array.isArray(w.events) ? [...w.events] : [];
        form.webhook_events = events;
        allEventsSelected.value = !events.length || events.length >= allEventKeys.value.length;
        form.clearErrors();
    },
    { immediate: true },
);

watch(allEventsSelected, (all) => {
    if (all) {
        form.webhook_events = [];
    } else if (form.webhook_events.length === 0) {
        form.webhook_events = [...allEventKeys.value];
    }
});

function toggleEvent(event, checked) {
    allEventsSelected.value = false;
    const set = new Set(form.webhook_events);
    if (checked) {
        set.add(event);
    } else {
        set.delete(event);
    }
    form.webhook_events = [...set];
    if (form.webhook_events.length >= allEventKeys.value.length) {
        allEventsSelected.value = true;
        form.webhook_events = [];
    }
}

function close() {
    emit('close');
}

function submit() {
    const payload = {
        webhook_url: form.webhook_url,
        webhook_enabled: form.webhook_enabled,
        webhook_events: allEventsSelected.value ? [] : form.webhook_events,
    };
    if (form.webhook_secret && form.webhook_secret !== props.webhookSecretMask) {
        payload.webhook_secret = form.webhook_secret;
    }

    form.transform(() => payload).patch(`/aplicacoes-api/${props.applicationId}/webhook`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            close();
        },
    });
}
</script>

<template>
    <Teleport to="body">
        <div v-show="open" class="fixed inset-0 z-[100000] flex justify-end" aria-modal="true" role="dialog">
            <div class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60" aria-hidden="true" @click="close" />
            <aside class="relative flex h-full w-full max-w-lg flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        {{ webhook?.configured ? 'Editar endpoint' : 'Adicionar endpoint' }}
                    </h2>
                    <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Fechar" @click="close">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <form class="flex flex-1 flex-col overflow-y-auto p-5" @submit.prevent="submit">
                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL do endpoint (HTTPS)</label>
                            <input
                                v-model="form.webhook_url"
                                type="url"
                                required
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-600 dark:bg-zinc-800"
                                placeholder="https://seu-servidor.com/webhooks/getfy"
                            />
                            <p v-if="form.errors.webhook_url" class="mt-1 text-sm text-red-600">{{ form.errors.webhook_url }}</p>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Endpoint ativo</p>
                                <p class="text-xs text-zinc-500">Pausar interrompe novas entregas sem remover a URL.</p>
                            </div>
                            <Toggle v-model="form.webhook_enabled" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Signing secret (opcional)</label>
                            <input
                                v-model="form.webhook_secret"
                                type="password"
                                autocomplete="off"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-600 dark:bg-zinc-800"
                                :placeholder="webhook?.has_secret ? 'Deixe em branco para manter' : 'Gerado automaticamente se vazio'"
                            />
                            <p class="mt-1 text-xs text-zinc-500">Usado no header X-Webhook-Signature (HMAC-SHA256).</p>
                        </div>

                        <div>
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Eventos</p>
                            <label class="mt-2 flex items-center gap-2 text-sm">
                                <input v-model="allEventsSelected" type="checkbox" class="rounded border-zinc-300" />
                                Todos os eventos
                            </label>

                            <div v-if="!allEventsSelected" class="mt-4 space-y-4">
                                <div v-for="(group, groupKey) in eventCatalog" :key="groupKey">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ group.label }}</p>
                                    <div class="mt-2 space-y-2">
                                        <label
                                            v-for="(label, event) in group.events"
                                            :key="event"
                                            class="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300"
                                        >
                                            <input
                                                type="checkbox"
                                                class="mt-0.5 rounded border-zinc-300"
                                                :checked="form.webhook_events.includes(event)"
                                                @change="toggleEvent(event, $event.target.checked)"
                                            />
                                            <span>
                                                <span class="font-mono text-xs">{{ event }}</span>
                                                <span class="block text-xs text-zinc-500">{{ label }}</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-auto flex gap-2 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                        <Button type="button" variant="outline" class="flex-1" @click="close">Cancelar</Button>
                        <Button type="submit" class="flex-1" :disabled="form.processing">Salvar endpoint</Button>
                    </div>
                </form>
            </aside>
        </div>
    </Teleport>
</template>
