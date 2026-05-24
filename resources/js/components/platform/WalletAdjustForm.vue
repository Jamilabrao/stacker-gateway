<script setup>
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    userId: { type: Number, required: true },
    redirectTo: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});

const form = useForm({
    amount: '',
    direction: 'credit',
    bucket: 'pix',
    note: '',
});

function submit() {
    form.transform((data) => ({
        ...data,
        redirect_to: props.redirectTo || undefined,
    })).post(`/plataforma/usuarios/${props.userId}/ajuste-saldo`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'note');
            form.direction = 'credit';
            form.bucket = 'pix';
        },
    });
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <div :class="compact ? 'grid gap-3 sm:grid-cols-2' : 'grid gap-4 sm:grid-cols-2 lg:grid-cols-4'">
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Operação</label>
                <select
                    v-model="form.direction"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <option value="credit">Creditar (+)</option>
                    <option value="debit">Debitar (−)</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Valor (R$)</label>
                <input
                    v-model="form.amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    required
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm tabular-nums dark:border-zinc-600 dark:bg-zinc-900"
                    placeholder="0,00"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Bucket</label>
                <select
                    v-model="form.bucket"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <option value="pix">PIX</option>
                    <option value="card">Cartão</option>
                    <option value="boleto">Boleto</option>
                </select>
            </div>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Motivo (obrigatório)</label>
            <textarea
                v-model="form.note"
                rows="2"
                required
                maxlength="500"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                placeholder="Ex.: correção manual, bônus, estorno administrativo..."
            />
        </div>
        <p v-if="form.errors.amount" class="text-sm text-red-600">{{ form.errors.amount }}</p>
        <p v-if="form.errors.note" class="text-sm text-red-600">{{ form.errors.note }}</p>
        <p v-if="form.errors.direction" class="text-sm text-red-600">{{ form.errors.direction }}</p>
        <div class="flex justify-end">
            <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Aplicando...' : 'Aplicar ajuste' }}
            </Button>
        </div>
    </form>
</template>
