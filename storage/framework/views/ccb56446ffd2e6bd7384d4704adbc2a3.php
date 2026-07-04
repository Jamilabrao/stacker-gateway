<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configuração inicial (Docker) - Stacker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#0050fc',
                    }
                }
            }
        }
    </script>
    <style>
        input:focus { outline: none; box-shadow: 0 0 0 2px rgba(200, 250, 100, 0.3); }
    </style>
</head>
<body class="min-h-screen bg-zinc-100 dark:bg-zinc-900 text-zinc-900 dark:text-white">
    <div class="min-h-screen flex flex-col items-center justify-center px-6 py-10">
        <div class="w-full max-w-xl">
            <div class="text-center mb-8">
                <img src="/images/logo.png" alt="Getfy" class="mx-auto mb-6 h-14 w-auto object-contain" />
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Configuração Docker</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Defina o domínio público para gerar links corretos</p>
            </div>

            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900/40 shadow-sm p-6">
                <?php if(session('success')): ?>
                    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                        <?php echo e(session('success')); ?>

                    </div>
                <?php endif; ?>

                <?php if($errors->any()): ?>
                    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200">
                        <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $err): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div><?php echo e($err); ?></div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo e(url('/docker-setup')); ?>" class="space-y-4">
                    <?php echo csrf_field(); ?>

                    <div>
                        <label for="domain" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Domínio</label>
                        <input
                            id="domain"
                            name="domain"
                            type="text"
                            value="<?php echo e(old('domain', $host)); ?>"
                            placeholder="ex: pay.seudominio.com"
                            class="mt-1.5 block w-full rounded-xl border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-4 py-3 text-zinc-900 dark:text-white placeholder-zinc-500 shadow-sm transition hover:border-primary focus:border-primary"
                            autocomplete="off"
                            required
                        />
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            A plataforma vai salvar: <span class="font-mono"><?php echo e($suggested_url); ?></span>
                        </p>
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            Dica: digite apenas o domínio (sem http/https). Se você colar uma URL, ela será normalizada automaticamente.
                        </p>
                    </div>

                    <button type="submit" class="w-full rounded-xl bg-primary px-4 py-3 font-semibold text-zinc-900 hover:opacity-90 transition">
                        Salvar e continuar
                    </button>

                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        Após salvar, você será levado ao login. Se for a primeira vez, o login redireciona para criar o admin.
                    </p>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
<?php /**PATH /var/www/html/resources/views/docker-setup.blade.php ENDPATH**/ ?>