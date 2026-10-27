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
use App\Services\AiService\AiServiceException;
use App\Services\UsageLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UsageLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::disk());
        Queue::fake();
        $this->user = User::factory()->create();
        CareerProfile::factory()->for($this->user)->create();
        Resume::factory()->withFile()->for($this->user)->create();
    }

    public function test_drafting_stops_at_the_draft_limit(): void
    {
        config(['limits.drafts_per_user' => 2]);
        [$a, $b, $c] = JobPosting::factory()->for($this->user)->count(3)->create();

        $this->actingAs($this->user)->post(route('applications.store', $a))->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('jobs.bulk.generate'), ['job_ids' => [$b->id, $c->id]])
            ->assertInertiaFlash('bulkResult.skipped.0.reason', app(UsageLimits::class)->draftLimitMessage());

        Queue::assertPushed(GenerateApplicationContent::class, 2);
        $this->assertSame(2, $this->user->fresh()->ai_drafts_used);
        $this->actingAs($this->user)->post(route('applications.store', JobPosting::factory()->for($this->user)->create()))
            ->assertSessionHasErrors(['application' => app(UsageLimits::class)->draftLimitMessage()]);
    }

    public function test_regenerate_counts_and_is_refused_at_the_limit(): void
    {
        config(['limits.drafts_per_user' => 1]);
        $job = JobPosting::factory()->for($this->user)->create();
        $app = Application::factory()->create(['job_id' => $job->id, 'user_id' => $this->user->id, 'status' => ApplicationStatus::Generated]);

        $this->actingAs($this->user)->post(route('applications.regenerate', $app))->assertRedirect();
        $this->assertSame(1, $this->user->fresh()->ai_drafts_used);

        $app->fresh()->update(['generation_state' => Application::GENERATION_DONE]); // the first one finished
        $this->actingAs($this->user)->post(route('applications.regenerate', $app))
            ->assertInertiaFlash('toast.type', 'error');
        Queue::assertPushed(GenerateApplicationContent::class, 1);
    }

    public function test_a_failed_draft_is_given_back(): void
    {
        config(['limits.drafts_per_user' => 1]);
        $this->actingAs($this->user)->post(route('applications.store', JobPosting::factory()->for($this->user)->create()));
        $application = $this->user->applications()->firstOrFail();

        (new GenerateApplicationContent($application))->failed(new AiServiceException('boom', 422));

        $this->assertSame(0, $this->user->fresh()->ai_drafts_used);
    }

    public function test_sending_stops_at_the_send_limit_and_replies_do_not_count(): void
    {
        config(['limits.sends_per_user' => 1]);
        $account = EmailAccount::factory()->for($this->user)->create();
        $approved = fn (string $email) => Application::factory()->create([
            'user_id' => $this->user->id, 'status' => ApplicationStatus::Approved, 'subject' => "Application {$email}",
            'resume_id' => $this->user->resumes()->value('id'),
            'job_id' => JobPosting::factory()->for($this->user)->create(['contact_email' => $email])->id,
        ]);
        $first = $approved('jobs@acme-careers.com');
        $second = $approved('hr@other-company.com');

        $this->actingAs($this->user)->post(route('applications.queue', $first), ['email_account_id' => $account->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('applications.queue', $second), ['email_account_id' => $account->id])
            ->assertSessionHasErrors('send');
        $this->assertSame(ApplicationStatus::Approved, $second->fresh()->status);

        // Cancelling frees the slot again.
        $this->actingAs($this->user)->post(route('applications.cancel-send', $first));
        $this->actingAs($this->user)->post(route('applications.queue', $second), ['email_account_id' => $account->id])
            ->assertSessionHasNoErrors();
    }

    public function test_zero_means_unlimited_and_the_dashboard_shows_usage(): void
    {
        config(['limits.drafts_per_user' => 0, 'limits.sends_per_user' => 1000]);
        $this->user->forceFill(['ai_drafts_used' => 5000])->save();

        $this->actingAs($this->user)->post(route('applications.store', JobPosting::factory()->for($this->user)->create()))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn ($p) => $p
            ->where('limits.drafts', ['used' => 5001, 'limit' => null])
            ->where('limits.sends', ['used' => 0, 'limit' => 1000]));
    }
}
