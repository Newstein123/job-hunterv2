<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeJobPosting;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\LlmUsage;
use App\Models\Resume;
use App\Models\User;
use App\Services\AiService\PipelinePayload;
use App\Services\AiService\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Resume $resume;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::DISK);
        config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret']);
        $this->user = User::factory()->create();
        CareerProfile::factory()->for($this->user)->create();
        $this->resume = Resume::factory()->withFile()->for($this->user)->create();
    }

    private function pipelineResult(): array
    {
        $r = json_decode(file_get_contents(base_path('tests/Fixtures/pipeline_result.json')), true);
        $r['resume_selection']['recommended_resume_id'] = (string) $this->resume->id;
        $r['meta']['model'] = 'gemini-2.5-flash';
        $r['meta']['usage'] = [
            ['task' => 'job_analysis', 'model' => 'gemini-2.5-flash', 'input_tokens' => 1000, 'output_tokens' => 400, 'thinking_tokens' => 0],
            ['task' => 'email', 'model' => 'gemini-2.5-flash', 'input_tokens' => 2000, 'output_tokens' => 500, 'thinking_tokens' => 1500],
        ];

        return $r;
    }

    public function test_cost_uses_output_rate_for_thinking_tokens(): void
    {
        // 1M input at $0.30 + 1M output at $2.50
        $this->assertSame(2.8, UsageRecorder::cost('gemini-2.5-flash', 1_000_000, 1_000_000));
        $this->assertSame(0.5, UsageRecorder::cost('gemini-2.5-flash-lite', 1_000_000, 1_000_000));
    }

    public function test_generation_records_usage_and_cost(): void
    {
        Http::fake(['ai.test/v1/applications/generate' => Http::response($this->pipelineResult())]);
        $job = JobPosting::factory()->for($this->user)->create();

        $this->actingAs($this->user)->post(route('applications.store', $job));

        $rows = LlmUsage::orderBy('id')->get();
        $this->assertSame(['job_analysis', 'email'], $rows->pluck('task')->all());
        $this->assertSame($job->id, $rows[1]->job_id);
        $this->assertNotNull($rows[1]->application_id);
        // email: 2000 in * 0.30 + (500 + 1500) out * 2.50, per 1M
        $this->assertEqualsWithDelta(0.0056, (float) $rows[1]->cost_usd, 0.000001);

        $this->actingAs($this->user)->get(route('dashboard'))
            ->assertInertia(fn ($p) => $p->where('aiUsage.calls', 2)->where('aiUsage.cost_usd', 0.0069));
    }

    public function test_stored_analysis_is_reused_only_while_the_description_is_unchanged(): void
    {
        Http::fake([
            'ai.test/v1/jobs/match' => Http::response(['job_analysis' => $this->pipelineResult()['job_analysis'], 'match' => $this->pipelineResult()['match'],
                'resume_selection' => $this->pipelineResult()['resume_selection'], 'meta' => $this->pipelineResult()['meta']]),
            'ai.test/v1/applications/generate' => Http::response($this->pipelineResult()),
        ]);
        $job = JobPosting::factory()->for($this->user)->create();
        AnalyzeJobPosting::start($job); // analysis stored with the description fingerprint

        $this->actingAs($this->user)->post(route('applications.store', $job));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'applications/generate')
            && ($r['job_analysis']['company'] ?? null) === 'Merlion Pay');

        // After the description changes, the old analysis must not be reused.
        $job->update(['description' => $job->description.' Also: GraphQL experience required.']);
        $this->assertNull(PipelinePayload::reusableAnalysis($job->fresh()));
    }
}
