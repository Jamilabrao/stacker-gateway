<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\User;
use App\Support\KycUpload;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycUploadSecurityTest extends TestCase
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

    private function createSellerForKyc(): User
    {
        $seller = User::query()->create([
            'name' => 'Seller KYC',
            'email' => 'kyc-upload-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'person_type' => 'pf',
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
        ]);
        $seller->update(['tenant_id' => $seller->id]);

        return $seller->fresh();
    }

    public function test_valid_jpeg_upload_is_accepted(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $jpeg = UploadedFile::fake()->image('rg-front.jpg', 800, 600);

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $jpeg,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('kyc_documents', [
            'user_id' => $seller->id,
            'kind' => 'rg_front',
            'original_mime' => 'image/jpeg',
        ]);
    }

    public function test_valid_pdf_upload_is_accepted(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $pdf = UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 test content');

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $pdf,
            ])
            ->assertOk();

        $this->assertDatabaseHas('kyc_documents', [
            'user_id' => $seller->id,
            'original_mime' => 'application/pdf',
        ]);
    }

    public function test_svg_upload_is_rejected(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $svg = UploadedFile::fake()->createWithContent(
            'evil.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'image/svg+xml'
        );

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $svg,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rg_front']);
    }

    public function test_php_file_renamed_as_jpeg_is_rejected(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $php = UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "x"; ?>', 'image/jpeg');

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $php,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rg_front']);
    }

    public function test_corrupted_image_with_jpeg_mime_is_rejected(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $bad = UploadedFile::fake()->createWithContent('broken.jpg', 'not-an-image', 'image/jpeg');

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $bad,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rg_front']);
    }

    public function test_pdf_without_valid_header_is_rejected(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $fakePdf = UploadedFile::fake()->createWithContent('fake.pdf', 'NOTPDF content', 'application/pdf');

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $fakePdf,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rg_front']);
    }

    public function test_stored_extension_derives_from_mime_not_client(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $jpeg = UploadedFile::fake()->image('evil.php.jpg', 200, 200);

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $jpeg,
            ])
            ->assertOk();

        $doc = $seller->kycDocuments()->where('kind', 'rg_front')->first();
        $this->assertNotNull($doc);
        $this->assertStringEndsWith('.jpg', $doc->disk_path);
        $this->assertStringNotContainsString('.php', $doc->disk_path);
    }

    public function test_kyc_upload_unit_rejects_octet_stream_without_client_fallback(): void
    {
        $file = UploadedFile::fake()->createWithContent('unknown.bin', 'data', 'application/octet-stream');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        KycUpload::assertValid($file, 'rg_front');
    }

    public function test_gif_upload_is_rejected(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $gif = UploadedFile::fake()->createWithContent(
            'doc.gif',
            'GIF89a'.str_repeat("\0", 20),
            'image/gif'
        );

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $gif,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rg_front']);
    }

    public function test_gif_disguised_as_jpeg_is_rejected(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $gif = UploadedFile::fake()->createWithContent(
            'photo.jpg',
            'GIF89a'.str_repeat("\0", 20),
            'image/jpeg'
        );

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $gif,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rg_front']);
    }

    public function test_tiny_image_below_minimum_dimensions_is_rejected(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $tiny = UploadedFile::fake()->image('tiny.jpg', 50, 50);

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $tiny,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rg_front']);
    }

    public function test_oversized_dimensions_are_rejected(): void
    {
        Storage::fake('local');

        $seller = $this->createSellerForKyc();
        $huge = UploadedFile::fake()->image('huge.jpg', 10001, 200);

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'rg_front' => $huge,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rg_front']);
    }
}
