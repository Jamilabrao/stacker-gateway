<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';

const props = defineProps({
    siteKey: { type: String, required: true },
    modelValue: { type: String, default: '' },
    /** interaction-only no checkout; always no login/cadastro para o checkbox aparecer */
    appearance: { type: String, default: 'interaction-only' },
    theme: { type: String, default: 'auto' },
    size: { type: String, default: 'flexible' },
});

const emit = defineEmits(['update:modelValue', 'ready', 'error']);

const containerRef = ref(null);
const loadError = ref('');
const loading = ref(true);
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

function onWidgetError(message = '') {
    loadError.value =
        message ||
        'Não foi possível carregar a verificação. Confira o domínio no painel Cloudflare Turnstile e recarregue a página.';
    loading.value = false;
    emit('update:modelValue', '');
    emit('error', loadError.value);
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

    loadError.value = '';
    loading.value = true;

    try {
        widgetId = window.turnstile.render(containerRef.value, {
            sitekey: props.siteKey,
            appearance: props.appearance,
            theme: props.theme,
            size: props.size,
            callback: (token) => {
                loading.value = false;
                emit('update:modelValue', token);
                emit('ready', token);
            },
            'expired-callback': () => {
                emit('update:modelValue', '');
            },
            'error-callback': () => {
                onWidgetError();
            },
        });
        if (widgetId === undefined || widgetId === null) {
            onWidgetError();
        }
    } catch (err) {
        onWidgetError(err instanceof Error ? err.message : String(err));
    }
}

onMounted(async () => {
    try {
        await loadTurnstileScript();
        renderWidget();
    } catch (_) {
        onWidgetError('Falha ao carregar o script do Cloudflare Turnstile.');
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
    <div class="w-full">
        <div
            ref="containerRef"
            class="w-full"
            :class="loadError ? '' : appearance === 'always' ? 'min-h-[72px]' : 'min-h-[65px]'"
            aria-label="Verificação de segurança"
        />
        <p v-if="loading && !loadError" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Carregando verificação de segurança…
        </p>
        <p v-if="loadError" class="mt-2 text-sm text-red-600 dark:text-red-400">
            {{ loadError }}
        </p>
    </div>
</template>
