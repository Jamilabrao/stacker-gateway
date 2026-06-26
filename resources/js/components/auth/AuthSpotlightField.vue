<script setup>
defineProps({
    id: { type: String, default: '' },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    modelValue: { type: [String, Number], default: '' },
    placeholder: { type: String, default: '' },
    autocomplete: { type: String, default: '' },
    required: { type: Boolean, default: false },
    maxlength: { type: [String, Number], default: undefined },
    inputmode: { type: String, default: undefined },
    error: { type: String, default: '' },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div>
        <label
            :for="id"
            class="mb-1.5 block text-[11px] font-semibold uppercase tracking-wider text-zinc-500"
        >
            {{ label }}
        </label>
        <div class="relative">
            <span
                v-if="$slots.icon"
                class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500"
            >
                <slot name="icon" />
            </span>
            <input
                :id="id"
                :type="type"
                :value="modelValue"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :required="required"
                :maxlength="maxlength"
                :inputmode="inputmode"
                class="wl-spotlight-input block w-full rounded-xl border border-zinc-300 bg-white py-3.5 text-sm text-zinc-900 placeholder-zinc-400 transition focus:border-[var(--wl-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--wl-primary)]/20 dark:border-zinc-700/70 dark:bg-[#141414] dark:text-white dark:placeholder-zinc-600"
                :class="[
                    $slots.icon && $slots.trailing ? 'pl-11 pr-11' : $slots.icon ? 'pl-11 pr-4' : $slots.trailing ? 'pl-4 pr-11' : 'px-4',
                ]"
                @input="$emit('update:modelValue', $event.target.value)"
            />
            <span v-if="$slots.trailing" class="absolute right-3 top-1/2 -translate-y-1/2">
                <slot name="trailing" />
            </span>
        </div>
        <p v-if="error" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>
</template>

<style scoped>
.wl-spotlight-input:focus {
    border-color: var(--wl-primary, #0050fc);
}
</style>
