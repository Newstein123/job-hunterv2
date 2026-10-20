<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\Rule;

/**
 * A job the user found elsewhere and added to JobHunter. Stored in the `jobs` table
 * (the queue uses `queue_jobs`); named JobPosting to avoid confusion with queued jobs.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $position
 * @property string|null $company
 * @property string $description
 * @property string|null $url
 * @property string|null $location
 * @property int|null $country_id
 * @property string|null $contact_email
 * @property list<string>|null $cc_emails
 * @property string|null $source
 * @property string $kind posting | speculative
 * @property string|null $website
 * @property string|null $industry
 * @property string|null $specialties
 * @property string|null $contact_flag
 * @property int|null $preferred_resume_id
 * @property string|null $analysis_state
 * @property string|null $analysis_error
 * @property CarbonImmutable $created_at
 * @property-read JobMatch|null $match
 * @property-read Country|null $country
 * @property-read Application|null $application
 * @property-read JobAnalysis|null $latestAnalysis
 */
#[Table('jobs')]
#[Fillable(['position', 'company', 'description', 'url', 'location', 'country_id', 'contact_email', 'cc_emails', 'source',
    'kind', 'website', 'industry', 'specialties', 'contact_flag', 'preferred_resume_id'])]
class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cc_emails' => 'array'];
    }

    public const ANALYSIS_PENDING = 'pending';

    public const ANALYSIS_RUNNING = 'running';

    public const ANALYSIS_FAILED = 'failed';

    public const ANALYSIS_DONE = 'done';

    /** Where a job is in the workflow, for filtering the Jobs list. */
    public const STAGES = [
        'not_analyzed' => 'Not analyzed',
        'analyzing' => 'Analyzing',
        'analyzed' => 'Analyzed',
        'drafting' => 'Drafting',
        'review' => 'Needs review',
        'approved' => 'Approved, ready to send',
        'scheduled' => 'Scheduled',
        'sent' => 'Sent',
        'outcome' => 'Replied / outcome',
        'withdrawn' => 'Withdrawn',
    ];

    public const KIND_POSTING = 'posting';

    /** A company from the user's own list that hasn't posted a job: the user offers a role they chose. */
    public const KIND_SPECULATIVE = 'speculative';

    /** Inbox types for contact_email, so weak recipients can be flagged before review. */
    public const CONTACT_HIRING = 'hiring';

    public const CONTACT_GENERAL = 'general';

    public const CONTACT_OTHER_TEAM = 'other_team';

    public const CONTACT_PERSON = 'person';

    private const HIRING_INBOX = '/^(hr|careers?|jobs?|recruit\w*|talent\w*|hiring|people|cv|resumes?|apply|employment)$/';

    private const GENERAL_INBOX = '/^(info|contact\w*|hello|hi|enquir\w*|inquir\w*|office|admin|mail|general|team|\d+)$/';

    private const OTHER_TEAM_INBOX = '/^(sales|accounts?|billing|finance|invoices?|support|help|helpdesk|service|marketing|media|press|pr|orders?|partners?|noreply|no-reply|legal|privacy|webmaster|it|tech)$/';

    /** What kind of inbox an address looks like, from the part before the @ only. */
    public static function contactFlagFor(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        $local = strtolower(explode('@', $email)[0]);
        // "101info", "10500sales": numbers around the name don't change the inbox type.
        $local = (string) preg_replace('/^\d+|\d+$/', '', $local) ?: $local;

        return match (true) {
            (bool) preg_match(self::HIRING_INBOX, $local) => self::CONTACT_HIRING,
            (bool) preg_match(self::GENERAL_INBOX, $local) => self::CONTACT_GENERAL,
            (bool) preg_match(self::OTHER_TEAM_INBOX, $local) => self::CONTACT_OTHER_TEAM,
            default => self::CONTACT_PERSON,
        };
    }

    public static function emailDomain(?string $email): ?string
    {
        return $email && str_contains($email, '@') ? strtolower(trim(substr($email, strrpos($email, '@') + 1))) : null;
    }

    public function isSpeculative(): bool
    {
        return $this->kind === self::KIND_SPECULATIVE;
    }

    /**
     * Where the user found the job. Traditional letters mention it ("as advertised on JobStreet"),
     * so these are the boards' own names. Regional boards cover the supported countries.
     */
    public const SOURCES = [
        'LinkedIn', 'Indeed', 'Glassdoor',
        'JobStreet',        // Singapore, Malaysia
        'JobsDB',           // Hong Kong, Thailand
        'MyCareersFuture',  // Singapore
        'SEEK',             // Australia
        'Bayt',             // UAE
        'GulfTalent',       // UAE
        'Naukrigulf',       // UAE
        'JobThai',          // Thailand
        'Hiredly',          // Malaysia
        'CTgoodjobs',       // Hong Kong
        'Company website', 'Referral', 'Facebook', 'Telegram', 'Other',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return HasMany<JobAnalysis, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(JobAnalysis::class, 'job_id');
    }

    /** @return HasOne<JobAnalysis, $this> */
    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(JobAnalysis::class, 'job_id')->latestOfMany();
    }

    /** @return HasOne<JobMatch, $this> */
    public function match(): HasOne
    {
        return $this->hasOne(JobMatch::class, 'job_id');
    }

    /**
     * @param  Builder<JobPosting>  $query
     */
    public function scopeInStage(Builder $query, string $stage): void
    {
        $app = fn (array $statuses) => fn ($q) => $q->whereIn('status', $statuses);
        $analyzing = [self::ANALYSIS_PENDING, self::ANALYSIS_RUNNING];

        match ($stage) {
            'not_analyzed' => $query->doesntHave('match')->doesntHave('application')
                ->where(fn ($q) => $q->whereNull('analysis_state')->orWhereNotIn('analysis_state', $analyzing)),
            'analyzing' => $query->whereIn('analysis_state', $analyzing),
            'analyzed' => $query->has('match')->doesntHave('application'),
            'drafting' => $query->whereHas('application', $app([ApplicationStatus::Draft])),
            'review' => $query->whereHas('application', $app([ApplicationStatus::Generated])),
            'approved' => $query->whereHas('application', $app([ApplicationStatus::Approved])),
            'scheduled' => $query->whereHas('application', $app([ApplicationStatus::Queued])),
            'sent' => $query->whereHas('application', $app([ApplicationStatus::Sent])),
            'outcome' => $query->whereHas('application', $app([
                ApplicationStatus::Replied, ApplicationStatus::Interview, ApplicationStatus::Rejected, ApplicationStatus::Offer,
            ])),
            'withdrawn' => $query->whereHas('application', $app([ApplicationStatus::Withdrawn])),
            default => null,
        };
    }

    public function isAnalyzing(): bool
    {
        return in_array($this->analysis_state, [self::ANALYSIS_PENDING, self::ANALYSIS_RUNNING], true);
    }

    /** @return HasOne<Application, $this> */
    public function application(): HasOne
    {
        return $this->hasOne(Application::class, 'job_id');
    }

    /** @return array<string, list<mixed>> */
    public static function validationRules(): array
    {
        return [
            'position' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:50', 'max:50000'],
            'url' => ['nullable', 'url', 'max:2048'],
            'location' => ['nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
            // Only addresses the posting provides; JobHunter never guesses recipients.
            'contact_email' => ['nullable', 'email:rfc,strict', 'max:255'],
            'cc_emails' => ['nullable', 'array', 'max:10'],
            'cc_emails.*' => ['email:rfc,strict', 'max:255', 'distinct'],
            'source' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Split "a@x.com, b@y.com; c@z.com" into a clean list (CC fields in forms and spreadsheets).
     *
     * @return list<string>|null
     */
    public static function parseEmails(mixed $value): ?array
    {
        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }
        $parts = preg_split('/[,;\s]+/', is_string($value) ? $value : '') ?: [];
        $emails = array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim($e)), $parts))));

        return $emails === [] ? null : $emails;
    }

    /** @param array<string, mixed> $data */
    public static function duplicateKey(array $data): string
    {
        return ! empty($data['url'])
            ? 'url:'.strtolower($data['url'])
            : 'cp:'.mb_strtolower(trim(($data['company'] ?? '').'|'.($data['position'] ?? '')));
    }

    /**
     * An existing job with the same URL, or the same company and position.
     *
     * @param  array<string, mixed>  $data
     */
    public static function findDuplicate(User $user, array $data, ?int $ignoreId = null): ?self
    {
        $query = $user->jobPostings()->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId));
        if (! empty($data['url'])) {
            return (clone $query)->where('url', $data['url'])->first();
        }
        if (empty($data['company']) || empty($data['position'])) {
            return null;
        }

        return $query->where('company', $data['company'])->where('position', $data['position'])->first();
    }

    public function displayTitle(): string
    {
        return $this->position ?: 'Untitled position';
    }
}
