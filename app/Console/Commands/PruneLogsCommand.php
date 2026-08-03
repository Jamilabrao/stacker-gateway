<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneLogsCommand extends Command
{
    protected $signature = 'logs:prune
                            {--days=7 : Manter arquivos daily por N dias}
                            {--max-mb=50 : Truncar laravel.log se passar deste tamanho}';

    protected $description = 'Remove logs diários antigos e trunca laravel.log oversized (evita estourar disco)';

    public function handle(): int
    {
        $dir = storage_path('logs');
        if (! is_dir($dir)) {
            $this->info('Pasta storage/logs inexistente.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $maxBytes = max(1, (int) $this->option('max-mb')) * 1024 * 1024;
        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = 0;

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.log') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $base = basename($file);
            if ($base === 'laravel.log') {
                continue;
            }

            $mtime = @filemtime($file) ?: 0;
            if ($mtime > 0 && $mtime < $cutoff) {
                if (@unlink($file)) {
                    $removed++;
                    $this->line("Removido: {$base}");
                }
            }
        }

        $single = $dir.DIRECTORY_SEPARATOR.'laravel.log';
        if (is_file($single)) {
            $size = (int) @filesize($single);
            if ($size > $maxBytes) {
                $keep = min(2 * 1024 * 1024, $maxBytes);
                $fp = fopen($single, 'rb');
                if ($fp !== false) {
                    fseek($fp, -$keep, SEEK_END);
                    $tail = stream_get_contents($fp) ?: '';
                    fclose($fp);
                    file_put_contents(
                        $single,
                        "…(truncated by logs:prune at ".now()->toIso8601String().")\n".$tail
                    );
                    $this->warn(sprintf(
                        'Truncado laravel.log: %s → ~%s',
                        $this->humanBytes($size),
                        $this->humanBytes(strlen($tail))
                    ));
                }
            }
        }

        $this->info("logs:prune ok — {$removed} arquivo(s) antigo(s) removido(s).");

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes}B";
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).'KB';
        }

        return round($bytes / (1024 * 1024), 1).'MB';
    }
}
