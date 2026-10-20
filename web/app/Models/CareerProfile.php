<?php

namespace App\Models;

use Database\Factories\CareerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $full_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $linkedin
 * @property string|null $location Where the candidate is now, e.g. "Bangkok, Thailand"
 * @property string|null $availability e.g. "Immediately" or "1 month notice"
 * @property string|null $headline
 * @property string|null $summary
 * @property list<string>|null $languages
 * @property-read Collection<int, CareerExperience> $experiences
 * @property-read Collection<int, CareerSkill> $skills
 * @property-read Collection<int, CareerProject> $projects
 * @property-read Collection<int, CareerEducation> $education
 * @property-read Collection<int, Country> $targetCountries
 */
#[Fillable(['full_name', 'email', 'phone', 'whatsapp', 'linkedin', 'location', 'availability', 'headline', 'summary', 'languages'])]
class CareerProfile extends Model
{
    /** @use HasFactory<CareerProfileFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['languages' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CareerExperience, $this> */
    public function experiences(): HasMany
    {
        return $this->hasMany(CareerExperience::class)->orderByDesc('start_date');
    }

    /** @return HasMany<CareerSkill, $this> */
    public function skills(): HasMany
    {
        return $this->hasMany(CareerSkill::class)->orderBy('name');
    }

    /** @return HasMany<CareerProject, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(CareerProject::class)->orderBy('name');
    }

    /** @return HasMany<CareerEducation, $this> */
    public function education(): HasMany
    {
        return $this->hasMany(CareerEducation::class)->orderByDesc('start_date');
    }

    /** @return BelongsToMany<Country, $this> */
    public function targetCountries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class)->orderBy('name');
    }

    /** True when the job's country is one the user isn't applying to (no targets set = everything is in). */
    public function isOutsideTargets(?int $countryId): bool
    {
        if ($countryId === null) {
            return false;
        }
        $targets = $this->targetCountries->modelKeys();

        return $targets !== [] && ! in_array($countryId, $targets, true);
    }
}
