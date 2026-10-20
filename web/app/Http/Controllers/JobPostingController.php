<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Models\Country;
use App\Models\JobMatch;
use App\Models\JobPosting;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class JobPostingController extends Controller
{
    /** Max jobs queued by one "Analyze all" click. */
    private const ANALYZE_BATCH = 100;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $fit = $request->string('fit')->toString();
        $stage = $request->string('stage')->toString();
        $countryId = $request->integer('country') ?: null;
        $sort = $request->string('sort')->toString() === 'fit' ? 'fit' : 'newest';
        $profileVersion = $user->profileVersion();
        $profile = $user->careerProfile?->load('targetCountries:id');

        $query = $user->jobPostings()
            ->with(['application:id,job_id,status,generation_state', 'application.outboundMessage', 'application.emailMessages:id,application_id,direction,status,in_reply_to_id,is_auto_reply,from_email,received_at,sent_at,created_at', 'country:id,name,timezone', 'match.recommendedResume:id,label'])
            ->when($countryId, fn ($q, $id) => $q->where('jobs.country_id', $id))
            ->when(array_key_exists($stage, JobPosting::STAGES), fn ($q) => $q->inStage($stage))
            ->when(in_array($fit, JobMatch::FITS, true), fn ($q) => $q->whereHas('match', fn ($m) => $m->where('fit', $fit)))
            ->when($fit === 'not_analyzed', fn ($q) => $q->doesntHave('match'));
        if ($sort === 'fit') {
            // Best fit first, then by how many required skills are covered.
            $query->leftJoin('job_matches', 'job_matches.job_id', '=', 'jobs.id')->select('jobs.*')
                ->orderByRaw("CASE job_matches.fit WHEN 'strong' THEN 0 WHEN 'partial' THEN 1 WHEN 'weak' THEN 2 WHEN 'unknown' THEN 3 ELSE 4 END")
                ->orderByRaw('job_matches.required_covered DESC');
        }
        $jobs = $query->latest('jobs.created_at')->paginate(50)->withQueryString()
            ->through(fn (JobPosting $job) => [
                'id' => $job->id,
                'position' => $job->displayTitle(),
                'company' => $job->company,
                'location' => $job->location,
                'country' => $job->country?->name,
                'outside_targets' => (bool) $profile?->isOutsideTargets($job->country_id),
                'kind' => $job->kind,
                'source' => $job->source,
                'created_at' => $job->created_at->toIso8601String(),
                'analysis_state' => $job->analysis_state,
                'analysis_error' => $job->analysis_error,
                'match' => $this->matchSummary($job->match, $profileVersion),
                'application' => $job->application ? [
                    ...$job->application->only(['id', 'status', 'generation_state']),
                    'scheduled_at' => $job->application->status === ApplicationStatus::Queued
                        ? $job->application->outboundMessage?->scheduled_at?->toIso8601String() : null,
                    'reply_state' => $job->application->replyState(),
                    // Show the send time in the job's country, where it was scheduled.
                    'scheduled_tz' => $job->country?->timezone,
                ] : null,
            ]);

        $counts = JobMatch::query()->whereIn('job_id', $user->jobPostings()->select('id'))
            ->toBase()->selectRaw('fit, count(*) as total')->groupBy('fit')->pluck('total', 'fit');

        return Inertia::render('jobs/index', [
            'jobs' => $jobs,
            'filters' => ['fit' => $fit ?: null, 'sort' => $sort, 'stage' => $stage ?: null, 'country' => $countryId],
            'stages' => collect(JobPosting::STAGES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'countries' => Country::options(),
            'accounts' => $user->emailAccounts()->orderByDesc('is_default')->get(['id', 'email', 'status', 'is_default']),
            'fitCounts' => [
                ...collect(JobMatch::FITS)->mapWithKeys(fn ($f) => [$f => (int) ($counts[$f] ?? 0)])->all(),
                'not_analyzed' => $user->jobPostings()->doesntHave('match')->count(),
            ],
            'analyzing' => $user->jobPostings()->whereIn('analysis_state', [JobPosting::ANALYSIS_PENDING, JobPosting::ANALYSIS_RUNNING])->count(),
            'canAnalyze' => $user->canAnalyzeJobs(),
        ]);
    }

    /** Queue fit analysis for one job. */
    public function analyze(Request $request, JobPosting $job): RedirectResponse
    {
        $this->ensureOwns($request, $job);
        $this->assertCanAnalyze($request);
        if (! $job->isAnalyzing()) {
            AnalyzeJobPosting::start($job);
        }
        $this->toast('Analyzing this job…', 'info');

        return back();
    }

    /** Queue analysis for every job that has none, failed, or is out of date with the profile. */
    public function analyzeAll(Request $request): RedirectResponse
    {
        $this->assertCanAnalyze($request);
        $user = $request->user();
        $version = $user->profileVersion();

        $jobs = $user->jobPostings()->where('kind', JobPosting::KIND_POSTING) // speculative ones have no posting to analyze
            ->where(fn ($q) => $q->whereNull('analysis_state')->orWhereNotIn('analysis_state', [JobPosting::ANALYSIS_PENDING, JobPosting::ANALYSIS_RUNNING]))
            ->where(fn ($q) => $q->doesntHave('match')
                ->orWhere('analysis_state', JobPosting::ANALYSIS_FAILED)
                ->orWhereHas('match', fn ($m) => $m->whereNull('resume_scores'))
                ->when($version, fn ($q) => $q->orWhereHas('match', fn ($m) => $m->where('profile_snapshot_at', '<', $version)->orWhereNull('profile_snapshot_at'))))
            ->limit(self::ANALYZE_BATCH)->get();

        AnalyzeJobPosting::start(...$jobs->all());
        $this->toast($jobs->isEmpty() ? 'Every job is already analyzed.' : "Analyzing {$jobs->count()} job(s)…", 'info');

        return back();
    }

    public function create(): Response
    {
        return Inertia::render('jobs/create', ['sources' => JobPosting::SOURCES, 'countries' => Country::options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['cc_emails' => JobPosting::parseEmails($request->input('cc_emails'))]);
        $data = $request->validate(JobPosting::validationRules());
        $this->rejectDuplicate($request, $data);

        $job = $request->user()->jobPostings()->create($data);
        if ($request->user()->canAnalyzeJobs()) {
            AnalyzeJobPosting::start($job);
        }
        $this->toast('Job added.');

        return to_route('jobs.show', $job);
    }

    public function show(Request $request, JobPosting $job): Response
    {
        $this->ensureOwns($request, $job);
        $job->load(['latestAnalysis', 'country:id,name', 'match.recommendedResume:id,label',
            'application:id,job_id,status,generation_state,generation_error']);

        return Inertia::render('jobs/show', [
            'job' => $job,
            'analysis' => $job->latestAnalysis?->data,
            'match' => $this->matchSummary($job->match, $request->user()->profileVersion()),
            'resumeComparison' => $this->resumeComparison($request, $job->match),
            'sources' => JobPosting::SOURCES,
            'countries' => Country::options(),
            'outsideTargets' => (bool) $request->user()->careerProfile?->load('targetCountries:id')->isOutsideTargets($job->country_id),
            'canGenerate' => [
                'profile' => $request->user()->careerProfile()->exists(),
                'resumes' => $request->user()->resumes()->where('is_active', true)->exists(),
            ],
        ]);
    }

    public function update(Request $request, JobPosting $job): RedirectResponse
    {
        $this->ensureOwns($request, $job);
        $request->merge(['cc_emails' => JobPosting::parseEmails($request->input('cc_emails'))]);
        $data = $request->validate(JobPosting::validationRules());
        $this->rejectDuplicate($request, $data, $job->id);
        $descriptionChanged = $data['description'] !== $job->description;
        $job->update($data);
        if ($descriptionChanged && $request->user()->canAnalyzeJobs()) {
            AnalyzeJobPosting::start($job);
        }
        $this->toast('Job updated.');

        return back();
    }

    public function destroy(Request $request, JobPosting $job): RedirectResponse
    {
        $this->ensureOwns($request, $job);
        $job->delete();
        $this->toast('Job deleted.');

        return to_route('jobs.index');
    }

    /** @return array<string, mixed>|null */
    private function matchSummary(?JobMatch $match, ?CarbonImmutable $profileVersion): ?array
    {
        if ($match === null) {
            return null;
        }

        return [
            ...$match->only(['fit', 'required_covered', 'required_total', 'preferred_covered', 'preferred_total',
                'matching_skills', 'missing_skills', 'reason']),
            'recommended_resume' => $match->recommendedResume?->label,
            // Each non-skill requirement (degree, years, duties) with the exact quote that proves it.
            'requirement_checks' => array_map(fn (array $c) => [
                'requirement' => (string) $c['requirement'],
                'required' => (bool) ($c['required'] ?? true),
                'status' => (string) $c['status'],
                'note' => $c['note'] ?? null,
                'evidence' => array_map(fn (array $e) => [
                    'source' => self::sourceLabel((string) $e['source']),
                    'quote' => (string) $e['quote'],
                ], $c['evidence'] ?? []),
            ], array_values(array_filter(
                $match->match['requirement_checks'] ?? [],
                // Skills already show as badges above.
                fn ($c) => is_array($c) && ($c['kind'] ?? 'requirement') === 'requirement',
            ))),
            // Profile or resumes changed after this was computed.
            'stale' => $profileVersion !== null && ($match->profile_snapshot_at === null || $match->profile_snapshot_at->lt($profileVersion)),
        ];
    }

    private static function sourceLabel(string $source): string
    {
        return match (strtok($source, ':')) {
            'experience' => 'Experience',
            'project' => 'Project',
            'education' => 'Education',
            'resume' => 'Resume',
            'skills' => 'Skills list',
            'languages' => 'Languages',
            default => 'Profile',
        };
    }

    /**
     * Every resume scored against this job: what it covers, what it could add (the profile backs it up),
     * and the job's skills the candidate genuinely lacks. Suggestions only; resumes are never rewritten.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resumeComparison(Request $request, ?JobMatch $match): array
    {
        if (! $match?->resume_scores) {
            return [];
        }
        $resumes = $request->user()->resumes()->get(['id', 'label', 'original_filename', 'is_active'])->keyBy('id');
        $required = (int) $match->required_total;
        $preferred = (int) $match->preferred_total;

        return collect($match->resume_scores)
            ->filter(fn ($s) => $resumes->has((int) $s['resume_id']))
            ->map(function ($s) use ($resumes, $match, $required, $preferred) {
                $resume = $resumes[(int) $s['resume_id']];

                return [
                    'resume_id' => $resume->id,
                    'label' => $resume->label,
                    'filename' => $resume->original_filename,
                    'is_active' => $resume->is_active,
                    'recommended' => $resume->id === $match->recommended_resume_id,
                    'required_covered' => $s['required_covered'],
                    'required_total' => $required,
                    'preferred_covered' => $s['preferred_covered'],
                    'preferred_total' => $preferred,
                    'keyword_hits' => $s['keyword_hits'] ?? [],
                    'cv_rule_issues' => $s['cv_rule_issues'] ?? [],
                    'could_add' => $s['could_add'] ?? [],
                    'gaps' => $s['gaps'] ?? [],
                    'page_count' => $s['page_count'] ?? null,
                    'word_count' => $s['word_count'] ?? null,
                    'ats_match' => $s['ats_match'] ?? null,
                    'ats_found' => $s['ats_found'] ?? [],
                    'ats_missing' => $s['ats_missing'] ?? [],
                ];
            })->values()->all();
    }

    private function assertCanAnalyze(Request $request): void
    {
        if (! $request->user()->canAnalyzeJobs()) {
            throw ValidationException::withMessages(['analyze' => 'Add your career profile and an active resume first.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function rejectDuplicate(Request $request, array $data, ?int $ignoreId = null): void
    {
        $duplicate = JobPosting::findDuplicate($request->user(), $data, $ignoreId);
        if ($duplicate) {
            throw ValidationException::withMessages([
                ! empty($data['url']) ? 'url' : 'position' => "You already added this job (#{$duplicate->id}).",
            ]);
        }
    }
}
