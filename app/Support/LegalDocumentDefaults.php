<?php

namespace App\Support;

/**
 * Textos legais padrão (genéricos, sem nome de marca).
 * Placeholder: {{privacy_contact_email}}
 */
class LegalDocumentDefaults
{
    public static function privacyPolicyHtml(): string
    {
        return <<<'HTML'
<h1>Política de Privacidade</h1>
<p>Esta Política de Privacidade descreve como o operador desta plataforma trata dados pessoais de titulares que utilizam nossos serviços, em conformidade com a Lei nº 13.709/2018 (Lei Geral de Proteção de Dados Pessoais — LGPD) e normas correlatas.</p>

<h2>1. Controlador e contato</h2>
<p>O controlador dos dados pessoais é o operador responsável por esta plataforma. Para exercer seus direitos ou esclarecer dúvidas sobre privacidade, entre em contato pelo e-mail: <strong>{{privacy_contact_email}}</strong>.</p>

<h2>2. Dados que podemos coletar</h2>
<ul>
<li><strong>Cadastro e conta:</strong> nome, e-mail, documento (CPF/CNPJ), data de nascimento, endereço, senha (armazenada de forma criptografada), dados de representante legal quando aplicável.</li>
<li><strong>Verificação de identidade (KYC):</strong> documentos enviados para validação, conforme exigências regulatórias e de prevenção à fraude.</li>
<li><strong>Transações e pagamentos:</strong> dados de compra, histórico de pedidos, informações necessárias a gateways de pagamento e instituições financeiras.</li>
<li><strong>Checkout:</strong> dados informados pelo comprador (nome, e-mail, telefone, endereço quando necessário para entrega).</li>
<li><strong>Uso da plataforma:</strong> registros de acesso, endereço IP, logs de segurança, cookies e tecnologias similares.</li>
</ul>

<h2>3. Finalidades e bases legais</h2>
<p>Tratamos dados pessoais para:</p>
<ul>
<li>executar o contrato e prestar os serviços solicitados (Art. 7º, V);</li>
<li>cumprir obrigações legais e regulatórias (Art. 7º, II);</li>
<li>prevenir fraudes e garantir segurança (legítimo interesse, Art. 7º, IX);</li>
<li>comunicações operacionais e suporte (execução de contrato ou legítimo interesse);</li>
<li>mediante consentimento, quando exigido (Art. 7º, I), por exemplo em comunicações de marketing não essenciais.</li>
</ul>

<h2>4. Compartilhamento</h2>
<p>Podemos compartilhar dados com processadores e parceiros estritamente necessários à operação, tais como provedores de hospedagem, gateways de pagamento, serviços de e-mail, ferramentas de análise e prevenção à fraude, sempre com medidas contratuais de proteção compatíveis com a LGPD.</p>
<p>Não vendemos dados pessoais. O compartilhamento pode ocorrer também por determinação legal ou ordem de autoridade competente.</p>

<h2>5. Retenção e segurança</h2>
<p>Mantemos os dados pelo tempo necessário às finalidades descritas, inclusive para cumprimento de prazos legais, contábeis e de defesa em processos. Adotamos medidas técnicas e administrativas razoáveis para proteger os dados contra acessos não autorizados, perda ou alteração indevida.</p>

<h2>6. Direitos do titular (Art. 18 da LGPD)</h2>
<p>Você pode solicitar, conforme aplicável: confirmação de tratamento; acesso; correção; anonimização, bloqueio ou eliminação; portabilidade; informação sobre compartilhamentos; revogação de consentimento; e oposição a tratamentos baseados em legítimo interesse, quando cabível.</p>
<p>As solicitações devem ser enviadas ao e-mail indicado na seção 1. Podemos solicitar informações adicionais para confirmar sua identidade.</p>

<h2>7. Cookies e tecnologias similares</h2>
<p>Utilizamos cookies e tecnologias semelhantes para funcionamento essencial da plataforma, segurança, preferências e, quando autorizado, análise de uso. Você pode gerenciar preferências no banner de cookies ou nas configurações do navegador. Cookies essenciais podem ser necessários ao uso do serviço.</p>

<h2>8. Transferência internacional</h2>
<p>Se houver transferência de dados para outros países, adotaremos garantias previstas na LGPD, como cláusulas contratuais ou decisões de adequação, conforme o caso.</p>

<h2>9. Alterações</h2>
<p>Esta política pode ser atualizada periodicamente. A versão vigente estará sempre disponível nesta página. Alterações relevantes poderão ser comunicadas por meios razoáveis (e-mail ou aviso na plataforma).</p>

<h2>10. Legislação aplicável</h2>
<p>Esta política é regida pelas leis da República Federativa do Brasil. O titular também pode apresentar reclamação à Autoridade Nacional de Proteção de Dados (ANPD).</p>
HTML;
    }

