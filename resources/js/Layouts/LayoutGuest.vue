<script setup>
import { Link } from '@inertiajs/vue3';
import ThemeToggler from '@/components/layout/ThemeToggler.vue';
import UserMenu from '@/components/layout/UserMenu.vue';
</script>

<template>
    <div class="min-h-screen bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100 transition-colors">
        <header class="sticky top-0 z-10 border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto flex h-14 max-w-7xl items-center justify-between gap-4 px-4">
                <div class="flex min-w-0 items-center gap-4">
                    <span class="truncate text-lg font-semibold text-zinc-900 dark:text-white">
                        Minha conta
                    </span>
                    <Link
                        href="/painel-cliente"
                        class="hidden text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white sm:inline"
                    >
                        Minhas compras
                    </Link>
                    <Link
                        href="/area-membros"
                        class="hidden text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white sm:inline"
                    >
                        Área de membros
                    </Link>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <ThemeToggler />
                    <UserMenu />
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-7xl px-4 py-8">
            <slot />
        </main>
    </div>
</template>
