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
