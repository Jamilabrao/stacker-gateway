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
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Cloudflare Turnstile</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Proteção anti-bot em <code class="text-xs">/login</code>, <code class="text-xs">/plataforma/login</code> e
                    <code class="text-xs">/cadastro</code>. Configure as chaves uma vez e ative por tela.
                </p>
            </div>
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100">
            <p class="font-medium">Recomendado em produção</p>
            <p class="mt-2 text-xs">
                Em ambientes públicos, ative o Turnstile no login e no cadastro, além da
                <strong>verificação de e-mail</strong> abaixo, para reduzir abuso automatizado.
            </p>
        </div>

        <div
            class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
        >
            <div class="grid gap-4 sm:grid-cols-2">
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
            </div>
            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                No painel Cloudflare, crie o widget como <strong>Gerenciado</strong> e inclua o domínio da instalação
                (ex.: <code class="text-[11px]">app.seudominio.com.br</code>) em hostnames permitidos.
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <label class="flex cursor-pointer items-center gap-3">
                <input
                    v-model="form.login_turnstile_enabled"
                    type="checkbox"
                    class="h-4 w-4 rounded border-zinc-300 text-violet-600 focus:ring-violet-500"
                    true-value="1"
                    false-value="0"
                    :disabled="!form.turnstile_keys_configured"
                />
                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Ativar Turnstile no login</span>
            </label>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                Exibe o widget em <code class="text-[11px]">/login</code> (infoprodutor/cliente) e
                <code class="text-[11px]">/plataforma/login</code> (admin).
            </p>
            <p v-if="!form.turnstile_keys_configured" class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                Configure site key e secret key acima para habilitar.
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <label class="flex cursor-pointer items-center gap-3">
                <input
                    v-model="form.registration_turnstile_enabled"
                    type="checkbox"
                    class="h-4 w-4 rounded border-zinc-300 text-violet-600 focus:ring-violet-500"
                    true-value="1"
                    false-value="0"
                    :disabled="!form.turnstile_keys_configured"
                />
                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Ativar Turnstile no cadastro</span>
            </label>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                Exibe o widget na última etapa de <code class="text-[11px]">/cadastro</code> de infoprodutores.
            </p>
            <p v-if="!form.turnstile_keys_configured" class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                Configure site key e secret key acima para habilitar.
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <label class="flex cursor-pointer items-center gap-3">
                <input
                    v-model="form.registration_email_verification_enabled"
                    type="checkbox"
                    class="h-4 w-4 rounded border-zinc-300 text-violet-600 focus:ring-violet-500"
                    true-value="1"
                    false-value="0"
                />
                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Exigir verificação de e-mail no cadastro de infoprodutores</span>
            </label>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                Quando ativado, novos cadastros recebem um e-mail de confirmação com a identidade visual da plataforma.
                Contas já existentes são preservadas automaticamente.
            </p>
            <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                Requer provedor de e-mail configurado na aba E-mail.
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Cadastro de infoprodutores</h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Quando ativado, novos sellers e infoprodutores poderão acessar a página de cadastro e criar uma conta.
                    Quando desativado, apenas usuários já cadastrados poderão acessar a plataforma — convites e indicações
                    de sellers ativos continuam permitindo cadastro.
                </p>
            </div>
            <label class="flex cursor-pointer items-center gap-3">
                <input
                    v-model="form.allow_new_infoproducers"
                    type="checkbox"
                    class="h-4 w-4 rounded border-zinc-300 text-violet-600 focus:ring-violet-500"
                    true-value="1"
                    false-value="0"
                />
                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Aceitar novos infoprodutores</span>
            </label>
            <p
                class="inline-flex rounded-md px-2 py-1 text-xs font-medium"
                :class="
                    form.allow_new_infoproducers === '1'
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                        : 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200'
                "
            >
                {{ form.allow_new_infoproducers === '1' ? 'Cadastros abertos' : 'Cadastros fechados' }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Aprovação de produtos</h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Quando ativado, novos produtos ficam disponíveis para venda imediatamente após o cadastro. Quando
                    desativado, os produtos permanecem em análise até a decisão de um administrador.
                </p>
            </div>
            <label class="flex cursor-pointer items-center gap-3">
                <input
                    v-model="form.auto_approve_products"
                    type="checkbox"
                    class="h-4 w-4 rounded border-zinc-300 text-violet-600 focus:ring-violet-500"
                    true-value="1"
                    false-value="0"
                />
                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Aprovar produtos automaticamente</span>
            </label>
            <p
                class="inline-flex rounded-md px-2 py-1 text-xs font-medium"
                :class="
                    form.auto_approve_products === '1'
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                        : 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200'
                "
            >
                {{
                    form.auto_approve_products === '1'
                        ? 'Aprovação automática ativada'
                        : 'Aprovação manual ativada'
                }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Gerentes de conta</h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Define se novos infoprodutores recebem automaticamente um gerente ativo com menor carteira.
                </p>
            </div>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Atribuição automática</label>
            <select
                v-model="form.account_manager_auto_assign_mode"
                class="w-full max-w-md rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >
                <option value="least_load">Menor carteira (recomendado)</option>
                <option value="none">Desativada (apenas manual)</option>
            </select>
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100">
            <p class="font-medium">Rate limit (automático)</p>
            <ul class="mt-2 list-inside list-disc space-y-1 text-xs">
                <li>Login: até 10 tentativas por IP + e-mail por minuto</li>
                <li>Cadastro: até 3 contas por IP por hora</li>
                <li>Recuperação de senha: até 3 solicitações por IP por hora</li>
            </ul>
            <p class="mt-2 text-xs">Em produção com muito tráfego, use Redis como <code>CACHE_STORE</code> para contadores precisos.</p>
        </div>
    </section>
</template>
