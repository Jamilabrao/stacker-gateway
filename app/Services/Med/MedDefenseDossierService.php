<?php

namespace App\Services\Med;

use App\Models\MedDispute;
use App\Models\MemberLessonProgress;
use App\Models\Order;
use App\Models\Product;
use App\Services\MemberProgressService;
use Carbon\Carbon;
use FPDF;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MedDefenseDossierService
{
    public function __construct(
        protected MedPolicyService $policy,
        protected MemberProgressService $progressService,
    ) {}

    public function generate(MedDispute $dispute): string
    {
        $dispute->loadMissing(['order.product', 'order.user', 'tenantOwner']);
        $order = $dispute->order;
        if ($order === null) {
            throw new \InvalidArgumentException('Pedido não encontrado para a disputa.');
        }

        $relativePath = 'med-dossiers/'.$dispute->id.'.pdf';
        $disk = Storage::disk('local');
        $disk->makeDirectory('med-dossiers');
        $absolutePath = $disk->path($relativePath);

        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, $this->encode('Dossiê de prova de entrega — MED'), 0, 1);

        $pdf->SetFont('Arial', '', 11);
        $this->sectionTitle($pdf, 'Identificação do pedido');
        $this->line($pdf, 'Pedido #'.$order->id.' ('.($order->public_reference ?? '—').')');
        $this->line($pdf, 'Valor: R$ '.number_format((float) $order->amount, 2, ',', '.'));
        $this->line($pdf, 'Pagamento: '.($order->paid_at ?? $order->updated_at?->format('d/m/Y H:i') ?? '—'));
        $this->line($pdf, 'Gateway: '.($order->gateway ?? '—'));
        $this->line($pdf, 'Método: '.($order->payment_method ?? '—'));
        $this->line($pdf, 'Cliente: '.($order->email ?? '—'));
        if ($order->user?->name) {
            $this->line($pdf, 'Nome: '.$order->user->name);
        }
        if ($dispute->txid) {
            $this->line($pdf, 'TXID: '.$dispute->txid);
        }

        $product = $order->product;
        if ($product) {
            $this->sectionTitle($pdf, 'Produto');
            $this->line($pdf, 'Nome: '.$product->name);
            $this->line($pdf, 'Tipo: '.$this->productTypeLabel($product));
        }

        $this->sectionTitle($pdf, 'Prova de acesso');
        $customerUser = $order->user;
        if ($customerUser && $product) {
            $enrolledAt = DB::table('product_user')
                ->where('product_id', $product->id)
                ->where('user_id', $customerUser->id)
                ->value('created_at');
            if ($enrolledAt) {
                $enrolled = Carbon::parse($enrolledAt);
                $this->line($pdf, 'Matrícula (product_user): '.$enrolled->format('d/m/Y H:i:s'));
                $paidAt = $order->updated_at;
                if ($paidAt && $order->status === 'completed') {
                    $hours = $enrolled->diffInHours($paidAt);
                    $this->line($pdf, 'Tempo pagamento → primeiro acesso: ~'.$hours.' h');
                }
            } else {
                $this->line($pdf, 'Matrícula: não registrada em product_user.');
            }

            if ($product->type === Product::TYPE_AREA_MEMBROS) {
                $total = $this->progressService->totalLessonsCount($product);
                $completed = $this->progressService->completedLessonsCount($product, $customerUser);
                $percent = $this->progressService->completionPercent($product, $customerUser);
                $this->line($pdf, 'Área de membros — aulas concluídas: '.$completed.' / '.$total.' ('.$percent.'%)');

                $lessons = MemberLessonProgress::forProduct($product->id)
                    ->forUser($customerUser->id)
                    ->whereNotNull('completed_at')
                    ->orderBy('completed_at')
                    ->limit(15)
                    ->get();
                foreach ($lessons as $lp) {
                    $this->line($pdf, '  • Aula #'.$lp->member_lesson_id.' concluída em '.$lp->completed_at?->format('d/m/Y H:i'));
                }
            }
        } else {
            $this->line($pdf, 'Dados de acesso indisponíveis (sem usuário ou produto vinculado).');
        }

        $this->sectionTitle($pdf, 'Contexto MED');
        $this->line($pdf, 'Disputa #'.$dispute->id);
        if ($dispute->cajupay_dispute_id) {
            $this->line($pdf, 'ID CajuPay: '.$dispute->cajupay_dispute_id);
        }
        $this->line($pdf, 'Abertura: '.($dispute->opened_at?->format('d/m/Y H:i') ?? '—'));
        if ($dispute->reason) {
            $this->line($pdf, 'Motivo: '.$dispute->reason);
        }
        if ($dispute->reason_code) {
            $this->line($pdf, 'Código: '.$dispute->reason_code);
        }
        $this->line($pdf, 'Origem venda: '.$this->policy->orderOriginLabel($order));
        $this->line($pdf, 'Responsável: '.($dispute->responsible_party === MedDispute::PARTY_PLATFORM ? 'Plataforma' : 'Infoprodutor'));

        $pdf->Ln(8);
        $pdf->SetFont('Arial', 'I', 9);
        $appName = config('app.name', 'Getfy');
        $pdf->Cell(0, 6, $this->encode('Gerado em '.now()->format('d/m/Y H:i').' — '.$appName), 0, 1);

        $pdf->Output('F', $absolutePath);

        $dispute->update(['defense_dossier_path' => $relativePath]);

        return $relativePath;
    }

    public function isAvailable(MedDispute $dispute): bool
    {
        return $this->downloadPath($dispute) !== null;
    }

    public function downloadPath(MedDispute $dispute): ?string
    {
        $path = $dispute->defense_dossier_path;
        if ($path === null || $path === '') {
            return null;
        }

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->path($path);
        }

        // Compat: dossiês gerados antes do disco local apontar para storage/app/private
        $legacyPath = storage_path('app/'.$path);
        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        return null;
    }

    private function sectionTitle(FPDF $pdf, string $title): void
    {
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $this->encode($title), 0, 1);
        $pdf->SetFont('Arial', '', 11);
    }

    private function line(FPDF $pdf, string $text): void
    {
        $pdf->MultiCell(0, 6, $this->encode($text));
    }

    private function productTypeLabel(Product $product): string
    {
        return match ($product->type) {
            Product::TYPE_PRODUTO_FISICO => 'Produto físico',
            Product::TYPE_AREA_MEMBROS => 'Área de membros',
            default => 'Digital / link',
        };
    }

    private function encode(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);

        return $converted !== false ? $converted : $text;
    }
}
