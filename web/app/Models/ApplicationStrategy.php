<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Structured AI pipeline outputs for one generation, kept so results are inspectable and reproducible.
 *
 * @property int $id
 * @property int $application_id
 * @property array<string, mixed> $match
 * @property array<string, mixed> $resume_selection
 * @property array<string, mixed> $skill_strategy
 * @property array<string, mixed> $application_strategy
 * @property array<string, mixed> $meta
 */
#[Fillable(['match', 'resume_selection', 'skill_strategy', 'application_strategy', 'meta'])]
class ApplicationStrategy extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'match' => 'array',
            'resume_selection' => 'array',
            'skill_strategy' => 'array',
            'application_strategy' => 'array',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
