<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Models\User;
use App\Services\AffiliateEnrollmentService;
use App\Services\StorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateJoinController extends Controller
{
    public function show(string $token): Response
    {
        $product = Product::findByAffiliateInviteToken($token);
        if ($product === null) {
            return Inertia::render('Produtos/Afiliar', [
                'invalid' => true,
                'message' => 'Este link de afiliação não é válido.',
                'token' => $token,
            ]);
        }

        $user = auth()->user();
        $owner = User::query()->find($product->tenant_id, ['id', 'name']);
        $storage = app(StorageService::class);

        $enrollment = null;
        if ($user instanceof User) {
            $enrollment = ProductAffiliateEnrollment::query()
                ->where('product_id', $product->id)
                ->where('affiliate_user_id', $user->id)
                ->first();
        }

        $isOwnProduct = $user instanceof User && (string) $product->tenant_id === (string) $user->tenant_id;
        $canAccessSeller = $user instanceof User && $user->canAccessSellerPanel();
        $programOpen = (bool) $product->affiliate_enabled && $product->isAvailableForPurchase();
        $checkoutUrl = $product->checkout_slug ? url('/c/'.$product->checkout_slug) : '';
        $affiliateLink = ($enrollment?->public_ref && $checkoutUrl)
            ? $checkoutUrl.(str_contains($checkoutUrl, '?') ? '&' : '?').'ref='.urlencode((string) $enrollment->public_ref)
            : null;

        $commissionPct = (float) $product->affiliate_commission_percent;
        $price = (float) $product->price;
        $commissionMax = round($price * $commissionPct / 100.0, 2);

        return Inertia::render('Produtos/Afiliar', [
            'invalid' => false,
            'token' => $token,
            'program_open' => $programOpen,
            'is_own_product' => $isOwnProduct,
            'can_request' => $programOpen && $canAccessSeller && ! $isOwnProduct && (
                $enrollment === null
                || in_array($enrollment->status, [
                    ProductAffiliateEnrollment::STATUS_REJECTED,
                    ProductAffiliateEnrollment::STATUS_REVOKED,
                ], true)
            ),
            'auth_email' => $user?->email,
            'is_guest' => ! ($user instanceof User),
            'is_seller' => $canAccessSeller,
            'login_url' => url('/login').'?redirect='.urlencode(url('/afiliar/'.$token)),
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'image_url' => $product->image ? $storage->url($product->image) : null,
                'price' => $price,
                'currency' => $product->currency ?? 'BRL',
                'affiliate_commission_percent' => $commissionPct,
                'commission_max_formatted' => number_format($commissionMax, 2, ',', '.'),
                'affiliate_page_url' => $product->affiliate_page_url,
                'affiliate_support_email' => $product->affiliate_support_email,
                'affiliate_showcase_description' => $product->affiliate_showcase_description,
                'affiliate_manual_approval' => (bool) $product->affiliate_manual_approval,
                'producer_name' => $owner?->name ?? '—',
            ],
            'enrollment' => $enrollment ? [
                'status' => $enrollment->status,
                'public_ref' => $enrollment->public_ref,
                'affiliate_link' => $affiliateLink,
            ] : null,
        ]);
    }

    public function enroll(Request $request, string $token): RedirectResponse
    {
        $product = Product::findByAffiliateInviteToken($token);
        if ($product === null) {
            return redirect()->route('affiliate.join.show', ['token' => $token])
                ->with('error', 'Este link de afiliação não é válido.');
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login', ['redirect' => url('/afiliar/'.$token)])
                ->with('error', 'Faça login com uma conta de infoprodutor para se afiliar.');
        }

        $result = app(AffiliateEnrollmentService::class)->requestEnrollment($product, $user, requireShowcase: false);

        return redirect()
            ->route('affiliate.join.show', ['token' => $token])
            ->with($result['flash'], $result['message']);
    }
}
