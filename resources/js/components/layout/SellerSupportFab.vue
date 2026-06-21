<script setup>
import { computed, ref, watch } from 'vue';
import { MessageCircle, Headset, HelpCircle } from 'lucide-vue-next';
import WhatsAppIcon from '@/components/icons/WhatsAppIcon.vue';
import { safeHttpHref, safeHttpSrc } from '@/lib/safeUrl';
import { usePwaInstall } from '@/composables/usePwaInstall';

const props = defineProps({
    config: { type: Object, default: () => ({}) },
    /** fixed = painel (Teleport + fixed); inline = preview/admin sem posicionamento */
    variant: { type: String, default: 'fixed' },
});

const { isStandalone } = usePwaInstall('painel');

function isTruthyEnabled(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

const href = computed(() => safeHttpHref(props.config?.href, ''));
const isVisible = computed(() => isTruthyEnabled(props.config?.enabled) && href.value !== '');
const backgroundColor = computed(() => props.config?.color || '#25D366');

const imageFailed = ref(false);

watch(
    () => props.config?.icon_url,
    () => {
        imageFailed.value = false;
    },
);

const customSrc = computed(() => safeHttpSrc(props.config?.icon_url));
const showCustomImage = computed(
    () => props.config?.icon === 'custom' && customSrc.value !== '' && !imageFailed.value,
);

const iconComponent = computed(() => {
    const icon = showCustomImage.value ? 'whatsapp' : (props.config?.icon || 'whatsapp');
    if (icon === 'message') {
        return MessageCircle;
    }
    if (icon === 'headset') {
        return Headset;
    }
    if (icon === 'help') {
        return HelpCircle;
    }
    return WhatsAppIcon;
});

const isFixed = computed(() => props.variant === 'fixed');

const anchorClass = computed(() => [
    'seller-support-fab',
    isFixed.value ? 'seller-support-fab--fixed' : 'seller-support-fab--inline',
    isFixed.value && isStandalone.value ? 'seller-support-fab--pwa' : '',
    showCustomImage.value
        ? 'seller-support-fab--image ring-2 ring-white/90 dark:ring-white/30'
        : 'text-white',
]);

const anchorStyle = computed(() => (showCustomImage.value ? undefined : { backgroundColor: backgroundColor.value }));

function onImageError() {
    imageFailed.value = true;
}
</script>

<template>
    <Teleport v-if="isFixed" to="body">
        <a
            v-if="isVisible"
            :href="href"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Suporte"
            :class="anchorClass"
            :style="anchorStyle"
        >
            <span v-if="showCustomImage" class="seller-support-fab__media">
                <img
                    :src="customSrc"
                    alt=""
                    class="h-full w-full object-cover"
                    @error="onImageError"
                />
            </span>
            <component :is="iconComponent" v-else class="h-6 w-6" />
            <span
                v-if="showCustomImage"
                class="seller-support-fab__online"
                aria-hidden="true"
            />
        </a>
    </Teleport>
    <a
        v-else-if="isVisible"
        :href="href"
        target="_blank"
        rel="noopener noreferrer"
        aria-label="Suporte"
        :class="anchorClass"
        :style="anchorStyle"
        @click.prevent
    >
        <span v-if="showCustomImage" class="seller-support-fab__media">
            <img
                :src="customSrc"
                alt=""
                class="h-full w-full object-cover"
                @error="onImageError"
            />
        </span>
        <component :is="iconComponent" v-else class="h-6 w-6" />
        <span
            v-if="showCustomImage"
            class="seller-support-fab__online"
            aria-hidden="true"
        />
    </a>
</template>

<style scoped>
.seller-support-fab {
    display: flex;
    height: 3.5rem;
    width: 3.5rem;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    transition: transform 0.15s ease, opacity 0.15s ease;
}

.seller-support-fab:hover {
    transform: scale(1.05);
    opacity: 0.95;
}

.seller-support-fab--fixed {
    position: fixed;
    right: max(1rem, env(safe-area-inset-right, 0px));
    bottom: calc(1.25rem + env(safe-area-inset-bottom, 0px));
    z-index: 100000;
}

@media (min-width: 1024px) {
    .seller-support-fab--fixed {
        right: max(1.5rem, env(safe-area-inset-right, 0px));
        bottom: calc(1.5rem + env(safe-area-inset-bottom, 0px));
    }
}

.seller-support-fab--fixed.seller-support-fab--pwa {
    bottom: calc(4.75rem + env(safe-area-inset-bottom, 0px));
}

.seller-support-fab--inline {
    position: relative;
}

.seller-support-fab--image {
    overflow: visible;
}

.seller-support-fab__media {
    display: block;
    height: 100%;
    width: 100%;
    overflow: hidden;
    border-radius: 9999px;
}

.seller-support-fab__online {
    position: absolute;
    top: 8px;
    left: 8px;
    z-index: 2;
    height: 0.5rem;
    width: 0.5rem;
    border-radius: 9999px;
    background-color: #25d366;
    box-shadow: 0 0 0 1.5px #fff;
    transform: translate(-50%, -50%);
}

:global(.dark) .seller-support-fab__online {
    box-shadow: 0 0 0 1.5px rgb(24 24 27);
}
</style>
