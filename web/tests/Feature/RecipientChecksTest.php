<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use App\Services\ApplicationStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecipientChecksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::DISK);
        $this->travelTo('2026-09-30 10:00:00'); // Wednesday, inside the default 09:00-17:00 UTC window
        $this->user = User::factory()->create();
        CareerProfile::factory()->for($this->user)->create();
        Resume::factory()->withFile()->for($this->user)->create();
        $this->account = EmailAccount::factory()->for($this->user)->create(['email' => 'me@gmail.com']);
    }

    private function generated(string $to, array $cc = [], array $bcc = []): Application
    {
        $job = JobPosting::factory()->for($this->user)->create(['contact_email' => $to]);

        return Application::factory()->create([
            'job_id' => $job->id, 'user_id' => $this->user->id, 'cc' => $cc ?: null, 'bcc' => $bcc ?: null,
            'resume_id' => $this->user->resumes()->value('id'),
        ]);
    }

    private function approveAndSend(Application $app)
    {
        return $this->actingAs($this->user)->post(route('applications.approve', $app), ['email_account_id' => $this->account->id]);
    }

    /** @return array<string, array{string, string}> */
    public static function badRecipients(): array
    {
        return [
            'test domain' => ['careers@company.example', "Can't send: careers@company.example uses a test domain (company.example) that never receives email."],
            'example.com' => ['jobs@example.com', "Can't send: jobs@example.com uses a test domain (example.com) that never receives email."],
            'no mail servers' => ['hr@no-mail-servers.com', "Can't send: no-mail-servers.com does not receive email (no mail servers found); check for a typo."],
            'own account' => ['me@gmail.com', "Can't send: me@gmail.com is one of your own connected accounts."],
        ];
    }

    #[DataProvider('badRecipients')]
    public function test_blocks_bad_recipients(string $to, string $message): void
    {
        $app = $this->generated($to);

        $this->approveAndSend($app)->assertSessionHasErrors(['recipient' => $message]);
        $this->assertSame(ApplicationStatus::Generated, $app->fresh()->status); // approve rolled back too
    }

    public function test_checks_cc_and_bcc_too(): void
    {
        $app = $this->generated('careers@acme-careers.com', cc: ['hr@typo-domain.cmo']);

        $this->approveAndSend($app)->assertSessionHasErrors(['recipient' => "Can't send: typo-domain.cmo does not receive email (no mail servers found); check for a typo."]);
    }

    public function test_blocks_addresses_that_bounced_before(): void
    {
        $old = $this->generated('gone@acme-careers.com');
        $old->emailMessages()->create([
            'user_id' => $this->user->id, 'direction' => EmailMessage::OUTBOUND, 'status' => EmailMessage::SENT,
            'to_email' => 'gone@acme-careers.com', 'last_error' => 'Delivery failed: bounce from mailer-daemon@googlemail.com',
        ]);
        $app = $this->generated('gone@acme-careers.com');

        $this->approveAndSend($app)->assertSessionHasErrors(['recipient' => "Can't send: gone@acme-careers.com bounced before; check the address."]);
    }

    public function test_cc_and_bcc_are_sent_and_recorded(): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'm1', 'threadId' => 't1'])]);
        $app = $this->generated('careers@acme-careers.com', cc: ['hiring.manager@acme-careers.com'], bcc: ['me.archive@gmail.com']);

        $this->approveAndSend($app)->assertSessionHasNoErrors();
        $this->artisan('emails:dispatch-due');

        $message = $app->fresh()->outboundMessage;
        $this->assertSame(EmailMessage::SENT, $message->status);
        $this->assertSame(['hiring.manager@acme-careers.com'], $message->cc);
        $this->assertSame(['me.archive@gmail.com'], $message->bcc);
        Http::assertSent(function (HttpRequest $r) {
            $raw = base64_decode(strtr((string) ($r['raw'] ?? ''), '-_', '+/'));

            return str_contains($raw, 'Cc: hiring.manager@acme-careers.com') && str_contains($raw, 'Bcc: me.archive@gmail.com');
        });
    }

    public function test_cc_from_the_job_is_copied_and_editable_on_review(): void
    {
        Queue::fake(); // the AI work these trigger isn't what's tested here
        $this->actingAs($this->user)->post(route('jobs.store'), [
            'description' => str_repeat('Laravel engineer building REST APIs for payments. ', 2),
            'contact_email' => 'careers@acme-careers.com', 'cc_emails' => 'HR@acme-careers.com; lead@acme-careers.com',
        ])->assertSessionHasNoErrors();
        $job = $this->user->jobPostings()->latest('id')->first();
        $this->assertSame(['hr@acme-careers.com', 'lead@acme-careers.com'], $job->cc_emails);

        $app = app(ApplicationStarter::class)->start($this->user, $job);
        $this->assertSame(['hr@acme-careers.com', 'lead@acme-careers.com'], $app->fresh()->cc);

        $app->forceFill(['status' => ApplicationStatus::Generated, 'generation_state' => Application::GENERATION_DONE])->save();
        $this->actingAs($this->user)->put(route('applications.recipients', $app), [
            'to' => 'careers@acme-careers.com', 'cc' => 'hr@acme-careers.com', 'bcc' => 'me.archive@gmail.com, not-an-email',
        ])->assertSessionHasErrors('bcc.1');
        $this->actingAs($this->user)->put(route('applications.recipients', $app), [
            'to' => 'careers@acme-careers.com', 'cc' => 'careers@acme-careers.com', // same as To
        ])->assertSessionHasErrors('cc.0');
    }

    public function test_fix_recipient_before_approving(): void
    {
        $app = $this->generated('careers@company.example');

        $this->actingAs($this->user)->put(route('applications.recipients', $app), ['to' => 'careers@typo-domain.cmo'])
            ->assertSessionHasErrors(['to' => 'typo-domain.cmo does not receive email (no mail servers found); check for a typo.']);

        $this->actingAs($this->user)->put(route('applications.recipients', $app), ['to' => 'Careers@Acme-Careers.com ', 'cc' => 'hr@acme-careers.com'])
            ->assertSessionHasNoErrors();
        $this->assertSame('careers@acme-careers.com', $app->job->fresh()->contact_email);
        $this->assertSame(['hr@acme-careers.com'], $app->fresh()->cc);
        $this->approveAndSend($app->fresh())->assertSessionHasNoErrors();
    }

    public function test_scheduled_email_keeps_its_time_with_new_recipients(): void
    {
        $this->travelTo('2026-09-30 20:00:00'); // after the window: scheduled for tomorrow
        $app = $this->generated('careers@acme-careers.com');
        $this->approveAndSend($app);
        $before = $app->fresh()->outboundMessage->scheduled_at;

        $this->actingAs($this->user)->put(route('applications.recipients', $app), ['to' => 'talent@acme-careers.com'])
            ->assertSessionHasNoErrors();

        $message = $app->fresh()->outboundMessage;
        $this->assertSame(EmailMessage::SCHEDULED, $message->status);
        $this->assertSame('talent@acme-careers.com', $message->to_email);
        $this->assertTrue($before->equalTo($message->scheduled_at));
    }

    public function test_failed_send_can_be_fixed_and_retried_in_one_step(): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'm1', 'threadId' => 't1'])]);
        $app = $this->generated('careers@acme-careers.com');
        $this->approveAndSend($app);
        $app->fresh()->outboundMessage->update(['status' => EmailMessage::FAILED, 'last_error' => 'Gmail: Invalid To header']);

        $this->actingAs($this->user)->put(route('applications.recipients', $app), [
            'to' => 'talent@acme-careers.com', 'then' => 'retry',
        ])->assertSessionHasNoErrors();

        $message = $app->fresh()->outboundMessage;
        $this->assertSame(EmailMessage::QUEUED, $message->status);
        $this->assertSame('talent@acme-careers.com', $message->to_email);
        $this->artisan('emails:dispatch-due');
        $this->assertSame(EmailMessage::SENT, $app->fresh()->outboundMessage->status);
    }

    public function test_bounced_email_can_be_resent_to_a_corrected_address(): void
    {
        Http::fakeSequence('gmail.googleapis.com/*')
            ->push(['id' => 'm1', 'threadId' => 't1'])
            ->push(['id' => 'm2', 'threadId' => 't2']);
        $app = $this->generated('carrers@acme-careers.com'); // typo
        $this->approveAndSend($app);
        $this->artisan('emails:dispatch-due');
        $app->fresh()->outboundMessage->update(['last_error' => EmailMessage::BOUNCE_PREFIX.': bounce from mailer-daemon@googlemail.com']);
        $this->assertSame(ApplicationStatus::Sent, $app->fresh()->status);

        // The bounced address itself is now refused.
        $this->actingAs($this->user)->put(route('applications.recipients', $app), ['to' => 'carrers@acme-careers.com', 'then' => 'resend'])
            ->assertSessionHasErrors('to');

        $this->actingAs($this->user)->put(route('applications.recipients', $app), ['to' => 'careers@acme-careers.com', 'then' => 'resend'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ApplicationStatus::Queued, $app->fresh()->status);
        $this->travel(4)->minutes(); // the account's 3-minute gap since the first send
        $this->artisan('emails:dispatch-due');
        $fresh = $app->fresh();
        $this->assertSame(ApplicationStatus::Sent, $fresh->status);
        $this->assertSame('careers@acme-careers.com', $fresh->outboundMessage->to_email);
        $this->assertSame(2, $fresh->emailMessages()->where('status', EmailMessage::SENT)->count());
    }

    public function test_recipients_are_locked_after_successful_delivery(): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'm1', 'threadId' => 't1'])]);
        $app = $this->generated('careers@acme-careers.com');
        $this->approveAndSend($app);
        $this->artisan('emails:dispatch-due');

        $this->actingAs($this->user)->put(route('applications.recipients', $app), ['to' => 'other@acme-careers.com'])->assertStatus(409);
    }

    public function test_import_reads_cc_column(): void
    {
        Queue::fake(); // the AI work these trigger isn't what's tested here
        $d = str_repeat('Laravel engineer building REST APIs for payments. ', 2);
        $csv = "Title,Description,Contact Email,CC\nA,\"{$d}\",careers@acme-careers.com,\"hr@acme-careers.com, lead@acme-careers.com\"\n";

        $this->actingAs($this->user)->post(route('jobs.import.store'), ['file' => UploadedFile::fake()->createWithContent('j.csv', $csv)])
            ->assertInertiaFlash('importResult.imported', 1);

        $this->assertSame(['hr@acme-careers.com', 'lead@acme-careers.com'], $this->user->jobPostings()->first()->cc_emails);
    }

    public function test_emails_queued_before_a_bounce_are_stopped_at_send_time(): void
    {
        Http::fake();
        $app = $this->generated('careers@acme-careers.com');
        $this->approveAndSend($app)->assertSessionHasNoErrors();
        // Another application to the same address bounces in the meantime.
        $other = $this->generated('careers@acme-careers.com');
        $other->emailMessages()->create([
            'user_id' => $this->user->id, 'direction' => EmailMessage::OUTBOUND, 'status' => EmailMessage::SENT,
            'to_email' => 'careers@acme-careers.com', 'last_error' => 'Delivery failed: bounce from mailer-daemon@googlemail.com',
        ]);

        $this->artisan('emails:dispatch-due');

        $message = $app->fresh()->outboundMessage;
        $this->assertSame(EmailMessage::FAILED, $message->status);
        $this->assertSame('Not sent: careers@acme-careers.com bounced before; check the address.', $message->last_error);
        Http::assertNothingSent();
    }
}
