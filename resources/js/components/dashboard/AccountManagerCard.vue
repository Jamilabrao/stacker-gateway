<script setup>
defineProps({
    manager: { type: Object, required: true },
});
</script>

<template>
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Gerente de conta</h2>
        <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center">
            <img
                v-if="manager.photo_url"
                :src="manager.photo_url"
                :alt="manager.name"
                class="h-16 w-16 shrink-0 rounded-full object-cover"
            />
            <div
                v-else
                class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-xl font-semibold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200"
            >
                {{ (manager.name || '?').charAt(0).toUpperCase() }}
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ manager.name }}</p>
                <p v-if="manager.email" class="mt-0.5 break-all text-sm text-zinc-600 dark:text-zinc-400">{{ manager.email }}</p>
                <p v-if="manager.phone_display || manager.phone" class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ manager.phone_display || manager.phone }}
                </p>
            </div>
            <a
                v-if="manager.whatsapp_url"
                :href="manager.whatsapp_url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex shrink-0 items-center justify-center rounded-xl bg-[#25D366] px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90"
            >
                Falar no WhatsApp
            </a>
        </div>
    </section>
</template>