    public static function termsOfUseHtml(): string
    {
        return <<<'HTML'
<h1>Termos de Uso</h1>
<p>Ao acessar ou utilizar esta plataforma, você declara ter lido, compreendido e aceito estes Termos de Uso. Se não concordar, não utilize os serviços.</p>

<h2>1. Objeto</h2>
<p>Estes termos regulam o uso da plataforma disponibilizada pelo operador, incluindo cadastro de conta, comercialização de produtos digitais ou físicos, processamento de pagamentos, gestão financeira e demais funcionalidades oferecidas.</p>

<h2>2. Elegibilidade e cadastro</h2>
<p>O usuário deve ter capacidade civil e, quando exigido, idade mínima legal. As informações fornecidas no cadastro devem ser verdadeiras, completas e atualizadas. Você é responsável pela confidencialidade de suas credenciais e por todas as atividades realizadas em sua conta.</p>

<h2>3. Uso permitido</h2>
<p>É permitido utilizar a plataforma conforme sua finalidade legítima, respeitando a legislação brasileira, estes termos e políticas complementares (incluindo a Política de Privacidade).</p>

<h2>4. Condutas proibidas</h2>
<ul>
<li>Utilizar a plataforma para atividades ilícitas, fraudulentas ou que violem direitos de terceiros;</li>
<li>Tentar acessar áreas ou dados de outros usuários sem autorização;</li>
<li>Interferir no funcionamento, segurança ou integridade dos sistemas;</li>
<li>Comercializar produtos proibidos por lei ou pelas regras da plataforma;</li>
<li>Utilizar dados pessoais de terceiros em desacordo com a LGPD.</li>
</ul>

<h2>5. Pagamentos, taxas e saques</h2>
<p>Transações financeiras estão sujeitas às regras exibidas na plataforma, taxas aplicáveis, prazos de liquidação e políticas dos provedores de pagamento. O operador pode reter, bloquear ou suspender valores em casos de suspeita de fraude, chargeback, contestação (MED) ou descumprimento destes termos.</p>

<h2>6. Propriedade intelectual</h2>
<p>Conteúdos, marcas, layout e software da plataforma pertencem ao operador ou a seus licenciadores. O usuário mantém os direitos sobre conteúdos que criar e licencia à plataforma o uso necessário para a prestação do serviço.</p>

<h2>7. Limitação de responsabilidade</h2>
<p>A plataforma é fornecida “como está”, dentro dos limites da lei. O operador não se responsabiliza por lucros cessantes, danos indiretos ou falhas de terceiros (gateways, internet, provedores), salvo quando a lei não permitir tal limitação.</p>

<h2>8. Suspensão e encerramento</h2>
<p>Podemos suspender ou encerrar contas que violem estes termos, a lei ou representem risco à plataforma ou a terceiros, com ou sem aviso prévio quando a urgência o exigir. O usuário pode solicitar o encerramento da conta pelos canais de suporte.</p>

<h2>9. Alterações dos termos</h2>
<p>Estes termos podem ser alterados a qualquer momento. A continuidade do uso após a publicação de nova versão constitui aceitação, salvo quando a lei exigir consentimento específico.</p>

<h2>10. Disposições gerais</h2>
<p>A invalidade de qualquer cláusula não afeta as demais. Estes termos são regidos pelas leis do Brasil. Fica eleito o foro da comarca do domicílio do operador, salvo disposição legal em contrário em relação ao consumidor.</p>
HTML;
    }

    public static function defaultPrivacyContactPlaceholder(): string
    {
        return 'privacidade@exemplo.com';
    }
}
