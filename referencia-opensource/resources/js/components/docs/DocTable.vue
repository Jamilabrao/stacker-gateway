<script setup>
defineProps({
    columns: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="my-8 overflow-hidden rounded-xl border border-white/10 text-sm">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        class="border-b border-white/10 bg-white/5 px-4 py-3 text-left font-semibold text-zinc-200"
                    >
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, i) in rows"
                    :key="i"
                    class="transition hover:bg-white/[0.02]"
                >
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        class="border-b border-white/5 px-4 py-3 text-zinc-400"
                    >
                        <slot
                            v-if="$slots.cell"
                            name="cell"
                            :column="col"
                            :row="row"
                            :value="row[col.key]"
                        >
                            {{ row[col.key] }}
                        </slot>
                        <template v-else>
                            {{ row[col.key] }}
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
