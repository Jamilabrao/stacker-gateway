<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'category')) {
            return;
        }

        $map = [
            'renda_extra_e_empreendedorismo' => 'negocios_e_empreendedorismo',
            'marketing_digital' => 'marketing_e_vendas',
            'vendas_e_negocios' => 'marketing_e_vendas',
            'tecnologia_e_programacao' => 'tecnologia_e_inovacao',
            'inteligencia_artificial' => 'tecnologia_e_inovacao',
            'design_e_criatividade' => 'lifestyle_e_hobbies',
            'fotografia_e_audiovisual' => 'lifestyle_e_hobbies',
            'redes_sociais_e_conteudo' => 'conteudo_e_recursos_digitais',
            'ecommerce_e_marketplaces' => 'negocios_e_empreendedorismo',
            'afiliados_e_infoprodutos' => 'marketing_e_vendas',
            'carreira_e_desenvolvimento_profissional' => 'desenvolvimento_pessoal',
            'produtividade_e_organizacao' => 'desenvolvimento_pessoal',
            'idiomas' => 'educacao_e_cursos',
            'concursos_e_vestibulares' => 'educacao_e_cursos',
            'fitness_e_emagrecimento' => 'saude_e_bem_estar',
            'nutricao_e_alimentacao' => 'saude_e_bem_estar',
            'beleza_e_estetica' => 'saude_e_bem_estar',
            'moda_e_estilo' => 'lifestyle_e_hobbies',
            'relacionamentos' => 'desenvolvimento_pessoal',
            'familia_e_parentalidade' => 'lifestyle_e_hobbies',
            'culinaria_e_gastronomia' => 'lifestyle_e_hobbies',
            'artesanato_e_trabalhos_manuais' => 'lifestyle_e_hobbies',
            'musica_e_instrumentos' => 'lifestyle_e_hobbies',
            'pets_e_animais' => 'lifestyle_e_hobbies',
            'casa_decoracao_e_jardinagem' => 'lifestyle_e_hobbies',
            'viagens_e_turismo' => 'lifestyle_e_hobbies',
            'espiritualidade_e_autoconhecimento' => 'desenvolvimento_pessoal',
            'direito_e_conhecimento_juridico' => 'negocios_e_empreendedorismo',
            'contabilidade_e_gestao_empresarial' => 'negocios_e_empreendedorismo',
            'imoveis_e_mercado_imobiliario' => 'negocios_e_empreendedorismo',
            'automotivo' => 'lifestyle_e_hobbies',
            'agronegocio' => 'negocios_e_empreendedorismo',
            'games_e_entretenimento' => 'lifestyle_e_hobbies',
            'esportes' => 'lifestyle_e_hobbies',
            'templates_packs_e_recursos_digitais' => 'conteudo_e_recursos_digitais',
            'softwares_sistemas_e_ferramentas' => 'tecnologia_e_inovacao',
            'comunidades_e_assinaturas' => 'conteudo_e_recursos_digitais',
            'eventos_workshops_e_mentorias' => 'educacao_e_cursos',
        ];

        foreach ($map as $from => $to) {
            DB::table('products')->where('category', $from)->update(['category' => $to]);
        }
    }

    public function down(): void
    {
        // Irreversível: várias categorias antigas colapsam na mesma nova.
    }
};
