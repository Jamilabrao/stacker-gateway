<?php

namespace App\Jobs;

use App\Mail\ProductApprovedMail;
use App\Models\Product;
use App\Models\User;
use App\Services\BrandingEmailData;
use App\Services\PlatformTransactionalMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendProductApprovedMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $productId
    ) {}

    public function handle(PlatformTransactionalMailService $mailService): void
    {
        $product = Product::query()->find($this->productId);
        if (! $product || $product->approval_status !== Product::APPROVAL_APPROVED) {
            return;
        }

        $seller = User::query()->find($product->tenant_id);
        if (! $seller?->email) {
            return;
        }

        try {
            $branding = BrandingEmailData::forTenant(null);
            $panelUrl = route('produtos.edit', $product);
            $mailService->send(
                new ProductApprovedMail($seller, $product, $branding, $panelUrl),
                $seller->email
            );
        } catch (\Throwable $e) {
            Log::warning('product.approved_mail_failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
