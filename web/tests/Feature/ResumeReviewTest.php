<?php

namespace Tests\Feature;

use App\Jobs\ReviewResume;
use App\Models\CareerProfile;
use App\Models\LlmUsage;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResumeReviewTest extends TestCase
{
    use RefreshDatabase;

    private const REVIEW = [
        'checks' => [
            ['id' => 'whatsapp', 'label' => 'WhatsApp number', 'status' => 'check', 'detail' => 'Mark which number has WhatsApp.', 'evidence' => '+66 094 (Line)'],
            ['id' => 'email', 'label' => 'Email address', 'status' => 'pass', 'detail' => '', 'evidence' => 'a@b.c'],
        ],
        'suggestions' => [['title' => 'Lead with results', 'detail' => 'Say what changed.', 'quote' => 'Duties and Responsibilities', 'example' => null]],
        'word_count' => 600, 'page_count' => 2, 'warnings' => [], 'model' => 'gemini-2.5-flash', 'prompt_version' => 'resume_review.v1',
        'usage' => [['task' => 'resume_review', 'model' => 'gemini-2.5-flash', 'input_tokens' => 1500, 'output_tokens' => 400, 'thinking_tokens' => 0]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::DISK);
        config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret']);
    }

    public function test_upload_queues_a_cv_check(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('resumes.store'), [
            'label' => 'Backend', 'file' => UploadedFile::fake()->create('Min_Thet_Paing_Backend.pdf', 100, 'application/pdf'),
        ])->assertInertiaFlash('toast.message', 'Resume uploaded. Checking it now…');

        Queue::assertPushed(ReviewResume::class);
        $this->assertSame(Resume::REVIEW_PENDING, $user->resumes()->first()->review_state);
    }

    public function test_review_is_stored_without_marking_job_fits_out_of_date(): void
    {
        Http::fake(['ai.test/v1/resumes/review' => Http::response(self::REVIEW)]);
        $user = User::factory()->create();
        CareerProfile::factory()->for($user)->create(['full_name' => 'Min Thet Paing']);
        $resume = Resume::factory()->withFile()->for($user)->create(['original_filename' => 'Min_Thet_Paing_Backend.pdf']);
        $this->travel(1)->hour();
        $before = $user->profileVersion();

        ReviewResume::start($resume); // sync queue in tests

        $resume->refresh();
        $this->assertSame(Resume::REVIEW_DONE, $resume->review_state);
        $this->assertSame('Lead with results', $resume->review['suggestions'][0]['title']);
        $this->assertArrayNotHasKey('usage', $resume->review);
        $this->assertEquals($before, $user->profileVersion()); // updated_at untouched
        $this->assertSame(1, LlmUsage::where('user_id', $user->id)->where('task', 'resume_review')->count());
        Http::assertSent(fn (HttpRequest $r) => $r['candidate_name'] === 'Min Thet Paing'
            && $r['resume']['filename'] === 'Min_Thet_Paing_Backend.pdf' && $r['resume']['content_base64'] !== '');

        $this->actingAs($user)->get(route('resumes.index'))->assertInertia(fn ($page) => $page
            ->where('resumes.0.review.checks.0.status', 'check')
            ->where('reviewing', 0));
    }

    public function test_failed_check_is_shown_and_can_be_retried_by_the_owner_only(): void
    {
        Http::fake(['ai.test/v1/resumes/review' => Http::response(['detail' => 'Unsupported resume format'], 422)]);
        $user = User::factory()->create();
        $resume = Resume::factory()->withFile()->for($user)->create();

        $this->actingAs($user)->post(route('resumes.review', $resume))->assertRedirect();
        $this->assertSame(Resume::REVIEW_FAILED, $resume->fresh()->review_state);
        $this->assertNotNull($resume->fresh()->review_error);

        $this->actingAs(User::factory()->create())->post(route('resumes.review', $resume))->assertNotFound();
    }
}
