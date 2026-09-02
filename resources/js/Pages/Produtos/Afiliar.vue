<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import { useI18n } from '@/composables/useI18n';
import { Loader2 } from 'lucide-vue-next';

const { t } = useI18n();
const page = usePage();
const branding = computed(() => page.props.public_branding ?? {});
const primary = computed(() => branding.value.theme_primary || '#0050fc');
const appName = computed(() => branding.value.app_name || 'Getfy');
const logoLight = computed(() => branding.value.app_logo || branding.value.app_logo_icon || '/images/logo.png');
const logoDark = computed(() => branding.value.app_logo_dark || branding.value.app_logo_icon_dark || logoLight.value);
const heroImage = computed(() => branding.value.login_hero_image || 'https://cdn.getfy.cloud/login.webp');
const flash = computed(() => page.props.flash ?? {});

const props = defineProps({
    invalid: { type: Boolean, default: false },
    message: { type: String, default: '' },
    token: { type: String, default: '' },
    program_open: { type: Boolean, default: false },
    is_own_product: { type: Boolean, default: false },
    can_request: { type: Boolean, default: false },
    auth_email: { type: String, default: null },
    is_guest: { type: Boolean, default: true },
    is_seller: { type: Boolean, default: false },
    login_url: { type: String, default: '/login' },
    product: { type: Object, default: null },
    enrollment: { type: Object, default: null },
});

const soliciting = ref(false);
const copied = ref(false);

function formatMoney(v, cur) {
    const n = Number(v);
    if (cur === 'USD') return `US$ ${n.toFixed(2)}`;
    if (cur === 'EUR') return `€ ${n.toFixed(2)}`;
    return `R$ ${n.toFixed(2).replace('.', ',')}`;
}

function solicit() {
    if (!props.token || soliciting.value) return;
    soliciting.value = true;
    router.post(`/afiliar/${props.token}`, {}, {
        preserveScroll: true,
        onFinish: () => {
            soliciting.value = false;
        },
    });
}

function copyLink(url) {
    if (!url) return;
    navigator.clipboard.writeText(url).then(() => {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    }).catch(() => {});
}
</script>

