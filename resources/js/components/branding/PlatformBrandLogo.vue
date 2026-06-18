<script setup>
import { computed } from 'vue';
import { Shield } from 'lucide-vue-next';
import { usePlatformBranding } from '@/composables/usePlatformBranding';

const props = defineProps({
    imgClass: { type: String, default: 'h-10 max-w-[200px] object-contain object-left' },
    iconClass: { type: String, default: 'h-8 w-8 shrink-0 object-contain' },
    /** auto = light/dark via classes do painel; dark = fundo escuro fixo (docs) */
    theme: { type: String, default: 'auto' },
});

const { branding, appName, hasLogoFull, hasLogoIcon } = usePlatformBranding();

const fullLogoSrc = computed(() => {
    const b = branding.value;
    if (props.theme === 'dark') {
        return b.app_logo_dark || b.app_logo || '';
    }

    return '';
});

const iconLogoSrc = computed(() => {
    const b = branding.value;
    if (props.theme === 'dark') {
        return b.app_logo_icon_dark || b.app_logo_icon || '';
    }

    return '';
});
</script>

<template>
    <template v-if="theme === 'dark'">
        <img
            v-if="hasLogoFull && fullLogoSrc"
            :src="fullLogoSrc"
            :alt="appName"
            :class="imgClass"
        />
        <img
            v-else-if="hasLogoIcon && iconLogoSrc"
            :src="iconLogoSrc"
            :alt="appName"
            :class="iconClass"
        />
        <Shield v-else class="h-8 w-8 shrink-0 text-teal-400" />
    </template>

    <template v-else>
        <template v-if="hasLogoFull">
            <img
                v-if="branding.app_logo"
                :src="branding.app_logo"
                :alt="appName"
                :class="[imgClass, branding.app_logo_dark ? 'dark:hidden' : '']"
            />
            <img
                v-if="branding.app_logo_dark"
                :src="branding.app_logo_dark"
                :alt="appName"
                :class="['hidden dark:block', imgClass]"
            />
        </template>
        <template v-else-if="hasLogoIcon">
            <img
                v-if="branding.app_logo_icon"
                :src="branding.app_logo_icon"
                :alt="appName"
                :class="[iconClass, branding.app_logo_icon_dark ? 'dark:hidden' : '']"
            />
            <img
                v-if="branding.app_logo_icon_dark"
                :src="branding.app_logo_icon_dark"
                :alt="appName"
                :class="['hidden dark:block', iconClass]"
            />
        </template>
        <Shield v-else class="h-8 w-8 shrink-0 text-[var(--color-primary)]" />
    </template>
</template>
