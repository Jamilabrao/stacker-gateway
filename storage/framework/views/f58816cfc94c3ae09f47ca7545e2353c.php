<?php $__env->startSection('content'); ?>
    <p style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#18181b;">Verificação aprovada</p>
    <p style="margin:0 0 16px 0;">Olá, <?php echo e($recipientName); ?>,</p>
    <p style="margin:0 0 16px 0;">Sua verificação de identidade (KYC) foi <strong style="color:#15803d;">aprovada</strong> pela equipe da plataforma. Você já pode utilizar os recursos que dependem dessa etapa, conforme as regras do seu painel.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px auto 0 auto;">
        <tr>
            <td style="border-radius:8px;background-color:<?php echo e($branding['theme_primary']); ?>;">
                <a href="<?php echo e($dashboardUrl); ?>" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Acessar o painel</a>
            </td>
        </tr>
    </table>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('emails.layouts.branded', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\getfy-gateway\resources\views/emails/kyc-approved.blade.php ENDPATH**/ ?>