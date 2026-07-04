<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { MessageSquare, Send, ExternalLink, Plus, Trash2 } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    settings: { type: Object, required: true },
    recent_dispatches: { type: Array, default: () => [] },
});

const page = usePage();
const testPhone = ref('');
const testMessage = ref('');
const testing = ref(false);
const testResult = ref(null);

const defaultRecoverySteps = () => [
    { delay_value: 10, delay_unit: 'minutes', message: 'Oi {nome}! Seu carrinho de {produto} te espera: {link}' },
    { delay_value: 24, delay_unit: 'hours', message: 'Oi {nome}! Seu carrinho de {produto} te espera: {link}' },
    { delay_value: 2, delay_unit: 'days', message: 'Oi {nome}! Seu carrinho de {produto} te espera: {link}' },
];

function buildFormState(settings) {
    return {
        is_active: settings.is_active ?? false,
        sms_checkout_only: settings.sms_checkout_only ?? true,
        api_token: '',
        sender_from: settings.sender_from || '29094',
        event_cart_recovery_enabled: settings.event_cart_recovery_enabled ?? false,
        event_order_paid_enabled: settings.event_order_paid_enabled ?? false,
        event_access_granted_enabled: settings.event_access_granted_enabled ?? false,
        event_pix_generated_enabled: settings.event_pix_generated_enabled ?? false,
        message_order_paid: settings.message_order_paid ?? '',
        message_access_granted: settings.message_access_granted ?? '',
        message_pix_generated: settings.message_pix_generated ?? '',
        cart_recovery_steps: (settings.cart_recovery_steps?.length
            ? JSON.parse(JSON.stringify(settings.cart_recovery_steps))
            : defaultRecoverySteps()),
    };
}

const form = useForm(buildFormState(props.settings));

watch(
    () => props.settings,
    (settings) => {
        form.defaults(buildFormState(settings));
        form.reset();
    },
    { deep: true },
);

const validationSummary = computed(() => {
    const errors = form.errors ?? {};
    const messages = Object.values(errors).flat().filter(Boolean);
    return [...new Set(messages)];
});

function stepDelayError(index) {
    return form.errors[`cart_recovery_steps.${index}.delay_value`]
        ?? form.errors[`cart_recovery_steps.${index}.delay_unit`]
        ?? form.errors[`cart_recovery_steps.${index}.message`]
        ?? null;
}

const delayUnits = [
    { value: 'minutes', label: 'Minutos' },
    { value: 'hours', label: 'Horas' },
    { value: 'days', label: 'Dias' },
];

const eventFields = [
    {
        key: 'event_order_paid_enabled',
        messageKey: 'message_order_paid',
        label: 'Compra aprovada',
        hint: 'Disparado quando o pedido é pago.',
    },
    {
        key: 'event_access_granted_enabled',
        messageKey: 'message_access_granted',
        label: 'Envio de acesso',
        hint: 'Disparado após pagamento em produtos com entrega de acesso.',
    },
    {
        key: 'event_pix_generated_enabled',
        messageKey: 'message_pix_generated',
        label: 'PIX gerado',
        hint: 'Disparado quando o QR Code PIX é criado.',
    },
];

const placeholders = ['{nome}', '{produto}', '{valor}', '{link}', '{link_acesso}'];

function charCount(field) {
    return (form[field] ?? '').length;
}

function stepCharCount(index) {
    return (form.cart_recovery_steps[index]?.message ?? '').length;
}

function addRecoveryStep() {
    if (form.cart_recovery_steps.length >= 10) {
        return;
    }

    form.cart_recovery_steps.push({
        delay_value: 24,
        delay_unit: 'hours',
        message: 'Oi {nome}! Seu carrinho de {produto} te espera: {link}',
    });
}

function removeRecoveryStep(index) {
    if (form.cart_recovery_steps.length <= 1) {
        return;
    }

    form.cart_recovery_steps.splice(index, 1);
}

function onDelayValueInput(step, event) {
    const raw = String(event.target?.value ?? '').replace(/\D/g, '');
    const parsed = raw === '' ? 1 : Math.max(1, parseInt(raw, 10));
    step.delay_value = parsed;
    if (event.target && event.target.value !== String(parsed)) {
        event.target.value = String(parsed);
    }
}

function delayUnitLabel(unit) {
    return delayUnits.find((item) => item.value === unit)?.label ?? unit;
}

const registerUrl = computed(() => props.settings.register_url || 'https://www.integrax.app/auth/register');
const supportWhatsapp = computed(() => props.settings.support_whatsapp || '+55 11 32808396');
const whatsappLink = computed(() => {
    const digits = String(supportWhatsapp.value).replace(/\D/g, '');

    return `https://wa.me/${digits}`;
});

