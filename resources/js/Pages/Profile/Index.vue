<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import ProfileTotpSection from '@/components/profile/ProfileTotpSection.vue';
import { Camera, Lock, Loader2 } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    totp_enabled: { type: Boolean, default: false },
    push_preferences: {
        type: Object,
        default: () => ({
            sale_approved: true,
            pix_generated: true,
            boleto_generated: true,
            withdrawal_paid: true,
            affiliate_sale_approved: true,
            affiliate_enrollment_approved: true,
            daily_summary: true,
            system: true,
            show_product_name: true,
            show_sale_amount: true,
            sale_amount_mode: 'gross',
            show_payment_method: true,
        }),
    },
});

const avatarInputRef = ref(null);
const avatarPreview = ref(null);

const profileForm = useForm({
    name: props.user.name,
    email: props.user.email,
    username: props.user.username ?? '',
    avatar: null,
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const pushForm = useForm({
    sale_approved: !!props.push_preferences.sale_approved,
    pix_generated: !!props.push_preferences.pix_generated,
    boleto_generated: !!props.push_preferences.boleto_generated,
    withdrawal_paid: !!props.push_preferences.withdrawal_paid,
    affiliate_sale_approved: !!props.push_preferences.affiliate_sale_approved,
    affiliate_enrollment_approved: !!props.push_preferences.affiliate_enrollment_approved,
    daily_summary: !!props.push_preferences.daily_summary,
    system: !!props.push_preferences.system,
    show_product_name: !!props.push_preferences.show_product_name,
    show_sale_amount: !!props.push_preferences.show_sale_amount,
    sale_amount_mode: props.push_preferences.sale_amount_mode === 'net' ? 'net' : 'gross',
    show_payment_method: !!props.push_preferences.show_payment_method,
});

function submitPushPreferences() {
    pushForm.put('/meu-perfil/preferencias-push', { preserveScroll: true });
}

function setSaleAmountMode(mode) {
    pushForm.sale_amount_mode = mode === 'net' ? 'net' : 'gross';
    pushForm.show_sale_amount = true;
}

const avatarUrl = computed(() => {
    if (avatarPreview.value) return avatarPreview.value;
    return props.user.avatar_url || null;
});

function triggerAvatarClick() {
    avatarInputRef.value?.click();
}

function onAvatarChange(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    profileForm.avatar = file;
    const reader = new FileReader();
    reader.onload = (e) => { avatarPreview.value = e.target?.result; };
    reader.readAsDataURL(file);
}

function submitProfile() {
    profileForm.post('/meu-perfil', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            profileForm.avatar = null;
            avatarPreview.value = null;
        },
    });
}

