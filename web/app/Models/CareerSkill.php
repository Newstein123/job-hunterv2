<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $career_profile_id
 * @property string $name
 * @property string|null $level
 * @property float|null $years
 */
#[Touches(['careerProfile'])]
#[Fillable(['name', 'level', 'years'])]
class CareerSkill extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['years' => 'float'];
    }

    /** @return BelongsTo<CareerProfile, $this> */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }
}
