<?php

namespace App\Services;

use App\Models\MemberCertificateIssued;
use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MemberProgressService
{
    public const CERT_RELEASE_COMPLETION = 'completion_percent';

    public const CERT_RELEASE_DAYS = 'days_after_access';

    public const CERT_RELEASE_BOTH = 'both';

    /**
     * Total lessons count for product (for completion %).
     */
    public function totalLessonsCount(Product $product): int
    {
        return MemberLesson::where('product_id', $product->id)->count();
    }

    /**
     * Completed lessons count for user in product.
     */
    public function completedLessonsCount(Product $product, User $user): int
    {
        return MemberLessonProgress::forProduct($product->id)
            ->forUser($user->id)
            ->whereNotNull('completed_at')
            ->count();
    }

    /**
     * Completion percentage (0-100).
     */
    public function completionPercent(Product $product, User $user): int
    {
        $total = $this->totalLessonsCount($product);
        if ($total === 0) {
            return 100;
        }
        $completed = $this->completedLessonsCount($product, $user);

        return (int) min(100, round(($completed / $total) * 100));
    }

    /**
     * Início do acesso do aluno ao produto (matrícula em product_user).
     */
    public function userAccessStartAt(Product $product, User $user): Carbon
    {
        if ($user->canAccessPanel() && $user->tenant_id === $product->tenant_id) {
            return now()->subYears(20);
        }

        $createdAt = DB::table('product_user')
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->value('created_at');

        if ($createdAt) {
            return Carbon::parse($createdAt);
        }

        return now();
    }

    /**
     * @param  array<string, mixed>  $cert
     * @return array{enabled: bool, release_mode: string, completion_percent: int, days_after_access: int}
     */
    public function normalizedCertificateConfig(array $cert): array
    {
        $mode = (string) ($cert['release_mode'] ?? self::CERT_RELEASE_COMPLETION);
        if (! in_array($mode, [self::CERT_RELEASE_COMPLETION, self::CERT_RELEASE_DAYS, self::CERT_RELEASE_BOTH], true)) {
            $mode = self::CERT_RELEASE_COMPLETION;
        }

        return [
            'enabled' => ! empty($cert['enabled']),
            'release_mode' => $mode,
            'completion_percent' => max(0, min(100, (int) ($cert['completion_percent'] ?? 100))),
            'days_after_access' => max(0, (int) ($cert['days_after_access'] ?? 0)),
        ];
    }

    /**
     * Requisitos de liberação do certificado (progresso, dias de acesso, etc.).
     *
     * @return array{
     *     enabled: bool,
     *     eligible: bool,
     *     already_issued: bool,
     *     release_mode: string,
     *     progress_percent: int,
     *     required_percent: int,
     *     percent_met: bool,
     *     days_after_access: int,
     *     days_elapsed: int,
     *     days_remaining: int,
     *     days_met: bool,
     *     unlocks_at: string|null
     * }
     */
    public function certificateEligibility(Product $product, User $user): array
    {
        $config = $product->member_area_config;
        $cert = $this->normalizedCertificateConfig(is_array($config['certificate'] ?? null) ? $config['certificate'] : []);

        $alreadyIssued = MemberCertificateIssued::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->exists();

        $progressPercent = $this->completionPercent($product, $user);
        $requiredPercent = $cert['completion_percent'];
        $percentMet = $progressPercent >= $requiredPercent;

        $daysAfter = $cert['days_after_access'];
        $accessStart = $this->userAccessStartAt($product, $user);
        $unlocksAt = $accessStart->copy()->startOfDay()->addDays($daysAfter);
        $daysMet = now()->startOfDay()->gte($unlocksAt);
        $daysElapsed = (int) max(0, $accessStart->copy()->startOfDay()->diffInDays(now()->startOfDay()));
        $daysRemaining = $daysMet ? 0 : (int) max(0, now()->startOfDay()->diffInDays($unlocksAt->copy()->startOfDay()));

        $eligible = false;
        if ($cert['enabled'] && ! $alreadyIssued) {
            $eligible = match ($cert['release_mode']) {
                self::CERT_RELEASE_DAYS => $daysMet,
                self::CERT_RELEASE_BOTH => $percentMet && $daysMet,
                default => $percentMet,
            };
        }

        return [
            'enabled' => $cert['enabled'],
            'eligible' => $eligible,
            'already_issued' => $alreadyIssued,
            'release_mode' => $cert['release_mode'],
            'progress_percent' => $progressPercent,
            'required_percent' => $requiredPercent,
            'percent_met' => $percentMet,
            'days_after_access' => $daysAfter,
            'days_elapsed' => $daysElapsed,
            'days_remaining' => $daysRemaining,
            'days_met' => $daysMet,
            'unlocks_at' => $unlocksAt->toDateString(),
        ];
    }

    /**
     * Garante que existe um registro de progresso ao abrir a aula (para "continuar assistindo").
     * Só cria se não existir; não altera aulas já concluídas.
     */
    public function ensureLessonStarted(MemberLesson $lesson, User $user): void
    {
        MemberLessonProgress::firstOrCreate(
            [
                'user_id' => $user->id,
                'member_lesson_id' => $lesson->id,
            ],
            [
                'product_id' => $lesson->product_id,
                'completed_at' => null,
            ]
        );
    }

    /**
     * Mark lesson as completed for user.
     */
    public function markLessonCompleted(int $lessonId, User $user): void
    {
        $lesson = MemberLesson::find($lessonId);
        if (! $lesson) {
            return;
        }
        MemberLessonProgress::updateOrCreate(
            [
                'user_id' => $user->id,
                'member_lesson_id' => $lessonId,
            ],
            [
                'product_id' => $lesson->product_id,
                'completed_at' => now(),
                'progress_percent' => 100,
            ]
        );
    }

    /**
     * Check if user can receive certificate (regras de liberação + ainda não emitido).
     */
    public function canIssueCertificate(Product $product, User $user): bool
    {
        return $this->certificateEligibility($product, $user)['eligible'];
    }

    /**
     * Issue certificate for user/product (if allowed).
     */
    public function issueCertificate(Product $product, User $user): ?MemberCertificateIssued
    {
        if (! $this->canIssueCertificate($product, $user)) {
            return null;
        }
        $percent = $this->completionPercent($product, $user);

        return MemberCertificateIssued::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'issued_at' => now(),
            'completion_percent' => $percent,
        ]);
    }
}