function submit() {
    form.put('/plataforma/integrax', {
        preserveScroll: true,
        onSuccess: () => {
            form.api_token = '';
        },
    });
}

async function sendTest() {
    testResult.value = null;
    testing.value = true;
    try {
        const { data } = await axios.post('/plataforma/integrax/test', {
            phone: testPhone.value,
            message: testMessage.value || undefined,
        });
        testResult.value = { ok: data.success, message: data.message || 'SMS de teste enviado.' };
    } catch (e) {
        testResult.value = {
            ok: false,
            message: e.response?.data?.message || 'Falha ao enviar SMS de teste.',
        };
    } finally {
        testing.value = false;
    }
}

function eventLabel(type, step = null) {
    const map = {
        cart_recovery: 'Recuperação de carrinho',
        order_paid: 'Compra aprovada',
        access_granted: 'Envio de acesso',
        pix_generated: 'PIX gerado',
    };

    const label = map[type] || type;
    if (type === 'cart_recovery' && step !== null && step !== undefined) {
        return `${label} #${Number(step) + 1}`;
    }

    return label;
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6 pb-10">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">IntegraX SMS</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Dispare SMS transacionais e de recuperação de carrinho para todos os vendedores da plataforma.
            </p>
        </div>

        <div
            v-if="page.props.flash?.error"
            class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ page.props.flash.error }}
        </div>

        <div
            v-if="validationSummary.length"
            class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200"
        >
            <p class="font-medium">Não foi possível salvar:</p>
            <ul class="mt-1 list-inside list-disc space-y-0.5">
                <li v-for="(msg, i) in validationSummary" :key="i">{{ msg }}</li>
            </ul>
        </div>

        <div
            v-if="page.props.flash?.success"
            class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Status</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Desative para pausar todos os envios SMS.</p>
                    </div>
                    <Toggle v-model="form.is_active" />
                </div>

                <div class="mt-5 flex items-center justify-between gap-4 border-t border-zinc-100 pt-5 dark:border-zinc-700">
                    <div>
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">Somente checkout da plataforma</p>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            Quando ativo, SMS não são enviados para pedidos criados via API PIX ou checkout de parceiro.
                            Vendedores que transacionam só pela API ficam de fora; quem usa o checkout Getfy continua recebendo.
                        </p>
                    </div>
                    <Toggle v-model="form.sms_checkout_only" />
                </div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Credenciais</h2>

                <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                    <p class="font-medium">Primeiro acesso</p>
                    <p class="mt-1">
                        Cadastre-se em
                        <a :href="registerUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-medium underline">
                            IntegraX
                            <ExternalLink class="h-3.5 w-3.5" />
                        </a>
                        e, após criar o token, entre em contato com o suporte pelo WhatsApp
                        <a :href="whatsappLink" target="_blank" rel="noopener" class="font-medium underline">{{ supportWhatsapp }}</a>
                        para ativar o token.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Token da API</label>
                        <input
                            v-model="form.api_token"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="settings.configured ? '•••••••• (deixe em branco para manter)' : 'Cole o token IntegraX'"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                        />
                        <p v-if="form.errors.api_token" class="mt-1 text-xs text-red-500">{{ form.errors.api_token }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Remetente (from)</label>
                        <input
                            v-model="form.sender_from"
                            type="text"
                            maxlength="32"
                            placeholder="29094"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                        />
                        <p v-if="form.errors.sender_from" class="mt-1 text-xs text-red-500">{{ form.errors.sender_from }}</p>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-4 flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Recuperação de carrinho</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            Crie várias mensagens, cada uma com o tempo de envio após o abandono do checkout.
                        </p>
                    </div>
                    <Toggle v-model="form.event_cart_recovery_enabled" />
                </div>

                <div class="space-y-4">
                    <div
                        v-for="(step, index) in form.cart_recovery_steps"
                        :key="index"
                        class="rounded-xl border border-zinc-100 p-4 dark:border-zinc-700"
                    >
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">Mensagem {{ index + 1 }}</p>
                            <button
                                v-if="form.cart_recovery_steps.length > 1"
                                type="button"
                                class="inline-flex items-center gap-1 text-xs text-red-500 hover:text-red-600"
                                @click="removeRecoveryStep(index)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                                Remover
                            </button>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Enviar após</label>
                                <div class="grid max-w-md grid-cols-[5.5rem_minmax(0,1fr)] items-center gap-2">
                                    <input
                                        :value="step.delay_value"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        class="h-10 w-full min-w-[5.5rem] rounded-lg border border-zinc-300 bg-white px-2 text-center text-base font-semibold leading-none tabular-nums text-zinc-900 shadow-sm dark:border-zinc-500 dark:bg-zinc-950 dark:text-white"
                                        @input="onDelayValueInput(step, $event)"
                                    />
                                    <select
                                        v-model="step.delay_unit"
                                        class="h-10 w-full min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-900 shadow-sm dark:border-zinc-500 dark:bg-zinc-950 dark:text-white"
                                    >
                                        <option v-for="unit in delayUnits" :key="unit.value" :value="unit.value">
                                            {{ unit.label }}
                                        </option>
                                    </select>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Contado desde o abandono do carrinho.
                                </p>
                                <p v-if="stepDelayError(index)" class="mt-1 text-xs text-red-500">{{ stepDelayError(index) }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Texto do SMS</label>
                                <textarea
                                    v-model="step.message"
                                    rows="2"
                                    maxlength="160"
                                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                />
                                <p class="mt-1 text-right text-xs" :class="stepCharCount(index) > 160 ? 'text-red-500' : 'text-zinc-500'">
                                    {{ stepCharCount(index) }}/160
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <Button type="button" variant="outline" :disabled="form.cart_recovery_steps.length >= 10" @click="addRecoveryStep">
                        <Plus class="mr-2 h-4 w-4" />
                        Adicionar mensagem
                    </Button>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Máximo de 10 mensagens. Os tempos devem ser crescentes.</p>
                </div>

                <p v-if="form.errors.cart_recovery_steps" class="mt-3 text-xs text-red-500">{{ form.errors.cart_recovery_steps }}</p>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Eventos transacionais</h2>
                <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                    Máximo de 160 caracteres por SMS. Placeholders:
                    <span v-for="(p, i) in placeholders" :key="p" class="font-mono text-xs text-[var(--color-primary)]">
                        {{ p }}<span v-if="i < placeholders.length - 1">, </span>
                    </span>
                </p>

                <div class="space-y-6">
                    <div
                        v-for="ev in eventFields"
                        :key="ev.key"
                        class="rounded-xl border border-zinc-100 p-4 dark:border-zinc-700"
                    >
                        <div class="mb-3 flex items-center justify-between gap-4">
                            <div>
                                <p class="font-medium text-zinc-900 dark:text-white">{{ ev.label }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ ev.hint }}</p>
                            </div>
                            <Toggle v-model="form[ev.key]" />
                        </div>
                        <textarea
                            v-model="form[ev.messageKey]"
                            rows="2"
                            maxlength="160"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                        />
                        <p class="mt-1 text-right text-xs" :class="charCount(ev.messageKey) > 160 ? 'text-red-500' : 'text-zinc-500'">
                            {{ charCount(ev.messageKey) }}/160
                        </p>
                        <p v-if="form.errors[ev.messageKey]" class="mt-1 text-xs text-red-500">{{ form.errors[ev.messageKey] }}</p>
                    </div>
                </div>
            </section>

            <div class="flex justify-end">
                <Button type="submit" :disabled="form.processing">Salvar IntegraX</Button>
            </div>
        </form>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                <Send class="h-4 w-4" />
                Teste de envio
            </h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Telefone (com DDD)</label>
                    <input v-model="testPhone" type="text" placeholder="11999999999" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Mensagem (opcional)</label>
                    <input v-model="testMessage" type="text" maxlength="160" placeholder="Teste IntegraX" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                </div>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <Button type="button" variant="outline" :disabled="testing || !testPhone" @click="sendTest">
                    {{ testing ? 'Enviando…' : 'Enviar SMS de teste' }}
                </Button>
                <p v-if="testResult" class="text-sm" :class="testResult.ok ? 'text-emerald-600' : 'text-red-500'">
                    {{ testResult.message }}
                </p>
            </div>
        </section>

        <section v-if="recent_dispatches.length" class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                <MessageSquare class="h-4 w-4" />
                Últimos envios
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700">
                            <th class="py-2 pr-4 font-medium">Evento</th>
                            <th class="py-2 pr-4 font-medium">Telefone</th>
                            <th class="py-2 pr-4 font-medium">Status</th>
                            <th class="py-2 font-medium">Quando</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in recent_dispatches" :key="row.id" class="border-b border-zinc-100 dark:border-zinc-700/60">
                            <td class="py-2 pr-4">{{ eventLabel(row.event_type, row.sequence_step) }}</td>
                            <td class="py-2 pr-4 font-mono text-xs">{{ row.phone }}</td>
                            <td class="py-2 pr-4">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="{
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200': row.status === 'sent',
                                        'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200': row.status === 'failed',
                                        'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200': row.status === 'pending',
                                    }"
                                >
                                    {{ row.status }}
                                </span>
                            </td>
                            <td class="py-2 text-xs text-zinc-500">{{ row.sent_at || row.created_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
