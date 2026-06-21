<script setup>
import { computed, ref } from 'vue';
import { Headset, Upload, Trash2 } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import SellerSupportFab from '@/components/layout/SellerSupportFab.vue';
import { buildWhatsAppUrl } from '@/lib/whatsappUrl';
import { isAllowedHttpUrl, safeHttpSrc } from '@/lib/safeUrl';

const props = defineProps({
    form: { type: Object, required: true },
});

const uploading = ref(false);
const uploadError = ref('');

const previewConfig = computed(() => {
    const enabled = props.form.seller_panel_support_enabled === '1'
        || props.form.seller_panel_support_enabled === true
        || props.form.seller_panel_support_enabled === 1;

    let href = null;
    if (props.form.seller_panel_support_destination === 'url') {
        const url = String(props.form.seller_panel_support_url ?? '').trim();
        href = isAllowedHttpUrl(url) ? url : null;
    } else {
        href = buildWhatsAppUrl(props.form.seller_panel_support_whatsapp);
    }

    const icon = props.form.seller_panel_support_icon || 'whatsapp';
    const iconUrl = safeHttpSrc(props.form.seller_panel_support_icon_image);

    return {
        enabled: enabled && !!href,
        href,
        icon,
        icon_url: icon === 'custom' && iconUrl ? iconUrl : null,
        color: props.form.seller_panel_support_color || '#25D366',
    };
});

async function onFileSelected(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) {
        return;
    }

    uploading.value = true;
    uploadError.value = '';
    try {
        const formData = new FormData();
        formData.append('file', file);
        const res = await window.axios.post('/plataforma/configuracoes/suporte-painel/upload', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        props.form.seller_panel_support_icon_image = res.data?.url ?? '';
        props.form.seller_panel_support_icon = 'custom';
    } catch (e) {
        uploadError.value = e?.response?.data?.message
            || e?.response?.data?.errors?.file?.[0]
            || 'Não foi possível enviar a imagem.';
    } finally {
        uploading.value = false;
    }
}

async function clearIcon() {
    uploadError.value = '';
    try {
        await window.axios.post('/plataforma/configuracoes/suporte-painel/clear-icon');
        props.form.seller_panel_support_icon_image = '';
        if (props.form.seller_panel_support_icon === 'custom') {
            props.form.seller_panel_support_icon = 'whatsapp';
        }
    } catch (e) {
        uploadError.value = e?.response?.data?.message || 'Não foi possível remover a imagem.';
    }
}
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            >
                <Headset class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Suporte do painel</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Botão flutuante no canto inferior direito do painel do infoprodutor e da equipe.
                </p>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <label class="flex cursor-pointer items-center gap-3">
                <input
                    v-model="form.seller_panel_support_enabled"
                    type="checkbox"
                    class="h-4 w-4 rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500"
                    true-value="1"
                    false-value="0"
                />
                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Ativar botão de suporte no painel</span>
            </label>

            <div v-if="form.seller_panel_support_enabled === '1'" class="mt-6 space-y-5">
                <fieldset class="space-y-3">
                    <legend class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Destino do link</legend>
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                        <input
                            v-model="form.seller_panel_support_destination"
                            type="radio"
                            value="whatsapp"
                            class="text-emerald-600 focus:ring-emerald-500"
                        />
                        Número do WhatsApp
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                        <input
                            v-model="form.seller_panel_support_destination"
                            type="radio"
                            value="url"
                            class="text-emerald-600 focus:ring-emerald-500"
                        />
                        Link personalizado
                    </label>
                </fieldset>

                <div v-if="form.seller_panel_support_destination === 'whatsapp'">
                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">WhatsApp (com DDD)</label>
                    <input
                        v-model="form.seller_panel_support_whatsapp"
                        type="text"
                        inputmode="tel"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        placeholder="11999998888"
                    />
                </div>
                <div v-else>
                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">URL do link</label>
                    <input
                        v-model="form.seller_panel_support_url"
                        type="url"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        placeholder="https://wa.me/5511999999999 ou outro link"
                    />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Ícone</label>
                        <select
                            v-model="form.seller_panel_support_icon"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        >
                            <option value="whatsapp">WhatsApp</option>
                            <option value="message">Mensagem</option>
                            <option value="headset">Headset</option>
                            <option value="help">Ajuda</option>
                            <option value="custom">Imagem personalizada</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor de fundo</label>
                        <div class="flex items-center gap-2">
                            <input
                                v-model="form.seller_panel_support_color"
                                type="color"
                                class="h-10 w-12 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600"
                            />
                            <input
                                v-model="form.seller_panel_support_color"
                                type="text"
                                class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                placeholder="#25D366"
                            />
                        </div>
                    </div>
                </div>

                <div v-if="form.seller_panel_support_icon === 'custom'" class="space-y-3">
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Imagem do ícone</label>
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600">
                            <Upload class="h-4 w-4" />
                            <span>{{ uploading ? 'Enviando…' : 'Enviar imagem' }}</span>
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                class="sr-only"
                                :disabled="uploading"
                                @change="onFileSelected"
                            />
                        </label>
                        <Button
                            v-if="form.seller_panel_support_icon_image"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="clearIcon"
                        >
                            <Trash2 class="mr-1 h-4 w-4" />
                            Remover
                        </Button>
                    </div>
                    <p v-if="uploadError" class="text-sm text-red-600 dark:text-red-400">{{ uploadError }}</p>
                    <p class="text-xs text-zinc-500">PNG, JPG, WebP ou SVG. Máximo 2 MB.</p>
                </div>

                <div class="relative mt-4 flex min-h-36 items-end justify-end rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50">
                    <p class="absolute left-4 top-4 text-xs text-zinc-500">Pré-visualização (canto inferior direito do painel)</p>
                    <SellerSupportFab :config="previewConfig" variant="inline" />
                </div>
            </div>
        </div>
    </section>
</template>
