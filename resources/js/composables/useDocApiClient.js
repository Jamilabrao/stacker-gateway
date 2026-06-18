import { computed, ref, watch } from 'vue';

const STORAGE_KEY = 'doc_api_playground_auth';

function loadStoredAuth() {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return null;
        }

        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function persistAuth(payload) {
    try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    } catch {
        //
    }
}

/**
 * Cliente HTTP para o playground da documentação API (same-origin fetch).
 *
 * @param {import('vue').Ref<string>|string} baseUrl
 */
export function useDocApiClient(baseUrl) {
    const stored = loadStoredAuth();

    const authMode = ref(stored?.authMode === 'legacy' ? 'legacy' : 'keypair');
    const publicKey = ref(stored?.publicKey ?? '');
    const secretKey = ref(stored?.secretKey ?? '');
    const legacyToken = ref(stored?.legacyToken ?? '');
    const loading = ref(false);
    const lastResponse = ref(null);

    watch(
        [authMode, publicKey, secretKey, legacyToken],
        () => {
            persistAuth({
                authMode: authMode.value,
                publicKey: publicKey.value,
                secretKey: secretKey.value,
                legacyToken: legacyToken.value,
            });
        },
        { deep: true }
    );

    const apiBase = computed(() => {
        const raw = typeof baseUrl === 'object' && baseUrl?.value != null ? baseUrl.value : baseUrl;
        const base = String(raw || '').replace(/\/$/, '');

        return base ? `${base}/api/v1` : '/api/v1';
    });

    function buildAuthHeaders(extraHeaders = {}) {
        const headers = { ...extraHeaders };

        if (authMode.value === 'legacy') {
            const token = legacyToken.value.trim();
            if (token) {
                headers.Authorization = `Bearer ${token}`;
            }

            return headers;
        }

        const pub = publicKey.value.trim();
        const sec = secretKey.value.trim();
        if (pub) {
            headers['X-Public-Key'] = pub;
        }
        if (sec) {
            headers['X-Secret-Key'] = sec;
        }

        return headers;
    }

    function resolvePath(path, pathParams = {}) {
        let resolved = path;
        for (const [key, value] of Object.entries(pathParams)) {
            const encoded = encodeURIComponent(String(value ?? ''));
            resolved = resolved.replace(`{${key}}`, encoded);
        }

        return resolved;
    }

    /**
     * @param {{
     *   method: string,
     *   path: string,
     *   body?: object|null,
     *   headers?: Record<string, string>,
     *   pathParams?: Record<string, string|number>,
     * }} options
     */
    async function sendRequest({ method, path, body = null, headers = {}, pathParams = {} }) {
        const resolvedPath = resolvePath(path, pathParams);
        const url = `${apiBase.value}${resolvedPath}`;
        const upperMethod = method.toUpperCase();

        lastResponse.value = null;
        loading.value = true;

        const started = performance.now();

        try {
            const requestHeaders = buildAuthHeaders({
                ...headers,
            });

            const opts = {
                method: upperMethod,
                headers: requestHeaders,
            };

            if (body != null && ['POST', 'PUT', 'PATCH'].includes(upperMethod)) {
                requestHeaders['Content-Type'] = requestHeaders['Content-Type'] || 'application/json';
                opts.body = JSON.stringify(body);
            }

            const res = await fetch(url, opts);
            const durationMs = Math.round(performance.now() - started);
            const text = await res.text();

            let data = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch {
                data = text;
            }

            const response = {
                method: upperMethod,
                path: resolvedPath,
                url,
                status: res.status,
                ok: res.ok,
                data,
                error: res.ok ? null : (typeof data === 'object' && data?.message ? data.message : res.statusText),
                durationMs,
            };

            lastResponse.value = response;

            return response;
        } catch (e) {
            const durationMs = Math.round(performance.now() - started);
            const response = {
                method: upperMethod,
                path: resolvedPath,
                url,
                status: null,
                ok: false,
                data: null,
                error: e?.message || 'Erro de rede',
                durationMs,
            };
            lastResponse.value = response;

            return response;
        } finally {
            loading.value = false;
        }
    }

    return {
        authMode,
        publicKey,
        secretKey,
        legacyToken,
        loading,
        lastResponse,
        apiBase,
        buildAuthHeaders,
        resolvePath,
        sendRequest,
    };
}
