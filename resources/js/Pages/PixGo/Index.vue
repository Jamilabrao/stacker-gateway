<script setup>
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ChevronDown, ChevronUp, User, Zap } from 'lucide-vue-next';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PixGoKeypad from '@/components/pixgo/PixGoKeypad.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    sidebar_label: { type: String, default: 'PixGO' },
    minimum_charge_brl: { type: Number, default: 0 },
});

const amountCents = ref(0);
const buyerOpen = ref(false);

const form = useForm({
    amount_cents: 0,
    buyer: {
        name: '',
        email: '',
        cpf: '',
    },
});

const minimumCents = computed(() => Math.max(1, Math.round((props.minimum_charge_brl || 0) * 100)));

const canCharge = computed(() => amountCents.value >= minimumCents.value);

const chargeHint = computed(() => {
    if (props.minimum_charge_brl > 0 && amountCents.value < minimumCents.value) {
        return `Valor mínimo: R$ ${props.minimum_charge_brl.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`;
    }
    return '';
});

function submitCharge() {
    if (!canCharge.value) return;
    form.amount_cents = amountCents.value;
    form.post('/pixgo/cobrar', { preserveScroll: true });
}

function goBack() {
    router.visit('/dashboard');
}
</script>

<template>
    <Head :title="sidebar_label" />

    <div class="flex min-h-[calc(100dvh-0px)] flex-col text-zinc-900 dark:text-zinc-100 lg:min-h-[calc(100dvh-1rem)]">
        <!-- Mobile header (sem AppHeader no PixGO) -->
        <header class="border-b border-zinc-200 px-4 py-4 dark:border-zinc-800/80 lg:hidden">
            <div class="mx-auto flex max-w-md items-center gap-3">
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-white"
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
                        <p class="text-base font-bold text-zinc-900 dark:text-white">{{ sidebar_label }}</p>
                        <p class="text-xs text-zinc-500">Digite o valor e cobre</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto flex w-full max-w-lg flex-1 flex-col items-center justify-center px-4 py-8 lg:py-12">
            <p class="mb-8 hidden text-center text-sm text-zinc-500 lg:block">
                Venda rápida PIX - digite o valor e gere o QR na hora.
            </p>

            <PixGoKeypad v-model="amountCents" />

            <button
                type="button"
                class="mt-6 w-full max-w-md rounded-xl bg-[var(--color-primary)] py-4 text-lg font-bold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!canCharge || form.processing"
                @click="submitCharge"
            >
                {{ form.processing ? 'Gerando PIX…' : 'Cobrar' }}
            </button>
            <p v-if="chargeHint" class="mt-2 text-center text-xs text-amber-600 dark:text-amber-400/90">{{ chargeHint }}</p>
            <p v-if="form.errors.amount" class="mt-2 text-center text-xs text-red-500 dark:text-red-400">{{ form.errors.amount }}</p>

            <div class="mt-6 w-full max-w-md">
                <button
                    type="button"
                    class="flex w-full items-center justify-between border-b border-zinc-200 py-3 text-sm text-zinc-500 transition hover:text-zinc-800 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
                    @click="buyerOpen = !buyerOpen"
                >
                    <span class="flex items-center gap-2">
                        <User class="h-4 w-4" />
                        Comprador (opcional)
                    </span>
                    <ChevronUp v-if="buyerOpen" class="h-4 w-4" />
                    <ChevronDown v-else class="h-4 w-4" />
                </button>
                <div v-show="buyerOpen" class="mt-4 space-y-3">
                    <input
                        v-model="form.buyer.name"
                        type="text"
                        placeholder="Nome"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-[var(--color-primary)]/50 focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)]/30 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white dark:placeholder:text-zinc-600"
                    />
                    <input
                        v-model="form.buyer.email"
                        type="email"
                        placeholder="E-mail"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-[var(--color-primary)]/50 focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)]/30 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white dark:placeholder:text-zinc-600"
                    />
                    <input
                        v-model="form.buyer.cpf"
                        type="text"
                        placeholder="CPF"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-[var(--color-primary)]/50 focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)]/30 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white dark:placeholder:text-zinc-600"
                    />
                </div>
            </div>
        </main>
    </div>
</template>
