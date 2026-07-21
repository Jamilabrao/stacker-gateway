<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberTurma;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class MemberBuilderTurmaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function createSeller(): User
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        return $owner->fresh();
    }

    public function test_detach_turma_user_returns_json_and_removes_pivot(): void
    {
        $owner = $this->createSeller();

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'mbturma'.substr(uniqid('', true), -8),
            'slug' => 'mt-'.substr(uniqid('', true), -8),
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);
        $product->users()->attach($aluno->id);

        $turma = MemberTurma::create([
            'product_id' => $product->id,
            'name' => 'Turma A',
            'position' => 1,
        ]);
        $turma->users()->attach($aluno->id);

        $resp = $this->actingAs($owner)->deleteJson(
            route('member-builder.turmas.users.detach', [$product, $turma, $aluno->id])
        );

        $resp->assertOk()->assertJsonFragment(['message' => 'Aluno removido da turma.']);
        $this->assertFalse($turma->users()->where('users.id', $aluno->id)->exists());
    }

    public function test_destroy_turma_returns_json_and_deletes_row(): void
    {
        $owner = $this->createSeller();

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'mbtdel'.substr(uniqid('', true), -8),
            'slug' => 'mtd-'.substr(uniqid('', true), -8),
        ]);

        $turma = MemberTurma::create([
            'product_id' => $product->id,
            'name' => 'Turma para apagar',
            'position' => 1,
        ]);

        $resp = $this->actingAs($owner)->deleteJson(
            route('member-builder.turmas.destroy', [$product, $turma])
        );

        $resp->assertOk()->assertJsonFragment(['message' => 'Turma removida.']);
        $this->assertDatabaseMissing('member_turmas', ['id' => $turma->id]);
    }

    public function test_store_turma_returns_json_payload(): void
    {
        $owner = $this->createSeller();

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'mbtnew'.substr(uniqid('', true), -8),
            'slug' => 'mtn-'.substr(uniqid('', true), -8),
        ]);

        $resp = $this->actingAs($owner)->postJson(
            route('member-builder.turmas.store', $product),
            ['name' => 'Nova turma']
        );

        $resp->assertOk()
            ->assertJsonPath('turma.name', 'Nova turma')
            ->assertJsonStructure(['turma' => ['id', 'name', 'users']]);
        $this->assertDatabaseHas('member_turmas', [
            'product_id' => $product->id,
            'name' => 'Nova turma',
        ]);
    }
}
