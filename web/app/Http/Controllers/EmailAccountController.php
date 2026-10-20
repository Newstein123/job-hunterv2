<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMailbox;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\Mail\GmailProvider;
use App\Services\Mail\MailProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class EmailAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = $request->user()->emailAccounts()->orderByDesc('is_default')->orderBy('email')->get()
            ->map(fn (EmailAccount $a) => [
                ...$a->only(['id', 'provider', 'email', 'status', 'status_reason', 'daily_limit', 'is_default']),
                'sent_today' => $a->sentToday(),
                'can_read' => $a->canReadMessages(),
                'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                'last_sent_at' => $a->last_sent_at?->toIso8601String(),
                'queued' => $a->messages()->whereIn('status', EmailMessage::ACTIVE_STATES)->count(),
            ]);

        return Inertia::render('email-accounts/index', [
            'accounts' => $accounts,
            'googleConfigured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
            'maxDailyLimit' => EmailAccount::MAX_DAILY_LIMIT,
        ]);
    }

    public function redirectToGoogle(): SymfonyRedirect
    {
        abort_unless(filled(config('services.google.client_id')), 503, 'Google OAuth is not configured.');

        $driver = Socialite::driver('google');
        abort_unless($driver instanceof GoogleProvider, 500);

        return $driver
            ->scopes(GmailProvider::SCOPES)
            // offline + consent so Google returns a refresh token; select_account to add another mailbox
            ->with(['access_type' => 'offline', 'prompt' => 'consent select_account', 'include_granted_scopes' => 'true'])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $this->toast('Google account was not connected.', 'info');

            return to_route('email-accounts.index');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            $this->toast('The Google sign-in expired. Please try again.', 'error');

            return to_route('email-accounts.index');
        }

        abort_unless($google instanceof SocialiteUser, 500);
        $granted = $google->approvedScopes;
        $missing = array_diff(GmailProvider::REQUIRED_SCOPES, $granted);
        if ($missing !== []) {
            $this->toast('Please allow JobHunter to send email and read message headers (for reply tracking).', 'error');

            return to_route('email-accounts.index');
        }

        $user = $request->user();
        $email = strtolower((string) $google->getEmail());
        $existing = $user->emailAccounts()->where('provider', EmailAccount::PROVIDER_GMAIL)->where('email', $email)->first();

        $user->emailAccounts()->updateOrCreate(
            ['provider' => EmailAccount::PROVIDER_GMAIL, 'email' => $email],
            [
                'auth_type' => 'oauth',
                'access_token' => $google->token,
                // Google only returns a refresh token on first consent; keep the stored one otherwise.
                'refresh_token' => $google->refreshToken ?: $existing?->refresh_token,
                'token_expires_at' => now()->addSeconds((int) ($google->expiresIn ?: 3600)),
                'scopes' => array_values($granted),
                'status' => EmailAccount::STATUS_ACTIVE,
                'status_reason' => null,
                'daily_limit' => $existing->daily_limit ?? EmailAccount::DEFAULT_DAILY_LIMIT,
                'is_default' => $existing->is_default ?? ! $user->emailAccounts()->exists(),
                'metadata' => ['google_id' => $google->getId(), 'name' => $google->getName()],
            ],
        );

        $this->toast($existing ? "Reconnected {$email}." : "Connected {$email}.");

        return to_route('email-accounts.index');
    }

    /** "Check for replies now": run the mailbox check for all of the user's accounts instead of waiting. */
    public function sync(Request $request): RedirectResponse
    {
        $accounts = $request->user()->emailAccounts()
            ->whereIn('status', [EmailAccount::STATUS_ACTIVE, EmailAccount::STATUS_RESTRICTED])->get();
        foreach ($accounts as $account) {
            SyncMailbox::dispatch($account);
        }
        $this->toast($accounts->isEmpty() ? 'No connected Gmail account to check.' : 'Checking your mailbox for replies…', 'info');

        return back();
    }

    public function update(Request $request, EmailAccount $emailAccount): RedirectResponse
    {
        $this->ensureOwns($request, $emailAccount);
        $data = $request->validate([
            'daily_limit' => ['sometimes', 'integer', 'min:1', 'max:'.EmailAccount::MAX_DAILY_LIMIT],
            'is_default' => ['sometimes', 'accepted'],
            'resume' => ['sometimes', 'accepted'],
        ]);

        DB::transaction(function () use ($request, $emailAccount, $data) {
            if (isset($data['daily_limit'])) {
                $emailAccount->update(['daily_limit' => $data['daily_limit']]);
            }
            if (isset($data['is_default'])) {
                $request->user()->emailAccounts()->update(['is_default' => false]);
                $emailAccount->update(['is_default' => true]);
            }
            // Only restricted accounts can be resumed by hand; a revoked token needs a reconnect.
            if (isset($data['resume']) && $emailAccount->status === EmailAccount::STATUS_RESTRICTED) {
                $emailAccount->update(['status' => EmailAccount::STATUS_ACTIVE, 'status_reason' => null]);
            }
        });
        $this->toast('Account updated.');

        return back();
    }

    public function destroy(Request $request, EmailAccount $emailAccount, MailProvider $provider): RedirectResponse
    {
        $this->ensureOwns($request, $emailAccount);
        if ($emailAccount->messages()->whereIn('status', EmailMessage::ACTIVE_STATES)->exists()) {
            $this->toast('Cancel the emails queued on this account before disconnecting it.', 'error');

            return back();
        }

        rescue(fn () => $provider->revoke($emailAccount), report: false);
        $emailAccount->delete();
        $this->toast('Account disconnected.');

        return back();
    }
}
