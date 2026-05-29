<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Award, Sparkles, CheckCircle2, ArrowRight, BookOpen, Calendar } from 'lucide-vue-next';

const props = defineProps({
    slug: { type: String, required: true },
});

const page = usePage();
const cert = computed(() => page.props.member_certificate ?? { enabled: false });
const release = computed(() => cert.value.release ?? {});

const certHref = computed(() => `/m/${props.slug}/certificado`);

const status = computed(() => {
    if (!cert.value.enabled) return 'hidden';
    if (cert.value.ready) return 'ready';
    if (cert.value.issued) return 'issued';
    return 'progress';
});

const completionProgress = computed(() => {
    const pct = cert.value.progress_percent ?? 0;
    const req = cert.value.required_percent ?? 100;
    if (req <= 0) return 100;
    return Math.min(100, Math.round((pct / req) * 100));
});

const daysProgress = computed(() => {
    const need = release.value.days_after_access ?? 0;
    const elapsed = release.value.days_elapsed ?? 0;
    if (need <= 0) return 100;
    return Math.min(100, Math.round((elapsed / need) * 100));
});

const showCompletionBar = computed(() => {
    const mode = release.value.mode || 'completion_percent';
    return mode === 'completion_percent' || mode === 'both';
});

const showDaysBar = computed(() => {
    const mode = release.value.mode || 'completion_percent';
    return mode === 'days_after_access' || mode === 'both';
});

const headline = computed(() => {
    if (status.value === 'ready') return 'Seu certificado está pronto!';
    if (status.value === 'issued') return 'Certificado conquistado';
    return 'Seu certificado de conclusão';
});

const subtitle = computed(() => {
    if (status.value === 'ready') {
        return 'Você cumpriu os requisitos. Acesse a página do certificado para visualizar e imprimir.';
    }
    if (status.value === 'issued') {
        return 'Parabéns! Seu certificado já foi emitido — visualize ou imprima quando quiser.';
    }

    const mode = release.value.mode || 'completion_percent';
    const pct = cert.value.progress_percent ?? 0;
    const req = cert.value.required_percent ?? 100;
    const daysLeft = release.value.days_remaining ?? 0;
    const daysNeed = release.value.days_after_access ?? 0;
    const daysElapsed = release.value.days_elapsed ?? 0;

    if (mode === 'days_after_access') {
        if (daysLeft > 0) {
            return `Faltam ${daysLeft} dia(s) de acesso (${daysElapsed}/${daysNeed}). O certificado fica em Meu certificado no menu.`;
        }
        return 'Aguarde o prazo de acesso. Você encontra o certificado em Meu certificado no menu superior.';
    }
    if (mode === 'both') {
        return `Complete o curso e aguarde o prazo de acesso. Acompanhe abaixo — o certificado fica em Meu certificado no menu.`;
    }
    return `Complete ${req}% do curso para liberar (hoje: ${pct}%). Acesse pelo menu Meu certificado.`;
});

const ctaLabel = computed(() => {
    if (status.value === 'ready') return 'Emitir certificado agora';
    if (status.value === 'issued') return 'Ver e imprimir certificado';
    return 'Ir para o certificado';
});
</script>

