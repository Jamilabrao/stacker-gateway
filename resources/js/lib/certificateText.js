/**
 * @typedef {Object} CertificateTextVars
 * @property {string} [aluno]
 * @property {string} [curso]
 * @property {string} [data]
 * @property {string} [plataforma]
 * @property {string} [carga_horaria]
 */

const PLACEHOLDER_MAP = {
    ALUNO: 'aluno',
    CURSO: 'curso',
    DATA: 'data',
    PLATAFORMA: 'plataforma',
    CARGA_HORARIA: 'carga_horaria',
};

/**
 * @param {string} template
 * @param {CertificateTextVars} vars
 * @returns {string}
 */
export function resolveCertificateBody(template, vars = {}) {
    const raw = String(template || '').trim();
    if (!raw) {
        return '';
    }

    return raw.replace(/\[([A-Z_]+)\]/g, (match, key) => {
        const field = PLACEHOLDER_MAP[key];
        if (!field) {
            return match;
        }
        const value = vars[field];
        return value != null && String(value).trim() !== '' ? String(value).trim() : '';
    });
}

/**
 * @param {Record<string, unknown>} config
 * @param {CertificateTextVars} vars
 * @returns {string}
 */
export function buildLegacyCertificateBodyLines(config = {}, vars = {}) {
    const intro = String(config.recipient_intro_text || 'Certificamos que').trim();
    const aluno = String(vars.aluno || 'Aluno').trim();
    const completion = String(config.completion_text || 'completou com sucesso o curso em').trim();
    const plataforma = String(vars.plataforma || '').trim();
    const issuedOn = String(config.issued_on_text || 'em').trim();
    const data = String(vars.data || '').trim();
    const durationEnabled = config.duration_enabled !== false;
    const durationText = String(vars.carga_horaria || '').trim();
    const durationLabel = String(config.duration_label_text || 'Duração').trim();

    const lines = [
        intro,
        aluno,
        plataforma ? `${completion} ${plataforma}` : completion,
    ];
    if (data) {
        lines.push(`${issuedOn} ${data}`.trim());
    }
    if (durationEnabled && durationText) {
        lines.push(`${durationLabel}: ${durationText}`);
    }

    return lines.filter(Boolean).join('\n');
}

/**
 * @param {number} fontScale
 * @returns {number}
 */
export function certificateFontScaleRatio(fontScale) {
    const n = Number(fontScale);
    if (!Number.isFinite(n) || n <= 0) {
        return 1;
    }
    return Math.max(0.75, Math.min(1.5, n / 100));
}

/**
 * @param {number} fontScale
 * @param {boolean} [forPrint]
 * @returns {Record<string, string>}
 */
export function certificateFontCssVars(fontScale, forPrint = false) {
    const base = certificateFontScaleRatio(fontScale);
    const scale = forPrint ? Math.max(base, base * 1.15) : base;

    return {
        '--cert-scale': String(scale),
        '--cert-header-size': `calc(12px * ${scale})`,
        '--cert-title-size': `calc(28px * ${scale})`,
        '--cert-body-size': `calc(16px * ${scale})`,
        '--cert-footer-size': `calc(13px * ${scale})`,
        '--cert-watermark-size': `calc(48px * ${scale})`,
    };
}
