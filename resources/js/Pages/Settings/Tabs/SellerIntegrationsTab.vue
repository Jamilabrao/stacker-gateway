<script setup>
const props = defineProps({
    form: { type: Object, required: true },
    catalog: { type: Array, default: () => [] },
});

function fieldKey(id) {
    return `integration_${id}_enabled`;
}

function isEnabled(id) {
    return Boolean(props.form[fieldKey(id)]);
}

function toggle(id, checked) {
    props.form[fieldKey(id)] = Boolean(checked);
}
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Integrações do infoprodutor</h2>
        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Controle quais apps aparecem na aba Integrações do painel do infoprodutor. Desative para testes ou para esconder uma integração nova.
            Você ainda pode liberar individualmente em Plataforma → Usuários → Editar, mesmo com o padrão global desligado.
        </p>
        <div class="mt-5 space-y-3">
            <label
                v-for="item in catalog"
                :key="item.id"
                class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80"
            >
                <input
                    type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                    :checked="isEnabled(item.id)"
                    @change="toggle(item.id, $event.target.checked)"
                />
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ item.label }}</span>
                        <span
                            class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium"
                            :class="
                                isEnabled(item.id)
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                                    : 'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300'
                            "
                        >
                            {{ isEnabled(item.id) ? 'Visível' : 'Oculta' }}
                        </span>
                    </span>
                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ item.description }}</span>
                </span>
            </label>
        </div>
        <ul class="mt-4 space-y-1.5 text-xs text-zinc-500 dark:text-zinc-400">
            <li>• Desligada: o infoprodutor não vê o card e não consegue configurar nem usar a integração.</li>
            <li>• Override por produtor: em Editar infoprodutor, escolha Herdar, Habilitada ou Desabilitada.</li>
        </ul>
    </section>
</template>
