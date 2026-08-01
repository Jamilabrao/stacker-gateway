<?php

namespace Database\Seeders;

use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Produto fictício com Área de Membros para testar o preview admin local.
 *
 * Uso: php artisan db:seed --class=DemoMemberAreaProductSeeder
 *
 * Senha do seller/aluno demo: password
 */
class DemoMemberAreaProductSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const SELLER_EMAIL = 'seller.area.membros@example.com';

    private const STUDENT_EMAIL = 'aluno.area.membros@example.com';

    private const CHECKOUT_SLUG = 'demomembros01';

    public function run(): void
    {
        $seller = $this->upsertSeller();
        $product = $this->upsertMemberAreaProduct($seller);
        $this->seedCurriculum($product);
        $student = $this->upsertStudent($product);

        $previewUrl = url('/plataforma/produtos/'.$product->id.'/area-membros/preview');
        $memberUrl = url('/m/'.$product->checkout_slug);

        $this->command?->info('Demo Área de Membros OK.');
        $this->command?->table(
            ['Item', 'Valor'],
            [
                ['Produto', $product->name],
                ['ID', (string) $product->id],
                ['Slug /m/', $product->checkout_slug],
                ['Seller', self::SELLER_EMAIL.' / '.self::PASSWORD],
                ['Aluno matriculado', self::STUDENT_EMAIL.' / '.self::PASSWORD],
                ['Admin plataforma', 'admin@admin.com / 12345678'],
                ['Preview admin', $previewUrl],
                ['Área de membros', $memberUrl],
                ['Listagem admin', url('/plataforma/produtos')],
            ]
        );
        $this->command?->info('No admin: Produtos → busque "Demo Moderação" → Ver Área de Membros.');
    }

    private function upsertSeller(): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => self::SELLER_EMAIL],
            [
                'name' => 'Seller Demo Área de Membros',
                'password' => Hash::make(self::PASSWORD),
                'role' => User::ROLE_INFOPRODUTOR,
                'account_status' => 'approved',
                'person_type' => 'pf',
                'phone' => '11995554433',
                'document' => '39053344705',
                'email_verified_at' => now(),
            ]
        );
        $user->forceFill(['tenant_id' => $user->id])->save();

        return $user->fresh();
    }

    private function upsertMemberAreaProduct(User $seller): Product
    {
        $config = array_replace_recursive(Product::defaultMemberAreaConfig(), [
            'hero' => [
                'title' => 'Academia Demo Stacker',
                'subtitle' => 'Conteúdo fictício para moderação e conformidade',
                'overlay' => true,
            ],
            'login' => [
                'title' => 'Academia Demo',
                'subtitle' => 'Entre para acessar as aulas de demonstração',
            ],
            'pwa' => [
                'name' => 'Academia Demo Stacker',
                'short_name' => 'Demo AM',
            ],
            'comments_enabled' => true,
            'comments_require_approval' => false,
        ]);

        $existing = Product::query()
            ->where('checkout_slug', self::CHECKOUT_SLUG)
            ->first();

        $payload = [
            'tenant_id' => $seller->id,
            'name' => 'Demo Moderação — Área de Membros',
            'slug' => 'demo-moderacao-area-membros',
            'checkout_slug' => self::CHECKOUT_SLUG,
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 97.00,
            'currency' => 'BRL',
            'is_active' => true,
            'description' => 'Produto fictício para testar o preview administrativo da Área de Membros.',
            'member_area_config' => $config,
        ];

        if (Schema::hasColumn('products', 'admin_blocked')) {
            $payload['admin_blocked'] = false;
        }

        if ($existing) {
            $existing->forceFill($payload)->save();

            return $existing->fresh();
        }

        $product = new Product;
        $product->forceFill($payload);
        $product->save();

        return $product->fresh();
    }

    private function seedCurriculum(Product $product): void
    {
        MemberLesson::query()->where('product_id', $product->id)->delete();
        MemberModule::query()->where('product_id', $product->id)->delete();
        MemberSection::query()->where('product_id', $product->id)->delete();

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Fundamentos (demo)',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $moduleIntro = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo 1 — Boas-vindas',
            'position' => 1,
            'show_title_on_cover' => true,
        ]);

        $moduleContent = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo 2 — Conteúdo de exemplo',
            'position' => 2,
            'show_title_on_cover' => true,
        ]);

        MemberLesson::create([
            'member_module_id' => $moduleIntro->id,
            'product_id' => $product->id,
            'title' => 'Aula 1 — Como usar esta demo',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => "<p>Esta é uma <strong>Área de Membros fictícia</strong> criada para testar o preview do administrador da plataforma.</p><p>O admin deve conseguir abrir este conteúdo em modo somente leitura, sem criar compra ou matrícula.</p>",
            'is_free' => true,
        ]);

        MemberLesson::create([
            'member_module_id' => $moduleIntro->id,
            'product_id' => $product->id,
            'title' => 'Aula 2 — Políticas e conformidade',
            'position' => 2,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Conteúdo de exemplo para auditoria: materiais, textos e estrutura publicados pelo seller.</p><ul><li>Não gera progresso para o admin</li><li>Não cria vínculo em product_user</li><li>Banner de modo administrador deve aparecer</li></ul>',
            'is_free' => false,
        ]);

        MemberLesson::create([
            'member_module_id' => $moduleContent->id,
            'product_id' => $product->id,
            'title' => 'Aula 3 — Link externo de exemplo',
            'position' => 1,
            'type' => MemberLesson::TYPE_LINK,
            'content_url' => 'https://example.com/demo-conteudo',
            'link_title' => 'Abrir material externo (exemplo)',
            'is_free' => false,
        ]);

        MemberLesson::create([
            'member_module_id' => $moduleContent->id,
            'product_id' => $product->id,
            'title' => 'Aula 4 — Texto complementar',
            'position' => 2,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Última aula da demo. Use o botão <em>Voltar aos produtos</em> no banner amarelo para retornar ao painel admin.</p>',
            'is_free' => false,
        ]);
    }

    private function upsertStudent(Product $product): User
    {
        $student = User::query()->updateOrCreate(
            ['email' => self::STUDENT_EMAIL],
            [
                'name' => 'Aluno Demo Área de Membros',
                'password' => Hash::make(self::PASSWORD),
                'role' => User::ROLE_CLIENTE,
                'account_status' => 'approved',
                'tenant_id' => null,
                'phone' => '11991112233',
                'document' => '52998224725',
                'email_verified_at' => now(),
            ]
        );

        if (! $product->users()->where('user_id', $student->id)->exists()) {
            $product->users()->attach($student->id);
        }

        return $student->fresh();
    }
}
