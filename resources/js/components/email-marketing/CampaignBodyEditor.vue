<script setup>
import { computed } from 'vue';
import { wrapCampaignBodyHtml } from '@/lib/emailCampaignBody';

const model = defineModel({ type: String, required: true });

const props = defineProps({
    error: { type: String, default: '' },
});

const previewHtml = computed(() => wrapCampaignBodyHtml(model.value));
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Mensagem do e-mail</label>
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                    Digite apenas o texto. O layout (cabeçalho, cores e rodapé) é aplicado automaticamente.
                    Use <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700">{nome}</code> e
                    <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700">{email}</code> no texto, se quiser.
                </p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                <textarea
                    v-model="model"
                    rows="12"
                    required
                    placeholder="Escreva a mensagem que seus destinatários vão ler..."
                    class="block w-full resize-y rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm leading-relaxed text-zinc-900 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                />
                <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/50">
                <p class="border-b border-zinc-200 px-3 py-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    Prévia do e-mail
                </p>
                <div class="max-h-[420px] overflow-auto p-2">
                    <iframe
                        :srcdoc="previewHtml"
                        title="Prévia do e-mail"
                        class="h-[380px] w-full rounded-lg border-0 bg-white"
                        sandbox=""
                    />
                </div>
            </div>
        </div>
    </div>
</template>
