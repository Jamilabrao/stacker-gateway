<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
    points: { type: Array, default: () => [] },
    metric: { type: String, default: 'conversions' },
});

const mapEl = ref(null);
let map = null;
let layer = null;

const valueOf = (p) => {
    if (props.metric === 'revenue') return Number(p.revenue || 0);
    if (props.metric === 'events') return Number(p.events || 0);
    return Number(p.conversions || 0);
};

const maxValue = computed(() => Math.max(...props.points.map(valueOf), 1));

function money(v) {
    return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function radiusFor(p) {
    const v = valueOf(p);
    if (v <= 0) return 6;
    const ratio = Math.sqrt(v / maxValue.value);
    return Math.max(8, Math.min(42, 8 + ratio * 34));
}

function renderPoints() {
    if (!map) return;
    if (layer) {
        layer.clearLayers();
    } else {
        layer = L.layerGroup().addTo(map);
    }

    const bounds = [];
    props.points.forEach((p) => {
        const lat = Number(p.lat);
        const lng = Number(p.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        bounds.push([lat, lng]);
        const hasConv = Number(p.conversions || 0) > 0;
        const circle = L.circleMarker([lat, lng], {
            radius: radiusFor(p),
            color: hasConv ? '#047857' : '#2563eb',
            fillColor: hasConv ? '#10b981' : '#3b82f6',
            fillOpacity: 0.55,
            weight: 1.5,
        });
        circle.bindPopup(`
            <div style="min-width:160px;font-size:12px;line-height:1.4">
                <strong>${p.label || 'Local'}</strong><br/>
                Eventos: ${p.events || 0}<br/>
                Conversões: ${p.conversions || 0}<br/>
                Receita: ${money(p.revenue)}
            </div>
        `);
        circle.addTo(layer);
    });

    if (bounds.length) {
        map.fitBounds(bounds, { padding: [28, 28], maxZoom: 11 });
    } else {
        map.setView([-14.235, -51.9253], 4);
    }
}

onMounted(() => {
    if (!mapEl.value) return;
    map = L.map(mapEl.value, {
        scrollWheelZoom: true,
        zoomControl: true,
    }).setView([-14.235, -51.9253], 4);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 18,
    }).addTo(map);

    renderPoints();
    setTimeout(() => map?.invalidateSize(), 80);
});

watch(() => [props.points, props.metric], () => {
    renderPoints();
}, { deep: true });

onBeforeUnmount(() => {
    if (map) {
        map.remove();
        map = null;
        layer = null;
    }
});
</script>

<template>
    <div class="relative overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div ref="mapEl" class="h-[460px] w-full bg-zinc-100 dark:bg-zinc-900" />
        <div class="pointer-events-none absolute bottom-3 left-3 rounded-lg bg-white/90 px-3 py-2 text-[11px] text-zinc-700 shadow dark:bg-zinc-900/90 dark:text-zinc-200">
            Verde = com conversão · Azul = só tráfego · Tamanho ∝ {{ metric === 'revenue' ? 'receita' : metric === 'events' ? 'eventos' : 'conversões' }}
        </div>
    </div>
</template>
