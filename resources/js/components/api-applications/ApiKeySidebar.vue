<script setup>
import { ref, watch, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { X } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    applicationId: { type: [Number, String], required: true },
    availableScopes: { type: Array, default: () => [] },
    scopeCatalog: { type: Array, default: () => [] },
    editingKey: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const isLegacy = computed(() => props.editingKey?.type === 'legacy');

const scopeOptions = computed(() => {
    if (props.scopeCatalog?.length) {
        return props.scopeCatalog;
    }
    return (props.availableScopes || []).map((id) => ({
        id,
        label: id,
        description: '',
    }));
});

const form = useForm({
    name: '',
    scopes: ['payments:read', 'payments:write'],
    is_active: true,
});

watch(
    () => [props.open, props.editingKey],
    () => {
        if (!props.open) return;
        if (props.editingKey) {
            form.name = props.editingKey.name ?? '';
            form.scopes = Array.isArray(props.editingKey.scopes) && props.editingKey.scopes[0] !== '*'
                ? [...props.editingKey.scopes]
                : ['payments:read', 'payments:write'];
            form.is_active = props.editingKey.is_active !== false;
        } else {
            form.reset();
            form.scopes = ['payments:read', 'payments:write'];
            form.is_active = true;
        }
        form.clearErrors();
    },
    { immediate: true },
);

function close() {
    emit('close');
}

function submit() {
    if (isLegacy.value) {
        router.put(`/aplicacoes-api/${props.applicationId}`, {
            name: form.name,
            is_active: form.is_active,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                emit('saved');
                close();
            },
        });
        return;
    }

    if (props.editingKey?.id) {
        form.put(`/aplicacoes-api/${props.applicationId}/keys/${props.editingKey.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                emit('saved');
                close();
            },
        });
        return;
    }

    form.post(`/aplicacoes-api/${props.applicationId}/keys`, {
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
                        {{ editingKey ? 'Editar chave' : 'Nova chave API' }}
                    </h2>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        aria-label="Fechar"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <form class="flex flex-1 flex-col overflow-y-auto p-5" @submit.prevent="submit">
                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome</label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                maxlength="120"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                placeholder="Ex.: produção, staging"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>

                        <div v-if="isLegacy" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100">
                            Chave principal com acesso total (legado). Escopos não se aplicam.
                        </div>

                        <div v-if="!isLegacy">
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Permissões</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Escolha o que esta chave pode fazer na API. Use o mínimo necessário.
                            </p>
                            <div class="mt-3 space-y-2">
                                <label
                                    v-for="scope in scopeOptions"
                                    :key="scope.id"
                                    class="flex cursor-pointer gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-3 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-800/50 dark:hover:border-zinc-600"
                                    :class="form.scopes.includes(scope.id) ? 'border-[var(--color-primary)]/40 bg-[var(--color-primary)]/5 dark:border-[var(--color-primary)]/30' : ''"
                                >
                                    <input
                                        v-model="form.scopes"
                                        type="checkbox"
                                        :value="scope.id"
                                        class="mt-1 rounded border-zinc-300"
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ scope.label }}
                                        </span>
                                        <span v-if="scope.description" class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ scope.description }}
                                        </span>
                                        <span class="mt-1 block font-mono text-[10px] text-zinc-400 dark:text-zinc-500">
                                            {{ scope.id }}
                                        </span>
                                    </span>
                                </label>
                            </div>
                            <p v-if="form.errors.scopes" class="mt-2 text-sm text-red-600">{{ form.errors.scopes }}</p>
                        </div>

                        <div v-if="editingKey" class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">Chaves inativas não autenticam requisições.</p>
                            </div>
                            <Toggle v-model="form.is_active" />
                        </div>
                    </div>

                    <div class="mt-auto flex gap-2 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                        <Button type="button" variant="outline" class="flex-1" @click="close">Cancelar</Button>
                        <Button type="submit" class="flex-1" :disabled="form.processing">
                            {{ editingKey ? 'Salvar' : 'Criar chave' }}
                        </Button>
                    </div>
                </form>
            </aside>
        </div>
    </Teleport>
</template>
