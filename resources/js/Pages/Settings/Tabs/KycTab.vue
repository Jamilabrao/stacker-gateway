<script setup>
import { BadgeCheck } from 'lucide-vue-next';

const props = defineProps({
    form: { type: Object, required: true },
});

const identityOptions = [
    { value: 'rg', label: 'RG / CIN', hint: 'Frente e verso' },
    { value: 'cnh', label: 'CNH', hint: 'Arquivo único' },
    { value: 'passport', label: 'Passaporte', hint: 'Página de identificação (dentro da validade)' },
];

function isIdentityChecked(value) {
    const list = Array.isArray(props.form.kyc_allowed_identity_types)
        ? props.form.kyc_allowed_identity_types
        : [];
    return list.includes(value);
}

function toggleIdentity(value, checked) {
    const current = Array.isArray(props.form.kyc_allowed_identity_types)
        ? [...props.form.kyc_allowed_identity_types]
        : [];
    if (checked) {
        if (!current.includes(value)) {
            current.push(value);
        }
    } else {
        const next = current.filter((v) => v !== value);
        // Mantém pelo menos um tipo selecionado.
        props.form.kyc_allowed_identity_types = next.length > 0 ? next : [value];
        return;
    }
    props.form.kyc_allowed_identity_types = current;
}
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            >
                <BadgeCheck class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Documentos do KYC</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Defina quais documentos os infoprodutores precisam enviar na verificação. Instalações mais
                    leves podem exigir só a identidade; outras podem ser mais criteriosas.
                </p>
            </div>
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100">
            <p class="font-medium">Escopo desta configuração</p>
            <p class="mt-1 text-xs leading-relaxed">
                Vale para novos envios e reenvios (KYC rejeitado). Contas já aprovadas ou em análise com o
                pacote antigo continuam válidas. O documento de identificação é sempre obrigatório.
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Tipos de identificação aceitos</h3>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                O seller escolhe um destes tipos. Selecione ao menos um.
            </p>
            <div class="mt-4 space-y-3">
                <label
                    v-for="opt in identityOptions"
                    :key="opt.value"
                    class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80"
                >
                    <input
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        :checked="isIdentityChecked(opt.value)"
                        @change="toggleIdentity(opt.value, $event.target.checked)"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ opt.label }}</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ opt.hint }}</span>
                    </span>
                </label>
            </div>
            <p v-if="form.errors?.kyc_allowed_identity_types" class="mt-2 text-sm text-red-600">
                {{ form.errors.kyc_allowed_identity_types }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Pessoa física — documentos extras</h3>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Além do documento de identificação do titular.
            </p>
            <div class="mt-4 space-y-3">
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                    <input
                        v-model="form.kyc_require_address_proof"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        true-value="1"
                        false-value="0"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Comprovante de residência</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                            Documento recente com nome e endereço do seller.
                        </span>
                    </span>
                </label>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                    <input
                        v-model="form.kyc_require_selfie_with_document"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        true-value="1"
                        false-value="0"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Selfie com documento</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                            Foto do seller segurando o mesmo documento de identificação.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Pessoa jurídica — documentos da empresa</h3>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Além do documento de identificação do responsável legal.
            </p>
            <div class="mt-4 space-y-3">
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                    <input
                        v-model="form.kyc_require_company_address_proof"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        true-value="1"
                        false-value="0"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Comprovante de endereço da empresa</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                            Conta de serviço, contrato de locação ou equivalente no nome da empresa.
                        </span>
                    </span>
                </label>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                    <input
                        v-model="form.kyc_require_company_constitution"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                        true-value="1"
                        false-value="0"
                    />
                    <span>
                        <span class="block text-sm font-medium text-zinc-900 dark:text-white">Documento de constituição</span>
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                            MEI: CCMEI. Demais empresas: contrato social / ato constitutivo atualizado.
                        </span>
                    </span>
                </label>
            </div>
        </div>
    </section>
</template>
