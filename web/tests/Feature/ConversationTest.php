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
use App\Services\Mail\GmailProvider;
use App\Services\Mail\ReplyText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EmailAccount $account;

    private Application $application;

    private EmailMessage $sent;

    private const REPLY = "Hi Alex,\n\nThanks for applying. We'd like to invite you to a video interview. Could you share your availability next week?\n\nBest,\nJane\n\nOn Wed, 30 Sep 2026 at 10:00, Alex Tan <alex@gmail.com> wrote:\n> Dear Hiring Team,\n> I am applying...";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::disk());
        config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret']);
        $this->travelTo('2026-09-30 10:00:00');
        $this->user = User::factory()->create();
        CareerProfile::factory()->for($this->user)->create(['full_name' => 'Alex Tan']);
        Resume::factory()->withFile()->for($this->user)->create();
        $this->account = EmailAccount::factory()->for($this->user)->create([
            'email' => 'alex@gmail.com',
            'scopes' => ['https://www.googleapis.com/auth/gmail.send', GmailProvider::READ_SCOPE],
        ]);
        $job = JobPosting::factory()->for($this->user)->create(['contact_email' => 'careers@acme-careers.com', 'company' => 'Acme']);
        $this->application = Application::factory()->create([
            'job_id' => $job->id, 'user_id' => $this->user->id, 'status' => ApplicationStatus::Sent,
            'subject' => 'Backend Engineer - Application - Alex Tan', 'body' => 'Dear Hiring Team, I am applying...',
            'email_account_id' => $this->account->id,
        ]);
        $this->sent = $this->application->emailMessages()->create([
            'user_id' => $this->user->id, 'email_account_id' => $this->account->id, 'direction' => EmailMessage::OUTBOUND,
            'status' => EmailMessage::SENT, 'to_email' => 'careers@acme-careers.com', 'subject' => $this->application->subject,
            'provider_message_id' => 'sent-1', 'thread_id' => 'thr-1', 'sent_at' => now()->subHour(),
        ]);
    }

    private function threadWith(array $message): array
    {
        return ['messages' => [
            ['id' => 'sent-1', 'labelIds' => ['SENT'], 'internalDate' => (string) now()->subHour()->getTimestampMs(), 'payload' => ['headers' => []]],
            $message,
        ]];
    }

    private function inbound(string $id, array $headers): array
    {
        return ['id' => $id, 'labelIds' => ['INBOX'], 'internalDate' => (string) now()->getTimestampMs(),
            'payload' => ['headers' => array_map(fn ($k, $v) => ['name' => $k, 'value' => $v], array_keys($headers), $headers)]];
    }

    private function fullMessage(string $text, array $headers): array
    {
        return ['id' => 'r-1', 'snippet' => 'Thanks for applying', 'payload' => [
            'mimeType' => 'multipart/alternative',
            'headers' => array_map(fn ($k, $v) => ['name' => $k, 'value' => $v], array_keys($headers), $headers),
            'parts' => [
                ['mimeType' => 'text/plain', 'body' => ['data' => rtrim(strtr(base64_encode($text), '+/', '-_'), '=')]],
                ['mimeType' => 'text/html', 'body' => ['data' => rtrim(strtr(base64_encode('<p>html</p>'), '+/', '-_'), '=')]],
            ],
        ]];
    }

    private function analysis(string $category = 'interview_invite', ?string $status = 'interview'): array
    {
        return ['category' => $category, 'summary' => 'They invite Alex to a video interview.', 'requests' => ['availability next week'],
            'questions' => ['Could you share your availability next week?'], 'dates_mentioned' => ['next week'],
            'evidence_quotes' => ["We'd like to invite you to a video interview"], 'suggested_status' => $status, 'warnings' => []];
    }

    public function test_reply_text_strips_quoted_history(): void
    {
        $this->assertSame(
            "Hi Alex,\n\nThanks for applying. We'd like to invite you to a video interview. Could you share your availability next week?\n\nBest,\nJane",
            ReplyText::latest(self::REPLY),
        );
    }

    public function test_sync_reads_reply_marks_replied_and_analyzes_it(): void
    {
        $headers = ['From' => 'Jane <jane@acme-careers.com>', 'Subject' => 'Re: Backend Engineer', 'Message-ID' => '<abc123@acme-careers.com>'];
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/threads/thr-1*' => Http::response($this->threadWith($this->inbound('r-1', $headers))),
            'gmail.googleapis.com/gmail/v1/users/me/messages/r-1*' => Http::response($this->fullMessage(self::REPLY, $headers)),
            'ai.test/v1/replies/analyze' => Http::response($this->analysis()),
        ]);

        SyncMailbox::dispatchSync($this->account);

        $this->assertSame(ApplicationStatus::Replied, $this->application->fresh()->status);
        $reply = EmailMessage::where('provider_message_id', 'r-1')->firstOrFail();
        $this->assertStringContainsString('video interview', $reply->body_text);
        $this->assertSame('<abc123@acme-careers.com>', $reply->rfc_message_id);
        $this->assertSame('done', $reply->analysis_state);
        $this->assertSame('interview', $reply->analysis['suggested_status']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'replies/analyze')
            && ! str_contains($r['reply_text'], 'I am applying')); // only the newest part goes to the AI

        $this->actingAs($this->user)->get(route('applications.show', $this->application))->assertOk()
            ->assertInertia(fn ($p) => $p->where('application.conversation.1.kind', 'reply')
                ->where('application.conversation.1.analysis.category', 'interview_invite')
                ->where('application.conversation.1.gmail_url', 'https://mail.google.com/mail/u/alex%40gmail.com/#all/thr-1'));
    }

    public function test_auto_reply_is_not_counted_as_a_reply(): void
    {
        $headers = ['From' => 'jane@acme-careers.com', 'Subject' => 'Automatic reply: Backend Engineer', 'Auto-Submitted' => 'auto-replied'];
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/threads/*' => Http::response($this->threadWith($this->inbound('r-1', $headers))),
            'gmail.googleapis.com/gmail/v1/users/me/messages/*' => Http::response($this->fullMessage('I am out of office.', $headers)),
        ]);

        SyncMailbox::dispatchSync($this->account);

        $this->assertSame(ApplicationStatus::Sent, $this->application->fresh()->status);
        $this->assertTrue(EmailMessage::where('provider_message_id', 'r-1')->value('is_auto_reply'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'ai.test'));
    }

    public function test_headers_only_account_still_detects_replies_without_reading_them(): void
    {
        $this->account->update(['scopes' => ['https://www.googleapis.com/auth/gmail.send', 'https://www.googleapis.com/auth/gmail.metadata']]);
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/threads/*' => Http::response(
            $this->threadWith($this->inbound('r-1', ['From' => 'jane@acme-careers.com', 'Subject' => 'Re: Backend'])))]);

        SyncMailbox::dispatchSync($this->account);

        $this->assertSame(ApplicationStatus::Replied, $this->application->fresh()->status);
        $this->assertNull(EmailMessage::where('provider_message_id', 'r-1')->value('body_text'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/messages/r-1') || str_contains($r->url(), 'ai.test'));
    }

    private function storedReply(): EmailMessage
    {
        $this->application->update(['status' => ApplicationStatus::Replied]);

        return $this->application->emailMessages()->create([
            'user_id' => $this->user->id, 'email_account_id' => $this->account->id, 'direction' => EmailMessage::INBOUND,
            'status' => EmailMessage::RECEIVED, 'from_email' => 'Jane <jane@acme-careers.com>', 'subject' => 'Re: Backend Engineer',
            'body_text' => self::REPLY, 'provider_message_id' => 'r-1', 'thread_id' => 'thr-1',
            'rfc_message_id' => '<abc123@acme-careers.com>', 'received_at' => now(), 'analysis' => $this->analysis(), 'analysis_state' => 'done',
        ]);
    }

    public function test_draft_reply_review_and_send_in_the_same_thread(): void
    {
        $reply = $this->storedReply();
        Http::fake([
            'ai.test/v1/replies/draft' => Http::response([
                'subject' => 'Re: Backend Engineer - Application - Alex Tan',
                'body' => "Dear Jane,\n\nThank you. I am available [[your available times]].\n\nKind regards,\nAlex Tan",
                'needs_input' => ['your available times'],
                'validation' => ['errors' => ['Fill in every [[...]] placeholder before sending'], 'warnings' => [], 'attempts' => 1],
            ]),
            'gmail.googleapis.com/*' => Http::response(['id' => 'ans-1', 'threadId' => 'thr-1']),
        ]);

        $this->actingAs($this->user)->post(route('replies.draft', $reply), ['notes' => 'mornings work best'])->assertSessionHasNoErrors();
        $draft = EmailMessage::where('in_reply_to_id', $reply->id)->firstOrFail();
        $this->assertSame(EmailMessage::DRAFT, $draft->status);
        $this->assertSame('jane@acme-careers.com', $draft->to_email);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'replies/draft') && $r['notes'] === 'mornings work best');

        // Placeholders block sending.
        $this->actingAs($this->user)->post(route('replies.send', $draft), ['email_account_id' => $this->account->id])
            ->assertSessionHasErrors('body');

        $this->actingAs($this->user)->put(route('replies.update', $draft), [
            'to_email' => 'jane@acme-careers.com', 'subject' => $draft->subject, 'attach_resume' => '0',
            'body_text' => "Dear Jane,\n\nThank you. I am available Tuesday 2-5pm SGT.\n\nKind regards,\nAlex Tan",
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('replies.send', $draft), ['email_account_id' => $this->account->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(EmailMessage::QUEUED, $draft->fresh()->status);

        $this->artisan('emails:dispatch-due');

        $this->assertSame(EmailMessage::SENT, $draft->fresh()->status);
        $this->assertSame(ApplicationStatus::Replied, $this->application->fresh()->status); // replying doesn't change the stage
        Http::assertSent(function (HttpRequest $r) {
            if (! str_contains($r->url(), 'messages/send')) {
                return false;
            }
            $raw = base64_decode(strtr((string) $r['raw'], '-_', '+/'));

            return $r['threadId'] === 'thr-1'
                && str_contains($raw, 'In-Reply-To: <abc123@acme-careers.com>')
                && str_contains($raw, 'Subject: Re: Backend Engineer')
                && str_contains($raw, 'Tuesday 2-5pm SGT');
        });
    }

    public function test_suggested_stage_is_applied_only_when_the_user_confirms(): void
    {
        $this->storedReply();
        $this->assertSame(ApplicationStatus::Replied, $this->application->fresh()->status);

        $this->actingAs($this->user)->post(route('applications.track', $this->application), [
            'status' => 'interview', 'note' => 'From reply: interview invite',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ApplicationStatus::Interview, $this->application->fresh()->status);
    }

    public function test_cancel_and_discard_drafts_and_ownership(): void
    {
        $reply = $this->storedReply();
        $draft = $this->application->emailMessages()->create([
            'user_id' => $this->user->id, 'in_reply_to_id' => $reply->id, 'email_account_id' => $this->account->id,
            'direction' => EmailMessage::OUTBOUND, 'status' => EmailMessage::SCHEDULED, 'to_email' => 'jane@acme-careers.com',
            'subject' => 'Re: x', 'body_text' => 'Thanks', 'scheduled_at' => now()->addHour(),
        ]);
        $intruder = User::factory()->create();
        $this->actingAs($intruder)->post(route('replies.cancel', $draft))->assertNotFound();
        $this->actingAs($intruder)->post(route('replies.draft', $reply))->assertNotFound();

        $this->actingAs($this->user)->post(route('replies.cancel', $draft))->assertRedirect();
        $this->assertSame(EmailMessage::DRAFT, $draft->fresh()->status);
        $this->actingAs($this->user)->delete(route('replies.destroy', $draft))->assertRedirect();
        $this->assertModelMissing($draft);
    }

    public function test_reply_state_tracks_whose_turn_it_is(): void
    {
        $this->assertNull($this->application->replyState()); // no reply yet

        $reply = $this->storedReply();
        $this->assertSame('awaiting_you', $this->application->fresh()->replyState()['state']);

        $answer = $this->application->emailMessages()->create([
            'user_id' => $this->user->id, 'in_reply_to_id' => $reply->id, 'email_account_id' => $this->account->id,
            'direction' => EmailMessage::OUTBOUND, 'status' => EmailMessage::DRAFT, 'to_email' => 'jane@acme-careers.com',
            'subject' => 'Re: x', 'body_text' => 'Thanks',
        ]);
        $this->assertSame('reply_draft', $this->application->fresh()->replyState()['state']);

        $answer->update(['status' => EmailMessage::SCHEDULED, 'scheduled_at' => now()->addHour()]);
        $this->assertSame('reply_scheduled', $this->application->fresh()->replyState()['state']);

        $this->travel(5)->minutes();
        $answer->update(['status' => EmailMessage::SENT, 'sent_at' => now(), 'provider_message_id' => 'ans-1']);
        $state = $this->application->fresh()->replyState();
        $this->assertSame('you_replied', $state['state']);

        // The application email stays the "outbound message"; the answer doesn't replace it.
        $this->assertSame($this->sent->id, $this->application->fresh()->outboundMessage->id);

        // A new message from the employer makes it the user's turn again.
        $this->travel(5)->minutes();
        $this->application->emailMessages()->create([
            'user_id' => $this->user->id, 'email_account_id' => $this->account->id, 'direction' => EmailMessage::INBOUND,
            'status' => EmailMessage::RECEIVED, 'from_email' => 'jane@acme-careers.com', 'provider_message_id' => 'r-2',
            'thread_id' => 'thr-1', 'received_at' => now(),
        ]);
        $this->assertSame('awaiting_you', $this->application->fresh()->replyState()['state']);

        $this->actingAs($this->user)->get(route('dashboard'))
            ->assertInertia(fn ($p) => $p->has('awaitingReply', 1)->where('awaitingReply.0.id', $this->application->id));
        $this->actingAs($this->user)->get(route('jobs.index'))
            ->assertInertia(fn ($p) => $p->where('jobs.data.0.application.reply_state.state', 'awaiting_you'));
    }

    public function test_messages_recorded_before_read_access_get_their_text_later(): void
    {
        // Recorded while the account had headers-only access.
        $old = $this->application->emailMessages()->create([
            'user_id' => $this->user->id, 'email_account_id' => $this->account->id, 'direction' => EmailMessage::INBOUND,
            'status' => EmailMessage::RECEIVED, 'from_email' => 'Jane <jane@acme-careers.com>', 'provider_message_id' => 'r-old',
            'thread_id' => 'thr-1', 'received_at' => now()->subMinutes(30),
        ]);
        $headers = ['From' => 'Jane <jane@acme-careers.com>', 'Subject' => 'Re: Backend', 'Message-ID' => '<old@acme-careers.com>'];
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/r-old*' => Http::response($this->fullMessage(self::REPLY, $headers)),
            'gmail.googleapis.com/gmail/v1/users/me/threads/*' => Http::response(['messages' => []]),
            'ai.test/v1/replies/analyze' => Http::response($this->analysis()),
        ]);

        SyncMailbox::dispatchSync($this->account);

        $old->refresh();
        $this->assertStringContainsString('video interview', $old->body_text);
        $this->assertSame('<old@acme-careers.com>', $old->rfc_message_id);
        $this->assertSame('done', $old->analysis_state);
    }

    public function test_reply_ai_gets_the_earlier_thread_oldest_first(): void
    {
        $first = $this->storedReply();
        $base = ['user_id' => $this->user->id, 'email_account_id' => $this->account->id, 'thread_id' => 'thr-1'];
        $this->travel(1)->hours();
        $this->application->emailMessages()->create([...$base, 'direction' => EmailMessage::OUTBOUND, 'status' => EmailMessage::SENT,
            'in_reply_to_id' => $first->id, 'to_email' => 'jane@acme-careers.com', 'body_text' => 'I am available Tue 2-5pm SGT.', 'sent_at' => now()]);
        $this->application->emailMessages()->create([...$base, 'direction' => EmailMessage::OUTBOUND, 'status' => EmailMessage::DRAFT,
            'in_reply_to_id' => $first->id, 'to_email' => 'jane@acme-careers.com', 'body_text' => 'unsent draft']);
        $this->travel(1)->hours();
        $this->application->emailMessages()->create([...$base, 'direction' => EmailMessage::INBOUND, 'status' => EmailMessage::RECEIVED,
            'from_email' => 'jane@acme-careers.com', 'is_auto_reply' => true, 'body_text' => 'I am out of office.', 'received_at' => now()]);
        $this->travel(1)->hours();
        $latest = $this->application->emailMessages()->create([...$base, 'direction' => EmailMessage::INBOUND, 'status' => EmailMessage::RECEIVED,
            'from_email' => 'jane@acme-careers.com', 'body_text' => "Tuesday 3pm works.\n\nOn Tue, Alex wrote:\n> I am available",
            'received_at' => now(), 'analysis' => $this->analysis(), 'analysis_state' => 'done']);

        Http::fake([
            'ai.test/v1/replies/analyze' => Http::response($this->analysis()),
            'ai.test/v1/replies/draft' => Http::response(['subject' => 'Re: x', 'body' => 'Dear Jane, confirmed.', 'needs_input' => [],
                'validation' => ['errors' => [], 'warnings' => [], 'attempts' => 1]]),
        ]);
        $this->actingAs($this->user)->post(route('replies.analyze', $latest));
        $this->actingAs($this->user)->post(route('replies.draft', $latest));

        foreach (['replies/analyze', 'replies/draft'] as $endpoint) {
            Http::assertSent(function (HttpRequest $r) use ($endpoint) {
                if (! str_contains($r->url(), $endpoint)) {
                    return false;
                }
                $history = $r['history'];

                return count($history) === 2
                    && $history[0]['sender'] === 'employer' && str_contains($history[0]['text'], 'invite you to a video interview')
                    && ! str_contains($history[0]['text'], 'I am applying') // quoted text stripped
                    && $history[1] === ['sender' => 'candidate', 'at' => $history[1]['at'], 'text' => 'I am available Tue 2-5pm SGT.']
                    && $r['reply_text'] === 'Tuesday 3pm works.';
            });
        }
    }
}
