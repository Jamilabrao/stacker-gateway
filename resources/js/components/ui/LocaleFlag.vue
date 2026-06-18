<script setup>
import { computed, ref, watch } from 'vue';
import { localeFlagSrc, localeToFlagEmoji } from '@/lib/localeFlag';

const props = defineProps({
    locale: { type: String, default: '' },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md'].includes(v),
    },
});

const imgFailed = ref(false);

watch(
    () => props.locale,
    () => {
        imgFailed.value = false;
    },
);

const src = computed(() => localeFlagSrc(props.locale));
const imgClass = computed(() =>
    props.size === 'md'
        ? 'h-[18px] w-[26px] rounded-[3px] object-cover shadow-sm'
        : 'h-4 w-6 rounded-[2px] object-cover shadow-sm',
);
const emojiClass = computed(() => (props.size === 'md' ? 'text-xl' : 'text-base'));
</script>

<template>
    <img
        v-if="src && !imgFailed"
        :src="src"
        :alt="localeToFlagEmoji(locale)"
        :class="imgClass"
        loading="lazy"
        decoding="async"
        @error="imgFailed = true"
    />
    <span v-else :class="[emojiClass, 'leading-none select-none']" aria-hidden="true">{{ localeToFlagEmoji(locale) }}</span>
</template>
