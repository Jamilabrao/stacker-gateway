<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    tab: { type: String, default: 'dashboard' },
    period: { type: String, default: 'hoje' },
    date_from: { type: String, default: null },
    date_to: { type: String, default: null },
    filters: { type: Object, default: () => ({}) },
    basePath: { type: String, default: '/metricas' },
    sellers: { type: Array, default: () => [] },
    sellerId: { type: [Number, String], default: null },
});

const tabs = computed(() => {
    const all = [
        { id: 'dashboard', label: 'Dashboard', href: props.basePath },
        { id: 'origins', label: 'Origem das vendas', href: `${props.basePath}/origem` },
        { id: 'funnel', label: 'Funil', href: `${props.basePath}/funil` },
        { id: 'clicks', label: 'Cliques', href: `${props.basePath}/cliques` },
        { id: 'map', label: 'Mapa', href: `${props.basePath}/mapa` },
    ];
    const showMap = props.basePath === '/metricas' || props.basePath.startsWith('/plataforma/metricas');

    return showMap ? all : all.filter((t) => t.id !== 'map');
});

const periodOptions = [
    { value: 'hoje', label: 'Hoje' },
    { value: 'ontem', label: 'Ontem' },
    { value: '7dias', label: '7 dias' },
    { value: '30dias', label: '30 dias' },
    { value: 'mes', label: 'Mês atual' },
    { value: 'mes_anterior', label: 'Mês anterior' },
    { value: 'personalizado', label: 'Personalizado' },
];

const isPlatform = computed(() => props.basePath.startsWith('/plataforma'));

const queryBase = computed(() => {
    const q = { period: props.period, ...props.filters };
    if (props.period === 'personalizado') {
        if (props.date_from) q.date_from = props.date_from;
        if (props.date_to) q.date_to = props.date_to;
    }
    if (isPlatform.value && props.sellerId) {
        q.seller_id = props.sellerId;
    }
    Object.keys(q).forEach((k) => {
        if (q[k] === null || q[k] === undefined || q[k] === '') delete q[k];
    });
    return q;
});

function currentPath() {
    return tabs.value.find((t) => t.id === props.tab)?.href || props.basePath;
}

function tabHref(href) {
    const params = new URLSearchParams();
    Object.entries(queryBase.value).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') params.set(k, String(v));
    });
    const qs = params.toString();
    return qs ? `${href}?${qs}` : href;
}

function setPeriod(value) {
    const path = currentPath();
    const q = { ...queryBase.value, period: value };
    if (value === 'personalizado') {
        const today = new Date();
        const first = new Date(today.getFullYear(), today.getMonth(), 1);
        const fmt = (d) => d.toISOString().slice(0, 10);
        q.date_from = props.date_from || fmt(first);
        q.date_to = props.date_to || fmt(today);
    } else {
        delete q.date_from;
        delete q.date_to;
    }
    router.get(path, q, { preserveState: false });
}

function setSeller(sellerId) {
    const path = currentPath();
    const q = { ...queryBase.value };
    if (sellerId) q.seller_id = sellerId;
    else delete q.seller_id;
    router.get(path, q, { preserveState: false });
}

defineExpose({ queryBase, setPeriod, setSeller, periodOptions });
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap gap-2 border-b border-zinc-200 pb-3 dark:border-zinc-700">
            <Link
                v-for="t in tabs"
                :key="t.id"
                :href="tabHref(t.href)"
                class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                :class="tab === t.id
                    ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                    : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
            >
                {{ t.label }}
            </Link>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="p in periodOptions"
                :key="p.value"
                type="button"
                class="rounded-full px-3 py-1 text-xs font-medium transition-colors"
                :class="period === p.value
                    ? 'bg-emerald-600 text-white'
                    : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700'"
                @click="setPeriod(p.value)"
            >
                {{ p.label }}
            </button>

            <select
                v-if="isPlatform && sellers.length"
                class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                :value="sellerId || ''"
                @change="setSeller($event.target.value || null)"
            >
                <option value="">Todos os infoprodutores</option>
                <option v-for="s in sellers" :key="s.id" :value="s.id">{{ s.label }}</option>
            </select>

            <slot name="filters" />
        </div>
    </div>
</template>
