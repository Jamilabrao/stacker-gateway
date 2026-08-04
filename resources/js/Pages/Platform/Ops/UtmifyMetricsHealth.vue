<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';
import {
    Activity, AlertTriangle, CheckCircle2, Clock, Info, Link2, XCircle,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const { pageClass, innerPanelClass, filterPanelClass } = usePanelThemeClasses();

const props = defineProps({
    dashboard: { type: Object, required: true },
    days: { type: Number, default: 7 },
    seller_id: { type: [Number, String], default: null },
});

const kpis = computed(() => props.dashboard.kpis ?? {});
const infra = computed(() => props.dashboard.infrastructure ?? {});
const lag = computed(() => props.dashboard.lag ?? {});
const issues = computed(() => props.dashboard.issues ?? []);
const sellers = computed(() => props.dashboard.sellers ?? []);

const severityStyles = {
    critical: 'border-red-200 bg-red-50 text-red-900 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-100',
    warning: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100',
    info: 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/40 dark:bg-sky-950/30 dark:text-sky-100',
};

function money(v) {
    return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('pt-BR');
    } catch {
        return iso;
    }
}

function fmtSeconds(s) {
    if (s == null) return '—';
    const n = Number(s);
    if (n < 60) return `${n}s`;
    if (n < 3600) return `${Math.round(n / 60)} min`;
    return `${(n / 3600).toFixed(1)} h`;
}

