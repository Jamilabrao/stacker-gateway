<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Consultas e apresentação do diretório admin de clientes (compradores).
 */
final class PlatformCustomerDirectory
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_DISPUTED = 'disputed';

    /** @var list<string> */
    public const ORDER_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_PENDING,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
        self::STATUS_DISPUTED,
    ];

    public const NOT_INFORMED = 'Não informado';

    /**
     * Base da listagem/export: usuários com pelo menos uma compra concluída.
     *
     * @return Builder<User>
     */
    public static function listingQuery(?string $search = null): Builder
    {
        $query = User::query()
            ->whereHas('orders', fn ($q) => $q->where('status', self::STATUS_COMPLETED))
            ->withCount([
                'orders as purchases_count' => fn ($q) => $q->where('status', self::STATUS_COMPLETED),
                'orders as purchases_pending_count' => fn ($q) => $q->where('status', self::STATUS_PENDING),
                'orders as purchases_cancelled_count' => fn ($q) => $q->where('status', self::STATUS_CANCELLED),
                'orders as purchases_refunded_count' => fn ($q) => $q->where('status', self::STATUS_REFUNDED),
                'orders as purchases_disputed_count' => fn ($q) => $q->where('status', self::STATUS_DISPUTED),
            ])
            ->withSum([
                'orders as total_spent' => fn ($q) => $q->where('status', self::STATUS_COMPLETED),
            ], 'amount')
            ->withSum([
                'orders as total_pending_amount' => fn ($q) => $q->where('status', self::STATUS_PENDING),
            ], 'amount')
            ->withMin(['orders as first_purchase_at' => fn ($q) => $q], 'created_at')
            ->withMax(['orders as last_purchase_at' => fn ($q) => $q], 'created_at')
            ->orderByDesc('id');

        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($like, $search) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('document', 'like', $like);
                if (Schema::hasColumn('users', 'phone')) {
                    $q->orWhere('phone', 'like', $like);
                }
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        return $query;
    }

    public static function searchFromRequest(Request $request): ?string
    {
        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';

        return $search !== '' ? $search : null;
    }

    public static function isViewableCustomer(User $user): bool
    {
        if ($user->isPlatformAdmin() || $user->isTeam()) {
            return false;
        }

        return $user->orders()
            ->where('status', self::STATUS_COMPLETED)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public static function purchaseSummary(User $user): array
    {
        $rows = Order::query()
            ->where('user_id', $user->id)
            ->selectRaw('status, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $count = static fn (string $status): int => (int) ($rows->get($status)?->cnt ?? 0);
        $sum = static fn (string $status): float => round((float) ($rows->get($status)?->total ?? 0), 2);

        $approvedCount = $count(self::STATUS_COMPLETED);
        $approvedTotal = $sum(self::STATUS_COMPLETED);
        $pendingCount = $count(self::STATUS_PENDING);
        $pendingTotal = $sum(self::STATUS_PENDING);
        $cancelledCount = $count(self::STATUS_CANCELLED);
        $refundedCount = $count(self::STATUS_REFUNDED);
        $disputedCount = $count(self::STATUS_DISPUTED);
        $totalOrders = $approvedCount + $pendingCount + $cancelledCount + $refundedCount + $disputedCount;

        $first = Order::query()->where('user_id', $user->id)->min('created_at');
        $last = Order::query()->where('user_id', $user->id)->max('created_at');

        return [
            'total_orders' => $totalOrders,
            'approved_count' => $approvedCount,
            'pending_count' => $pendingCount,
            'cancelled_count' => $cancelledCount,
            'refunded_count' => $refundedCount,
            'disputed_count' => $disputedCount,
            'approved_total' => $approvedTotal,
            'pending_total' => $pendingTotal,
            'average_ticket' => $approvedCount > 0 ? round($approvedTotal / $approvedCount, 2) : 0.0,
            'first_purchase_at' => $first,
            'last_purchase_at' => $last,
        ];
    }

    public static function formatCpf(?string $document): ?string
    {
        $digits = BrazilianDocuments::digits($document);
        if (strlen($digits) === 11) {
            return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
        }
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'
                .substr($digits, 8, 4).'-'.substr($digits, 12, 2);
        }
        $raw = trim((string) $document);

        return $raw !== '' ? $raw : null;
    }

    public static function formatPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        $local = $digits;
        if (str_starts_with($local, '55') && strlen($local) >= 12) {
            $local = substr($local, 2);
        }
        if (strlen($local) === 11) {
            return '('.substr($local, 0, 2).') '.substr($local, 2, 5).'-'.substr($local, 7);
        }
        if (strlen($local) === 10) {
            return '('.substr($local, 0, 2).') '.substr($local, 2, 4).'-'.substr($local, 6);
        }

        return trim((string) $phone) !== '' ? trim((string) $phone) : null;
    }

    public static function formatZip(?string $zip): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $zip) ?? '';
        if (strlen($digits) === 8) {
            return substr($digits, 0, 5).'-'.substr($digits, 5);
        }
        $raw = trim((string) $zip);

        return $raw !== '' ? $raw : null;
    }

    /**
     * @return array{has_address: bool, formatted: ?string, zip: ?string, street: ?string, number: ?string, complement: ?string, neighborhood: ?string, city: ?string, state: ?string}
     */
    public static function addressPayload(User $user): array
    {
        $zip = Schema::hasColumn('users', 'address_zip') ? trim((string) ($user->address_zip ?? '')) : '';
        $street = Schema::hasColumn('users', 'address_street') ? trim((string) ($user->address_street ?? '')) : '';
        $number = Schema::hasColumn('users', 'address_number') ? trim((string) ($user->address_number ?? '')) : '';
        $complement = Schema::hasColumn('users', 'address_complement') ? trim((string) ($user->address_complement ?? '')) : '';
        $neighborhood = Schema::hasColumn('users', 'address_neighborhood') ? trim((string) ($user->address_neighborhood ?? '')) : '';
        $city = Schema::hasColumn('users', 'address_city') ? trim((string) ($user->address_city ?? '')) : '';
        $state = Schema::hasColumn('users', 'address_state') ? trim((string) ($user->address_state ?? '')) : '';

        $has = $zip !== '' || $street !== '' || $number !== '' || $neighborhood !== '' || $city !== '' || $state !== '';

        $parts = [];
        if ($street !== '') {
            $line = $street;
            if ($number !== '') {
                $line .= ', '.$number;
            }
            if ($complement !== '') {
                $line .= ', '.$complement;
            }
            $parts[] = $line;
        } elseif ($number !== '') {
            $parts[] = $number;
        }
        if ($neighborhood !== '') {
            $parts[] = $neighborhood;
        }
        if ($city !== '' || $state !== '') {
            $parts[] = trim($city.($city !== '' && $state !== '' ? '/' : '').$state);
        }
        $zipFormatted = self::formatZip($zip !== '' ? $zip : null);
        if ($zipFormatted !== null) {
            $parts[] = 'CEP '.$zipFormatted;
        }

        return [
            'has_address' => $has,
            'formatted' => $has && $parts !== [] ? implode(', ', $parts) : null,
            'zip' => $zip !== '' ? $zipFormatted : null,
            'street' => $street !== '' ? $street : null,
            'number' => $number !== '' ? $number : null,
            'complement' => $complement !== '' ? $complement : null,
            'neighborhood' => $neighborhood !== '' ? $neighborhood : null,
            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
        ];
    }

    public static function accountStatusLabel(?string $status): string
    {
        $map = [
            'approved' => 'Aprovado',
            'pending' => 'Pendente',
            'blocked' => 'Bloqueado',
            'rejected' => 'Rejeitado',
            'suspended' => 'Suspenso',
        ];
        $key = (string) ($status ?? 'approved');

        return $map[$key] ?? ($key !== '' ? $key : self::NOT_INFORMED);
    }

    public static function orderStatusLabel(?string $status): string
    {
        $map = [
            self::STATUS_COMPLETED => 'Pago',
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_CANCELLED => 'Cancelado',
            self::STATUS_REFUNDED => 'Reembolsado',
            self::STATUS_DISPUTED => 'MED',
        ];

        return $map[$status ?? ''] ?? (($status ?? '') !== '' ? (string) $status : self::NOT_INFORMED);
    }

    public static function discountAmountFromOrder(Order $order): ?float
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        foreach (['discount_amount', 'coupon_discount', 'discount'] as $key) {
            if (isset($meta[$key]) && is_numeric($meta[$key])) {
                return round((float) $meta[$key], 2);
            }
        }

        return null;
    }

    public static function safeChargeUrlFromOrder(Order $order): ?string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        foreach (['boleto_url', 'payment_link', 'checkout_url', 'ticket_url', 'invoice_url', 'qr_code_url'] as $key) {
            $url = $meta[$key] ?? null;
            if (is_string($url) && SafeUrl::isAllowedHttpUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function exportHeaders(): array
    {
        return [
            'ID do cliente',
            'Nome',
            'E-mail',
            'Telefone',
            'CPF',
            'CEP',
            'Logradouro',
            'Número',
            'Complemento',
            'Bairro',
            'Cidade',
            'Estado',
            'Data de cadastro',
            'Status',
            'Total de compras',
            'Compras aprovadas',
            'Compras pendentes',
            'Compras canceladas',
            'Compras reembolsadas',
            'Valor total aprovado',
            'Valor total pendente',
            'Primeira compra',
            'Última compra',
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function exportRow(User $user): array
    {
        $address = self::addressPayload($user);
        $phone = Schema::hasColumn('users', 'phone') ? self::formatPhone($user->phone) : null;

        return [
            $user->id,
            $user->name,
            $user->email,
            $phone ?? '',
            self::formatCpf($user->document) ?? '',
            $address['zip'] ?? '',
            $address['street'] ?? '',
            $address['number'] ?? '',
            $address['complement'] ?? '',
            $address['neighborhood'] ?? '',
            $address['city'] ?? '',
            $address['state'] ?? '',
            optional($user->created_at)?->format('d/m/Y H:i') ?? '',
            self::accountStatusLabel($user->account_status),
            (int) ($user->purchases_count ?? 0)
                + (int) ($user->purchases_pending_count ?? 0)
                + (int) ($user->purchases_cancelled_count ?? 0)
                + (int) ($user->purchases_refunded_count ?? 0)
                + (int) ($user->purchases_disputed_count ?? 0),
            (int) ($user->purchases_count ?? 0),
            (int) ($user->purchases_pending_count ?? 0),
            (int) ($user->purchases_cancelled_count ?? 0),
            (int) ($user->purchases_refunded_count ?? 0),
            number_format((float) ($user->total_spent ?? 0), 2, ',', '.'),
            number_format((float) ($user->total_pending_amount ?? 0), 2, ',', '.'),
            self::formatExportDate($user->first_purchase_at ?? null),
            self::formatExportDate($user->last_purchase_at ?? null),
        ];
    }

    private static function formatExportDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return '';
        }
    }
}
