<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycDocumentViewDownloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_kyc_document_opens_inline_by_default_and_downloads_with_query(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'kyc_status' => User::KYC_PENDING_REVIEW,
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $path = 'kyc/'.$seller->id.'/rg_front.jpg';
        Storage::disk('local')->put($path, 'fake-image-bytes');

        $doc = KycDocument::query()->create([
            'user_id' => $seller->id,
            'kind' => KycDocument::KIND_RG_FRONT,
            'disk_path' => $path,
            'original_mime' => 'image/jpeg',
            'size_bytes' => 16,
            'public_token' => '11111111-1111-4111-8111-111111111111',
        ]);

        $view = $this->actingAs($admin)->get(route('plataforma.kyc.document', $doc));
        $view->assertOk();
        $view->assertHeader('content-disposition', 'inline; filename="kyc-document.jpg"');

        $download = $this->actingAs($admin)->get(route('plataforma.kyc.document', [
            'document' => $doc,
            'download' => 1,
        ]));
        $download->assertOk();
        $download->assertHeader('content-disposition', 'attachment; filename="kyc-document.jpg"');

        $page = $this->actingAs($admin)->get(route('plataforma.kyc.show', $seller));
        $page->assertOk()->assertInertia(fn ($assert) => $assert
            ->component('Platform/Kyc/Show')
            ->has('documents', 1)
            ->where('documents.0.view_url', route('plataforma.kyc.document', $doc))
            ->where('documents.0.download_url', route('plataforma.kyc.document', $doc).'?download=1')
        );
    }
}
