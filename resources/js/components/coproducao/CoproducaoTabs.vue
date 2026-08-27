<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Handshake, CircleDollarSign, ChartNoAxesCombined } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

const page = usePage();
const { t } = useI18n();
const { isAurora, isKawaii, themePrefix } = useSellerDashboardTemplate();

const path = computed(() => page.url.split('?')[0]);

const panelPath = '/' + ('co' + 'produ' + 'cao');
const legacyPath = '/produtos/' + ('co' + 'produ' + 'cao');
const metricsPath = '/produtos/' + ('co' + 'produ' + 'cao') + '/metricas';

const isPainel = computed(() => path.value === panelPath || path.value === legacyPath);
const isMetricas = computed(
    () => path.value === metricsPath || path.value.startsWith(metricsPath + '/')
);

const navClass = computed(() => {
    if (isAurora.value) return 'aurora-subnav';
    if (isKawaii.value) return 'kawaii-subnav';
    return 'inline-flex flex-wrap gap-1 rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80';
});

function linkClass(active) {
    if (themePrefix.value) {
        return [`${themePrefix.value}-subnav-item flex items-center gap-2`, active && `${themePrefix.value}-subnav-item-active`];
    }
    return [
        'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
        active
            ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
    ];
}
</script>

<template>
    <nav :class="navClass" :aria-label="t('sidebar.coproduction', 'Co-produção')">
        <Link :href="panelPath" :class="linkClass(isPainel)">
            <CircleDollarSign class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('coproduction.tab_panel', 'Painel') }}
        </Link>
        <Link :href="metricsPath" :class="linkClass(isMetricas)">
            <ChartNoAxesCombined class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('coproduction.tab_metrics', 'Métricas') }}
        </Link>
        <span class="hidden items-center gap-2 px-2 text-xs text-zinc-400 sm:inline-flex">
            <Handshake class="h-3.5 w-3.5" aria-hidden="true" />
            {{ t('coproduction.tab_hint', 'Suas participações') }}
        </span>
    </nav>
</template>
