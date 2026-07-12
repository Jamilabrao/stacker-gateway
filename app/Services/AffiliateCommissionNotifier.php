<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\PanelNotification;

class AffiliateCommissionNotifier
{
    public function __construct(
        protected PanelPushService $panelPushService,
    ) {}

    public function notifyNewCommission(AffiliateCommission $commission): void
    {
        $commission->loadMissing(['product', 'affiliate']);
        $affiliate = $commission->affiliate;
        if ($affiliate === null) {
            return;
        }

        $tenantId = (int) ($affiliate->tenant_id ?? 0);
        if ($tenantId < 1) {
            return;
        }

        $productName = $commission->product?->name ?? 'Produto';
        $net = number_format((float) $commission->commission_net, 2, ',', '.');
        $title = 'Nova comissão de afiliado';
        $body = $productName.' — R$ '.$net;
        $url = url('/vendas?view=affiliate&commission='.$commission->id);
        $eventKey = 'affiliate_sale_'.$commission->order_id;

        PanelNotification::firstOrCreate(
            [
                'user_id' => $affiliate->id,
                'event_key' => $eventKey,
            ],
            [
                'tenant_id' => $tenantId,
                'type' => 'affiliate_sale_approved',
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ],
        );

        $this->panelPushService->sendAndPersistToTenant(
            $tenantId,
            'affiliate_sale_approved',
            $title,
            $body,
            $url,
            $eventKey,
        );
    }
}
