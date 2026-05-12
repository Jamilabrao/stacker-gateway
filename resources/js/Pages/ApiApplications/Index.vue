<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { KeyRound, Lock, ExternalLink, Copy, Check, RefreshCw, Eye, EyeOff } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    pix_application: { type: Object, required: true },
    api_key_reveal: { type: Object, default: null },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const flashError = computed(() => page.props.flash?.error ?? null);

const revealPublic = ref(props.api_key_reveal?.public_key ?? '');
const revealSecret = ref(props.api_key_reveal?.secret_key ?? '');
const copyDone = ref({ public: false, secret: false });
const revealLoading = ref(false);
const revealError = ref('');

const canRevealSecret = computed(() => !!props.pix_application?.can_reveal_secret);

watch(() => props.api_key_reveal, (v) => {
    if (v) {
        revealPublic.value = v.public_key ?? '';
        revealSecret.value = v.secret_key ?? '';
        copyDone.value = { public: false, secret: false };
        revealError.value = '';
    }
}, { immediate: true });

const displayPublic = computed(() => revealPublic.value || props.pix_application.public_key || '');

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function copyKey(which, text) {
    if (!text) return;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        copyDone.value = { ...copyDone.value, [which]: true };
        setTimeout(() => {
            copyDone.value = { ...copyDone.value, [which]: false };
        }, 2000);
    } catch {
        copyDone.value = { ...copyDone.value, [which]: false };
    }
}

async function fetchRevealSecret() {
    revealError.value = '';
    revealLoading.value = true;
    try {
        const res = await fetch(`/aplicacoes-api/${props.pix_application.id}/reveal-secret`, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
            body: '{}',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            revealError.value = data.message || 'Não foi possível revelar a secret.';
            return;
        }
        revealSecret.value = data.secret_key ?? '';
    } finally {
        revealLoading.value = false;
    }
}

function hideSecret() {
    revealSecret.value = '';
    revealError.value = '';
}

function regenerateKeys() {
    if (!window.confirm('Gerar novas chaves? As credenciais atuais deixam de funcionar imediatamente.')) return;
    router.post(
        `/aplicacoes-api/${props.pix_application.id}/regenerate-key`,
        { return_to: 'index' },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="mx-auto w-full min-w-0 max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <h1 class="text-xl font-semibold tracking-tight text-zinc-900 dark:text-white sm:pt-0.5">
                Chaves da API
            </h1>
            <Button as="a" href="/docs/api-pagamentos" target="_blank" rel="noopener noreferrer" variant="outline" size="sm" class="inline-flex w-fit shrink-0 items-center gap-2">
                <ExternalLink class="h-4 w-4" />
                Documentação
            </Button>
        </div>

        <div v-if="flashSuccess" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-200">
            {{ flashSuccess }}
        </div>
        <div v-if="flashError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200">
            {{ flashError }}
        </div>

        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Envie a public key nas requisições públicas e assine com a secret no servidor. A secret fica criptografada até você revelar.
        </p>

        <div class="grid grid-cols-1 items-stretch gap-6 lg:grid-cols-2 lg:gap-8">
            <!-- Card: Public key -->
            <div class="flex min-h-[280px] flex-col rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-7">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300">
                        <KeyRound class="h-5 w-5" />
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Public key</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Identificador público da integração</p>
                    </div>
                </div>
                <p class="mt-4 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                    Pode ser exposta no front-end ou em configs de parceiros; não dá acesso sozinha à conta.
                </p>
                <code class="mt-4 min-h-[4.5rem] flex-1 break-all rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm font-mono leading-relaxed text-zinc-800 dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-200">
                    {{ displayPublic || '—' }}
                </code>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="mt-4 inline-flex w-full items-center justify-center gap-2 sm:w-auto sm:self-start"
                    :disabled="!displayPublic"
                    @click="copyKey('public', displayPublic)"
                >
                    <Check v-if="copyDone.public" class="h-4 w-4 text-emerald-600" />
                    <Copy v-else class="h-4 w-4" />
                    {{ copyDone.public ? 'Copiado' : 'Copiar public key' }}
                </Button>
            </div>

            <!-- Card: Secret key -->
            <div class="flex min-h-[280px] flex-col rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200">
                            <Lock class="h-5 w-5" />
                        </span>
                        <div>
                            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Secret key</h2>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Nunca compartilhe em código público</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="revealSecret && canRevealSecret"
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="text-zinc-600 dark:text-zinc-400"
                            @click="hideSecret"
                        >
                            <EyeOff class="h-4 w-4" />
                            Ocultar
                        </Button>
                        <Button
                            v-if="!revealSecret && canRevealSecret"
                            type="button"
                            size="sm"
                            variant="outline"
                            class="inline-flex items-center gap-2"
                            :disabled="revealLoading"
                            @click="fetchRevealSecret"
                        >
                            <Eye class="h-4 w-4" />
                            {{ revealLoading ? 'Carregando…' : 'Revelar secret' }}
                        </Button>
                    </div>
                </div>

                <p v-if="!canRevealSecret" class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                    Regenere as chaves uma vez (após atualizar o sistema) para poder revelar a secret aqui.
                </p>
                <p v-else-if="revealError" class="mt-4 text-sm text-red-600 dark:text-red-400">{{ revealError }}</p>
                <p v-else-if="canRevealSecret && !revealSecret" class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                    Use <span class="font-medium text-zinc-800 dark:text-zinc-200">Revelar secret</span> quando precisar copiar a credencial.
                </p>

                <code
                    v-if="revealSecret"
                    class="mt-4 min-h-[4.5rem] flex-1 break-all rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-mono leading-relaxed text-zinc-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    {{ revealSecret }}
                </code>
                <div v-else class="mt-4 flex min-h-[4.5rem] flex-1 items-center justify-center rounded-lg border border-dashed border-zinc-200 bg-zinc-50/80 dark:border-zinc-600 dark:bg-zinc-800/40">
                    <span class="px-4 text-center text-sm text-zinc-400 dark:text-zinc-500">Secret oculta</span>
                </div>

                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="mt-4 inline-flex w-full items-center justify-center gap-2 sm:w-auto sm:self-start"
                    :disabled="!revealSecret"
                    @click="copyKey('secret', revealSecret)"
                >
                    <Check v-if="copyDone.secret" class="h-4 w-4 text-emerald-600" />
                    <Copy v-else class="h-4 w-4" />
                    {{ copyDone.secret ? 'Copiado' : 'Copiar secret key' }}
                </Button>

                <div class="mt-auto border-t border-zinc-200 pt-5 dark:border-zinc-700">
                    <Button type="button" variant="outline" size="sm" class="inline-flex items-center gap-2" @click="regenerateKeys">
                        <RefreshCw class="h-4 w-4" />
                        Regenerar chaves
                    </Button>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        Gera um par novo (public + secret) e invalida o anterior na hora.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
