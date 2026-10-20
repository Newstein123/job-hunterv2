<?php

namespace App\Services\AiService;

use App\Models\EmailMessage;
use App\Services\Mail\ReplyText;

/**
 * Builds the AI service requests for understanding a reply and drafting the answer,
 * and stores the results on the messages.
 */
class ReplyAssistant
{
    public function __construct(
        private readonly AiServiceClient $client,
        private readonly UsageRecorder $usage,
    ) {}

    public function analyze(EmailMessage $inbound): void
    {
        $inbound->loadMissing('application.job.latestAnalysis');
        $result = $this->client->analyzeReply([
            'job' => $this->job($inbound),
            'sent_body' => (string) $inbound->application->body,
            'reply_text' => ReplyText::latest((string) $inbound->body_text),
        ]);
        $this->usage->record($result['usage'] ?? null, $inbound->user_id, $inbound->application->job_id, $inbound->application_id);
        unset($result['usage']);
        $inbound->update(['analysis' => $result, 'analysis_state' => 'done', 'analysis_error' => null]);
    }

    /** Start a new draft answer to $inbound (replacing an unsent one); the AI fills it in the background. */
    public function newDraft(EmailMessage $inbound): EmailMessage
    {
        $inbound->loadMissing(['application', 'account']);
        $application = $inbound->application;
        $application->emailMessages()->where('in_reply_to_id', $inbound->id)->where('status', EmailMessage::DRAFT)->delete();

        return $application->emailMessages()->create([
            'user_id' => $application->user_id,
            'in_reply_to_id' => $inbound->id,
            'email_account_id' => $inbound->email_account_id,
            'direction' => EmailMessage::OUTBOUND,
            'status' => EmailMessage::DRAFT,
            'to_email' => $this->replyAddress((string) $inbound->from_email),
            'from_email' => $inbound->account?->email,
            'thread_id' => $inbound->thread_id,
            'attach_resume' => false,
        ]);
    }

    /** Ask the AI for the answer and store it on the draft. */
    public function fill(EmailMessage $draft, string $notes): void
    {
        $inbound = $draft->inReplyTo;
        if ($inbound === null) {
            throw new AiServiceException('The reply this draft answers was deleted', 422);
        }
        $inbound->loadMissing(['application.job.country', 'application.job.latestAnalysis', 'application.user.careerProfile',
            'application.user.applicationRule']);
        $application = $inbound->application;
        if (! $inbound->analysis) {
            throw new AiServiceException('Analyze the reply before drafting an answer', 422);
        }
        if (! $application->user->careerProfile) {
            throw new AiServiceException('Add your career profile first', 422);
        }

        $result = $this->client->draftReply([
            'job' => $this->job($inbound),
            'profile' => PipelinePayload::profile($application->user->careerProfile),
            'sent_subject' => (string) $application->subject,
            'sent_body' => (string) $application->body,
            'reply_text' => ReplyText::latest((string) $inbound->body_text),
            'analysis' => $inbound->analysis,
            'notes' => $notes,
            'country' => $application->job->country?->name,
            'user_rules' => $application->user->applicationRule?->rules ?: null,
        ]);

        $this->usage->record($result['usage'] ?? null, $draft->user_id, $application->job_id, $application->id);
        $draft->update([
            'subject' => mb_substr((string) $result['subject'], 0, 255),
            'body_text' => $result['body'],
            'validation' => $result['validation'] + ['needs_input' => $result['needs_input'] ?? []],
            'last_error' => null,
        ]);
    }

    /** "Jane Doe <jane@company.com>" -> "jane@company.com" */
    public function replyAddress(string $from): string
    {
        return strtolower(preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : trim($from));
    }

    /** @return array<string, mixed> JobAnalysis shape */
    private function job(EmailMessage $inbound): array
    {
        $job = $inbound->application->job;

        return $job->latestAnalysis->data ?? [
            'title' => $job->displayTitle(), 'company' => (string) $job->company, 'seniority' => '',
            'location' => (string) $job->location, 'country' => '', 'required_skills' => [], 'preferred_skills' => [],
            'responsibilities' => [], 'keywords' => [], 'requirements' => [],
        ];
    }
}
