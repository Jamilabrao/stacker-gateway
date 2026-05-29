<script setup>
import { computed } from 'vue';
import {
    SelectContent,
    SelectItem,
    SelectItemIndicator,
    SelectItemText,
    SelectPortal,
    SelectRoot,
    SelectTrigger,
    SelectViewport,
} from 'radix-vue';
import { Eye, EyeOff, ChevronDown, Check, Calendar } from 'lucide-vue-next';

const props = defineProps({
    period: { type: String, required: true },
    periodOptions: { type: Array, required: true },
    valuesVisible: { type: Boolean, required: true },
    periodLabel: { type: String, default: 'Período' },
    hideValuesLabel: { type: String, default: 'Ocultar valores' },
    showValuesLabel: { type: String, default: 'Mostrar valores' },
});

const emit = defineEmits(['update:period', 'toggle-values']);

function formatLongDate(date) {
    return date.toLocaleDateString('pt-BR', { day: 'numeric', month: 'long', year: 'numeric' });
}

const periodDisplayLabel = computed(() => {
    const now = new Date();

    if (props.period === 'hoje') {
        return formatLongDate(now);
    }

    if (props.period === 'ontem') {
        const yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);
        return formatLongDate(yesterday);
    }

    const selected = props.periodOptions.find((opt) => opt.value === props.period);
    return selected?.label ?? props.periodLabel;
});
</script>

<template>
    <div class="kawaii-period-shell kawaii-period-shell--compact">
        <div class="flex w-full items-center justify-end gap-2">
            <button
                type="button"
                :aria-label="valuesVisible ? hideValuesLabel : showValuesLabel"
                class="kawaii-icon-btn flex h-9 w-9 shrink-0 items-center justify-center"
                @click="emit('toggle-values')"
            >
                <Eye v-if="valuesVisible" class="h-4 w-4" aria-hidden="true" />
                <EyeOff v-else class="h-4 w-4" aria-hidden="true" />
            </button>

            <SelectRoot :model-value="period" @update:model-value="emit('update:period', $event)">
                <SelectTrigger
                    type="button"
                    :aria-label="periodLabel"
                    class="kawaii-date-picker-btn shrink-0"
                >
                    <Calendar class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span class="truncate">{{ periodDisplayLabel }}</span>
                    <ChevronDown class="ml-1 h-3 w-3 shrink-0 opacity-50" aria-hidden="true" />
                </SelectTrigger>
                <SelectPortal to="body">
                    <SelectContent
                        class="kawaii-select-content z-[99999] min-w-[var(--radix-select-trigger-width)] overflow-hidden border py-1 shadow-xl"
                        :side-offset="4"
                        position="popper"
                        :avoid-collisions="true"
                    >
                        <SelectViewport class="p-1">
                            <SelectItem
                                v-for="opt in periodOptions"
                                :key="opt.value"
                                :value="opt.value"
                                class="relative flex cursor-pointer select-none items-center rounded-xl py-2.5 pl-10 pr-4 text-sm font-semibold outline-none transition data-[highlighted]:bg-[var(--kawaii-accent-soft)] data-[highlighted]:text-[var(--kawaii-accent-text)]"
                            >
                                <SelectItemIndicator class="absolute left-3 flex h-4 w-4 items-center justify-center">
                                    <Check class="h-4 w-4 text-[var(--kawaii-accent-text)]" />
                                </SelectItemIndicator>
                                <SelectItemText>{{ opt.label }}</SelectItemText>
                            </SelectItem>
                        </SelectViewport>
                    </SelectContent>
                </SelectPortal>
            </SelectRoot>
        </div>
    </div>
</template>
