<script setup>
import { ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import CampaignBodyEditor from '@/components/email-marketing/CampaignBodyEditor.vue';
import { defaultFilterConfig, normalizeFilterConfigFromCampaign, recipientTypeLabel } from '@/lib/emailCampaignFilters';
import axios from 'axios';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    campaign: {
        type: Object,
        required: true,
    },
    email_configured: { type: Boolean, default: false },
    products: { type: Array, default: () => [] },
    default_message_text: { type: String, default: '' },
});

const form = useForm({
    name: props.campaign.name,
    subject: props.campaign.subject,
    body_message: props.campaign.body_message || props.default_message_text || '',
    filter_config: normalizeFilterConfigFromCampaign(props.campaign.filter_config) ?? defaultFilterConfig(),
});

const recipientCount = ref(null);
const recipientBreakdown = ref(null);
const recipientSample = ref([]);
const loadingRecipients = ref(false);

watch(
    () => form.filter_config.include_customers,
    (on) => {
        if (!on && !form.filter_config.include_infoprodutors) {
            form.filter_config.include_infoprodutors = true;
        }
    }
);

watch(
    () => form.filter_config.include_infoprodutors,
    (on) => {
        if (!on && !form.filter_config.include_customers) {
            form.filter_config.include_customers = true;
        }
    }
);

async function previewRecipients() {
    loadingRecipients.value = true;
    recipientCount.value = null;
    recipientBreakdown.value = null;
    recipientSample.value = [];
    try {
        const res = await axios.post(
            `/plataforma/email-marketing/${props.campaign.id}/preview-recipients`,
            { filter_config: form.filter_config },
            { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } }
        );
        if (res?.data) {
            recipientCount.value = res.data.count;
            recipientBreakdown.value = res.data.breakdown ?? null;
            recipientSample.value = res.data.sample || [];
        }
    } catch (_) {
        recipientCount.value = 0;
    } finally {
        loadingRecipients.value = false;
    }
}

function confirmSend() {
    if (!props.email_configured) return;
    if (!confirm('Disparar esta campanha? Os e-mails serão enviados em lotes de 30 por minuto.')) return;
    router.post(`/plataforma/email-marketing/${props.campaign.id}/send`);
}
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Editar campanha</h1>
            <Link href="/plataforma/email-marketing" class="text-sm text-zinc-600 hover:underline dark:text-zinc-400">Voltar</Link>
        </div>

        <div
            v-if="!email_configured"
            class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30"
        >
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
                Configure o e-mail em <Link href="/plataforma/configuracoes" class="underline">Configurações &gt; E-mail</Link> antes
                de disparar.
            </p>
        </div>

        <form
            class="space-y-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800/50"
            @submit.prevent="form.put(`/plataforma/email-marketing/${campaign.id}`)"
        >
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome da campanha</label>
                <input
                    v-model="form.name"
                    type="text"
                    required
                    class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Assunto do e-mail</label>
                <input
                    v-model="form.subject"
                    type="text"
                    required
                    class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2"
                />
                <p v-if="form.errors.subject" class="mt-1 text-sm text-red-600">{{ form.errors.subject }}</p>
            </div>

            <CampaignBodyEditor v-model="form.body_message" :error="form.errors.body_message" />

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Público</label>
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Marque quem deve receber esta campanha (pode combinar).</p>
                <div class="mt-3 space-y-4 rounded-lg border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-900/40">
                    <label class="flex items-start gap-3">
                        <input
                            v-model="form.filter_config.include_customers"
                            type="checkbox"
                            class="mt-1 h-4 w-4 rounded border-zinc-300"
                        />
                        <span>
                            <span class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">Compradores</span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">Quem já concluiu pelo menos uma compra na plataforma</span>
                        </span>
                    </label>

                    <div
                        v-if="form.filter_config.include_customers"
                        class="ml-7 space-y-2 border-l-2 border-zinc-200 pl-4 dark:border-zinc-600"
                    >
                        <label class="flex items-center gap-2">
                            <input v-model="form.filter_config.all_customers" type="radio" :value="true" class="h-4 w-4" />
                            <span class="text-sm">Todos os compradores</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input v-model="form.filter_config.all_customers" type="radio" :value="false" class="h-4 w-4" />
                            <span class="text-sm">Compradores de produto(s) específico(s)</span>
                        </label>
                        <select
                            v-if="form.filter_config.all_customers === false"
                            v-model="form.filter_config.product_ids"
                            multiple
                            class="block w-full max-w-md rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm"
                        >
                            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>

                    <label class="flex items-start gap-3">
                        <input
                            v-model="form.filter_config.include_infoprodutors"
                            type="checkbox"
                            class="mt-1 h-4 w-4 rounded border-zinc-300"
                        />
                        <span>
                            <span class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">Infoprodutores</span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">Vendedores cadastrados na plataforma (contas ativas)</span>
                        </span>
                    </label>
                </div>
                <p v-if="form.errors.filter_config" class="mt-1 text-sm text-red-600">{{ form.errors.filter_config }}</p>

                <div class="mt-3">
                    <Button type="button" variant="secondary" size="sm" :disabled="loadingRecipients" @click="previewRecipients">
                        {{ loadingRecipients ? 'Carregando...' : 'Ver destinatários' }}
                    </Button>
                    <span v-if="recipientCount !== null" class="ml-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ recipientCount }} destinatário(s)
                        <template v-if="recipientBreakdown">
                            — {{ recipientBreakdown.customers }} comprador(es), {{ recipientBreakdown.infoprodutors }} infoprodutor(es)
                        </template>
                    </span>
                    <ul v-if="recipientSample.length" class="mt-2 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        <li v-for="(r, i) in recipientSample" :key="i">
                            {{ r.email }} ({{ r.name }}) · {{ recipientTypeLabel(r.type) }}
                        </li>
                    </ul>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <Button type="submit" variant="primary" :disabled="form.processing">Salvar</Button>
                <Button
                    v-if="email_configured"
                    type="button"
                    variant="primary"
                    :disabled="form.processing"
                    @click="confirmSend"
                >
                    Disparar campanha
                </Button>
                <Link href="/plataforma/email-marketing">
                    <Button type="button" variant="outline">Cancelar</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
