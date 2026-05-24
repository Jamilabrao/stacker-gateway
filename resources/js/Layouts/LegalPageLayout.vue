<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import CookieConsentBanner from '@/components/legal/CookieConsentBanner.vue';

const page = usePage();
const branding = () => page.props.public_branding ?? {};
</script>

<template>
    <div class="min-h-screen bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
        <header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <Link href="/" class="text-sm font-semibold text-zinc-800 hover:text-[var(--color-primary)] dark:text-zinc-200">
                    {{ branding().app_name || 'Início' }}
                </Link>
                <nav class="flex gap-4 text-sm">
                    <Link href="/termos-de-uso" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                        Termos
                    </Link>
                    <Link
                        href="/politica-privacidade"
                        class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                    >
                        Privacidade
                    </Link>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
            <slot />
        </main>

        <CookieConsentBanner />
    </div>
</template>
