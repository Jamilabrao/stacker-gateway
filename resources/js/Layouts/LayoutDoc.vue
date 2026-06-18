<script setup>
import { Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import PlatformBrandLogo from '@/components/branding/PlatformBrandLogo.vue';

defineProps({
    showBack: { type: Boolean, default: true },
});

const page = usePage();

const docTitle = () => page.props.pageTitle || 'Documentação da API';
const docSubtitle = () => page.props.docSubtitle || '';
const docsHomeHref = () => (page.props.publicMode ? '/docs/api-pagamentos' : '/aplicacoes-api');
</script>

<template>
    <div class="min-h-screen bg-zinc-950 text-zinc-100">
        <header class="sticky top-0 z-20 border-b border-white/5 bg-zinc-950/90 backdrop-blur-md">
            <div class="mx-auto flex h-14 max-w-[1600px] items-center justify-between gap-4 px-4 sm:px-6">
                <div class="flex min-w-0 flex-1 items-center gap-3 sm:gap-4">
                    <Link
                        :href="docsHomeHref()"
                        class="flex shrink-0 items-center rounded-lg py-1 transition hover:opacity-90"
                        :title="docTitle()"
                    >
                        <PlatformBrandLogo
                            theme="dark"
                            img-class="h-9 max-w-[160px] object-contain object-left sm:h-10 sm:max-w-[200px]"
                            icon-class="h-8 w-8 shrink-0 object-contain sm:h-9 sm:w-9"
                        />
                    </Link>

                    <div class="hidden h-8 w-px shrink-0 bg-white/10 sm:block" />

                    <div class="min-w-0 flex-1 sm:flex-none">
                        <p class="truncate text-xs font-semibold tracking-tight text-white sm:text-sm">
                            {{ docTitle() }}
                        </p>
                        <p
                            v-if="docSubtitle()"
                            class="hidden truncate text-xs text-zinc-500 sm:block"
                        >
                            {{ docSubtitle() }}
                        </p>
                    </div>

                    <Link
                        v-if="showBack && !page.props.publicMode"
                        href="/aplicacoes-api"
                        class="ml-auto flex shrink-0 items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm font-medium text-zinc-400 transition hover:border-teal-500/30 hover:bg-white/10 hover:text-white sm:ml-0"
                    >
                        <ArrowLeft class="h-4 w-4" />
                        <span class="hidden md:inline">Voltar ao painel</span>
                    </Link>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-[1600px] px-4 py-0 sm:px-6">
            <slot />
        </main>
    </div>
</template>
