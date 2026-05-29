/**
 * useCajuPaySdk
 *
 * Carrega o SDK CajuPay (CDN) e monta o widget em `embeddedOnly`.
 * O backend Getfy devolve o token público após `POST /checkout` + `POST /checkout/cajupay/sdk-session`.
 */

const SDK_URL = 'https://cdn.cajupay.com.br/sdk/v1/cajupay-sdk.min.js';
const DEFAULT_API_BASE = 'https://api.cajupay.com.br';

let sdkPromise = null;

/**
 * @returns {Promise<typeof window.CajuPaySDK>}
 */
export function loadCajuPaySdk() {
    if (typeof window === 'undefined') {
        return Promise.reject(new Error('CajuPay SDK só pode ser carregado no navegador.'));
    }
    if (window.CajuPaySDK) {
        return Promise.resolve(window.CajuPaySDK);
    }
    if (sdkPromise) {
        return sdkPromise;
    }

    sdkPromise = new Promise((resolve, reject) => {
        const existing = document.querySelector(`script[src="${SDK_URL}"]`);
        const handle = (script) => {
            script.addEventListener('load', () => {
                if (window.CajuPaySDK) {
                    resolve(window.CajuPaySDK);
                } else {
                    sdkPromise = null;
                    reject(new Error('CajuPay SDK carregado, mas window.CajuPaySDK não existe.'));
                }
            });
            script.addEventListener('error', () => {
                sdkPromise = null;
                reject(new Error('Falha ao carregar o SDK da CajuPay.'));
            });
        };

        if (existing) {
            handle(existing);

            return;
        }

        const script = document.createElement('script');
        script.src = SDK_URL;
        script.async = true;
        handle(script);
        document.head.appendChild(script);
    });

    return sdkPromise;
}

/**
 * @param {string} containerSelector
 * @param {{ token: string, defaultMethod?: string, preparePaymentUIOnMount?: boolean, initialPayer?: object, baseUrl?: string, onStatus?: (event: any) => void }} opts
 */
export async function mountCajuPayCheckout(containerSelector, opts) {
    if (!opts || !opts.token) {
        throw new Error('CajuPay: token público da sessão é obrigatório.');
    }
    const sdk = await loadCajuPaySdk();
    if (!sdk?.init) {
        throw new Error('CajuPay SDK não expõe init().');
    }
    const base = typeof opts.baseUrl === 'string' && opts.baseUrl.trim() !== ''
        ? opts.baseUrl.trim().replace(/\/$/, '')
        : DEFAULT_API_BASE;
    const instance = sdk.init({ baseUrl: base });
    if (!instance?.mountCheckout) {
        throw new Error('CajuPay SDK não expõe mountCheckout().');
    }

    const defaultMethod = opts.defaultMethod || 'card';
    const preparePaymentUIOnMount = opts.preparePaymentUIOnMount ?? (defaultMethod === 'card');

    return await instance.mountCheckout(containerSelector, {
        token: opts.token,
        defaultMethod,
        embeddedOnly: true,
        preparePaymentUIOnMount,
        initialPayer: opts.initialPayer || undefined,
        onStatus: typeof opts.onStatus === 'function' ? opts.onStatus : undefined,
    });
}

export async function confirmCajuPayController(controller) {
    if (!controller || typeof controller.confirm !== 'function') {
        throw new Error('CajuPay: widget não está pronto. Recarregue a página.');
    }
    try {
        return await controller.confirm();
    } catch (err) {
        const msg = err?.message || err?.error || err?.toString?.() || 'Falha ao confirmar pagamento na CajuPay.';
        const e = new Error(msg);
        e.cause = err;
        throw e;
    }
}

export function setCajuPayPayer(controller, payer) {
    if (!controller || typeof controller.setPayer !== 'function') {
        return false;
    }
    const cleaned = {};
    if (payer && typeof payer === 'object') {
        if (typeof payer.name === 'string' && payer.name.trim() !== '') cleaned.name = payer.name.trim();
        if (typeof payer.email === 'string' && payer.email.trim() !== '') cleaned.email = payer.email.trim();
        if (typeof payer.document === 'string' && payer.document.trim() !== '') {
            cleaned.document = payer.document.replace(/\D/g, '');
        }
    }
    try {
        controller.setPayer(cleaned);

        return true;
    } catch (_) {
        return false;
    }
}

export function cajupayDefaultMethodFor(method) {
    switch (method) {
        case 'apple_pay':
            return 'apple_pay';
        case 'google_pay':
            return 'google_pay';
        case 'pix':
            return 'pix';
        default:
            return 'card';
    }
}