<template>
    <div class="wl-root flex min-h-screen">
        <div class="flex w-full flex-col justify-center px-8 py-12 lg:w-[36%] lg:min-w-[380px]">
            <div class="text-center">
                <img :src="logoLight" :alt="appName" class="mx-auto mb-8 h-12 w-auto object-contain dark:hidden" />
                <img :src="logoDark" :alt="appName" class="mx-auto mb-8 hidden h-12 w-auto object-contain dark:block" />
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ t('products.join.title', 'Afiliação') }}</h1>
            </div>

            <div
                v-if="invalid"
                class="mt-8 rounded-xl border border-zinc-200 bg-white p-6 text-center text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
            >
                {{ message || t('products.join.invalid', 'Este link de afiliação não é válido.') }}
            </div>

            <div
                v-else-if="product"
                class="mt-8 space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800"
            >
                <div v-if="product.image_url" class="aspect-video w-full overflow-hidden rounded-xl bg-zinc-200 dark:bg-zinc-700">
                    <img :src="product.image_url" :alt="product.name" class="h-full w-full object-cover" />
                </div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ product.name }}</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ t('products.join.producer', 'Produtor') }}: {{ product.producer_name }}
                </p>
                <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ formatMoney(product.price, product.currency) }}</p>
                <p class="text-sm text-emerald-600 dark:text-emerald-400">
                    {{ t('products.join.you_receive', 'Você recebe até') }}
                    R$ {{ product.commission_max_formatted }} ({{ product.affiliate_commission_percent }}%)
                </p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ product.affiliate_manual_approval
                        ? t('products.join.manual_approval', 'O produtor aprova as solicitações manualmente.')
                        : t('products.join.auto_approval', 'A afiliação é aprovada automaticamente.') }}
                </p>
                <p v-if="product.affiliate_showcase_description" class="whitespace-pre-wrap text-sm text-zinc-600 dark:text-zinc-300">
                    {{ product.affiliate_showcase_description }}
                </p>

                <div
                    v-if="flash.error || flash.success || flash.info"
                    class="rounded-lg px-3 py-2 text-sm"
                    :class="
                        flash.error
                            ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200'
                            : flash.info
                              ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200'
                              : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200'
                    "
                >
                    {{ flash.error || flash.success || flash.info }}
                </div>

                <div v-if="enrollment?.status === 'approved' && enrollment.affiliate_link" class="space-y-2">
                    <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ t('products.showcase.your_link', 'Seu link de afiliação') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <code class="max-w-full flex-1 truncate rounded bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-800">{{ enrollment.affiliate_link }}</code>
                        <Button type="button" size="sm" variant="outline" @click="copyLink(enrollment.affiliate_link)">
                            {{ copied ? t('common.copy', 'Copiar') + ' ✓' : t('common.copy', 'Copiar') }}
                        </Button>
                    </div>
                </div>

                <div class="space-y-3 pt-2">
                    <p v-if="is_own_product" class="text-center text-sm text-zinc-600 dark:text-zinc-400">
                        {{ t('products.join.own_product', 'Este é o seu produto. Gerencie afiliados na edição.') }}
                    </p>
                    <Link
                        v-if="is_own_product"
                        :href="`/produtos/${product.id}/edit?tab=afiliados`"
                        class="flex w-full items-center justify-center rounded-lg bg-zinc-100 px-4 py-2.5 text-sm font-medium text-zinc-900 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-white dark:hover:bg-zinc-700"
                    >
                        {{ t('products.showcase.edit_affiliate_settings', 'Abrir configurações de afiliados') }}
                    </Link>

                    <p v-else-if="!program_open" class="text-center text-sm text-zinc-600 dark:text-zinc-400">
                        {{ t('products.join.unavailable', 'Este produto não está aceitando afiliados no momento.') }}
                    </p>

                    <template v-else-if="is_guest">
                        <a
                            :href="login_url"
                            class="flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-white hover:opacity-90"
                            :style="{ backgroundColor: primary }"
                        >
                            {{ t('products.join.login', 'Entrar para solicitar afiliação') }}
                        </a>
                    </template>

                    <p v-else-if="!is_seller" class="text-center text-sm text-zinc-600 dark:text-zinc-400">
                        {{ t('products.join.not_seller', 'Entre com uma conta de infoprodutor para se afiliar.') }}
                    </p>

                    <Button
                        v-else-if="can_request"
                        type="button"
                        class="w-full"
                        :disabled="soliciting"
                        :style="{ backgroundColor: primary }"
                        @click="solicit"
                    >
                        <Loader2 v-if="soliciting" class="mr-2 h-4 w-4 animate-spin" />
                        {{ soliciting ? t('common.sending', 'Enviando...') : t('products.join.request', 'Solicitar afiliação') }}
                    </Button>

                    <template v-else-if="enrollment?.status === 'pending'">
                        <p class="text-center text-sm text-amber-700 dark:text-amber-300">
                            {{ t('products.join.pending', 'Sua solicitação está aguardando aprovação do produtor.') }}
                        </p>
                        <Link
                            href="/produtos/afiliados"
                            class="flex w-full items-center justify-center rounded-lg border border-zinc-200 px-4 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            {{ t('products.showcase.go_affiliates_list', 'Ver em Afiliados') }}
                        </Link>
                    </template>

                    <template v-else-if="enrollment?.status === 'approved'">
                        <p class="text-center text-sm font-medium text-emerald-600 dark:text-emerald-400">
                            {{ t('products.join.approved', 'Você já é afiliado deste produto.') }}
                        </p>
                        <Link
                            :href="`/produtos/${product.id}/painel-afiliado`"
                            class="flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-white hover:opacity-90"
                            :style="{ backgroundColor: primary }"
                        >
                            {{ t('products.join.go_panel', 'Abrir painel do afiliado') }}
                        </Link>
                    </template>
                </div>
            </div>
        </div>
        <div class="relative hidden flex-1 lg:block">
            <img :src="heroImage" alt="" class="absolute inset-0 h-full w-full object-cover" />
        </div>
    </div>
</template>
