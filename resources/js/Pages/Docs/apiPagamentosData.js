import {
    BookOpen,
    CreditCard,
    FileCode,
    Key,
    Layers,
    QrCode,
    ShoppingCart,
    Webhook,
} from 'lucide-vue-next';

export const fieldColumns = [
    { key: 'field', label: 'Campo' },
    { key: 'type', label: 'Tipo' },
    { key: 'required', label: 'Obrigatório' },
    { key: 'desc', label: 'Descrição' },
];

export const customerFields = [
    { field: 'customer', type: 'objeto', required: 'Sim', desc: 'Dados do cliente' },
    { field: 'customer.email', type: 'string', required: 'Sim', desc: 'E-mail' },
    { field: 'customer.name', type: 'string', required: 'Não', desc: 'Nome (se vazio, usa o e-mail)' },
    { field: 'customer.cpf', type: 'string', required: 'Não', desc: 'CPF (somente dígitos ou formatado)' },
    { field: 'customer.phone', type: 'string', required: 'Não', desc: 'Telefone' },
];

export const pixRequestFields = [
    ...customerFields,
    {
        field: 'amount',
        type: 'number',
        required: 'Sim*',
        desc: 'Valor em reais (ex.: 97.90). *Obrigatório no body; ignorado se product_id definir o preço',
    },
    { field: 'currency', type: 'string', required: 'Não', desc: 'BRL, USD ou EUR (default: BRL)' },
    {
        field: 'product_id',
        type: 'string (UUID)',
        required: 'Não',
        desc: 'Produto do catálogo do vendedor. Se informado, o valor vem do produto/oferta/plano',
    },
    { field: 'product_offer_id', type: 'integer', required: 'Não', desc: 'Oferta vinculada ao product_id' },
    { field: 'subscription_plan_id', type: 'integer', required: 'Não', desc: 'Plano de assinatura do product_id' },
    {
        field: 'metadata',
        type: 'objeto',
        required: 'Não',
        desc: 'Dados livres (ex.: external_id) — devolvidos no webhook e em GET /payments',
    },
    {
        field: 'idempotency_key',
        type: 'string',
        required: 'Não',
        desc: 'Chave única (máx. 128). Também aceito no header Idempotency-Key',
    },
];

export const pixResponseFields = [
    { field: 'order_id', type: 'integer', required: 'Sim', desc: 'ID do pedido — use em GET, cancel e refund' },
    { field: 'transaction_id', type: 'string', required: 'Não', desc: 'Referência no gateway/adquirente' },
    {
        field: 'qrcode',
        type: 'string',
        required: 'Não',
        desc: 'Imagem QR em base64 ou data URI (formato depende do gateway)',
    },
    { field: 'copy_paste', type: 'string', required: 'Não', desc: 'Código PIX copia e cola (EMV)' },
    { field: 'status', type: 'string', required: 'Sim', desc: 'Sempre pending na criação' },
];

export const paymentStatusResponseFields = [
    { field: 'order_id', type: 'integer', required: 'Sim', desc: 'ID do pedido' },
    { field: 'status', type: 'string', required: 'Sim', desc: 'pending, completed, cancelled, refunded, disputed…' },
    { field: 'amount', type: 'number', required: 'Sim', desc: 'Valor do pedido' },
    { field: 'email', type: 'string', required: 'Sim', desc: 'E-mail do comprador' },
    { field: 'gateway', type: 'string', required: 'Não', desc: 'Slug do gateway usado (ex.: efi, cajupay)' },
    { field: 'gateway_id', type: 'string', required: 'Não', desc: 'ID da transação no gateway' },
    { field: 'metadata', type: 'objeto', required: 'Não', desc: 'Metadados enviados na criação' },
    { field: 'created_at', type: 'string (ISO 8601)', required: 'Não', desc: 'Data de criação' },
    { field: 'updated_at', type: 'string (ISO 8601)', required: 'Não', desc: 'Última atualização' },
];

export const orderStatusValues = [
    { status: 'pending', quando: 'PIX gerado; aguardando pagamento' },
    { status: 'completed', quando: 'Pagamento confirmado pelo gateway' },
    { status: 'cancelled', quando: 'Cancelado via API ou expiração administrativa' },
    { status: 'refunded', quando: 'Estorno concluído via POST /pix/{id}/refund' },
    { status: 'disputed', quando: 'Disputa MED / chargeback em andamento' },
];

export const integrationPrerequisites = [
    'Conta de infoprodutor ativa no gateway',
    'API PIX habilitada para o tenant (plataforma ou vendedor)',
    'Gateway PIX conectado (Integrações → Gateways)',
    'Aplicação criada em /aplicacoes-api com status ativo',
    'Par Public key + Secret key copiado (secret só no backend)',
    'Webhook URL em HTTPS configurada na aplicação (recomendado)',
    'IPs permitidos vazio (qualquer IP) ou seu servidor na lista',
];

