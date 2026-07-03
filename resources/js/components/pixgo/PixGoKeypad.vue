<script setup>
import { computed } from 'vue';
import { Trash2, Delete } from 'lucide-vue-next';
import { usePixGoTheme } from '@/composables/usePixGoTheme';

const { amountCardStyle } = usePixGoTheme();

const props = defineProps({
    modelValue: { type: Number, default: 0 },
    maxCents: { type: Number, default: 9999999 },
    label: { type: String, default: 'VALOR DA VENDA' },
});

const emit = defineEmits(['update:modelValue']);

const formatted = computed(() => {
    const cents = props.modelValue;
    const reais = Math.floor(cents / 100);
    const centavos = cents % 100;
    return `R$ ${reais.toLocaleString('pt-BR')},${String(centavos).padStart(2, '0')}`;
});

function pushDigit(d) {
    const next = props.modelValue * 10 + d;
    if (next <= props.maxCents) {
        emit('update:modelValue', next);
    }
}

function clearAll() {
    emit('update:modelValue', 0);
}

function backspace() {
    emit('update:modelValue', Math.floor(props.modelValue / 10));
}
</script>

<template>
    <div class="w-full max-w-md">
        <div
            class="rounded-2xl border border-[var(--color-primary)]/40 bg-zinc-50 px-6 py-8 text-center dark:bg-zinc-950"
            :style="amountCardStyle"
        >
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[var(--color-primary)]/80">{{ label }}</p>
            <p class="mt-3 text-4xl font-bold tracking-tight text-[var(--color-primary)] sm:text-5xl">{{ formatted }}</p>
        </div>

        <div class="mt-6 grid grid-cols-3 gap-3">
            <button
                v-for="n in 9"
                :key="n"
                type="button"
                class="flex h-16 items-center justify-center rounded-xl bg-zinc-100 text-2xl font-medium text-zinc-900 transition hover:bg-zinc-200 active:scale-95 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800"
                @click="pushDigit(n)"
            >
                {{ n }}
            </button>
            <button
                type="button"
                class="flex h-16 flex-col items-center justify-center gap-0.5 rounded-xl bg-zinc-100 text-xs font-medium text-zinc-600 transition hover:bg-zinc-200 active:scale-95 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                @click="clearAll"
            >
                <Trash2 class="h-4 w-4" />
                Limpar
            </button>
            <button
                type="button"
                class="flex h-16 items-center justify-center rounded-xl bg-zinc-100 text-2xl font-medium text-zinc-900 transition hover:bg-zinc-200 active:scale-95 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800"
                @click="pushDigit(0)"
            >
                0
            </button>
            <button
                type="button"
                class="flex h-16 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 transition hover:bg-zinc-200 active:scale-95 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                aria-label="Apagar"
                @click="backspace"
            >
                <Delete class="h-5 w-5" />
            </button>
        </div>
    </div>
</template>
