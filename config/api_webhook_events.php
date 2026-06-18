<?php

return [
    'groups' => [
        'payments' => [
            'label' => 'Pagamentos',
            'events' => [
                'order.pending' => 'Pedido pendente',
                'order.completed' => 'Pedido concluído',
                'order.cancelled' => 'Pedido cancelado',
                'order.refunded' => 'Pedido reembolsado',
                'order.expired' => 'Pedido expirado',
                'pix.generated' => 'PIX gerado',
            ],
        ],
        'withdrawals' => [
            'label' => 'Saques',
            'events' => [
                'withdrawal.created' => 'Saque criado',
                'withdrawal.processing' => 'Saque em processamento',
                'withdrawal.completed' => 'Saque concluído',
                'withdrawal.failed' => 'Saque falhou',
                'withdrawal.rejected' => 'Saque rejeitado',
                'withdrawal.cancelled' => 'Saque cancelado',
            ],
        ],
    ],

    /** @deprecated Use groups.*.events keys */
    'payment_events' => [
        'order.pending',
        'order.completed',
        'order.cancelled',
        'order.refunded',
        'order.expired',
        'pix.generated',
    ],

    /** @deprecated Use groups.*.events keys */
    'withdrawal_events' => [
        'withdrawal.created',
        'withdrawal.processing',
        'withdrawal.completed',
        'withdrawal.failed',
        'withdrawal.rejected',
        'withdrawal.cancelled',
    ],
];
