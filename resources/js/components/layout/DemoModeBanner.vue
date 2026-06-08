<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';

const page = usePage();
const enabled = computed(() => !!page.props.demo_mode?.enabled);
</script>

<template>
    <div
        v-if="enabled"
        class="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-100"
        role="status"
    >
        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <p>
            <strong>Modo demonstração</strong> — dados ilustrativos. Alterações desabilitadas.
        </p>
    </div>
</template>