<template>
    <section
        v-if="status !== 'hidden'"
        class="certificate-highlight relative overflow-hidden rounded-2xl border p-5 sm:p-6"
        :class="[
            status === 'ready'
                ? 'border-amber-400/50 bg-gradient-to-br from-amber-500/15 via-[var(--ma-primary)]/10 to-transparent shadow-lg shadow-amber-500/10'
                : status === 'issued'
                    ? 'border-emerald-500/40 bg-gradient-to-br from-emerald-500/15 via-[var(--ma-primary)]/8 to-transparent'
                    : 'border-zinc-600/80 bg-gradient-to-br from-zinc-800/90 via-zinc-800/50 to-zinc-900/80',
        ]"
        aria-labelledby="certificate-highlight-title"
    >
        <div
            class="pointer-events-none absolute -right-8 -top-8 h-32 w-32 rounded-full opacity-20 blur-2xl"
            :style="{ backgroundColor: 'var(--ma-primary)' }"
            aria-hidden="true"
        />
        <div
            v-if="status === 'ready'"
            class="pointer-events-none absolute -left-4 top-1/2 h-24 w-24 -translate-y-1/2 rounded-full bg-amber-400/20 blur-2xl"
            aria-hidden="true"
        />

        <div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 flex-1 gap-4">
                <div
                    class="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl shadow-inner"
                    :class="status === 'ready' ? 'certificate-medal-pulse bg-amber-500/25 text-amber-300' : status === 'issued' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-[var(--ma-primary)]/20 text-[var(--ma-primary)]'"
                >
                    <Sparkles
                        v-if="status === 'ready'"
                        class="absolute -right-1 -top-1 h-4 w-4 text-amber-300"
                        aria-hidden="true"
                    />
                    <CheckCircle2 v-if="status === 'issued'" class="h-7 w-7" />
                    <Award v-else class="h-7 w-7" />
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-400">
                        Área do aluno · Certificado
                    </p>
                    <h2 id="certificate-highlight-title" class="mt-0.5 text-lg font-bold sm:text-xl">
                        {{ headline }}
                    </h2>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        {{ subtitle }}
                    </p>

                    <div v-if="status === 'progress'" class="mt-4 space-y-3">
                        <div v-if="showCompletionBar" class="space-y-1.5">
                            <div class="flex items-center justify-between gap-2 text-xs text-zinc-500">
                                <span class="inline-flex items-center gap-1.5">
                                    <BookOpen class="h-3.5 w-3.5" />
                                    Progresso do curso
                                </span>
                                <span>{{ cert.progress_percent ?? 0 }}% / {{ cert.required_percent ?? 100 }}%</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-zinc-700/80">
                                <div
                                    class="h-full rounded-full transition-all duration-500"
                                    :style="{
                                        width: `${completionProgress}%`,
                                        backgroundColor: release.percent_met ? '#34d399' : 'var(--ma-primary)',
                                    }"
                                />
                            </div>
                        </div>
                        <div v-if="showDaysBar" class="space-y-1.5">
                            <div class="flex items-center justify-between gap-2 text-xs text-zinc-500">
                                <span class="inline-flex items-center gap-1.5">
                                    <Calendar class="h-3.5 w-3.5" />
                                    Tempo de acesso
                                </span>
                                <span>
                                    {{ release.days_elapsed ?? 0 }}/{{ release.days_after_access ?? 0 }} dias
                                </span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-zinc-700/80">
                                <div
                                    class="h-full rounded-full transition-all duration-500"
                                    :style="{
                                        width: `${daysProgress}%`,
                                        backgroundColor: release.days_met ? '#34d399' : 'var(--ma-primary)',
                                    }"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <Link
                :href="certHref"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ma-primary)] focus-visible:ring-offset-2 focus-visible:ring-offset-zinc-900"
                :class="
                    status === 'ready'
                        ? 'bg-amber-500 text-zinc-900 hover:bg-amber-400 shadow-md shadow-amber-500/25'
                        : status === 'issued'
                            ? 'border border-emerald-500/50 bg-emerald-500/15 text-emerald-300 hover:bg-emerald-500/25'
                            : 'border border-zinc-600 bg-zinc-800 text-zinc-100 hover:border-[var(--ma-primary)]/50 hover:bg-zinc-700'
                "
            >
                {{ ctaLabel }}
                <ArrowRight class="h-4 w-4" />
            </Link>
        </div>
    </section>
</template>

<style scoped>
.certificate-medal-pulse {
    animation: cert-medal-glow 2.5s ease-in-out infinite;
}
@keyframes cert-medal-glow {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.35);
    }
    50% {
        box-shadow: 0 0 20px 4px rgba(251, 191, 36, 0.25);
    }
}
</style>
