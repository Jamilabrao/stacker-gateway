<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    manager: { type: Object, default: null },
});

const isEdit = !!props.manager?.id;

const form = useForm({
    name: props.manager?.name ?? '',
    email: props.manager?.email ?? '',
    phone: props.manager?.phone_display ?? props.manager?.phone ?? '',
    is_active: props.manager?.is_active ?? true,
    show_email: props.manager?.show_email ?? true,
    show_phone: props.manager?.show_phone ?? true,
    show_whatsapp: props.manager?.show_whatsapp ?? true,
    show_photo: props.manager?.show_photo ?? true,
    notes: props.manager?.notes ?? '',
    avatar: null,
    remove_avatar: false,
});

function onFile(e) {
    form.avatar = e.target.files?.[0] ?? null;
    form.remove_avatar = false;
}

function submit() {
    if (isEdit) {
        form.transform((data) => ({ ...data, _method: 'PUT' })).post(`/plataforma/gerentes-conta/${props.manager.id}`, {
            forceFormData: true,
        });
    } else {
        form.post('/plataforma/gerentes-conta', { forceFormData: true });
    }
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <Link href="/plataforma/gerentes-conta" class="text-sm text-[var(--color-primary)] hover:underline">← Voltar</Link>
            <h1 class="mt-2 text-xl font-semibold text-zinc-900 dark:text-white">
                {{ isEdit ? 'Editar gerente' : 'Novo gerente de conta' }}
            </h1>
        </div>

        <form class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm font-medium">Nome</label>
                <input v-model="form.name" required class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
                <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">E-mail</label>
                <input v-model="form.email" type="email" required class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Telefone / WhatsApp</label>
                <input v-model="form.phone" required placeholder="(34) 99999-9999" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
                <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Foto de perfil</label>
                <div v-if="manager?.avatar_url && !form.remove_avatar" class="mb-2">
                    <img :src="manager.avatar_url" alt="" class="h-16 w-16 rounded-full object-cover" />
                </div>
                <input type="file" accept="image/jpeg,image/png,image/webp" @change="onFile" />
                <label v-if="manager?.avatar_url" class="mt-2 flex items-center gap-2 text-sm">
                    <input v-model="form.remove_avatar" type="checkbox" />
                    Remover foto atual
                </label>
                <p v-if="form.errors.avatar" class="mt-1 text-xs text-red-600">{{ form.errors.avatar }}</p>
            </div>

            <fieldset class="space-y-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <legend class="px-1 text-sm font-semibold">Exibir ao infoprodutor</legend>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.show_photo" type="checkbox" /> Foto</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.show_email" type="checkbox" /> E-mail</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.show_phone" type="checkbox" /> Telefone</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.show_whatsapp" type="checkbox" /> Botão WhatsApp</label>
                <p class="text-xs text-zinc-500">O nome é sempre exibido.</p>
            </fieldset>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.is_active" type="checkbox" />
                Gerente ativo
            </label>

            <div>
                <label class="mb-1 block text-sm font-medium">Notas internas (só admin)</label>
                <textarea v-model="form.notes" rows="3" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
            </div>

            <div class="flex justify-end gap-2">
                <Link href="/plataforma/gerentes-conta">
                    <Button type="button" variant="secondary">Cancelar</Button>
                </Link>
                <Button type="submit" :disabled="form.processing">{{ isEdit ? 'Salvar' : 'Cadastrar' }}</Button>
            </div>
        </form>
    </div>
</template>
