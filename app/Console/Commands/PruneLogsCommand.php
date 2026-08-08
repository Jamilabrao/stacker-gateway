<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneLogsCommand extends Command
{
    protected $signature = 'logs:prune
                            {--days=7 : Manter arquivos daily por N dias}
                            {--max-mb=50 : Truncar qualquer *.log se passar deste tamanho}
                            {--max-total-mb=200 : Teto total de storage/logs (remove .log mais antigos)}';

    protected $description = 'Remove logs antigos, trunca *.log oversized e limita o total em storage/logs (evita estourar disco)';

    public function handle(): int
    {
        $dir = storage_path('logs');
        if (! is_dir($dir)) {
            $this->info('Pasta storage/logs inexistente.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $maxBytes = max(1, (int) $this->option('max-mb')) * 1024 * 1024;
        $maxTotalBytes = max(1, (int) $this->option('max-total-mb')) * 1024 * 1024;
        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = 0;
        $truncated = 0;
        $freed = 0;

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.log') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $base = basename($file);
            $mtime = @filemtime($file) ?: 0;

            // Daily files older than N days (keep laravel.log for size-based truncate only).
            if ($base !== 'laravel.log' && $mtime > 0 && $mtime < $cutoff) {
                $size = (int) @filesize($file);
                if (@unlink($file)) {
                    $removed++;
                    $freed += $size;
                    $this->line("Removido (idade): {$base}");
                }

                continue;
            }

            $size = (int) @filesize($file);
            if ($size > $maxBytes) {
                $before = $size;
                if ($this->truncateLogFile($file, $maxBytes)) {
                    $after = (int) @filesize($file);
                    $truncated++;
                    $freed += max(0, $before - $after);
                    $this->warn(sprintf(
                        'Truncado %s: %s → %s',
                        $base,
                        $this->humanBytes($before),
                        $this->humanBytes($after)
                    ));
                }
            }
        }

        // Cap total size of storage/logs by deleting oldest *.log first.
        $totalFreed = $this->enforceTotalCap($dir, $maxTotalBytes);
        if ($totalFreed['removed'] > 0) {
            $removed += $totalFreed['removed'];
            $freed += $totalFreed['bytes'];
        }

        $this->info(sprintf(
            'logs:prune ok — %d arquivo(s) removido(s), %d truncado(s), ~%s liberados.',
            $removed,
            $truncated,
            $this->humanBytes($freed)
        ));

        return self::SUCCESS;
    }

    /**
     * Keep the tail of a log file when it exceeds maxBytes.
     */
    private function truncateLogFile(string $path, int $maxBytes): bool
    {
        $keep = min(2 * 1024 * 1024, $maxBytes);
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return false;
        }

        fseek($fp, -$keep, SEEK_END);
        $tail = stream_get_contents($fp) ?: '';
        fclose($fp);

        $written = file_put_contents(
            $path,
            '…(truncated by logs:prune at '.now()->toIso8601String().")\n".$tail
        );

        return $written !== false;
    }

    /**
     * @return array{removed: int, bytes: int}
     */
    private function enforceTotalCap(string $dir, int $maxTotalBytes): array
    {
        $files = [];
        $total = 0;

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.log') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }
            $size = (int) @filesize($file);
            $mtime = @filemtime($file) ?: 0;
            $files[] = ['path' => $file, 'size' => $size, 'mtime' => $mtime];
            $total += $size;
        }

        if ($total <= $maxTotalBytes) {
            return ['removed' => 0, 'bytes' => 0];
        }

        // Oldest first.
        usort($files, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);

        $removed = 0;
        $bytes = 0;

        foreach ($files as $entry) {
            if ($total <= $maxTotalBytes) {
                break;
            }

            $base = basename($entry['path']);
            if (@unlink($entry['path'])) {
                $removed++;
                $bytes += $entry['size'];
                $total -= $entry['size'];
                $this->line("Removido (teto total): {$base}");
            }
        }

        if ($removed > 0) {
            $this->warn(sprintf(
                'Teto total storage/logs: %s — total agora ~%s',
                $this->humanBytes($maxTotalBytes),
                $this->humanBytes(max(0, $total))
            ));
        }

        return ['removed' => $removed, 'bytes' => $bytes];
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
