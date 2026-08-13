/** Espelha `App\Support\BrazilianDocuments` (dígitos verificadores). */

export function digitsOnly(value) {
    return String(value || '').replace(/\D/g, '');
}

export function isValidCpf(value) {
    const cpf = digitsOnly(value);
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
        return false;
    }
    for (let t = 9; t < 11; t++) {
        let sum = 0;
        for (let i = 0; i < t; i++) {
            sum += parseInt(cpf[i], 10) * (t + 1 - i);
        }
        let r = (sum * 10) % 11;
        if (r === 10) {
            r = 0;
        }
        if (r !== parseInt(cpf[t], 10)) {
            return false;
        }
    }
    return true;
}

export function isValidCnpj(value) {
    const cnpj = digitsOnly(value);
    if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) {
        return false;
    }
    const w1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    let sum = 0;
    for (let i = 0; i < 12; i++) {
        sum += parseInt(cnpj[i], 10) * w1[i];
    }
    let r = sum % 11;
    const dv1 = r < 2 ? 0 : 11 - r;
    if (dv1 !== parseInt(cnpj[12], 10)) {
        return false;
    }
    const w2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    sum = 0;
    for (let i = 0; i < 13; i++) {
        sum += parseInt(cnpj[i], 10) * w2[i];
    }
    r = sum % 11;
    const dv2 = r < 2 ? 0 : 11 - r;
    return dv2 === parseInt(cnpj[13], 10);
}

/** Máscara progressiva de CNPJ (14 dígitos). */
export function formatCnpjMask(value) {
    const d = digitsOnly(value).slice(0, 14);
    return d
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
}
