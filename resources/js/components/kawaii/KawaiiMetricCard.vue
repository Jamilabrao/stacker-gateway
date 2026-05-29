<script setup>
import { computed } from 'vue';
import { ChevronRight } from 'lucide-vue-next';

const props = defineProps({
    icon: { type: Object, required: true },
    label: { type: String, required: true },
    value: { type: String, required: true },
    footer: { type: String, default: '' },
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['hero', 'default', 'stacked'].includes(v),
    },
    tint: {
        type: String,
        default: 'green',
        validator: (v) => ['green', 'purple', 'blue', 'yellow', 'pink', 'orange', 'sky', ''].includes(v),
    },
});

const starPositions = computed(() => {
    const map = {
        green: [
            { top: '12%', left: '8%', size: 10, rotate: -12, opacity: 0.55 },
            { top: '22%', left: '18%', size: 7, rotate: 18, opacity: 0.4 },
            { top: '8%', right: '38%', size: 8, rotate: 8, opacity: 0.35 },
        ],
        purple: [
            { top: '10%', left: '10%', size: 9, rotate: -8, opacity: 0.5 },
            { top: '18%', right: '42%', size: 11, rotate: 14, opacity: 0.45 },
            { top: '28%', left: '22%', size: 7, rotate: -20, opacity: 0.35 },
        ],
        blue: [
            { top: '14%', left: '12%', size: 8, rotate: 10, opacity: 0.4 },
            { top: '6%', right: '40%', size: 9, rotate: -15, opacity: 0.35 },
        ],
        yellow: [
            { top: '10%', left: '8%', size: 10, rotate: -10, opacity: 0.55 },
            { top: '20%', left: '20%', size: 8, rotate: 22, opacity: 0.45 },
            { top: '12%', right: '36%', size: 9, rotate: 5, opacity: 0.4 },
            { top: '26%', right: '48%', size: 6, rotate: -18, opacity: 0.35 },
        ],
        pink: [],
        orange: [],
        sky: [],
    };
    return map[props.tint] ?? [];
});

const starColor = computed(() => {
    const map = {
        green: '#38bdf8',
        purple: 'var(--color-primary)',
        blue: '#60a5fa',
        yellow: '#fb923c',
    };
    return map[props.tint] ?? 'var(--color-primary)';
});

const iconWrapClass = computed(() => `kawaii-icon-wrap kawaii-icon-wrap--${props.tint}`);
const valueClass = computed(() => `kawaii-metric-value kawaii-metric-value--${props.tint}`);
const stackedIconClass = computed(() => `kawaii-stacked-icon kawaii-stacked-icon--${props.tint === 'sky' ? 'blue' : props.tint}`);
</script>

<template>
    <!-- Cards empilhados (coluna direita) -->
    <div
        v-if="variant === 'stacked'"
        class="kawaii-stacked-card group cursor-default"
        :class="[
            tint === 'pink' ? 'kawaii-stacked-card--pink' : '',
            tint === 'orange' ? 'kawaii-stacked-card--orange' : '',
            tint === 'sky' || tint === 'blue' ? 'kawaii-stacked-card--blue' : '',
        ]"
    >
        <span :class="[stackedIconClass, 'flex h-11 w-11 shrink-0 items-center justify-center']">
            <component :is="icon" class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="kawaii-metric-label">{{ label }}</p>
            <p :class="[valueClass, 'mt-1 text-xl']">{{ value }}</p>
        </div>
        <ChevronRight class="kawaii-fg-subtle h-5 w-5 shrink-0 opacity-60 transition group-hover:translate-x-0.5" aria-hidden="true" />
    </div>

    <!-- KPI cards principais -->
    <div
        v-else
        class="kawaii-metric-card"
        :class="[
            tint === 'green' || variant === 'hero' ? 'kawaii-metric-card--green' : '',
            tint === 'purple' ? 'kawaii-metric-card--purple' : '',
            tint === 'blue' ? 'kawaii-metric-card--blue' : '',
            tint === 'yellow' ? 'kawaii-metric-card--yellow' : '',
        ]"
    >
        <!-- Estrelas decorativas -->
        <span
            v-for="(star, i) in starPositions"
            :key="i"
            class="kawaii-sparkle pointer-events-none absolute"
            :style="{
                top: star.top,
                left: star.left,
                right: star.right,
                width: `${star.size}px`,
                height: `${star.size}px`,
                transform: `rotate(${star.rotate}deg)`,
                opacity: star.opacity,
                color: starColor,
            }"
            aria-hidden="true"
        >
            <svg viewBox="0 0 24 24" fill="currentColor" class="h-full w-full">
                <path d="M12 1.5l2.8 7.6L23 12l-8.2 2.9L12 22.5l-2.8-7.6L1 12l8.2-2.9L12 1.5z" />
            </svg>
        </span>

        <!-- Ícone -->
        <div class="kawaii-metric-icon-area kawaii-metric-icon-area--no-cloud" aria-hidden="true">
            <span :class="[iconWrapClass, 'kawaii-metric-icon-badge']">
                <component :is="icon" class="h-5 w-5" />
            </span>
        </div>

        <!-- Conteúdo -->
        <div class="kawaii-metric-body">
            <p class="kawaii-metric-label">{{ label }}</p>
            <p :class="valueClass">{{ value }}</p>
            <p v-if="footer" class="kawaii-metric-footer">{{ footer }}</p>
        </div>
    </div>
</template>
