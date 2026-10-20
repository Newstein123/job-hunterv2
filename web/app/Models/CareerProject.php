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
 * @property string|null $description
 * @property list<string>|null $skills
 * @property string|null $url
 */
#[Touches(['careerProfile'])]
#[Fillable(['name', 'description', 'skills', 'url'])]
class CareerProject extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['skills' => 'array'];
    }

    /** @return BelongsTo<CareerProfile, $this> */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }
}
