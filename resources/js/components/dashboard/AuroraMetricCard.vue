<script setup>
import { ChevronRight } from 'lucide-vue-next';

defineProps({
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
        default: '',
        validator: (v) => ['', 'purple', 'orange', 'blue'].includes(v),
    },
});
</script>

<template>
    <div
        v-if="variant === 'stacked'"
        class="aurora-stacked-card"
        :class="[
            tint === 'purple' ? 'aurora-tint-purple' : '',
            tint === 'orange' ? 'aurora-tint-orange' : '',
            tint === 'blue' ? 'aurora-tint-blue' : '',
        ]"
    >
        <div class="aurora-icon-box shrink-0">
            <component :is="icon" class="h-4 w-4" aria-hidden="true" />
        </div>
        <div class="min-w-0 flex-1">
            <p class="aurora-card-label text-[10px] font-bold uppercase tracking-widest">
                {{ label }}
            </p>
            <p class="aurora-card-value mt-0.5 text-lg font-bold leading-none">
                {{ value }}
            </p>
            <p v-if="footer" class="aurora-card-footer mt-1 text-[11px]">
                {{ footer }}
            </p>
        </div>
        <ChevronRight class="aurora-fg-subtle h-4 w-4 shrink-0 ml-2" />
    </div>

    <div
        v-else
        class="aurora-card p-5"
        :class="variant === 'hero' ? 'aurora-card-hero' : ''"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <p class="aurora-card-label text-[10px] font-bold uppercase tracking-widest">
                    {{ label }}
                </p>
                <p class="aurora-card-value mt-2 text-[26px] font-bold leading-none tracking-tight">
                    {{ value }}
                </p>
                <p v-if="footer" class="aurora-card-footer mt-2 text-[11px]">
                    {{ footer }}
                </p>
            </div>
            <span class="aurora-icon-circle h-10 w-10 shrink-0">
                <component :is="icon" class="h-4 w-4" aria-hidden="true" />
            </span>
        </div>
    </div>
</template>
