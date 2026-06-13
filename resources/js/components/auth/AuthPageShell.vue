<script setup>
import { computed } from 'vue';
import { useLoginTemplate } from '@/composables/useLoginTemplate';
import { useAuthTheme } from '@/composables/useAuthTheme';
import AuthPageShellDefault from '@/components/auth/AuthPageShellDefault.vue';
import AuthPageShellSpotlight from '@/components/auth/AuthPageShellSpotlight.vue';
import AuthPageShellImmersive from '@/components/auth/AuthPageShellImmersive.vue';

const props = defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    variant: {
        type: String,
        default: 'seller',
        validator: (v) => ['seller', 'platform', 'neutral'].includes(v),
    },
    wide: { type: Boolean, default: false },
});

const { isSpotlight, isImmersive } = useLoginTemplate();

// Sincroniza tema global (panel_color_scheme) nas telas de auth — ignora localStorage do painel
useAuthTheme();

const shellComponent = computed(() => {
    if (isImmersive.value) {
        return AuthPageShellImmersive;
    }
    if (isSpotlight.value) {
        return AuthPageShellSpotlight;
    }
    return AuthPageShellDefault;
});
</script>

<template>
    <component
        :is="shellComponent"
        :title="title"
        :subtitle="subtitle"
        :variant="variant"
        :wide="wide"
    >
        <slot />
        <template v-if="$slots.footer" #footer>
            <slot name="footer" />
        </template>
    </component>
</template>
