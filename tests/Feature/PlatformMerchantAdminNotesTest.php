<?php

namespace Tests\Feature;

use App\Models\MerchantAdminNote;
use App\Models\User;
use Tests\TestCase;

class PlatformMerchantAdminNotesTest extends TestCase
{
    public function test_platform_admin_can_list_and_create_merchant_admin_notes(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $list = $this->actingAs($admin)->getJson(route('plataforma.usuarios.notes.index', $merchant));
        $list->assertOk();
        $list->assertJsonPath('notes', []);

        $create = $this->actingAs($admin)->postJson(route('plataforma.usuarios.notes.store', $merchant), [
            'body' => 'Acordo comercial 4% até 31/12',
        ]);
        $create->assertCreated();
        $create->assertJsonPath('note.body', 'Acordo comercial 4% até 31/12');
        $create->assertJsonPath('note.author.id', $admin->id);

        $this->assertDatabaseHas('merchant_admin_notes', [
            'merchant_user_id' => $merchant->id,
            'author_user_id' => $admin->id,
            'body' => 'Acordo comercial 4% até 31/12',
        ]);

        $listAfter = $this->actingAs($admin)->getJson(route('plataforma.usuarios.notes.index', $merchant));
        $listAfter->assertOk();
        $listAfter->assertJsonCount(1, 'notes');
    }

    public function test_merchant_cannot_access_admin_notes(): void
    {
        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $this->actingAs($merchant)
            ->getJson(route('plataforma.usuarios.notes.index', $merchant))
            ->assertForbidden();

        $this->actingAs($merchant)
            ->postJson(route('plataforma.usuarios.notes.store', $merchant), [
                'body' => 'Tentativa de nota',
            ])
            ->assertForbidden();
    }

    public function test_note_body_is_sanitized(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $merchant = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $merchant->forceFill(['tenant_id' => $merchant->id])->save();

        $this->actingAs($admin)->postJson(route('plataforma.usuarios.notes.store', $merchant), [
            'body' => '<script>alert(1)</script>Observação válida',
        ])->assertCreated();

        $note = MerchantAdminNote::query()->where('merchant_user_id', $merchant->id)->first();
        $this->assertNotNull($note);
        $this->assertStringNotContainsString('<script>', $note->body);
        $this->assertStringContainsString('Observação válida', $note->body);
    }
}
