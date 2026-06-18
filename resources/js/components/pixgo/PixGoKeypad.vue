<script setup>
import { computed } from 'vue';
import { Trash2, Delete } from 'lucide-vue-next';

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
            class="rounded-2xl border border-lime-500/40 bg-zinc-950 px-6 py-8 text-center shadow-[0_0_40px_rgba(163,230,53,0.08)]"
        >
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-lime-400/80">{{ label }}</p>
            <p class="mt-3 text-4xl font-bold tracking-tight text-lime-400 sm:text-5xl">{{ formatted }}</p>
        </div>

        <div class="mt-6 grid grid-cols-3 gap-3">
            <button
                v-for="n in 9"
                :key="n"
                type="button"
                class="flex h-16 items-center justify-center rounded-xl bg-zinc-900 text-2xl font-medium text-zinc-100 transition hover:bg-zinc-800 active:scale-95"
                @click="pushDigit(n)"
            >
                {{ n }}
            </button>
            <button
                type="button"
                class="flex h-16 flex-col items-center justify-center gap-0.5 rounded-xl bg-zinc-900 text-xs font-medium text-zinc-300 transition hover:bg-zinc-800 active:scale-95"
                @click="clearAll"
            >
                <Trash2 class="h-4 w-4" />
                Limpar
            </button>
            <button
                type="button"
                class="flex h-16 items-center justify-center rounded-xl bg-zinc-900 text-2xl font-medium text-zinc-100 transition hover:bg-zinc-800 active:scale-95"
                @click="pushDigit(0)"
            >
                0
            </button>
            <button
                type="button"
                class="flex h-16 items-center justify-center rounded-xl bg-zinc-900 text-zinc-300 transition hover:bg-zinc-800 active:scale-95"
                aria-label="Apagar"
                @click="backspace"
            >
                <Delete class="h-5 w-5" />
            </button>
        </div>
    </div>
</template>
