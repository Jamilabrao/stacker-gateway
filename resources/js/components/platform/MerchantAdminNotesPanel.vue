<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    merchantUserId: { type: Number, required: true },
    compact: { type: Boolean, default: false },
    initialNotes: { type: Array, default: () => [] },
    initialCount: { type: Number, default: 0 },
});

const emit = defineEmits(['count-changed']);

const notes = ref([...props.initialNotes]);
const body = ref('');
const loading = ref(false);
const submitting = ref(false);
const error = ref('');

const displayNotes = computed(() => (props.compact ? notes.value.slice(0, 5) : notes.value));

async function loadNotes() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(`/plataforma/usuarios/${props.merchantUserId}/observacoes`);
        notes.value = data.notes || [];
        emit('count-changed', notes.value.length);
    } catch (e) {
        error.value = 'Não foi possível carregar as observações.';
    } finally {
        loading.value = false;
    }
}

async function submitNote() {
    const text = body.value.trim();
    if (text.length < 3) {
        error.value = 'A observação deve ter pelo menos 3 caracteres.';
        return;
    }
    submitting.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(`/plataforma/usuarios/${props.merchantUserId}/observacoes`, {
            body: text,
        });
        if (data.note) {
            notes.value = [data.note, ...notes.value];
            emit('count-changed', notes.value.length);
        }
        body.value = '';
    } catch (e) {
        error.value = e.response?.data?.message || 'Não foi possível registrar a observação.';
    } finally {
        submitting.value = false;
    }
}

function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('pt-BR');
}

watch(
    () => props.merchantUserId,
    (id) => {
        if (id) {
            if (props.initialNotes.length) {
                notes.value = [...props.initialNotes];
            } else {
                loadNotes();
            }
        }
    },
    { immediate: true }
);
</script>

<template>
    <div class="space-y-3">
        <p v-if="!compact" class="text-sm text-zinc-600 dark:text-zinc-400">
            Uso interno da plataforma. O infoprodutor não vê estas observações.
        </p>
        <form class="space-y-2" @submit.prevent="submitNote">
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nova observação</label>
            <textarea
                v-model="body"
                rows="3"
                maxlength="5000"
                placeholder="Ex.: Acordo comercial 4% até 31/12"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
            />
            <div class="flex items-center justify-between gap-2">
                <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
                <span v-else class="text-xs text-zinc-500">{{ body.length }}/5000</span>
                <Button type="submit" size="sm" :disabled="submitting || body.trim().length < 3">
                    {{ submitting ? 'Registrando…' : 'Registrar ocorrência' }}
                </Button>
            </div>
        </form>
        <div v-if="loading" class="text-sm text-zinc-500">Carregando observações…</div>
        <div v-else-if="displayNotes.length === 0" class="text-sm text-zinc-500">Nenhuma observação registrada.</div>
        <ul v-else class="space-y-3">
            <li
                v-for="note in displayNotes"
                :key="note.id"
                class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800/60"
            >
                <p class="whitespace-pre-wrap text-zinc-800 dark:text-zinc-200">{{ note.body }}</p>
                <p class="mt-1 text-xs text-zinc-500">
                    {{ note.author?.name || 'Admin' }} · {{ formatDate(note.created_at) }}
                </p>
            </li>
        </ul>
        <p v-if="compact && notes.length > 5" class="text-xs text-zinc-500">
            + {{ notes.length - 5 }} observação(ões) anteriores (veja no detalhe do infoprodutor).
        </p>
    </div>
</template>
