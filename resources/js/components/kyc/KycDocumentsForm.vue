<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import { Upload, FileText, CheckCircle2, BadgeCheck, Loader2 } from 'lucide-vue-next';

const props = defineProps({
    person_type: { type: String, default: 'pf' },
    kyc_status: { type: String, default: 'not_submitted' },
    rejection_reason: { type: String, default: null },
    /** Quando true, omite título principal (uso na aba Financeiro). */
    embedded: { type: Boolean, default: false },
});

const isPj = computed(() => props.person_type === 'pj');

const isPendingReview = computed(() => props.kyc_status === 'pending_review');
const isApproved = computed(() => props.kyc_status === 'approved');
const isReadOnlyKyc = computed(() => isPendingReview.value || isApproved.value);

const uploading = reactive({
    rg_front: false,
    rg_back: false,
    company_document: false,
});

const uploaded = reactive({
    rg_front: false,
    rg_back: false,
    company_document: false,
});

const fieldErrors = reactive({
    rg_front: '',
    rg_back: '',
    company_document: '',
});

const finalizeProcessing = ref(false);
const uploadError = ref('');

const MAX_BYTES = 20 * 1024 * 1024;

function parseAxiosError(err, field) {
    const data = err?.response?.data;
    if (data?.errors?.[field]?.[0]) {
        return data.errors[field][0];
    }
    if (data?.errors?.upload?.[0]) {
        return data.errors.upload[0];
    }
    if (data?.message) {
        return data.message;
    }
    if (err?.response?.status === 413) {
        return 'Arquivo grande demais para o servidor. Use até 20 MB por arquivo.';
    }

    return 'Não foi possível enviar o arquivo. Tente novamente.';
}

async function onFile(field, event) {
    const f = event.target.files?.[0];
    event.target.value = '';
    fieldErrors[field] = '';
    uploadError.value = '';

    if (!f) {
        return;
    }

    if (f.size > MAX_BYTES) {
        fieldErrors[field] = 'O arquivo não pode ser maior que 20 MB.';
        return;
    }

    uploading[field] = true;
    uploaded[field] = false;

    const fd = new FormData();
    fd.append('field', field);
    fd.append(field, f);

    try {
        await axios.post('/kyc/document', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        uploaded[field] = true;
    } catch (err) {
        fieldErrors[field] = parseAxiosError(err, field);
    } finally {
        uploading[field] = false;
    }
}

const canFinalize = computed(() => {
    if (isReadOnlyKyc.value) {
        return false;
    }
    if (!uploaded.rg_front || !uploaded.rg_back) {
        return false;
    }
    if (isPj.value && !uploaded.company_document) {
        return false;
    }

    return true;
});

function submitForReview() {
    uploadError.value = '';
    if (!canFinalize.value) {
        uploadError.value = 'Envie todos os documentos (um por vez) antes de concluir.';
        return;
    }

    finalizeProcessing.value = true;
    router.post(
        '/kyc/finalize',
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                uploadError.value =
                    errors?.upload ||
                    errors?.finalize ||
                    Object.values(errors || {})[0] ||
                    'Não foi possível enviar para análise.';
            },
            onFinish: () => {
                finalizeProcessing.value = false;
            },
        }
    );
}

const inputFileClass =
    'block w-full cursor-pointer rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1 file:text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:file:bg-zinc-700';

const fileAccept =
    'image/jpeg,image/jpg,image/png,image/webp,image/gif,image/heic,image/heif,application/pdf,.pdf,.jpg,.jpeg,.png,.webp,.gif,.heic,.heif';
</script>

