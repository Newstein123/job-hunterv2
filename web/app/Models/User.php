<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Jobs\AnalyzeJobPosting;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CareerProfile|null $careerProfile
 * @property-read ApplicationRule|null $applicationRule
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasOne<CareerProfile, $this> */
    public function careerProfile(): HasOne
    {
        return $this->hasOne(CareerProfile::class);
    }

    /** @return HasMany<Resume, $this> */
    public function resumes(): HasMany
    {
        return $this->hasMany(Resume::class);
    }

    /** @return HasMany<JobPosting, $this> */
    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * When the profile or resumes last changed; a job fit computed before this is out of date.
     */
    /** Fit analysis needs something to match against. */
    public function canAnalyzeJobs(): bool
    {
        return $this->careerProfile()->exists() && $this->resumes()->where('is_active', true)->exists();
    }

    /**
     * After resumes change, re-check jobs whose resume can still change: not yet approved, queued or sent.
     * The stored job analysis is reused, so this only re-runs matching. Returns how many were queued.
     */
    public function reanalyzeOpenJobs(int $limit = 100): int
    {
        if (! $this->canAnalyzeJobs()) {
            return 0;
        }
        $jobs = $this->jobPostings()->where('kind', JobPosting::KIND_POSTING)
            ->where(fn ($q) => $q->has('match')->orWhere('analysis_state', JobPosting::ANALYSIS_FAILED))
            ->where(fn ($q) => $q->whereNull('analysis_state')->orWhereNotIn('analysis_state', [JobPosting::ANALYSIS_PENDING, JobPosting::ANALYSIS_RUNNING]))
            ->where(fn ($q) => $q->doesntHave('application')
                ->orWhereHas('application', fn ($a) => $a->whereIn('status', [ApplicationStatus::Draft, ApplicationStatus::Generated])))
            ->latest()->limit($limit)->get();
        AnalyzeJobPosting::start(...$jobs->all());

        return $jobs->count();
    }

    public function profileVersion(): ?CarbonImmutable
    {
        $times = array_filter([
            $this->careerProfile()->value('updated_at'),
            $this->resumes()->max('updated_at'),
        ]);

        return $times === [] ? null : max(array_map(fn ($t) => CarbonImmutable::parse($t), $times));
    }

    /** @return HasMany<EmailAccount, $this> */
    public function emailAccounts(): HasMany
    {
        return $this->hasMany(EmailAccount::class);
    }

    /** @return HasOne<ApplicationRule, $this> */
    public function applicationRule(): HasOne
    {
        return $this->hasOne(ApplicationRule::class);
    }
}
