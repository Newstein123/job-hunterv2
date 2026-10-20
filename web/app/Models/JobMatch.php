<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fit between a job and the user's profile. The rating comes from explainable counts
 * (required skills the user can evidence), never from an LLM-generated percentage.
 *
 * @property int $id
 * @property int $job_id
 * @property int|null $recommended_resume_id
 * @property string $fit
 * @property int $required_covered
 * @property int $required_total
 * @property int $preferred_covered
 * @property int $preferred_total
 * @property list<string> $matching_skills
 * @property list<string> $missing_skills
 * @property string|null $reason
 * @property array<string, mixed> $match
 * @property list<array<string, mixed>>|null $resume_scores
 * @property CarbonImmutable|null $profile_snapshot_at
 * @property-read Resume|null $recommendedResume
 */
#[Fillable([
    'recommended_resume_id', 'fit', 'required_covered', 'required_total', 'preferred_covered', 'preferred_total',
    'matching_skills', 'missing_skills', 'reason', 'match', 'resume_scores', 'profile_snapshot_at',
])]
class JobMatch extends Model
{
    public const STRONG = 'strong';

    public const PARTIAL = 'partial';

    public const WEAK = 'weak';

    /** No required skills were found in the posting, so coverage can't be judged. */
    public const UNKNOWN = 'unknown';

    public const FITS = [self::STRONG, self::PARTIAL, self::WEAK, self::UNKNOWN];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'matching_skills' => 'array',
            'missing_skills' => 'array',
            'match' => 'array',
            'resume_scores' => 'array',
            'profile_snapshot_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Resume, $this> */
    public function recommendedResume(): BelongsTo
    {
        return $this->belongsTo(Resume::class, 'recommended_resume_id');
    }

    /** Strong: at least 80% of required skills evidenced. Partial: at least 50%. Otherwise weak. */
    public static function fitFor(int $covered, int $total): string
    {
        if ($total === 0) {
            return self::UNKNOWN;
        }
        $ratio = $covered / $total;

        return match (true) {
            $ratio >= 0.8 => self::STRONG,
            $ratio >= 0.5 => self::PARTIAL,
            default => self::WEAK,
        };
    }
}