function reload(extra = {}) {
    const q = {
        days: props.days,
        seller_id: props.seller_id || undefined,
        ...extra,
    };
    Object.keys(q).forEach((k) => { if (q[k] === '' || q[k] == null) delete q[k]; });
    router.get('/plataforma/ops/saude-utmify', q, { preserveState: false });
}
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader
            title="Saúde UTMify × Métricas"
            subtitle="Comparação local: eventos internos vs dispatches enviados à UTMify (write-only)."
        />

        <div class="mt-4 space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <select
                    class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                    :value="days"
                    @change="reload({ days: Number($event.target.value) })"
                >
                    <option :value="1">Último dia</option>
                    <option :value="7">7 dias</option>
                    <option :value="14">14 dias</option>
                    <option :value="30">30 dias</option>
                    <option :value="90">90 dias</option>
                </select>
                <select
                    class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                    :value="seller_id || ''"
                    @change="reload({ seller_id: $event.target.value || null })"
                >
                    <option value="">Todos os infoprodutores</option>
                    <option v-for="s in sellers" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
                <p class="text-xs text-zinc-500">{{ dashboard.note }}</p>
            </div>

            <div v-if="issues.length" class="space-y-2">
                <div
                    v-for="(issue, idx) in issues"
                    :key="idx"
                    class="rounded-xl border px-4 py-3 text-sm"
                    :class="severityStyles[issue.severity] || severityStyles.info"
                >
                    <div class="flex items-start gap-2">
                        <AlertTriangle v-if="issue.severity === 'critical' || issue.severity === 'warning'" class="mt-0.5 h-4 w-4 shrink-0" />
                        <Info v-else class="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <div class="font-semibold">{{ issue.title }}</div>
                            <div class="mt-0.5 opacity-90">{{ issue.detail }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else :class="[innerPanelClass, 'flex items-center gap-2 p-4 text-sm text-emerald-700 dark:text-emerald-300']">
                <CheckCircle2 class="h-4 w-4" />
                Nenhum alerta no período — cobertura e fila ok.
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                <div :class="[innerPanelClass, 'p-3']">
                    <div class="text-xs text-zinc-500">Metrics approved</div>
                    <div class="text-xl font-semibold">{{ kpis.metrics_payment_approved || 0 }}</div>
                </div>
                <div :class="[innerPanelClass, 'p-3']">
                    <div class="text-xs text-zinc-500">Orders completed</div>
                    <div class="text-xl font-semibold">{{ kpis.orders_completed || 0 }}</div>
                </div>
                <div :class="[innerPanelClass, 'p-3']">
                    <div class="text-xs text-zinc-500">UTMify paid/sent</div>
                    <div class="text-xl font-semibold text-emerald-600">{{ kpis.utmify_paid_sent || 0 }}</div>
                </div>
                <div :class="[innerPanelClass, 'p-3']">
                    <div class="text-xs text-zinc-500">Gap (sem paid/sent)</div>
                    <div class="text-xl font-semibold" :class="(kpis.gap_missing_paid_sent || 0) > 0 ? 'text-rose-600' : ''">
                        {{ kpis.gap_missing_paid_sent || 0 }}
                    </div>
                </div>
                <div :class="[innerPanelClass, 'p-3']">
                    <div class="text-xs text-zinc-500">Cobertura</div>
                    <div class="text-xl font-semibold">{{ kpis.coverage_pct || 0 }}%</div>
                </div>
                <div :class="[innerPanelClass, 'p-3']">
                    <div class="text-xs text-zinc-500">Paid failed</div>
                    <div class="text-xl font-semibold text-rose-600">{{ kpis.utmify_paid_failed || 0 }}</div>
                </div>
                <div :class="[innerPanelClass, 'p-3']">
                    <div class="text-xs text-zinc-500">Paid pending</div>
                    <div class="text-xl font-semibold">{{ kpis.utmify_paid_pending || 0 }}</div>
                </div>
                <div :class="[innerPanelClass, 'p-3']">
                    <div class="text-xs text-zinc-500">Waiting sent</div>
                    <div class="text-xl font-semibold">{{ kpis.utmify_waiting_sent || 0 }}</div>
                </div>
                <div :class="[innerPanelClass, 'p-3']">
                    <div class="text-xs text-zinc-500">PIX criados (metrics)</div>
                    <div class="text-xl font-semibold">{{ kpis.metrics_pix_created || 0 }}</div>
                </div>
                <div :class="[innerPanelClass, 'p-3']">
                    <div class="text-xs text-zinc-500">Failure rate</div>
                    <div class="text-xl font-semibold">{{ kpis.failure_rate_pct || 0 }}%</div>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div :class="[innerPanelClass, 'p-4 text-sm']">
                    <div class="mb-2 flex items-center gap-2 font-semibold"><Activity class="h-4 w-4" /> Infra</div>
                    <ul class="space-y-1 text-zinc-600 dark:text-zinc-300">
                        <li>Métricas internas: {{ infra.metrics_enabled ? 'ativas' : 'desligadas' }}</li>
                        <li>Integrações ativas: {{ infra.active_integrations }}</li>
                        <li>Tenants com UTMify: {{ infra.tenants_with_utmify }}</li>
                        <li>Fila <code>{{ infra.utmify_queue }}</code>: {{ infra.utmify_queue_size ?? '—' }}</li>
                    </ul>
                </div>
                <div :class="[innerPanelClass, 'p-4 text-sm']">
                    <div class="mb-2 flex items-center gap-2 font-semibold"><Clock class="h-4 w-4" /> Lag approved → sent</div>
                    <ul class="space-y-1 text-zinc-600 dark:text-zinc-300">
                        <li>Amostras: {{ lag.samples || 0 }}</li>
                        <li>Média: {{ fmtSeconds(lag.avg_seconds) }}</li>
                        <li>P50: {{ fmtSeconds(lag.p50_seconds) }}</li>
                        <li>P95: {{ fmtSeconds(lag.p95_seconds) }}</li>
                    </ul>
                </div>
                <div :class="[innerPanelClass, 'p-4 text-sm']">
                    <div class="mb-2 flex items-center gap-2 font-semibold"><Link2 class="h-4 w-4" /> Como ler</div>
                    <p class="text-zinc-600 dark:text-zinc-300">
                        Gap ≠ necessariamente bug: integração pode filtrar produtos, ou o pedido ainda estar pending na fila.
                        Falhas e pending antigos merecem atenção imediata.
                    </p>
                </div>
            </div>

            <div :class="[filterPanelClass, 'overflow-hidden']">
                <div class="border-b border-zinc-200 px-4 py-3 text-sm font-semibold dark:border-zinc-700">Por dia</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800/80">
                            <tr>
                                <th class="px-3 py-2">Dia</th>
                                <th class="px-3 py-2">Metrics approved</th>
                                <th class="px-3 py-2">UTMify paid/sent</th>
                                <th class="px-3 py-2">Gap</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in (dashboard.timeseries || [])" :key="r.bucket" class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="px-3 py-2">{{ r.bucket }}</td>
                                <td class="px-3 py-2">{{ r.metrics_approved }}</td>
                                <td class="px-3 py-2">{{ r.utmify_paid_sent }}</td>
                                <td class="px-3 py-2" :class="r.gap > 0 ? 'text-rose-600 font-medium' : ''">{{ r.gap }}</td>
                            </tr>
                            <tr v-if="!(dashboard.timeseries || []).length">
                                <td colspan="4" class="px-3 py-6 text-center text-zinc-500">Sem dados no período.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div :class="[filterPanelClass, 'overflow-hidden']">
                <div class="border-b border-zinc-200 px-4 py-3 text-sm font-semibold dark:border-zinc-700">Por infoprodutor</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800/80">
                            <tr>
                                <th class="px-3 py-2">Seller</th>
                                <th class="px-3 py-2">Approved</th>
                                <th class="px-3 py-2">Sent</th>
                                <th class="px-3 py-2">Failed</th>
                                <th class="px-3 py-2">Gap</th>
                                <th class="px-3 py-2">Cobertura</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in (dashboard.by_seller || [])" :key="r.tenant_id" class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="px-3 py-2">{{ r.seller }}</td>
                                <td class="px-3 py-2">{{ r.metrics_approved }}</td>
                                <td class="px-3 py-2">{{ r.utmify_paid_sent }}</td>
                                <td class="px-3 py-2">{{ r.utmify_paid_failed }}</td>
                                <td class="px-3 py-2" :class="r.gap > 0 ? 'text-rose-600 font-medium' : ''">{{ r.gap }}</td>
                                <td class="px-3 py-2">{{ r.coverage_pct }}%</td>
                            </tr>
                            <tr v-if="!(dashboard.by_seller || []).length">
                                <td colspan="6" class="px-3 py-6 text-center text-zinc-500">Sem dados.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div :class="[filterPanelClass, 'overflow-hidden']">
                    <div class="border-b border-zinc-200 px-4 py-3 text-sm font-semibold dark:border-zinc-700">
                        Completed sem paid/sent
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-zinc-50 uppercase text-zinc-500 dark:bg-zinc-800/80">
                                <tr>
                                    <th class="px-3 py-2">Pedido</th>
                                    <th class="px-3 py-2">Seller</th>
                                    <th class="px-3 py-2">Valor</th>
                                    <th class="px-3 py-2">Metrics?</th>
                                    <th class="px-3 py-2">Erro</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in (dashboard.missing_paid || [])" :key="r.order_id" class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2 font-mono">#{{ r.order_id }}</td>
                                    <td class="px-3 py-2">{{ r.seller }}</td>
                                    <td class="px-3 py-2">{{ money(r.amount) }}</td>
                                    <td class="px-3 py-2">
                                        <CheckCircle2 v-if="r.has_metrics_approved" class="inline h-3.5 w-3.5 text-emerald-600" />
                                        <XCircle v-else class="inline h-3.5 w-3.5 text-zinc-400" />
                                    </td>
                                    <td class="max-w-[220px] truncate px-3 py-2" :title="r.utmify_last_error">{{ r.utmify_last_error || '—' }}</td>
                                </tr>
                                <tr v-if="!(dashboard.missing_paid || []).length">
                                    <td colspan="5" class="px-3 py-6 text-center text-zinc-500">Nenhum gap listado.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div :class="[filterPanelClass, 'overflow-hidden']">
                    <div class="border-b border-zinc-200 px-4 py-3 text-sm font-semibold dark:border-zinc-700">
                        Dispatches failed / stuck
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-zinc-50 uppercase text-zinc-500 dark:bg-zinc-800/80">
                                <tr>
                                    <th class="px-3 py-2">Pedido</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2">Tent.</th>
                                    <th class="px-3 py-2">Quando</th>
                                    <th class="px-3 py-2">Erro</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in [...(dashboard.failed_dispatches || []), ...(dashboard.stuck_pending || [])]"
                                    :key="`${r.id}-${r.utmify_status}`"
                                    class="border-t border-zinc-100 dark:border-zinc-800"
                                >
                                    <td class="px-3 py-2 font-mono">#{{ r.order_id }}</td>
                                    <td class="px-3 py-2">{{ r.utmify_status }}</td>
                                    <td class="px-3 py-2">{{ r.attempts }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ fmtDate(r.updated_at) }}</td>
                                    <td class="max-w-[220px] truncate px-3 py-2" :title="r.last_error">{{ r.last_error || '—' }}</td>
                                </tr>
                                <tr v-if="!(dashboard.failed_dispatches || []).length && !(dashboard.stuck_pending || []).length">
                                    <td colspan="5" class="px-3 py-6 text-center text-zinc-500">Sem falhas recentes.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
