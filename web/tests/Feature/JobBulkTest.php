<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Jobs\GenerateApplicationContent;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\Country;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JobBulkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::DISK);
        $this->travelTo('2026-09-30 02:00:00'); // Wednesday 10:00 Singapore
        $this->user = User::factory()->create();
        CareerProfile::factory()->for($this->user)->create();
        Resume::factory()->withFile()->for($this->user)->create();
        $this->account = EmailAccount::factory()->for($this->user)->create(['email' => 'me@gmail.com']);
    }

    private function approvedJob(string $position, ?string $email = 'jobs@acme-careers.com', string $country = 'SG'): JobPosting
    {
        $job = JobPosting::factory()->for($this->user)->create([
            'position' => $position, 'contact_email' => $email,
            'country_id' => Country::where('code', $country)->value('id'),
        ]);
        $app = Application::factory()->create([
            'job_id' => $job->id, 'user_id' => $this->user->id, 'status' => ApplicationStatus::Approved,
            'resume_id' => $this->user->resumes()->value('id'), 'subject' => "{$position} - Application",
        ]);
        $app->strategies()->create(['match' => [], 'resume_selection' => [], 'skill_strategy' => [], 'meta' => [],
            'application_strategy' => ['sending_rules' => ['timezone' => 'Asia/Singapore',
                'allowed_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'start_time' => '09:00', 'end_time' => '17:00']]]);

        return $job;
    }

    public function test_filter_by_country_and_stage(): void
    {
        $this->approvedJob('Approved SG');
        $this->approvedJob('Approved AE', country: 'AE');
        JobPosting::factory()->for($this->user)->create(['position' => 'New SG']);

        $this->actingAs($this->user)->get(route('jobs.index', ['stage' => 'approved']))
            ->assertInertia(fn ($p) => $p->has('jobs.data', 2));
        $this->actingAs($this->user)->get(route('jobs.index', ['stage' => 'approved', 'country' => Country::where('code', 'AE')->value('id')]))
            ->assertInertia(fn ($p) => $p->has('jobs.data', 1)->where('jobs.data.0.position', 'Approved AE'));
        $this->actingAs($this->user)->get(route('jobs.index', ['stage' => 'not_analyzed']))
            ->assertInertia(fn ($p) => $p->has('jobs.data', 1)->where('jobs.data.0.position', 'New SG'));
    }

    public function test_bulk_generate_skips_jobs_with_applications(): void
    {
        Queue::fake();
        $new1 = JobPosting::factory()->for($this->user)->create();
        $new2 = JobPosting::factory()->for($this->user)->create();
        $done = $this->approvedJob('Has app');

        $this->actingAs($this->user)->post(route('jobs.bulk.generate'), ['job_ids' => [$new1->id, $new2->id, $done->id]])
            ->assertInertiaFlash('bulkResult.skipped.0.reason', 'already has an application');

        Queue::assertPushed(GenerateApplicationContent::class, 2);
        $this->assertSame(ApplicationStatus::Draft, $new1->fresh()->application->status);
    }

    public function test_bulk_schedule_at_chosen_time_and_skips_unready(): void
    {
        $a = $this->approvedJob('A');
        $b = $this->approvedJob('B');
        $noEmail = $this->approvedJob('No email', email: null);
        $new = JobPosting::factory()->for($this->user)->create(['position' => 'Not drafted']);

        $this->actingAs($this->user)->post(route('jobs.bulk.schedule'), [
            'job_ids' => [$a->id, $b->id, $noEmail->id, $new->id],
            'email_account_id' => $this->account->id,
            'mode' => 'at', 'send_at' => '2026-10-01T10:30', 'timezone_mode' => 'fixed', 'timezone' => 'Asia/Singapore',
        ])->assertSessionHasNoErrors()
            ->assertInertiaFlash('bulkResult.skipped.0.reason', 'This job has no contact email. Add the address the posting gives.')
            ->assertInertiaFlash('bulkResult.skipped.1.reason', 'has no application yet');

        foreach ([$a, $b] as $job) {
            $app = $job->fresh()->application;
            $this->assertSame(ApplicationStatus::Queued, $app->status);
            $this->assertSame('2026-10-01T02:30:00+00:00', $app->outboundMessage->scheduled_at->utc()->toIso8601String());
        }
        $this->assertSame(ApplicationStatus::Approved, $noEmail->fresh()->application->status);

        $this->actingAs($this->user)->get(route('jobs.index', ['stage' => 'scheduled']))
            ->assertInertia(fn ($p) => $p->has('jobs.data', 2)->where('jobs.data.0.application.scheduled_at', fn ($v) => $v !== null));
    }

    public function test_keep_within_window_moves_time_to_next_slot(): void
    {
        $job = $this->approvedJob('Late');

        $this->actingAs($this->user)->post(route('jobs.bulk.schedule'), [
            'job_ids' => [$job->id], 'email_account_id' => $this->account->id, 'keep_window' => '1',
            'mode' => 'at', 'send_at' => '2026-10-03T08:00', 'timezone_mode' => 'country', // Saturday
        ])->assertInertiaFlash('bulkResult.message',
            'Scheduled 1 email(s) from me@gmail.com at 08:00 local time: 1 in Asia/Singapore. 1 moved to the next time your sending window allows.');

        // Monday 09:00 Singapore
        $this->assertSame('2026-10-05T01:00:00+00:00', $job->fresh()->application->outboundMessage->scheduled_at->utc()->toIso8601String());
    }

    public function test_bulk_schedule_validates_input(): void
    {
        $job = $this->approvedJob('A');
        $foreign = EmailAccount::factory()->create();

        $this->actingAs($this->user)->post(route('jobs.bulk.schedule'), [
            'job_ids' => [$job->id], 'email_account_id' => $foreign->id, 'mode' => 'earliest',
        ])->assertSessionHasErrors('email_account_id');
        $this->actingAs($this->user)->post(route('jobs.bulk.schedule'), [
            'job_ids' => [$job->id], 'email_account_id' => $this->account->id, 'mode' => 'at',
            'send_at' => '2020-01-01T10:00', 'timezone_mode' => 'country',
        ])->assertInertiaFlash('bulkResult.skipped.0.reason', 'that time has already passed in Asia/Singapore');
        $this->actingAs($this->user)->post(route('jobs.bulk.schedule'), [
            'job_ids' => [$job->id], 'email_account_id' => $this->account->id, 'mode' => 'at',
            'send_at' => '2026-10-01T10:00', 'timezone_mode' => 'fixed',
        ])->assertSessionHasErrors('timezone');
        $this->actingAs($this->user)->post(route('jobs.bulk.schedule'), [
            'job_ids' => [], 'email_account_id' => $this->account->id, 'mode' => 'earliest',
        ])->assertSessionHasErrors('job_ids');
    }

    public function test_bulk_cancel_returns_to_approved_and_ignores_other_users_jobs(): void
    {
        $job = $this->approvedJob('A');
        $this->actingAs($this->user)->post(route('jobs.bulk.schedule'), [
            'job_ids' => [$job->id], 'email_account_id' => $this->account->id, 'mode' => 'earliest',
        ]);
        $foreign = JobPosting::factory()->create();

        $this->actingAs($this->user)->post(route('jobs.bulk.cancel'), ['job_ids' => [$job->id, $foreign->id]]);

        $this->assertSame(ApplicationStatus::Approved, $job->fresh()->application->status);
        $this->assertSame(EmailMessage::CANCELLED, $job->fresh()->application->outboundMessage->status);
    }

    public function test_specific_time_uses_each_jobs_country_timezone_exactly(): void
    {
        $th = $this->approvedJob('Bangkok role', country: 'TH');
        $au = $this->approvedJob('Sydney role', country: 'AU');
        $sg = $this->approvedJob('Singapore role', country: 'SG');

        $this->actingAs($this->user)->post(route('jobs.bulk.schedule'), [
            'job_ids' => [$th->id, $au->id, $sg->id], 'email_account_id' => $this->account->id,
            'mode' => 'at', 'send_at' => '2026-10-01T06:30', 'timezone_mode' => 'country',
        ])->assertSessionHasNoErrors()->assertInertiaFlash('bulkResult.message',
            'Scheduled 3 email(s) from me@gmail.com at 06:30 local time: 1 in Asia/Bangkok, 1 in Australia/Sydney, 1 in Asia/Singapore.');

        // 06:30 local in each country, kept exactly even though it is before the 09:00 window.
        $utc = fn (JobPosting $j) => $j->fresh()->application->outboundMessage->scheduled_at->utc()->toIso8601String();
        $this->assertSame('2026-09-30T23:30:00+00:00', $utc($th)); // UTC+7
        $this->assertSame('2026-09-30T20:30:00+00:00', $utc($au)); // UTC+10 (AEST)
        $this->assertSame('2026-09-30T22:30:00+00:00', $utc($sg)); // UTC+8
        $this->assertFalse($th->fresh()->application->outboundMessage->respect_window);

        $this->actingAs($this->user)->get(route('jobs.index', ['stage' => 'scheduled']))
            ->assertInertia(fn ($p) => $p->where('jobs.data', fn ($rows) => collect($rows)->pluck('application.scheduled_tz')
                ->sort()->values()->all() === ['Asia/Bangkok', 'Asia/Singapore', 'Australia/Sydney']));
    }

    public function test_exact_time_outside_window_is_sent_at_that_time(): void
    {
        Http::fake([
            'gmail.googleapis.com/*' => Http::response(['id' => 'm1', 'threadId' => 't1']),
            // the clock jumps ~21h ahead below, so the access token gets refreshed first
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
        ]);
        $th = $this->approvedJob('Bangkok role', country: 'TH');
        $this->actingAs($this->user)->post(route('jobs.bulk.schedule'), [
            'job_ids' => [$th->id], 'email_account_id' => $this->account->id,
            'mode' => 'at', 'send_at' => '2026-10-01T06:30', 'timezone_mode' => 'country',
        ]);

        $this->travelTo('2026-09-30 23:29:00'); // 06:29 Bangkok: not yet
        $this->artisan('emails:dispatch-due');
        $this->assertSame(EmailMessage::SCHEDULED, $th->fresh()->application->outboundMessage->status);

        $this->travelTo('2026-09-30 23:30:30'); // 06:30 Bangkok
        $this->artisan('emails:dispatch-due');
        $this->assertSame(EmailMessage::SENT, $th->fresh()->application->outboundMessage->status);
        $this->assertSame(ApplicationStatus::Sent, $th->fresh()->application->status);
    }
}
