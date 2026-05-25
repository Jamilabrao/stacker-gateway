/**
 * Espelha App\Support\EmailCampaignTemplate para pré-visualização no painel.
 */
export const CAMPAIGN_BODY_MARKER = 'data-campaign-body="1"';

export function defaultCampaignMessage() {
    return (
        'Temos uma novidade importante para compartilhar com você.\n\n'
        + 'Escreva aqui o conteúdo da sua mensagem. Você pode usar parágrafos separando com uma linha em branco.\n\n'
        + 'Qualquer dúvida, basta responder este e-mail.\n\n'
        + 'Abraços!'
    );
}

function escapeHtml(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function plainTextToHtmlBlock(plainText) {
    const parts = plainText.split(/\n\n+/).map((p) => p.trim()).filter(Boolean);
    if (parts.length === 0) {
        return `<p style="margin:0;font-size:16px;line-height:1.65;color:#334155;">${escapeHtml(plainText)}</p>`;
    }
    return parts
        .map((part) => {
            const line = escapeHtml(part).replace(/\n/g, '<br>');
            return `<p style="margin:0 0 16px;font-size:16px;line-height:1.65;color:#334155;">${line}</p>`;
        })
        .join('');
}

export function wrapCampaignBodyHtml(plainText) {
    const inner = plainTextToHtmlBlock((plainText || '').trim() || defaultCampaignMessage());
    const primary = '#0ea5e9';

    return (
        '<!DOCTYPE html><html lang="pt-BR"><head>'
        + '<meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        + '</head><body style="margin:0;padding:0;background-color:#f1f5f9;">'
        + '<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:32px 16px;">'
        + '<tr><td align="center">'
        + '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(15,23,42,0.08);">'
        + `<tr><td style="padding:28px 32px 20px;background:linear-gradient(135deg,${primary},#0284c7);text-align:center;">`
        + '<p style="margin:0;font-size:13px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:rgba(255,255,255,0.9);">Mensagem para você</p>'
        + '<h1 style="margin:12px 0 0;font-size:26px;font-weight:700;color:#fff;">Olá, João!</h1>'
        + '</td></tr>'
        + `<tr><td ${CAMPAIGN_BODY_MARKER} style="padding:32px 36px 28px;">${inner}</td></tr>`
        + '<tr><td style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;">'
        + '<p style="margin:0;font-size:13px;color:#64748b;">Prévia — no envio real usamos {nome} e {email}.</p>'
        + '</td></tr></table></td></tr></table></body></html>'
    );
}
