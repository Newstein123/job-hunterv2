<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Jobs\GenerateApplicationContent;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\EmailAccount;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private JobPosting $job;

    private Resume $backend;

    private Resume $fullstack;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::DISK);
        config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret']);

        $this->user = User::factory()->create();
        CareerProfile::factory()->for($this->user)->create(['full_name' => 'Alex Tan']);
        $this->backend = Resume::factory()->withFile()->for($this->user)->create(['original_filename' => 'backend_developer.pdf']);
        $this->fullstack = Resume::factory()->withFile()->for($this->user)->create(['original_filename' => 'fullstack.docx']);
        $this->job = JobPosting::factory()->for($this->user)->create(['position' => null, 'company' => null]);
    }

    private function pipelineResult(): array
    {
        $result = json_decode(file_get_contents(base_path('tests/Fixtures/pipeline_result.json')), true);
        $result['resume_selection']['recommended_resume_id'] = (string) $this->backend->id;

        return $result;
    }

    private function generate(): Application
    {
        Http::fake(['ai.test/*' => Http::response($this->pipelineResult())]);
        $this->actingAs($this->user)->post(route('applications.store', $this->job)); // queue is sync in tests

        return $this->job->fresh()->application;
    }

    public function test_starting_an_application_queues_generation(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->post(route('applications.store', $this->job));

        $application = $this->job->application;
        $response->assertRedirect(route('applications.show', $application));
        $this->assertSame(ApplicationStatus::Draft, $application->status);
        $this->assertSame(Application::GENERATION_PENDING, $application->generation_state);
        Queue::assertPushed(GenerateApplicationContent::class);

        // Duplicate protection: a second attempt reuses the same application.
        $this->actingAs($this->user)->post(route('applications.store', $this->job))
            ->assertRedirect(route('applications.show', $application));
        $this->assertSame(1, Application::count());
    }

    public function test_requires_profile_and_active_resume(): void
    {
        $this->user->resumes()->update(['is_active' => false]);

        $this->actingAs($this->user)->post(route('applications.store', $this->job))->assertSessionHasErrors('application');
        $this->assertNull($this->job->application);
    }

    public function test_generation_stores_structured_results(): void
    {
        $application = $this->generate();

        Http::assertSent(function (HttpRequest $request) {
            $body = $request->data();

            return $request->url() === 'http://ai.test/v1/applications/generate'
                && $request->header('X-Service-Token')[0] === 'secret'
                && $body['profile']['name'] === 'Alex Tan'
                && $body['profile']['experiences'][0]['id'] === 'exp_'.$this->user->careerProfile->experiences->first()->id
                && count($body['resumes']) === 2
                && base64_decode($body['resumes'][0]['content_base64']) === "PHP Laravel developer\n";
        });

        $this->assertSame(ApplicationStatus::Generated, $application->status);
        $this->assertSame(Application::GENERATION_DONE, $application->generation_state);
        $this->assertSame($this->backend->id, $application->resume_id);
        $this->assertSame(json_decode(file_get_contents(base_path('tests/Fixtures/pipeline_result.json')), true)['email']['subject'], $application->subject);
        $this->assertSame(['PHP', 'Laravel', 'MySQL', 'REST API', 'Docker'], $application->latestStrategy->skill_strategy['emphasize']);

        $analysis = $this->job->latestAnalysis;
        $this->assertSame('fake', $analysis->model);
        $this->assertSame(5, $analysis->requirements()->where('type', 'required_skill')->count());
        // Blank job metadata is filled from the extraction.
        $this->assertSame('Merlion Pay', $this->job->fresh()->company);
        $this->assertSame(['draft', 'generated'], $application->statusHistory->pluck('to_status')->reverse()->values()->all());

        $this->actingAs($this->user)->get(route('applications.show', $application))->assertOk();
    }

    public function test_rejected_input_fails_without_retry(): void
    {
        Http::fake(['ai.test/*' => Http::response(['detail' => 'Invalid rules configuration'], 422)]);

        $this->actingAs($this->user)->post(route('applications.store', $this->job));

        $application = $this->job->application;
        $this->assertSame(Application::GENERATION_FAILED, $application->generation_state);
        $this->assertSame('Invalid rules configuration', $application->generation_error);
        $this->assertSame(ApplicationStatus::Draft, $application->status);
        Http::assertSentCount(1);
    }

    public function test_unknown_recommended_resume_is_rejected(): void
    {
        $result = $this->pipelineResult();
        $result['resume_selection']['recommended_resume_id'] = '999999';
        Http::fake(['ai.test/*' => Http::response($result)]);

        $this->actingAs($this->user)->post(route('applications.store', $this->job));

        $this->assertSame(Application::GENERATION_FAILED, $this->job->application->generation_state);
    }

    public function test_edit_approve_track_flow(): void
    {
        $application = $this->generate();

        $this->actingAs($this->user)->put(route('applications.update', $application), [
            'subject' => 'Edited subject', 'body' => 'Edited body', 'resume_id' => $this->fullstack->id,
        ])->assertSessionHasNoErrors();
        $application->refresh();
        $this->assertSame($this->fullstack->id, $application->resume_id);
        $this->assertNotNull($application->edited_at);

        $this->actingAs($this->user)->post(route('applications.approve', $application))->assertSessionHasNoErrors();
        $application->refresh();
        $this->assertSame(ApplicationStatus::Approved, $application->status);
        $this->assertNotNull($application->approved_at);

        // Approved content is locked until reopened.
        $this->actingAs($this->user)->put(route('applications.update', $application), [
            'subject' => 'x', 'body' => 'y', 'resume_id' => $this->backend->id,
        ])->assertStatus(409);

        $this->actingAs($this->user)->post(route('applications.track', $application), ['status' => 'sent', 'note' => 'Sent from my Gmail']);
        $this->actingAs($this->user)->post(route('applications.track', $application), ['status' => 'interview']);
        $this->assertSame(ApplicationStatus::Interview, $application->fresh()->status);

        $this->actingAs($this->user)->post(route('applications.track', $application), ['status' => 'sent'])
            ->assertSessionHasErrors('status');
        $this->assertSame(
            ['draft', 'generated', 'approved', 'sent', 'interview'],
            $application->statusHistory()->pluck('to_status')->reverse()->values()->all(),
        );
    }

    public function test_cannot_edit_with_another_users_resume(): void
    {
        $application = $this->generate();
        $foreign = Resume::factory()->create();

        $this->actingAs($this->user)->put(route('applications.update', $application), [
            'subject' => 'S', 'body' => 'B', 'resume_id' => $foreign->id,
        ])->assertSessionHasErrors('resume_id');
    }

    public function test_approval_with_validation_errors_needs_acknowledgement(): void
    {
        $application = Application::factory()->create([
            'job_id' => $this->job->id, 'user_id' => $this->user->id,
            'validation' => ['errors' => ['Email has 250 words; max is 200'], 'warnings' => [], 'attempts' => 2],
        ]);

        $this->actingAs($this->user)->post(route('applications.approve', $application))->assertSessionHasErrors('acknowledge');
        $this->assertSame(ApplicationStatus::Generated, $application->fresh()->status);

        $this->actingAs($this->user)->post(route('applications.approve', $application), ['acknowledge' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(ApplicationStatus::Approved, $application->fresh()->status);
    }

    public function test_reopen_regenerate_and_withdraw(): void
    {
        $application = $this->generate();
        $this->actingAs($this->user)->post(route('applications.approve', $application));
        $this->actingAs($this->user)->post(route('applications.regenerate', $application))->assertStatus(409);

        $this->actingAs($this->user)->post(route('applications.reopen', $application));
        $this->assertSame(ApplicationStatus::Generated, $application->fresh()->status);

        $this->actingAs($this->user)->post(route('applications.regenerate', $application))->assertRedirect();
        $this->assertSame(2, $application->strategies()->count());

        $this->actingAs($this->user)->post(route('applications.withdraw', $application));
        $this->assertSame(ApplicationStatus::Withdrawn, $application->fresh()->status);
        $this->actingAs($this->user)->post(route('applications.withdraw', $application))->assertStatus(409);
    }

    public function test_other_users_cannot_see_applications(): void
    {
        $application = $this->generate();

        $this->actingAs(User::factory()->create())->get(route('applications.show', $application))->assertNotFound();
        $this->actingAs(User::factory()->create())->post(route('applications.approve', $application))->assertNotFound();
    }

    public function test_approve_only_does_not_queue_even_with_an_account_selected(): void
    {
        $application = $this->generate();
        $account = EmailAccount::factory()->for($this->user)->create();

        $this->actingAs($this->user)->post(route('applications.approve', $application), [
            'email_account_id' => $account->id, 'approve_only' => '1',
        ])->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ApplicationStatus::Approved, $application->status);
        $this->assertSame(0, $application->emailMessages()->count());
    }
}
