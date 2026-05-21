<?php

namespace App\Http\Controllers;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\PlatformEmailNotifications;
use App\Support\KycUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SellerKycController extends Controller
{
    public function __construct(
        protected PlatformEmailNotifications $platformEmailNotifications
    ) {}

    private static function financeiroKycTabUrl(): string
    {
        return '/financeiro?tab=seus-dados';
    }

    /** @var array<string, string> */
    private const FIELD_TO_KIND = [
        'rg_front' => KycDocument::KIND_RG_FRONT,
        'rg_back' => KycDocument::KIND_RG_BACK,
        'company_document' => KycDocument::KIND_COMPANY_DOCUMENT,
    ];

    public function show(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->canAccessSellerPanel()) {
            abort(403);
        }

        $subject = $user->kycSubjectUser();
        if ($subject->kyc_status === User::KYC_APPROVED) {
            return redirect()->route('dashboard')->with('success', 'Sua conta já está verificada.');
        }

        return redirect(self::financeiroKycTabUrl());
    }

    /**
     * Envia um documento por vez (evita POST gigante com vários arquivos).
     */
    public function uploadDocument(Request $request): JsonResponse|RedirectResponse
    {
        if ($message = KycUpload::detectPostTooLarge()) {
            throw ValidationException::withMessages(['upload' => $message]);
        }

        $user = $request->user();
        if (! $user->canAccessSellerPanel()) {
            abort(403);
        }

        $subject = $user->kycSubjectUser();
        if ($subject->kyc_status === User::KYC_APPROVED) {
            return response()->json(['message' => 'Conta já verificada.'], 422);
        }
        if ($subject->kyc_status === User::KYC_PENDING_REVIEW) {
            return response()->json(['message' => 'Documentos em análise. Aguarde a conclusão.'], 422);
        }

        $isPj = $subject->person_type === 'pj';

        $validated = $request->validate([
            'field' => ['required', 'string', Rule::in(array_keys(self::FIELD_TO_KIND))],
        ]);

        $field = $validated['field'];
        if ($field === 'company_document' && ! $isPj) {
            throw ValidationException::withMessages([
                'company_document' => 'Documento da empresa só se aplica a contas PJ.',
            ]);
        }

        if (! $request->hasFile($field)) {
            throw ValidationException::withMessages([
                $field => KycUpload::messageForUploadError(UPLOAD_ERR_NO_FILE),
            ]);
        }

        $file = $request->file($field);
        KycUpload::assertValid($file, $field);

        $kind = self::FIELD_TO_KIND[$field];
        $disk = Storage::disk('local');
        $baseDir = 'kyc/'.$subject->id;

        try {
            KycDocument::query()
                ->where('user_id', $subject->id)
                ->where('kind', $kind)
                ->get()
                ->each(function (KycDocument $old) use ($disk) {
                    if ($old->disk_path && $disk->exists($old->disk_path)) {
                        $disk->delete($old->disk_path);
                    }
                    $old->delete();
                });

            $this->storeFile($subject, $file, $kind, $disk, $baseDir);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                $field => 'Não foi possível processar o arquivo. Use imagem (JPG, PNG, WebP, GIF ou HEIC) ou PDF, máx. 20 MB.',
            ]);
        }

        if ($request->header('X-Inertia')) {
            return redirect(self::financeiroKycTabUrl())->with('success', 'Arquivo enviado. Envie os demais e clique em "Enviar para análise".');
        }

        return response()->json([
            'ok' => true,
            'field' => $field,
            'message' => 'Arquivo recebido.',
        ]);
    }

    /**
     * Envia todos os documentos obrigatórios de uma vez (legado / fallback).
     */
    public function store(Request $request): RedirectResponse
    {
        if ($message = KycUpload::detectPostTooLarge()) {
            return redirect(self::financeiroKycTabUrl())->with('error', $message);
        }

        $user = $request->user();
        if (! $user->canAccessSellerPanel()) {
            abort(403);
        }

        $subject = $user->kycSubjectUser();
        if ($subject->kyc_status === User::KYC_APPROVED) {
            return redirect(self::financeiroKycTabUrl())->with('error', 'Conta já verificada.');
        }

        $isPj = $subject->person_type === 'pj';

        $kycFile = ['required', 'file', 'max:'.KycUpload::MAX_FILE_KB, 'mimes:jpg,jpeg,png,webp,gif,heic,heif,pdf'];

        $rules = [
            'rg_front' => $kycFile,
            'rg_back' => $kycFile,
        ];
        if ($isPj) {
            $rules['company_document'] = $kycFile;
        }

        $messages = [
            'rg_front.max' => 'O arquivo da frente do RG não pode ser maior que 20 MB.',
            'rg_back.max' => 'O arquivo do verso do RG não pode ser maior que 20 MB.',
            'company_document.max' => 'O documento da empresa não pode ser maior que 20 MB.',
            'rg_front.uploaded' => KycUpload::messageForUploadError(UPLOAD_ERR_NO_FILE),
            'rg_back.uploaded' => KycUpload::messageForUploadError(UPLOAD_ERR_NO_FILE),
            'company_document.uploaded' => KycUpload::messageForUploadError(UPLOAD_ERR_NO_FILE),
        ];

        foreach (array_keys($rules) as $field) {
            if (! $request->hasFile($field)) {
                throw ValidationException::withMessages([
                    $field => KycUpload::messageForUploadError(UPLOAD_ERR_NO_FILE),
                ]);
            }
        }

        $request->validate($rules, $messages);

        $disk = Storage::disk('local');
        $baseDir = 'kyc/'.$subject->id;

        KycDocument::query()->where('user_id', $subject->id)->get()->each(function (KycDocument $old) use ($disk) {
            if ($old->disk_path && $disk->exists($old->disk_path)) {
                $disk->delete($old->disk_path);
            }
            $old->delete();
        });

        try {
            $this->storeFile($subject, $request->file('rg_front'), KycDocument::KIND_RG_FRONT, $disk, $baseDir);
            $this->storeFile($subject, $request->file('rg_back'), KycDocument::KIND_RG_BACK, $disk, $baseDir);

            if ($isPj && $request->hasFile('company_document')) {
                $this->storeFile($subject, $request->file('company_document'), KycDocument::KIND_COMPANY_DOCUMENT, $disk, $baseDir);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return redirect(self::financeiroKycTabUrl())->with('error', 'Não foi possível processar os arquivos. Use imagem (JPG, PNG, WebP, GIF ou HEIC) ou PDF, máx. 20 MB por arquivo.');
        }

        return $this->markPendingReview($subject);
    }

    public function finalize(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->canAccessSellerPanel()) {
            abort(403);
        }

        $subject = $user->kycSubjectUser();
        if ($subject->kyc_status === User::KYC_APPROVED) {
            return redirect(self::financeiroKycTabUrl())->with('error', 'Conta já verificada.');
        }
        if ($subject->kyc_status === User::KYC_PENDING_REVIEW) {
            return redirect(self::financeiroKycTabUrl())->with('info', 'Seus documentos já estão em análise.');
        }

        $requiredKinds = [KycDocument::KIND_RG_FRONT, KycDocument::KIND_RG_BACK];
        if ($subject->person_type === 'pj') {
            $requiredKinds[] = KycDocument::KIND_COMPANY_DOCUMENT;
        }

        $existing = KycDocument::query()
            ->where('user_id', $subject->id)
            ->whereIn('kind', $requiredKinds)
            ->pluck('kind')
            ->all();

        $missing = array_diff($requiredKinds, $existing);
        if ($missing !== []) {
            $labels = [
                KycDocument::KIND_RG_FRONT => 'RG (frente)',
                KycDocument::KIND_RG_BACK => 'RG (verso)',
                KycDocument::KIND_COMPANY_DOCUMENT => 'documento da empresa',
            ];
            $list = implode(', ', array_map(fn ($k) => $labels[$k] ?? $k, $missing));

            return redirect(self::financeiroKycTabUrl())->with('error', 'Envie todos os documentos antes de concluir: '.$list.'.');
        }

        return $this->markPendingReview($subject);
    }

    private function markPendingReview(User $subject): RedirectResponse
    {
        $subject->forceFill([
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'kyc_rejection_reason' => null,
            'kyc_reviewed_at' => null,
            'kyc_reviewed_by' => null,
        ])->save();

        $this->platformEmailNotifications->kycSubmitted($subject->fresh());

        return redirect(self::financeiroKycTabUrl())->with('success', 'Documentos enviados. Aguarde a análise da plataforma.');
    }

    private function storeFile(User $subject, \Illuminate\Http\UploadedFile $file, string $kind, \Illuminate\Contracts\Filesystem\Filesystem $disk, string $baseDir): void
    {
        $mime = KycUpload::normalizeMime($file);
        if (! in_array($mime, KycUpload::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('MIME não permitido.');
        }
        if ($file->getSize() > KycUpload::MAX_BYTES) {
            throw new \InvalidArgumentException('Arquivo muito grande.');
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        $name = Str::uuid()->toString().'.'.$ext;
        $storedPath = $disk->putFileAs($baseDir, $file, $name);
        if (! is_string($storedPath) || $storedPath === '') {
            throw new \RuntimeException('Falha ao gravar arquivo.');
        }

        KycDocument::query()->create([
            'user_id' => $subject->id,
            'kind' => $kind,
            'disk_path' => $storedPath,
            'original_mime' => $mime,
            'size_bytes' => (int) $file->getSize(),
        ]);
    }
}
