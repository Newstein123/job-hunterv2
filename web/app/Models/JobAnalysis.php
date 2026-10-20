<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $job_id
 * @property array<string, mixed> $data
 * @property list<string>|null $warnings
 * @property string $model
 * @property string $prompt_version
 * @property string|null $description_hash
 */
#[Fillable(['data', 'warnings', 'model', 'prompt_version', 'description_hash'])]
class JobAnalysis extends Model
{
    /** Analysis list fields that are also stored as normalized job_requirements rows. */
    public const REQUIREMENT_FIELDS = [
        'required_skills' => 'required_skill',
        'preferred_skills' => 'preferred_skill',
        'requirements' => 'requirement',
        'responsibilities' => 'responsibility',
        'keywords' => 'keyword',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'array', 'warnings' => 'array'];
    }

    /** @return BelongsTo<JobPosting, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_id');
    }

    /** @return HasMany<JobRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(JobRequirement::class);
    }
}
