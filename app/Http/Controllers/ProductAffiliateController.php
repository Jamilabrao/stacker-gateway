<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Services\AffiliateEnrollmentNotifier;
use App\Services\SellerActivityLogService;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ProductAffiliateController extends Controller
{
    use LogsSellerActivity;

    public function updateSettings(Request $request, Product $produto): RedirectResponse
    {
        $this->authorizeProduct($produto);

        $validated = $request->validate([
            'affiliate_enabled' => ['boolean'],
            'affiliate_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'affiliate_manual_approval' => ['boolean'],
            'affiliate_show_in_showcase' => ['boolean'],
            'affiliate_page_url' => ['nullable', 'string', 'max:2048'],
            'affiliate_support_email' => ['nullable', 'email', 'max:255'],
            'affiliate_showcase_description' => ['nullable', 'string', 'max:65535'],
            'affiliate_hide_customer_data' => ['boolean'],
            'affiliate_shared_offer_ids' => ['nullable', 'array'],
            'affiliate_shared_offer_ids.*' => [
                'integer',
                Rule::exists('product_offers', 'id')->where(fn ($q) => $q->where('product_id', $produto->id)),
            ],
        ]);

        $affiliateEnabled = $request->boolean('affiliate_enabled', false);
        $showInShowcase = $request->boolean('affiliate_show_in_showcase', false);
        // Vitrine só faz sentido com programa ativo; evita estado "só vitrine" que a query da vitrine ignora.
        if ($showInShowcase) {
            $affiliateEnabled = true;
        }
        if (! $affiliateEnabled) {
            $showInShowcase = false;
        }

        $produto->affiliate_enabled = $affiliateEnabled;
        $produto->affiliate_commission_percent = $validated['affiliate_commission_percent'];
        $produto->affiliate_manual_approval = $request->boolean('affiliate_manual_approval', true);
        $produto->affiliate_show_in_showcase = $showInShowcase;
        $produto->affiliate_page_url = $validated['affiliate_page_url'] ?? null;
        $produto->affiliate_support_email = $validated['affiliate_support_email'] ?? null;
        $produto->affiliate_showcase_description = $validated['affiliate_showcase_description'] ?? null;
        $produto->affiliate_hide_customer_data = $request->boolean('affiliate_hide_customer_data', false);

        if ($produto->affiliate_enabled && ! $produto->affiliateCommissionTotalsValid()) {
            return back()->withErrors([
                'affiliate_commission_percent' => 'A soma da comissão de afiliados com as comissões de co-produtores (vendas por afiliado) não pode exceder 100%.',
            ])->withInput();
        }

        $produto->save();

        if ($affiliateEnabled && Schema::hasColumn($produto->getTable(), 'affiliate_invite_token')) {
            $produto->ensureAffiliateInviteToken();
        }

        if (Schema::hasColumn('product_offers', 'affiliate_share_enabled')) {
            $sharedIds = collect($validated['affiliate_shared_offer_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();

            $produto->offers()->update(['affiliate_share_enabled' => false]);

            if ($sharedIds !== []) {
                $produto->offers()->whereIn('id', $sharedIds)->update(['affiliate_share_enabled' => true]);
            }
        }

        $tab = $request->query('tab');
        $url = route('produtos.edit', $produto).($tab ? '?tab='.urlencode((string) $tab) : '');

        $this->logSellerActivity(SellerActivityLogService::AFFILIATE_SETTINGS_UPDATED, $produto, [
            'name' => $produto->name,
            'enabled' => (bool) $produto->affiliate_enabled,
        ]);

        return redirect($url)->with('success', 'Configurações de afiliados atualizadas.');
    }

    public function approve(Request $request, Product $produto, ProductAffiliateEnrollment $enrollment): RedirectResponse
    {
        $this->authorizeProduct($produto);

        if ($enrollment->product_id !== $produto->id) {
            abort(404);
        }

        if ($enrollment->status !== ProductAffiliateEnrollment::STATUS_PENDING) {
            return back()->with('error', 'Esta solicitação não está pendente.');
        }

        $produto->refresh();
        if ($produto->affiliate_enabled && ! $produto->affiliateCommissionTotalsValid()) {
            return back()->with('error', 'Não é possível aprovar: a soma de comissões (co-produção + afiliado) excede 100%. Ajuste as porcentagens.');
        }

        $enrollment->update(['status' => ProductAffiliateEnrollment::STATUS_APPROVED]);
        $enrollment->ensurePublicRef();
        app(AffiliateEnrollmentNotifier::class)->notifyApproved($enrollment->fresh());

        $this->logSellerActivity(SellerActivityLogService::AFFILIATE_APPROVED, $enrollment, [
            'product_id' => $produto->id,
            'product_name' => $produto->name,
            'affiliate_user_id' => $enrollment->affiliate_user_id,
        ]);

        $tab = $request->query('tab');
        $url = route('produtos.edit', $produto).($tab ? '?tab='.urlencode((string) $tab) : '');

        return redirect($url)->with('success', 'Afiliação aprovada.');
    }

    public function reject(Request $request, Product $produto, ProductAffiliateEnrollment $enrollment): RedirectResponse
    {
        $this->authorizeProduct($produto);

        if ($enrollment->product_id !== $produto->id) {
            abort(404);
        }

        if ($enrollment->status !== ProductAffiliateEnrollment::STATUS_PENDING) {
            return back()->with('error', 'Esta solicitação não está pendente.');
        }

        $enrollment->update(['status' => ProductAffiliateEnrollment::STATUS_REJECTED]);

        $this->logSellerActivity(SellerActivityLogService::AFFILIATE_REJECTED, $enrollment, [
            'product_id' => $produto->id,
            'product_name' => $produto->name,
            'affiliate_user_id' => $enrollment->affiliate_user_id,
        ]);

        $tab = $request->query('tab');
        $url = route('produtos.edit', $produto).($tab ? '?tab='.urlencode((string) $tab) : '');

        return redirect($url)->with('success', 'Solicitação recusada.');
    }

    public function revoke(Request $request, Product $produto, ProductAffiliateEnrollment $enrollment): RedirectResponse
    {
        $this->authorizeProduct($produto);

        if ($enrollment->product_id !== $produto->id) {
            abort(404);
        }

        if ($enrollment->status !== ProductAffiliateEnrollment::STATUS_APPROVED) {
            return back()->with('error', 'Afiliação não está ativa.');
        }

        $enrollment->update(['status' => ProductAffiliateEnrollment::STATUS_REVOKED]);

        $this->logSellerActivity(SellerActivityLogService::AFFILIATE_REVOKED, $enrollment, [
            'product_id' => $produto->id,
            'product_name' => $produto->name,
            'affiliate_user_id' => $enrollment->affiliate_user_id,
        ]);

        $tab = $request->query('tab');
        $url = route('produtos.edit', $produto).($tab ? '?tab='.urlencode((string) $tab) : '');

        return redirect($url)->with('success', 'Afiliação revogada.');
    }

    public function regenerateInviteToken(Request $request, Product $produto): RedirectResponse
    {
        $this->authorizeProduct($produto);

        if (! $produto->affiliate_enabled) {
            return back()->with('error', 'Ative a afiliação para gerar o link.');
        }

        if (! Schema::hasColumn($produto->getTable(), 'affiliate_invite_token')) {
            return back()->with('error', 'Link de afiliação indisponível.');
        }

        $produto->regenerateAffiliateInviteToken();

        $this->logSellerActivity(SellerActivityLogService::AFFILIATE_SETTINGS_UPDATED, $produto, [
            'name' => $produto->name,
            'enabled' => true,
            'invite_token_regenerated' => true,
        ]);

        $tab = $request->query('tab');
        $url = route('produtos.edit', $produto).($tab ? '?tab='.urlencode((string) $tab) : '');

        return redirect($url)->with('success', 'Novo link de afiliação gerado. O link anterior deixou de funcionar.');
    }

    private function authorizeProduct(Product $produto): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($produto->tenant_id !== $tenantId) {
            abort(403);
        }

        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            if (! in_array($produto->id, $allowed, true)) {
                abort(403);
            }
        }
    }
}
