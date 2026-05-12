<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\Services\MemberAreaMagicAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts magic access via opaque token (e-mail) or legacy signed URL.
 */
class ValidateMemberAreaMagicAccess
{
    public function __construct(
        protected MemberAreaMagicAccessToken $magicTokens
    ) {}

    /**
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $product = $request->attributes->get('member_area_product');
        if (! $product instanceof Product) {
            abort(404, 'Área de membros não encontrada.');
        }

        $token = $request->query('m');
        if (is_string($token) && $token !== '') {
            $userId = $this->magicTokens->resolveUserId($token, $product);
            if ($userId === null) {
                return $this->rejectMagicAccess($request);
            }
            $request->attributes->set('member_area_magic_user_id', $userId);

            return $next($request);
        }

        if ($request->hasValidSignature()) {
            return $next($request);
        }

        return $this->rejectMagicAccess($request);
    }

    private function rejectMagicAccess(Request $request): Response
    {
        $slug = $request->attributes->get('member_area_slug');
        if (is_string($slug) && $slug !== '' && $request->routeIs('member-area.magic-access')) {
            return redirect()->route('member-area.login', ['slug' => $slug])
                ->with('error', 'Link inválido ou expirado. Faça login com e-mail e senha.');
        }

        return redirect()->to('/login')
            ->with('error', 'Link inválido ou expirado. Faça login com e-mail e senha.');
    }
}
