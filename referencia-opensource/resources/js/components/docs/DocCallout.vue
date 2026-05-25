<script setup>
import { AlertCircle, Info, Lightbulb } from 'lucide-vue-next';

const props = defineProps({
    type: { type: String, default: 'info' },
    title: { type: String, default: '' },
});

const icons = {
    info: Info,
    warning: AlertCircle,
    tip: Lightbulb,
};
const typeStyles = {
    info: 'border-teal-500/30 bg-teal-500/10 text-teal-400/90',
    warning: 'border-amber-500/30 bg-amber-500/10 text-amber-400/90',
    tip: 'border-sky-500/30 bg-sky-500/10 text-sky-400/90',
};
const icon = icons[props.type] || Info;
const style = typeStyles[props.type] || typeStyles.info;
</script>

<template>
    <div
        class="doc-callout my-6 rounded-xl border px-4 py-4"
        :class="style"
    >
        <div class="flex gap-3">
            <component
                :is="icon"
                class="h-5 w-5 shrink-0 mt-0.5"
            />
            <div class="min-w-0 flex-1">
                <p v-if="title" class="font-semibold mb-1">
                    {{ title }}
                </p>
                <div class="text-sm leading-relaxed text-zinc-300">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
