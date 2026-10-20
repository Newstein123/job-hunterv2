<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $career_profile_id
 * @property string $institution
 * @property string $degree
 * @property string|null $field
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $end_date
 */
#[Table('career_education')]
#[Touches(['careerProfile'])]
#[Fillable(['institution', 'degree', 'field', 'start_date', 'end_date'])]
class CareerEducation extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<CareerProfile, $this> */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }
}