export const integrationSteps = [
    'Obter Public key e Secret key em /aplicacoes-api',
    'Chamar POST /api/v1/payments/pix com customer.email e amount (ou product_id)',
    'Exibir copy_paste e/ou qrcode ao cliente final',
    'Confirmar pagamento via webhook order.completed ou polling GET /payments/{order_id}',
    'Correlacionar com metadata.external_id (ou campo que você enviar)',
];

export const webhookEvents = [
    { event: 'order.pending', desc: 'Pedido criado; PIX aguardando pagamento (disparado na criação)' },
    { event: 'order.completed', desc: 'Pagamento confirmado — libere o produto/serviço' },
    { event: 'order.refunded', desc: 'Estorno concluído' },
    { event: 'order.cancelled', desc: 'Pedido cancelado' },
];

export const webhookPayloadExample = `{
  "event": "order.completed",
  "order_id": 456,
  "amount": 97.90,
  "status": "completed",
  "email": "cliente@exemplo.com",
  "metadata": { "external_id": "ped-1001", "source": "api" },
  "customer": { "name": "Cliente", "email": "cliente@exemplo.com", "document": "52998224725" },
  "created_at": "2026-06-13T14:00:00.000000Z",
  "updated_at": "2026-06-13T14:05:12.000000Z"
}`;

export const webhookVerifyPhpExample = `// Body bruto (antes de json_decode)
$raw = file_get_contents('php://input');
$secret = 'seu_webhook_secret';
$expected = hash_hmac('sha256', $raw, $secret);
$received = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
if (!hash_equals($expected, $received)) {
    http_response_code(401);
    exit;
}`;

export const webhookVerifyNodeExample = `import crypto from 'crypto';

const expected = crypto
  .createHmac('sha256', process.env.WEBHOOK_SECRET)
  .update(rawBody) // Buffer/string do body bruto
  .digest('hex');

if (req.headers['x-webhook-signature'] !== expected) {
  return res.status(401).end();
}`;

export const commonApiErrors = [
    {
        code: '401',
        message: 'Missing or invalid API key.',
        cause: 'Headers X-Public-Key / X-Secret-Key ausentes ou incompletos',
        action: 'Envie os dois headers em toda requisição',
    },
    {
        code: '401',
        message: 'Invalid API key.',
        cause: 'Par de chaves incorreto ou secret errado',
        action: 'Copie novamente em /aplicacoes-api → Revelar secret',
    },
    {
        code: '403',
        message: 'API application is disabled.',
        cause: 'Aplicação desativada no painel',
        action: 'Ative a integração em /aplicacoes-api',
    },
    {
        code: '403',
        message: 'IP not allowed.',
        cause: 'Seu IP não está na lista de IPs permitidos',
        action: 'Adicione o IP do servidor ou deixe a lista vazia',
    },
    {
        code: '403',
        message: 'API PIX disabled for this tenant.',
        cause: 'API PIX desligada na plataforma ou para o vendedor',
        action: 'Habilite API PIX nas configurações da conta',
    },
    {
        code: '404',
        message: 'Pedido não encontrado.',
        cause: 'order_id inexistente ou pertence a outra aplicação',
        action: 'Use o order_id retornado na criação com as mesmas chaves',
    },
    {
        code: '422',
        message: '(validação)',
        cause: 'Campo obrigatório ausente, amount < 0.01, produto indisponível',
        action: 'Corrija o body; erros Laravel vêm em errors.{campo}',
    },
    {
        code: '422',
        message: 'Não foi possível gerar o PIX.',
        cause: 'Gateway PIX indisponível ou credenciais inválidas',
        action: 'Verifique Integrações → Gateways e logs do servidor',
    },
    {
        code: '429',
        message: 'Too Many Attempts.',
        cause: 'Rate limit (throttle:api)',
        action: 'Aguarde e use idempotency_key em retentativas',
    },
];

export const errorCodes = [
    { code: '401', meaning: 'Credenciais ausentes ou inválidas' },
    { code: '403', meaning: 'Integração inativa, IP bloqueado ou API PIX desabilitada' },
    { code: '404', meaning: 'Pedido não encontrado (outra app ou ID inválido)' },
    { code: '422', meaning: 'Validação ou falha ao gerar PIX no gateway' },
    { code: '429', meaning: 'Rate limit' },
    { code: '500', meaning: 'Erro interno' },
];

