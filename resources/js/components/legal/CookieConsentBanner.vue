<script setup>
import { ref, onMounted, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const STORAGE_KEY = 'cookie_consent_v1';

const page = usePage();
const visible = ref(false);

const legal = computed(() => page.props.legal ?? {});
const privacyUrl = computed(() => legal.value.privacy_url || '/politica-privacidade');

onMounted(() => {
    if (!legal.value.cookie_banner_enabled) {
        return;
    }
    try {
        if (!localStorage.getItem(STORAGE_KEY)) {
            visible.value = true;
        }
    } catch {
        visible.value = true;
    }
});

function saveChoice(value) {
    try {
        localStorage.setItem(STORAGE_KEY, value);
    } catch {
        /* ignore */
    }
    visible.value = false;
}

function accept() {
    saveChoice('accepted');
}

function rejectNonEssential() {
    saveChoice('essential_only');
}
</script>

<template>
    <div
        v-if="visible"
        class="fixed inset-x-0 bottom-0 z-[9999] border-t border-zinc-200 bg-white p-4 shadow-lg dark:border-zinc-700 dark:bg-zinc-900 sm:p-5"
        role="dialog"
        aria-label="Preferências de cookies"
    >
        <div class="mx-auto flex max-w-4xl flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                Utilizamos cookies e tecnologias similares para funcionamento, segurança e melhoria da experiência.
                Consulte nossa
                <Link :href="privacyUrl" class="font-medium text-[var(--color-primary)] underline underline-offset-2">
                    Política de Privacidade
                </Link>
                para mais informações.
            </p>
            <div class="flex shrink-0 flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    @click="rejectNonEssential"
                >
                    Recusar não essenciais
                </button>
                <button
                    type="button"
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900"
                    @click="accept"
                >
                    Aceitar
                </button>
            </div>
        </div>
    </div>
</template>
