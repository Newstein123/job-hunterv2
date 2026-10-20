<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $career_profile_id
 * @property string $title
 * @property string $company
 * @property string|null $location
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property string|null $summary
 * @property list<string>|null $achievements
 * @property list<string>|null $skills
 */
#[Touches(['careerProfile'])]
#[Fillable(['title', 'company', 'location', 'start_date', 'end_date', 'summary', 'achievements', 'skills'])]
class CareerExperience extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'achievements' => 'array',
            'skills' => 'array',
        ];
    }

    /** @return BelongsTo<CareerProfile, $this> */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }
}
