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
    { field: 'customer.name', type: 'string', required: 'Não', desc: 'Nome' },
    { field: 'customer.cpf', type: 'string', required: 'Não', desc: 'CPF' },
    { field: 'customer.phone', type: 'string', required: 'Não', desc: 'Telefone' },
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
    { cenario: 'Valor avulso sem produto', sugestao: 'amount/currency; product_id opcional' },
];

export const webhookEvents = [
    { event: 'order.completed', desc: 'Pagamento concluído' },
    { event: 'order.pending', desc: 'Aguardando pagamento' },
    { event: 'order.refunded', desc: 'Pedido estornado' },
    { event: 'order.cancelled', desc: 'Pedido cancelado' },
];

export const errorCodes = [
    { code: '401', meaning: 'Credenciais ausentes ou inválidas' },
    { code: '403', meaning: 'Integração inativa ou IP não permitido' },
    { code: '404', meaning: 'Recurso não encontrado' },
    { code: '422', meaning: 'Validação falhou' },
    { code: '429', meaning: 'Rate limit' },
    { code: '500', meaning: 'Erro interno' },
];

export const navSections = [
    {
        title: 'Introdução',
        icon: BookOpen,
        items: [
            { title: 'Início rápido', id: 'inicio-rapido' },
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
            { title: 'Webhooks', id: 'processadora-webhooks' },
        ],
    },
    {
        title: 'PIX',
        icon: QrCode,
        items: [
            { title: 'POST /payments/pix', id: 'post-payments-pix' },
            { title: 'GET /payments/{order_id}', id: 'get-payments-order-id' },
            { title: 'POST /pix/{order_id}/cancel', id: 'post-pix-cancel' },
            { title: 'POST /pix/{order_id}/refund', id: 'post-pix-refund' },
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
            { title: 'Idempotência', id: 'idempotencia' },
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
            { title: 'Códigos de erro', id: 'codigos-de-erro' },
            { title: 'Boas práticas gerais', id: 'boas-praticas' },
            { title: 'Resumo de endpoints', id: 'resumo-endpoints' },
        ],
    },
];