export const sessionFields = [
    ...customerFields,
    { field: 'amount', type: 'number', required: 'Sim', desc: 'Valor (ex.: 97.90)' },
    { field: 'currency', type: 'string', required: 'Não', desc: 'BRL, USD ou EUR (default: BRL)' },
    { field: 'product_id', type: 'string (UUID)', required: 'Não', desc: 'ID do produto no catálogo' },
    { field: 'product_offer_id', type: 'integer', required: 'Não', desc: 'ID da oferta' },
    { field: 'subscription_plan_id', type: 'integer', required: 'Não', desc: 'ID do plano de assinatura' },
    { field: 'metadata', type: 'objeto', required: 'Não', desc: 'Dados livres para webhook' },
    { field: 'return_url', type: 'string', required: 'Não', desc: 'URL após concluir o pagamento' },
    { field: 'expires_in', type: 'integer', required: 'Não', desc: 'Minutos até expirar (5–1440; default: 30)' },
];

export const endpointSummary = [
    { method: 'POST', endpoint: '/api/v1/payments/pix', desc: 'Criar cobrança PIX (QR + copia e cola)' },
    { method: 'GET', endpoint: '/api/v1/payments/{order_id}', desc: 'Consultar status do pedido' },
    { method: 'POST', endpoint: '/api/v1/pix/{order_id}/cancel', desc: 'Cancelar PIX pendente' },
    { method: 'POST', endpoint: '/api/v1/pix/{order_id}/refund', desc: 'Estornar PIX pago' },
    { method: 'POST', endpoint: '/api/v1/checkout/sessions', desc: 'Checkout Pro — link hospedado' },
    { method: 'POST', endpoint: '/api/v1/payments/card', desc: 'Pagamento com cartão' },
    { method: 'POST', endpoint: '/api/v1/payments/boleto', desc: 'Pagamento com boleto' },
];

export const whenToUse = [
    { cenario: 'PIX na sua própria tela', sugestao: 'POST /payments/pix' },
    { cenario: 'Página de pagamento pronta', sugestao: 'POST /checkout/sessions' },
    { cenario: 'Cartão ou boleto na mesma conta', sugestao: '/payments/card e /payments/boleto' },
    { cenario: 'Valor avulso sem produto', sugestao: 'Envie amount; omita product_id' },
    { cenario: 'Cobrar preço do catálogo', sugestao: 'Envie product_id (amount no body é ignorado)' },
];

export const navSections = [
    {
        title: 'Introdução',
        icon: BookOpen,
        items: [
            { title: 'Início rápido', id: 'inicio-rapido' },
            { title: 'Integração em 5 passos', id: 'fluxo-pix' },
            { title: 'Pré-requisitos', id: 'pre-requisitos' },
            { title: 'Integração para parceiros', id: 'para-parceiros' },
            { title: 'Visão geral', id: 'visao-geral' },
            { title: 'Modos de checkout', id: 'quando-usar' },
        ],
    },
    {
        title: 'Autenticação',
        icon: Key,
        items: [
            { title: 'Envio das chaves', id: 'envio-api-key' },
            { title: 'Obtenção das chaves', id: 'obtencao-api-key' },
            { title: 'Segurança', id: 'seguranca' },
        ],
    },
    {
        title: 'Conta e integração',
        icon: Layers,
        items: [
            { title: 'Chaves e configuração', id: 'integracao-conta' },
            { title: 'Webhooks (config)', id: 'processadora-webhooks' },
        ],
    },
    {
        title: 'PIX',
        icon: QrCode,
        items: [
            { title: 'POST /payments/pix', id: 'post-payments-pix' },
            { title: 'Campos do request', id: 'post-payments-pix-campos' },
            { title: 'Exemplo curl', id: 'exemplo-curl' },
            { title: 'GET /payments/{order_id}', id: 'get-payments-order-id' },
            { title: 'Status do pedido', id: 'status-pedido' },
            { title: 'POST /pix/cancel e refund', id: 'post-pix-cancel' },
            { title: 'Idempotência', id: 'idempotencia-pix' },
        ],
    },
    {
        title: 'Checkout Pro',
        icon: ShoppingCart,
        items: [
            { title: 'POST /checkout/sessions', id: 'post-checkout-sessions' },
        ],
    },
    {
        title: 'Cartão e boleto',
        icon: CreditCard,
        items: [
            { title: 'Dados comuns (customer)', id: 'dados-comuns-customer' },
            { title: 'POST /payments/card', id: 'post-payments-card' },
            { title: 'POST /payments/boleto', id: 'post-payments-boleto' },
        ],
    },
    {
        title: 'Webhooks',
        icon: Webhook,
        items: [
            { title: 'Eventos', id: 'webhooks-eventos' },
            { title: 'Formato do payload', id: 'webhooks-formato' },
            { title: 'Assinatura', id: 'webhooks-assinatura' },
            { title: 'Boas práticas', id: 'webhooks-boas-praticas' },
        ],
    },
    {
        title: 'Referência',
        icon: FileCode,
        items: [
            { title: 'Erros comuns', id: 'erros-comuns' },
            { title: 'Códigos de erro', id: 'codigos-de-erro' },
            { title: 'Boas práticas gerais', id: 'boas-praticas' },
            { title: 'Resumo de endpoints', id: 'resumo-endpoints' },
        ],
    },
];
