<?php

use App\Jobs\SendEmailMessage;
use App\Jobs\SyncMailbox;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\JobPosting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Dispatch due emails: at most one per account per run, respecting a minimum gap between sends,
 * each account's daily limit, and the lower daily cap for speculative applications. Messages outside their window are re-scheduled by the job.
 */
Artisan::command('emails:dispatch-due', function () {
    $gap = (int) config('services.mail_sending.min_seconds_between_sends', 180);
    $dispatched = 0;

    EmailAccount::where('status', EmailAccount::STATUS_ACTIVE)->each(function (EmailAccount $account) use ($gap, &$dispatched) {
        if (! $account->hasCapacity() || $account->last_sent_at?->gt(now()->subSeconds($gap))) {
            return;
        }
        $message = $account->messages()
            ->where('direction', EmailMessage::OUTBOUND)
            ->whereIn('status', [EmailMessage::QUEUED, EmailMessage::SCHEDULED, EmailMessage::RETRYING])
            ->where('scheduled_at', '<=', now())
            // Over today's speculative cap: those wait for tomorrow, replies and job-post applications still go.
            ->when(! $account->hasSpeculativeCapacity(), fn ($q) => $q->where(fn ($m) => $m->whereNotNull('in_reply_to_id')
                ->orWhereDoesntHave('application.job', fn ($j) => $j->where('kind', JobPosting::KIND_SPECULATIVE))))
            ->orderBy('scheduled_at')->first();
        if ($message === null) {
            return;
        }
        // Claim it atomically so overlapping runs cannot send it twice.
        $claimed = EmailMessage::whereKey($message->id)->where('status', $message->status)
            ->update(['status' => EmailMessage::SENDING]);
        if ($claimed) {
            SendEmailMessage::dispatch($message);
            $dispatched++;
        }
    });

    $this->info("Dispatched {$dispatched} email(s).");
})->purpose('Send queued application emails that are due');

Artisan::command('emails:sync', function () {
    EmailAccount::whereIn('status', [EmailAccount::STATUS_ACTIVE, EmailAccount::STATUS_RESTRICTED])
        ->each(fn (EmailAccount $account) => SyncMailbox::dispatch($account));
})->purpose('Check connected mailboxes for replies to sent applications');

Schedule::command('emails:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('emails:sync')->everyTenMinutes()->withoutOverlapping();
