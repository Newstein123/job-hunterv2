<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Jobs\SyncMailbox;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailSendingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EmailAccount $account;

    private Application $application;

    private const SEND_URL = 'gmail.googleapis.com/gmail/v1/users/me/messages/send';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::disk());
        $this->travelTo('2026-09-30 02:00:00');

        $this->user = User::factory()->create();
        CareerProfile::factory()->for($this->user)->create(['full_name' => 'Alex Tan']);
        $resume = Resume::factory()->withFile("%PDF resume\n")->for($this->user)->create(['original_filename' => 'backend.pdf', 'mime_type' => 'application/pdf']);
        $this->account = EmailAccount::factory()->for($this->user)->create(['email' => 'alex@gmail.com']);
        $job = JobPosting::factory()->for($this->user)->create(['contact_email' => 'careers@merlionpay.sg']);
        $this->application = Application::factory()->create([
            'job_id' => $job->id, 'user_id' => $this->user->id, 'resume_id' => $resume->id,
            'subject' => 'Senior Backend Engineer - Application - Alex Tan', 'body' => "Dear Hiring Team,\n\nHello.",
        ]);
        $this->application->strategies()->create([
            'match' => [], 'resume_selection' => [], 'skill_strategy' => [], 'meta' => [],
            'application_strategy' => [],
        ]);
    }

    private function approveAndQueue(): void
    {
        $this->actingAs($this->user)->post(route('applications.approve', $this->application), [
            'email_account_id' => $this->account->id,
        ])->assertSessionHasNoErrors();
    }

    private function message(): EmailMessage
    {
        return $this->application->emailMessages()->where('direction', 'outbound')->latest('id')->firstOrFail();
    }

    public function test_approve_and_send_queues_then_scheduler_sends_via_gmail(): void
    {
        Http::fake([self::SEND_URL => Http::response(['id' => 'msg-1', 'threadId' => 'thr-1'])]);

        $this->approveAndQueue();
        $this->assertSame(ApplicationStatus::Queued, $this->application->fresh()->status);
        $this->assertSame(EmailMessage::QUEUED, $this->message()->status);
        Http::assertNothingSent(); // never from the request cycle

        $this->artisan('emails:dispatch-due')->assertSuccessful(); // queue is sync in tests

        $message = $this->message();
        $this->assertSame(EmailMessage::SENT, $message->status);
        $this->assertSame('thr-1', $message->thread_id);
        $this->assertSame(ApplicationStatus::Sent, $this->application->fresh()->status);
        $this->assertSame(1, $this->account->fresh()->sentToday());

        Http::assertSent(function (HttpRequest $request) {
            if (! str_contains($request->url(), 'messages/send')) {
                return false;
            }
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));

            return $request->header('Authorization')[0] === 'Bearer access-token'
                && str_contains($raw, 'To: careers@merlionpay.sg')
                && str_contains($raw, 'From: Alex Tan <alex@gmail.com>')
                && str_contains($raw, 'Subject: Senior Backend Engineer - Application - Alex Tan')
                && str_contains($raw, 'filename=backend.pdf');
        });
    }

    public function test_approve_and_send_goes_out_straight_away_at_any_hour(): void
    {
        Http::fake([
            'gmail.googleapis.com/*' => Http::response(['id' => 'm1', 'threadId' => 't1']),
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
        ]);
        $this->travelTo('2026-10-03 15:00:00'); // Saturday 23:00 in Singapore

        $this->approveAndQueue();
        $this->assertSame(EmailMessage::QUEUED, $this->message()->status);
        $this->artisan('emails:dispatch-due');

        $this->assertSame(EmailMessage::SENT, $this->message()->status);
    }

    public function test_queue_requires_recipient_active_account_and_blocks_duplicates(): void
    {
        $this->application->job->update(['contact_email' => null]);
        $this->actingAs($this->user)->post(route('applications.approve', $this->application), ['email_account_id' => $this->account->id])
            ->assertSessionHasErrors('recipient');
        $this->assertSame(ApplicationStatus::Generated, $this->application->fresh()->status); // whole approve rolled back

        $this->application->job->update(['contact_email' => 'careers@merlionpay.sg']);
        $this->account->update(['status' => EmailAccount::STATUS_NEEDS_REAUTH]);
        $this->actingAs($this->user)->post(route('applications.approve', $this->application), ['email_account_id' => $this->account->id])
            ->assertSessionHasErrors('email_account_id');

        $this->account->update(['status' => EmailAccount::STATUS_ACTIVE]);
        $this->approveAndQueue();
        $this->actingAs($this->user)->post(route('applications.queue', $this->application), ['email_account_id' => $this->account->id])
            ->assertStatus(409);

        $foreign = EmailAccount::factory()->create();
        $this->actingAs($this->user)->post(route('applications.retry-send', $this->application), ['email_account_id' => $foreign->id])
            ->assertStatus(409);
    }

    public function test_cancel_send_returns_to_approved(): void
    {
        $this->approveAndQueue();

        $this->actingAs($this->user)->post(route('applications.cancel-send', $this->application))->assertSessionHasNoErrors();

        $this->assertSame(EmailMessage::CANCELLED, $this->message()->status);
        $this->assertSame(ApplicationStatus::Approved, $this->application->fresh()->status);
        $this->artisan('emails:dispatch-due');
        $this->assertSame(EmailMessage::CANCELLED, $this->message()->status);
    }

    public function test_transient_errors_retry_later_then_succeed(): void
    {
        Http::fakeSequence(self::SEND_URL)
            ->push(['error' => ['message' => 'Backend Error']], 503)
            ->push(['id' => 'msg-2', 'threadId' => 'thr-2']);

        $this->approveAndQueue();
        $this->artisan('emails:dispatch-due');
        $message = $this->message();
        $this->assertSame(EmailMessage::RETRYING, $message->status);
        $this->assertSame(1, $message->attempts);

        $this->artisan('emails:dispatch-due'); // not due yet
        $this->assertSame(EmailMessage::RETRYING, $this->message()->status);

        $this->travel(16)->minutes();
        $this->artisan('emails:dispatch-due');
        $this->assertSame(EmailMessage::SENT, $this->message()->status);
    }

    public function test_revoked_token_marks_account_for_reconnect_and_fails_message(): void
    {
        $this->account->update(['token_expires_at' => now()->subMinute()]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->approveAndQueue();
        $this->artisan('emails:dispatch-due');

        $this->assertSame(EmailMessage::FAILED, $this->message()->status);
        $this->assertSame(EmailAccount::STATUS_NEEDS_REAUTH, $this->account->fresh()->status);
        $this->assertSame(ApplicationStatus::Queued, $this->application->fresh()->status);
        $this->assertDatabaseHas('email_events', ['email_account_id' => $this->account->id, 'type' => 'account_error']);

        // The user retries from another healthy account.
        $other = EmailAccount::factory()->for($this->user)->create();
        Http::fake([self::SEND_URL => Http::response(['id' => 'm3', 'threadId' => 't3'])]);
        $this->actingAs($this->user)->post(route('applications.retry-send', $this->application), ['email_account_id' => $other->id])
            ->assertSessionHasNoErrors();
        $this->artisan('emails:dispatch-due');
        $this->assertSame(EmailMessage::SENT, $this->message()->status);
        $this->assertSame($other->id, $this->message()->email_account_id);
    }

    public function test_daily_limit_quota_restricts_account(): void
    {
        Http::fake([self::SEND_URL => Http::response(['error' => [
            'message' => 'Daily limit exceeded', 'errors' => [['reason' => 'dailyLimitExceeded']],
        ]], 403)]);

        $this->approveAndQueue();
        $this->artisan('emails:dispatch-due');

        $this->assertSame(EmailAccount::STATUS_RESTRICTED, $this->account->fresh()->status);
        $this->assertSame(EmailMessage::FAILED, $this->message()->status);
    }

    public function test_respects_daily_limit_and_min_gap(): void
    {
        Http::fake([self::SEND_URL => Http::response(['id' => 'm', 'threadId' => 't'])]);
        $this->account->update(['daily_limit' => 1, 'sent_today' => 1, 'usage_date' => now()->toDateString()]);

        $this->approveAndQueue();
        $this->artisan('emails:dispatch-due');
        $this->assertSame(EmailMessage::QUEUED, $this->message()->status);

        $this->account->update(['daily_limit' => 5, 'last_sent_at' => now()->subSeconds(30)]);
        $this->artisan('emails:dispatch-due');
        $this->assertSame(EmailMessage::QUEUED, $this->message()->status);

        $this->travel(4)->minutes(); // past the 3-minute gap between sends
        $this->artisan('emails:dispatch-due');
        $this->assertSame(EmailMessage::SENT, $this->message()->status);
    }

    public function test_sync_detects_reply_and_ignores_own_messages_and_bounces(): void
    {
        Http::fake([self::SEND_URL => Http::response(['id' => 'msg-1', 'threadId' => 'thr-1'])]);
        $this->approveAndQueue();
        $this->artisan('emails:dispatch-due');

        $later = now()->addHour()->getTimestampMs();
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/threads/thr-1*' => Http::sequence()
            ->push(['messages' => [
                ['id' => 'msg-1', 'labelIds' => ['SENT'], 'internalDate' => (string) now()->getTimestampMs(), 'payload' => ['headers' => [['name' => 'From', 'value' => 'alex@gmail.com']]]],
                ['id' => 'msg-2', 'labelIds' => ['SENT'], 'internalDate' => (string) $later, 'payload' => ['headers' => [['name' => 'From', 'value' => 'alex@gmail.com']]]],
                ['id' => 'b-1', 'labelIds' => ['INBOX'], 'internalDate' => (string) $later, 'payload' => ['headers' => [['name' => 'From', 'value' => 'Mail Delivery Subsystem <mailer-daemon@googlemail.com>']]]],
            ]])
            ->push(['messages' => [
                ['id' => 'msg-1', 'labelIds' => ['SENT'], 'internalDate' => (string) now()->getTimestampMs(), 'payload' => ['headers' => []]],
                ['id' => 'r-1', 'labelIds' => ['INBOX', 'UNREAD'], 'internalDate' => (string) $later, 'payload' => ['headers' => [['name' => 'From', 'value' => 'Jane <jane@merlion.example>']]]],
            ]])
            // Replied threads keep being synced (the conversation continues); the same reply again:
            ->push(['messages' => [
                ['id' => 'r-1', 'labelIds' => ['INBOX'], 'internalDate' => (string) $later, 'payload' => ['headers' => [['name' => 'From', 'value' => 'jane@merlion.example']]]],
            ]]),
        ]);

        SyncMailbox::dispatchSync($this->account);
        $this->assertSame(ApplicationStatus::Sent, $this->application->fresh()->status);
        $this->assertDatabaseHas('email_events', ['type' => 'bounce_detected']);
        $this->assertStringContainsString('bounce', (string) $this->message()->fresh()->last_error);

        SyncMailbox::dispatchSync($this->account);
        $this->assertSame(ApplicationStatus::Replied, $this->application->fresh()->status);
        // An inbound reply must not hide the outbound email on the application.
        $this->assertSame('msg-1', $this->application->fresh()->outboundMessage?->provider_message_id);
        $this->assertDatabaseHas('email_messages', ['direction' => 'inbound', 'provider_message_id' => 'r-1']);
        $this->assertNotNull($this->account->fresh()->last_synced_at);

        // Idempotent: syncing again does not duplicate.
        SyncMailbox::dispatchSync($this->account);
        $this->assertSame(1, EmailMessage::where('provider_message_id', 'r-1')->count());
    }

    public function test_withdraw_cancels_a_queued_send(): void
    {
        $this->approveAndQueue();

        $this->actingAs($this->user)->post(route('applications.withdraw', $this->application));

        $this->assertSame(ApplicationStatus::Withdrawn, $this->application->fresh()->status);
        $this->assertSame(EmailMessage::CANCELLED, $this->message()->status);
    }
}