function submitPassword() {
    passwordForm.put('/meu-perfil/senha', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-8">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                Meu perfil
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Atualize sua foto, nome, e-mail e senha.
            </p>
        </div>

        <!-- Card: Foto e dados -->
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="p-6 sm:p-8">
                <div class="flex flex-col items-center gap-6 sm:flex-row sm:items-start">
                    <div class="relative shrink-0">
                        <button
                            type="button"
                            class="group relative flex h-28 w-28 overflow-hidden rounded-2xl bg-zinc-100 ring-2 ring-zinc-200 transition-all hover:ring-[var(--color-primary)] dark:bg-zinc-700 dark:ring-zinc-600 dark:hover:ring-[var(--color-primary)]"
                            @click="triggerAvatarClick"
                        >
                            <img
                                v-if="avatarUrl"
                                :src="avatarUrl"
                                alt="Foto de perfil"
                                class="h-full w-full object-cover"
                            />
                            <span
                                v-else
                                class="flex h-full w-full items-center justify-center text-3xl font-semibold text-zinc-400 dark:text-zinc-500"
                            >
                                {{ (user.name || '?').charAt(0).toUpperCase() }}
                            </span>
                            <span
                                class="absolute inset-0 flex items-center justify-center bg-zinc-900/50 opacity-0 transition-opacity group-hover:opacity-100"
                            >
                                <Camera class="h-8 w-8 text-white" />
                            </span>
                        </button>
                        <input
                            ref="avatarInputRef"
                            type="file"
                            accept="image/*"
                            class="sr-only"
                            @change="onAvatarChange"
                        />
                        <p v-if="profileForm.errors.avatar" class="mt-2 max-w-xs text-center text-sm text-red-600 dark:text-red-400">
                            {{ profileForm.errors.avatar }}
                        </p>
                    </div>
                    <form
                        class="min-w-0 flex-1 space-y-4"
                        @submit.prevent="submitProfile"
                    >
                        <div>
                            <label
                                for="profile-name"
                                class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                Nome
                            </label>
                            <input
                                id="profile-name"
                                v-model="profileForm.name"
                                type="text"
                                required
                                maxlength="255"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                placeholder="Seu nome"
                            />
                            <p
                                v-if="profileForm.errors.name"
                                class="mt-1 text-sm text-red-600 dark:text-red-400"
                            >
                                {{ profileForm.errors.name }}
                            </p>
                        </div>
                        <div>
                            <label
                                for="profile-email"
                                class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                E-mail
                            </label>
                            <input
                                id="profile-email"
                                v-model="profileForm.email"
                                type="email"
                                required
                                autocomplete="email"
                                maxlength="255"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                placeholder="seu@email.com"
                            />
                            <p
                                v-if="profileForm.errors.email"
                                class="mt-1 text-sm text-red-600 dark:text-red-400"
                            >
                                {{ profileForm.errors.email }}
                            </p>
                        </div>
                        <div>
                            <label
                                for="profile-username"
                                class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                @username (para conquistas compartilhadas)
                            </label>
                            <input
                                id="profile-username"
                                v-model="profileForm.username"
                                type="text"
                                maxlength="64"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                placeholder="meunome"
                            />
                            <p
                                v-if="profileForm.errors.username"
                                class="mt-1 text-sm text-red-600 dark:text-red-400"
                            >
                                {{ profileForm.errors.username }}
                            </p>
                        </div>
                        <Button
                            type="submit"
                            class="w-full sm:w-auto"
                            :disabled="profileForm.processing"
                        >
                            <Loader2
                                v-if="profileForm.processing"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            Salvar alterações
                        </Button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Card: Alterar senha -->
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700 sm:px-8">
                <div class="flex items-center gap-2">
                    <span
                        class="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--color-primary)]/10 text-[var(--color-primary)]"
                    >
                        <Lock class="h-4 w-4" />
                    </span>
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Alterar senha
                    </h2>
                </div>
            </div>
            <form
                class="space-y-4 p-6 sm:p-8"
                @submit.prevent="submitPassword"
            >
                <div>
                    <label
                        for="current-password"
                        class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Senha atual
                    </label>
                    <input
                        id="current-password"
                        v-model="passwordForm.current_password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                        placeholder="Digite sua senha atual"
                    />
                    <p
                        v-if="passwordForm.errors.current_password"
                        class="mt-1 text-sm text-red-600 dark:text-red-400"
                    >
                        {{ passwordForm.errors.current_password }}
                    </p>
                </div>
                <div>
                    <label
                        for="new-password"
                        class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Nova senha
                    </label>
                    <input
                        id="new-password"
                        v-model="passwordForm.password"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                        placeholder="Mínimo 8 caracteres"
                    />
                    <p
                        v-if="passwordForm.errors.password"
                        class="mt-1 text-sm text-red-600 dark:text-red-400"
                    >
                        {{ passwordForm.errors.password }}
                    </p>
                </div>
                <div>
                    <label
                        for="confirm-password"
                        class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Confirmar nova senha
                    </label>
                    <input
                        id="confirm-password"
                        v-model="passwordForm.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                        placeholder="Repita a nova senha"
                    />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    class="w-full sm:w-auto"
                    :disabled="passwordForm.processing"
                >
                    <Loader2
                        v-if="passwordForm.processing"
                        class="mr-2 h-4 w-4 animate-spin"
                    />
                    Alterar senha
                </Button>
            </form>
        </div>

        <ProfileTotpSection
            :totp_enabled="totp_enabled"
            begin-url="/seguranca/totp/iniciar"
            confirm-url="/seguranca/totp/confirmar"
            disable-url="/seguranca/totp/desativar"
            context="seller"
        />

        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Preferências de notificações push</h2>
            <p class="mt-1 text-sm text-zinc-500">
                Vale para todos os dispositivos inscritos. Dados pessoais do comprador nunca são enviados no push.
            </p>
            <form class="mt-4 space-y-4" @submit.prevent="submitPushPreferences">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.sale_approved" type="checkbox" class="rounded" /> Venda aprovada</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.pix_generated" type="checkbox" class="rounded" /> PIX gerado</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.boleto_generated" type="checkbox" class="rounded" /> Boleto gerado</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.withdrawal_paid" type="checkbox" class="rounded" /> Saque pago</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.affiliate_sale_approved" type="checkbox" class="rounded" /> Comissão de afiliado</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.affiliate_enrollment_approved" type="checkbox" class="rounded" /> Afiliação aprovada</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.daily_summary" type="checkbox" class="rounded" /> Resumo diário de vendas</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.system" type="checkbox" class="rounded" /> Comunicados administrativos</label>
                </div>
                <div class="border-t border-zinc-100 pt-4 dark:border-zinc-700">
                    <p class="mb-2 text-sm font-medium">Dados na notificação de venda</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.show_product_name" type="checkbox" class="rounded" /> Nome do produto</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.show_sale_amount" type="checkbox" class="rounded" /> Valor</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.show_payment_method" type="checkbox" class="rounded" /> Forma de pagamento</label>
                    </div>
                    <div
                        v-if="pushForm.show_sale_amount"
                        class="mt-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-600 dark:bg-zinc-900/40"
                    >
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Qual valor exibir
                        </p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                class="flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm transition"
                                :class="
                                    pushForm.sale_amount_mode === 'gross'
                                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 text-zinc-900 dark:text-white'
                                        : 'border-zinc-200 bg-white text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200'
                                "
                            >
                                <input
                                    type="radio"
                                    name="sale_amount_mode"
                                    class="mt-0.5"
                                    :checked="pushForm.sale_amount_mode === 'gross'"
                                    @change="setSaleAmountMode('gross')"
                                />
                                <span>
                                    <span class="font-medium">Valor bruto</span>
                                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">Total pago pelo cliente</span>
                                </span>
                            </label>
                            <label
                                class="flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm transition"
                                :class="
                                    pushForm.sale_amount_mode === 'net'
                                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 text-zinc-900 dark:text-white'
                                        : 'border-zinc-200 bg-white text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200'
                                "
                            >
                                <input
                                    type="radio"
                                    name="sale_amount_mode"
                                    class="mt-0.5"
                                    :checked="pushForm.sale_amount_mode === 'net'"
                                    @change="setSaleAmountMode('net')"
                                />
                                <span>
                                    <span class="font-medium">Valor líquido</span>
                                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">Após taxas da plataforma</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
                <Button type="submit" :disabled="pushForm.processing">
                    {{ pushForm.processing ? 'Salvando…' : 'Salvar preferências' }}
                </Button>
            </form>
        </div>
    </div>
</template>
