<?php

namespace App\Services\Mail;

use App\Models\Application;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Resume;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Builds the exact message the user approved: subject, body, cover letter and the selected resume.
 */
class EmailComposer
{
    /** An answer in the employer's thread: "Re:" subject, In-Reply-To/References, optional resume. */
    public function composeReply(EmailMessage $reply, EmailAccount $account): string
    {
        $reply->loadMissing(['inReplyTo', 'application.resume', 'application.user.careerProfile']);
        $application = $reply->application;
        $name = $application->user->careerProfile->full_name ?? $application->user->name;

        $email = (new Email)
            ->from(new Address($account->email, $name))
            ->to((string) $reply->to_email)
            ->subject((string) $reply->subject)
            ->text((string) $reply->body_text);
        if ($reply->cc) {
            $email->cc(...$reply->cc);
        }
        $parent = $reply->inReplyTo?->rfc_message_id;
        if ($parent) {
            $email->getHeaders()->addIdHeader('In-Reply-To', trim($parent, '<> '));
            $email->getHeaders()->addIdHeader('References', trim($parent, '<> '));
        }
        $resume = $application->resume;
        if ($reply->attach_resume && $resume && Storage::disk(Resume::disk())->exists($resume->path)) {
            $email->attach((string) Storage::disk(Resume::disk())->get($resume->path), $resume->original_filename, $resume->mime_type);
        }

        return $email->toString();
    }

    public function compose(Application $application, EmailAccount $account): string
    {
        $application->loadMissing(['job', 'resume', 'user.careerProfile']);
        $resume = $application->resume;
        if ($resume === null || ! Storage::disk(Resume::disk())->exists($resume->path)) {
            throw new MailProviderException('The selected resume file is missing.', MailProviderException::PERMANENT);
        }

        $name = $application->user->careerProfile->full_name ?? $application->user->name;
        $email = (new Email)
            ->from(new Address($account->email, $name))
            ->to((string) $application->job->contact_email)
            ->subject((string) $application->subject)
            ->text((string) $application->body)
            ->attach((string) Storage::disk(Resume::disk())->get($resume->path), $resume->original_filename, $resume->mime_type);

        if ($application->cc) {
            $email->cc(...$application->cc);
        }

        if ($application->cover_letter) {
            $email->attach($application->cover_letter, 'Cover letter.txt', 'text/plain');
        }

        $raw = $email->toString();

        // Symfony drops the Bcc header when rendering (right for SMTP). Gmail's API instead reads
        // Bcc from the raw message to deliver blind copies, then removes it, so add it back here.
        if ($application->bcc) {
            $bcc = implode(', ', array_map(fn ($a) => (new Address($a))->toString(), $application->bcc));
            $raw = "Bcc: {$bcc}\r\n".$raw;
        }

        return $raw;
    }
}
