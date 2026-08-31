/**
 * Silent Order Post (Cielo): tokeniza o cartão no browser.
 * PAN/CVV não passam pelo backend.
 *
 * @see https://docs.cielo.com.br/ecommerce-cielo/docs/integrando-com-o-sop
 */

const SCRIPT_ATTR = 'data-getfy-cielo-sop';

const SOP_SCRIPT = {
    sandbox: 'https://transactionsandbox.pagador.com.br/post/scripts/silentorderpost-1.0.min.js',
    production: 'https://www.pagador.com.br/post/scripts/silentorderpost-1.0.min.js',
};

function sopFunction() {
    const fn = typeof window !== 'undefined' ? window.bpSop_silentOrderPost : null;
    return typeof fn === 'function' ? fn : null;
}

/**
 * SOP só aceita A-Z e espaço no nome (sem acento). Sem isso o script recusa o cartão.
 */
export function formatCieloSopHolderName(name) {
    return String(name || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z ]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 64);
}

function csrfToken() {
    const match = typeof document !== 'undefined' && document.cookie ? document.cookie.match(/XSRF-TOKEN=([^;]+)/) : null;
    if (match) {
        try {
            return decodeURIComponent(match[1]);
        } catch {
            return '';
        }
    }
    return '';
}

function loadSopScript(environment) {
    if (sopFunction()) {
        return Promise.resolve();
    }

    const env = environment === 'sandbox' ? 'sandbox' : 'production';
    const src = SOP_SCRIPT[env];
    document.querySelectorAll(`script[${SCRIPT_ATTR}]`).forEach((el) => el.remove());

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.setAttribute(SCRIPT_ATTR, '1');
        script.onload = () => {
            if (sopFunction()) {
                resolve();
                return;
            }
            script.remove();
            reject(new Error('Script Silent Order Post da Cielo indisponível.'));
        };
        script.onerror = () => {
            script.remove();
            reject(new Error('Não foi possível carregar o Silent Order Post da Cielo.'));
        };
        document.head.appendChild(script);
    });
}

/**
 * @param {{ productId: string|number, installments?: number }} options
 * @returns {Promise<{ payment_token: string, card_mask: string }>}
 */
export async function requestCieloPaymentToken(options) {
    const productId = options?.productId;
    if (productId === undefined || productId === null || productId === '') {
        throw new Error('Produto inválido para tokenização Cielo.');
    }

    const res = await fetch('/checkout/cielo-sop-token', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ product_id: productId }),
    });

    let data = {};
    try {
        data = await res.json();
    } catch {
        data = {};
    }
    if (!res.ok) {
        throw new Error(typeof data.message === 'string' && data.message !== ''
            ? data.message
            : 'Não foi possível iniciar a tokenização Cielo.');
    }

    const accessToken = typeof data.access_token === 'string' ? data.access_token.trim() : '';
    const environment = data.environment === 'sandbox' ? 'sandbox' : 'production';
    if (accessToken === '') {
        throw new Error('Cielo não retornou AccessToken do Silent Order Post.');
    }

    await loadSopScript(environment);

    const sopFn = sopFunction();
    if (!sopFn) {
        throw new Error('Script Silent Order Post da Cielo indisponível.');
    }

    const sopResult = await new Promise((resolve, reject) => {
        try {
            sopFn({
                accessToken,
                environment,
                language: 'pt',
                enableBinQuery: true,
                enableVerifyCard: false,
                enableTokenize: false,
                cvvrequired: true,
                cardType: 'creditCard',
                onSuccess: (response) => resolve(response && typeof response === 'object' ? response : {}),
                onError: (response) => {
                    const msg = response?.Message
                        || response?.message
                        || response?.Text
                        || (response?.Code != null ? `Falha na tokenização Cielo (HTTP ${response.Code}).` : null)
                        || 'Falha na tokenização Cielo.';
                    reject(new Error(typeof msg === 'string' ? msg : 'Falha na tokenização Cielo.'));
                },
                onInvalid: (validation) => {
                    reject(new Error('Verifique os dados do cartão e tente novamente.'));
                    void validation;
                },
            });
        } catch (e) {
            reject(e instanceof Error ? e : new Error('Falha na tokenização Cielo.'));
        }
    });

    const paymentToken = String(sopResult.PaymentToken || sopResult.paymentToken || '').trim();
    if (paymentToken === '') {
        throw new Error('Cielo não retornou PaymentToken.');
    }

    const last4 = String(sopResult.CardLast4Digits || '').replace(/\D/g, '').slice(-4);
    const brand = String(sopResult.Brand || '').trim();
    const installments = Math.max(1, Math.min(12, Number(options.installments) || 1));

    return {
        payment_token: JSON.stringify({
            payment_token: paymentToken,
            brand,
            installments,
        }),
        card_mask: last4 ? `****${last4}` : '',
    };
}
