<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeJobPosting;
use App\Models\CareerProfile;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JobMatchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Resume $resume;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::disk());
        config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret']);
        $this->user = User::factory()->create();
        CareerProfile::factory()->for($this->user)->create();
        $this->resume = Resume::factory()->withFile()->for($this->user)->create(['label' => 'Backend CV']);
    }

    /** A MatchPreview response, derived from the real pipeline fixture. */
    private function preview(int $requiredCovered = 5, int $requiredTotal = 5): array
    {
        $r = json_decode(file_get_contents(base_path('tests/Fixtures/pipeline_result.json')), true);
        $r['resume_selection']['recommended_resume_id'] = (string) $this->resume->id;
        $r['match']['summary']['required_covered'] = $requiredCovered;
        $r['match']['summary']['required_total'] = $requiredTotal;

        return ['job_analysis' => $r['job_analysis'], 'match' => $r['match'], 'resume_selection' => $r['resume_selection'], 'meta' => $r['meta']];
    }

    private function csv(int $rows): UploadedFile
    {
        $lines = ['Title,Company,Description'];
        for ($i = 1; $i <= $rows; $i++) {
            $lines[] = "Role {$i},Company {$i},\"".str_repeat("Laravel PHP role number {$i} building REST APIs. ", 2).'"';
        }

        return UploadedFile::fake()->createWithContent('jobs.csv', implode("\n", $lines));
    }

    public function test_fit_thresholds(): void
    {
        $this->assertSame(JobMatch::STRONG, JobMatch::fitFor(4, 5));
        $this->assertSame(JobMatch::PARTIAL, JobMatch::fitFor(3, 6));
        $this->assertSame(JobMatch::WEAK, JobMatch::fitFor(1, 4));
        $this->assertSame(JobMatch::UNKNOWN, JobMatch::fitFor(0, 0));
    }

    public function test_import_queues_analysis_for_each_new_job(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post(route('jobs.import.store'), ['file' => $this->csv(3)])
            ->assertInertiaFlash('importResult.analysis', 'queued');

        Queue::assertPushed(AnalyzeJobPosting::class, 3);
        $this->assertSame(3, $this->user->jobPostings()->where('analysis_state', JobPosting::ANALYSIS_PENDING)->count());
    }

    public function test_import_without_profile_does_not_analyze(): void
    {
        Queue::fake();
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('jobs.import.store'), ['file' => $this->csv(2)])
            ->assertInertiaFlash('importResult.analysis', 'needs_profile');

        Queue::assertNothingPushed();
    }

    public function test_analysis_stores_fit_without_drafting_an_email(): void
    {
        Http::fake(['ai.test/v1/jobs/match' => Http::response($this->preview())]);
        $job = JobPosting::factory()->for($this->user)->create(['position' => null, 'company' => null]);

        AnalyzeJobPosting::start($job); // sync queue in tests

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://ai.test/v1/jobs/match');
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'applications/generate'));
        $job->refresh();
        $this->assertSame(JobPosting::ANALYSIS_DONE, $job->analysis_state);
        $this->assertSame('Merlion Pay', $job->company);
        $this->assertSame(JobMatch::STRONG, $job->match->fit);
        $this->assertSame($this->resume->id, $job->match->recommended_resume_id);
        $this->assertSame(['Kubernetes'], $job->match->missing_skills);
        $this->assertNull($job->application);
        $this->assertSame(5, $job->latestAnalysis->requirements()->where('type', 'required_skill')->count());
    }

    public function test_job_page_shows_keyword_match_per_resume(): void
    {
        $preview = $this->preview();
        $preview['resume_selection']['scores'][0]['resume_id'] = (string) $this->resume->id;
        Http::fake(['ai.test/v1/jobs/match' => Http::response($preview)]);
        $job = JobPosting::factory()->for($this->user)->create();
        AnalyzeJobPosting::start($job);

        $this->actingAs($this->user)->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page
            ->has('resumeComparison', 1)
            ->where('resumeComparison.0.ats_match', 80)
            ->where('resumeComparison.0.ats_missing', ['Kubernetes', 'fintech']));
    }

    public function test_job_page_shows_requirement_quotes_but_not_skills_twice(): void
    {
        Http::fake(['ai.test/v1/jobs/match' => Http::response($this->preview())]);
        $job = JobPosting::factory()->for($this->user)->create();
        AnalyzeJobPosting::start($job);

        $this->actingAs($this->user)->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page
            ->has('match.requirement_checks', 1)
            ->where('match.requirement_checks.0.status', 'met')
            ->where('match.requirement_checks.0.evidence.0.source', 'Profile')
            ->where('match.requirement_checks.0.evidence.0.quote', '8 years of work experience in total')
            ->where('match.missing_skills', ['Kubernetes'])
            ->where('match.matching_skills', fn ($skills) => ! collect($skills)->contains(fn ($s) => str_contains($s, 'years'))));
    }

    public function test_failed_analysis_is_recorded(): void
    {
        Http::fake(['ai.test/*' => Http::response(['detail' => 'GEMINI_API_KEY is not configured'], 503)]);
        $job = JobPosting::factory()->for($this->user)->create();

        try {
            AnalyzeJobPosting::start($job);
        } catch (\Throwable) {
            // retryable error rethrown by the sync queue on the last attempt
        }

        $this->assertContains($job->fresh()->analysis_state, [JobPosting::ANALYSIS_FAILED, JobPosting::ANALYSIS_RUNNING]);
    }

    public function test_jobs_list_filters_and_sorts_by_fit_and_flags_stale(): void
    {
        Http::fakeSequence('ai.test/v1/jobs/match')
            ->push($this->preview(1, 5))   // weak
            ->push($this->preview(5, 5))   // strong
            ->push($this->preview(3, 5));  // partial
        $weak = JobPosting::factory()->for($this->user)->create(['position' => 'Weak one']);
        $strong = JobPosting::factory()->for($this->user)->create(['position' => 'Strong one']);
        $partial = JobPosting::factory()->for($this->user)->create(['position' => 'Partial one']);
        JobPosting::factory()->for($this->user)->create(['position' => 'Not analyzed']);
        AnalyzeJobPosting::start($weak, $strong, $partial);

        $this->actingAs($this->user)->get(route('jobs.index', ['sort' => 'fit']))->assertOk()
            ->assertInertia(fn ($page) => $page->component('jobs/index')
                ->where('jobs.data.0.position', 'Strong one')
                ->where('jobs.data.1.position', 'Partial one')
                ->where('jobs.data.2.position', 'Weak one')
                ->where('jobs.data.3.position', 'Not analyzed')
                ->where('jobs.data.0.match.stale', false)
                ->where('fitCounts.strong', 1)->where('fitCounts.not_analyzed', 1));

        $this->actingAs($this->user)->get(route('jobs.index', ['fit' => 'weak']))
            ->assertInertia(fn ($page) => $page->has('jobs.data', 1)->where('jobs.data.0.position', 'Weak one'));

        // Changing the profile makes existing fits out of date.
        $this->travel(1)->minutes();
        $this->user->careerProfile->skills()->create(['name' => 'Kubernetes']);
        $this->actingAs($this->user)->get(route('jobs.index', ['sort' => 'fit']))
            ->assertInertia(fn ($page) => $page->where('jobs.data.0.match.stale', true));
    }

    public function test_bulk_analyze_queues_only_selected_jobs(): void
    {
        $picked = JobPosting::factory()->for($this->user)->create();
        $failed = JobPosting::factory()->for($this->user)->create(['analysis_state' => JobPosting::ANALYSIS_FAILED]);
        $running = JobPosting::factory()->for($this->user)->create(['analysis_state' => JobPosting::ANALYSIS_RUNNING]);
        $company = JobPosting::factory()->for($this->user)->create(['kind' => JobPosting::KIND_SPECULATIVE]);
        $notSelected = JobPosting::factory()->for($this->user)->create();
        $foreign = JobPosting::factory()->create();

        Queue::fake();
        $this->actingAs($this->user)->post(route('jobs.bulk.analyze'), [
            'job_ids' => [$picked->id, $failed->id, $running->id, $company->id, $foreign->id],
        ])->assertSessionHasNoErrors()->assertInertiaFlash('bulkResult.message', 'Analyzing 2 job(s)…');

        Queue::assertPushed(AnalyzeJobPosting::class, 2);
        Queue::assertPushed(AnalyzeJobPosting::class, fn ($j) => in_array($j->posting->id, [$picked->id, $failed->id], true));
        Queue::assertNotPushed(AnalyzeJobPosting::class, fn ($j) => $j->posting->id === $notSelected->id);

        $this->actingAs($this->user)->post(route('jobs.bulk.analyze'), ['job_ids' => range(1, 101)])
            ->assertSessionHasErrors('job_ids');
    }

    public function test_adding_a_job_queues_analysis_and_other_users_cannot_trigger_it(): void
    {
        Queue::fake();
        $this->actingAs($this->user)->post(route('jobs.store'), [
            'description' => str_repeat('Laravel engineer building REST APIs for payments. ', 2),
        ]);
        Queue::assertPushed(AnalyzeJobPosting::class, 1);

        $job = $this->user->jobPostings()->first();
        $this->actingAs(User::factory()->create())->post(route('jobs.analyze', $job))->assertNotFound();
    }
}
