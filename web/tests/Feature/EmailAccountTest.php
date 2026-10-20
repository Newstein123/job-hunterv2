<?php

namespace Tests\Feature;

use App\Jobs\SyncMailbox;
use App\Models\Application;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Mail\GmailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class EmailAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'secret']);
    }

    private function fakeGoogle(string $email, array $scopes = GmailProvider::SCOPES, ?string $refresh = 'refresh-1'): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'g-'.$email, 'email' => $email, 'name' => 'Alex Tan',
            'token' => 'access-'.$email, 'refreshToken' => $refresh, 'expiresIn' => 3599, 'approvedScopes' => $scopes,
        ]));
    }

    public function test_redirect_requests_offline_gmail_scopes(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('email-accounts.google.connect'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth', $location);
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/gmail.send'), $location);
    }

    public function test_connect_multiple_accounts_and_first_is_default(): void
    {
        $user = User::factory()->create();

        $this->fakeGoogle('a@gmail.com');
        $this->actingAs($user)->get(route('email-accounts.google.callback'))->assertRedirect(route('email-accounts.index'));
        $this->fakeGoogle('b@gmail.com');
        $this->actingAs($user)->get(route('email-accounts.google.callback'));

        $accounts = $user->emailAccounts()->orderBy('email')->get();
        $this->assertSame(['a@gmail.com', 'b@gmail.com'], $accounts->pluck('email')->all());
        $this->assertSame([true, false], $accounts->pluck('is_default')->all());
        $this->assertSame('refresh-1', $accounts[0]->refresh_token);
        // Tokens are encrypted at rest.
        $this->assertStringNotContainsString('refresh-1', (string) $accounts[0]->getRawOriginal('refresh_token'));
        $this->actingAs($user)->get(route('email-accounts.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('email-accounts/index')->has('accounts', 2)
                ->missing('accounts.0.access_token'));
    }

    public function test_reconnect_keeps_refresh_token_and_reactivates(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create([
            'email' => 'a@gmail.com', 'status' => EmailAccount::STATUS_NEEDS_REAUTH, 'refresh_token' => 'old-refresh', 'daily_limit' => 40,
        ]);

        $this->fakeGoogle('a@gmail.com', refresh: null);
        $this->actingAs($user)->get(route('email-accounts.google.callback'));

        $account->refresh();
        $this->assertSame(EmailAccount::STATUS_ACTIVE, $account->status);
        $this->assertSame('old-refresh', $account->refresh_token);
        $this->assertSame(40, $account->daily_limit);
        $this->assertSame(1, $user->emailAccounts()->count());
    }

    public function test_rejects_when_gmail_scopes_not_granted(): void
    {
        $user = User::factory()->create();
        $this->fakeGoogle('a@gmail.com', ['openid', 'email']);

        $this->actingAs($user)->get(route('email-accounts.google.callback'));

        $this->assertSame(0, $user->emailAccounts()->count());
    }

    public function test_update_default_limit_and_resume(): void
    {
        $user = User::factory()->create();
        $a = EmailAccount::factory()->for($user)->create(['is_default' => true]);
        $b = EmailAccount::factory()->for($user)->create(['status' => EmailAccount::STATUS_RESTRICTED]);

        $this->actingAs($user)->patch(route('email-accounts.update', $b), ['is_default' => true, 'daily_limit' => 50, 'resume' => true]);

        $this->assertFalse($a->fresh()->is_default);
        $b->refresh();
        $this->assertTrue($b->is_default);
        $this->assertSame(50, $b->daily_limit);
        $this->assertSame(EmailAccount::STATUS_ACTIVE, $b->status);

        $this->actingAs($user)->patch(route('email-accounts.update', $b), ['daily_limit' => 5000])->assertSessionHasErrors('daily_limit');
    }

    public function test_disconnect_revokes_and_is_blocked_while_emails_queued(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response()]);
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create();
        $application = Application::factory()->create(['user_id' => $user->id, 'job_id' => JobPosting::factory()->for($user)]);
        $message = $application->emailMessages()->create([
            'user_id' => $user->id, 'email_account_id' => $account->id, 'direction' => EmailMessage::OUTBOUND,
            'status' => EmailMessage::SCHEDULED, 'scheduled_at' => now()->addHour(),
        ]);

        $this->actingAs($user)->delete(route('email-accounts.destroy', $account));
        $this->assertModelExists($account);

        $message->update(['status' => EmailMessage::CANCELLED]);
        $this->actingAs($user)->delete(route('email-accounts.destroy', $account));
        $this->assertModelMissing($account);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke'));
    }

    public function test_cannot_manage_other_users_accounts(): void
    {
        $account = EmailAccount::factory()->create();

        $this->actingAs(User::factory()->create())->patch(route('email-accounts.update', $account), ['daily_limit' => 5])->assertNotFound();
        $this->actingAs(User::factory()->create())->delete(route('email-accounts.destroy', $account))->assertNotFound();
    }

    public function test_check_for_replies_now_runs_the_mailbox_check(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        EmailAccount::factory()->for($user)->count(2)->create();
        EmailAccount::factory()->for($user)->create(['status' => EmailAccount::STATUS_NEEDS_REAUTH]);

        $this->actingAs($user)->post(route('email-accounts.sync'))->assertRedirect();

        Queue::assertPushed(SyncMailbox::class, 2);
    }
}
