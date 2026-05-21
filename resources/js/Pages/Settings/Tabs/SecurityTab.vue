<script setup>
import { Shield } from 'lucide-vue-next';

const props = defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300"
            >
                <Shield class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Segurança do checkout</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Proteção contra bots e flood em <code class="text-xs">/checkout</code>. Rate limits já estão ativos no servidor;
                    o Turnstile é opcional.
                </p>
            </div>
        </div>

        <div
            class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
        >
            <label class="flex cursor-pointer items-center gap-3">
                <input
                    v-model="form.checkout_turnstile_enabled"
                    type="checkbox"
                    class="h-4 w-4 rounded border-zinc-300 text-violet-600 focus:ring-violet-500"
                    true-value="1"
                    false-value="0"
                />
                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Ativar Cloudflare Turnstile no checkout</span>
            </label>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Site key (pública)</label>
                    <input
                        v-model="form.checkout_turnstile_site_key"
                        type="text"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        placeholder="0x4AAAAAAA..."
                        autocomplete="off"
                    />
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                        Secret key
                        <span
                            v-if="form.checkout_turnstile_secret_configured"
                            class="ml-1 text-emerald-600 dark:text-emerald-400"
                        >(configurada — deixe em branco para manter)</span>
                    </label>
                    <input
                        v-model="form.checkout_turnstile_secret_key"
                        type="password"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        placeholder="••••••••"
                        autocomplete="new-password"
                    />
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Modo no checkout</label>
                    <select
                        v-model="form.checkout_turnstile_mode"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                    >
                        <option value="disabled">Desativado (mesmo com toggle ligado, use para testes)</option>
                        <option value="pix_boleto">PIX e boleto apenas (recomendado)</option>
                        <option value="all_payments">Todos os métodos de pagamento</option>
                    </select>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        No painel Cloudflare, crie o widget como <strong>Managed</strong>. No site usamos
                        <code class="text-[11px]">appearance: interaction-only</code> — a maioria dos compradores não vê desafio;
                        só tráfego suspeito.
                    </p>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100">
            <p class="font-medium">Rate limit (automático)</p>
            <ul class="mt-2 list-inside list-disc space-y-1 text-xs">
                <li>PIX: até 3 tentativas por IP por minuto</li>
                <li>Checkout geral: até 10 pedidos por IP por minuto</li>
                <li>Pedidos pendentes duplicados: bloqueio por e-mail + produto</li>
            </ul>
            <p class="mt-2 text-xs">Em produção com muito tráfego, use Redis como <code>CACHE_STORE</code> para contadores precisos.</p>
        </div>
    </section>
</template>
