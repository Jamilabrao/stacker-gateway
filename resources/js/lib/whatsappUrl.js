/**
 * Normaliza telefone do checkout (BR) e monta link wa.me.
 *
 * @param {string|null|undefined} phone
 * @returns {string|null}
 */
export function buildWhatsAppUrl(phone) {
    const digits = String(phone ?? '').replace(/\D/g, '');
    if (digits.length < 10) {
        return null;
    }

    let normalized = digits;
    if (normalized.length <= 11 && !normalized.startsWith('55')) {
        normalized = `55${normalized}`;
    }

    return `https://wa.me/${normalized}`;
}

/**
 * Telefone do pedido (checkout) com fallback na sessão.
 *
 * @param {{ phone?: string, checkout_session?: { phone?: string } }|null|undefined} order
 * @returns {string}
 */
export function orderCustomerPhone(order) {
    if (!order) {
        return '';
    }
    const fromOrder = String(order.phone ?? '').trim();
    if (fromOrder) {
        return fromOrder;
    }

    return String(order.checkout_session?.phone ?? '').trim();
}