<template>
    <div class="space-y-6" :class="embedded ? '' : 'mx-auto max-w-2xl'">
        <div v-if="!embedded && !isReadOnlyKyc">
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Verificação de identidade (KYC)</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Envie <strong>um arquivo por vez</strong> (imagem ou PDF, até 20 MB). Depois clique em
                <strong>Enviar para análise</strong>.
            </p>
        </div>
        <div v-else-if="!embedded && isReadOnlyKyc">
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Verificação de identidade (KYC)</h1>
        </div>
        <div v-else-if="embedded && !isReadOnlyKyc">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Documentos para verificação</h3>
            <p class="mt-1 text-xs text-zinc-500">
                Selecione cada arquivo separadamente (até 20 MB). Formatos: JPG, PNG, WebP, GIF, HEIC/HEIF ou PDF.
            </p>
        </div>

        <div
            v-if="rejection_reason && !isReadOnlyKyc"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            <p class="font-medium">Última análise foi rejeitada:</p>
            <p class="mt-1">{{ rejection_reason }}</p>
        </div>

        <div
            v-if="isPendingReview"
            class="rounded-2xl border border-emerald-200/90 bg-emerald-50/90 px-5 py-6 text-center shadow-sm dark:border-emerald-900/50 dark:bg-emerald-950/35"
        >
            <div class="flex justify-center">
                <CheckCircle2 class="h-12 w-12 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
            </div>
            <h3 class="mt-4 text-base font-semibold text-emerald-950 dark:text-emerald-100">Documentos enviados</h3>
            <p class="mt-2 text-sm text-emerald-900/90 dark:text-emerald-200/95">
                Recebemos seus arquivos. Eles estão <strong>em análise</strong> pela equipe da plataforma. Você será avisado quando a verificação for concluída.
            </p>
        </div>

        <div
            v-else-if="isApproved"
            class="rounded-2xl border border-[var(--color-primary)]/40 bg-[var(--color-primary)]/10 px-5 py-6 text-center shadow-sm dark:border-[var(--color-primary)]/35 dark:bg-[var(--color-primary)]/15"
        >
            <div class="flex justify-center">
                <BadgeCheck class="h-12 w-12 text-[var(--color-primary)]" aria-hidden="true" />
            </div>
            <h3 class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Verificação aprovada</h3>
            <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                Sua identidade foi <strong>confirmada</strong> pela plataforma. Não é necessário enviar novos documentos.
            </p>
        </div>

        <form
            v-else
            class="space-y-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/40"
            @submit.prevent="submitForReview"
        >
            <p v-if="uploadError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                {{ uploadError }}
            </p>

            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <Upload class="h-4 w-4 text-[var(--color-primary)]" />
                    RG — frente e verso
                </h2>
                <p class="mt-1 text-xs text-zinc-500">Documento de identidade do responsável.</p>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium uppercase text-zinc-500">Frente</label>
                        <input
                            type="file"
                            :accept="fileAccept"
                            :class="inputFileClass"
                            :disabled="uploading.rg_front"
                            @change="onFile('rg_front', $event)"
                        />
                        <p v-if="uploading.rg_front" class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                            <Loader2 class="h-3 w-3 animate-spin" /> Enviando…
                        </p>
                        <p v-else-if="uploaded.rg_front" class="mt-1 text-xs text-emerald-600">Arquivo recebido</p>
                        <p v-if="fieldErrors.rg_front" class="mt-1 text-sm text-red-600">{{ fieldErrors.rg_front }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase text-zinc-500">Verso</label>
                        <input
                            type="file"
                            :accept="fileAccept"
                            :class="inputFileClass"
                            :disabled="uploading.rg_back"
                            @change="onFile('rg_back', $event)"
                        />
                        <p v-if="uploading.rg_back" class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                            <Loader2 class="h-3 w-3 animate-spin" /> Enviando…
                        </p>
                        <p v-else-if="uploaded.rg_back" class="mt-1 text-xs text-emerald-600">Arquivo recebido</p>
                        <p v-if="fieldErrors.rg_back" class="mt-1 text-sm text-red-600">{{ fieldErrors.rg_back }}</p>
                    </div>
                </div>
            </div>

            <div v-if="isPj" class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <FileText class="h-4 w-4 text-[var(--color-primary)]" />
                    Empresa
                </h2>
                <p class="mt-1 text-xs text-zinc-500">
                    Cartão CNPJ <strong>ou</strong> contrato social (imagem ou PDF).
                </p>
                <div class="mt-3 max-w-xl">
                    <label class="block text-xs font-medium uppercase text-zinc-500">Documento da empresa</label>
                    <input
                        type="file"
                        :accept="fileAccept"
                        :class="inputFileClass"
                        :disabled="uploading.company_document"
                        @change="onFile('company_document', $event)"
                    />
                    <p v-if="uploading.company_document" class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                        <Loader2 class="h-3 w-3 animate-spin" /> Enviando…
                    </p>
                    <p v-else-if="uploaded.company_document" class="mt-1 text-xs text-emerald-600">Arquivo recebido</p>
                    <p v-if="fieldErrors.company_document" class="mt-1 text-sm text-red-600">{{ fieldErrors.company_document }}</p>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <Button type="submit" :disabled="finalizeProcessing || !canFinalize">
                    {{ finalizeProcessing ? 'Enviando…' : 'Enviar para análise' }}
                </Button>
            </div>
        </form>
    </div>
</template>
