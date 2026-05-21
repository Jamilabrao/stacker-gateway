<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';

const props = defineProps({
    siteKey: { type: String, required: true },
    modelValue: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const containerRef = ref(null);
let widgetId = null;
let scriptLoading = null;

function loadTurnstileScript() {
    if (typeof window === 'undefined') {
        return Promise.resolve();
    }
    if (window.turnstile) {
        return Promise.resolve();
    }
    if (scriptLoading) {
        return scriptLoading;
    }
    scriptLoading = new Promise((resolve, reject) => {
        const existing = document.querySelector('script[data-turnstile="1"]');
        if (existing) {
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', reject);
            return;
        }
        const script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true;
        script.defer = true;
        script.dataset.turnstile = '1';
        script.onload = () => resolve();
        script.onerror = reject;
        document.head.appendChild(script);
    });

    return scriptLoading;
}

function renderWidget() {
    if (!containerRef.value || !window.turnstile || !props.siteKey) {
        return;
    }
    if (widgetId !== null) {
        try {
            window.turnstile.remove(widgetId);
        } catch (_) {
            /* ignore */
        }
        widgetId = null;
    }
    widgetId = window.turnstile.render(containerRef.value, {
        sitekey: props.siteKey,
        appearance: 'interaction-only',
        size: 'flexible',
        callback: (token) => emit('update:modelValue', token),
        'expired-callback': () => emit('update:modelValue', ''),
        'error-callback': () => emit('update:modelValue', ''),
    });
}

onMounted(async () => {
    try {
        await loadTurnstileScript();
        renderWidget();
    } catch (_) {
        emit('update:modelValue', '');
    }
});

watch(
    () => props.siteKey,
    async () => {
        await loadTurnstileScript();
        renderWidget();
    }
);

onBeforeUnmount(() => {
    if (widgetId !== null && window.turnstile) {
        try {
            window.turnstile.remove(widgetId);
        } catch (_) {
            /* ignore */
        }
    }
});

function reset() {
    if (widgetId !== null && window.turnstile) {
        window.turnstile.reset(widgetId);
    }
    emit('update:modelValue', '');
}

defineExpose({ reset });
</script>

<template>
    <div ref="containerRef" class="min-h-[65px] w-full" aria-label="Verificação de segurança" />
</template>
