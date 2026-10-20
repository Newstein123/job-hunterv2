<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeReply;
use App\Jobs\DraftReply;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\JobPosting;
use App\Services\AiService\ReplyAssistant;
use App\Services\Mail\EmailQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The conversation after an application is sent: understand the employer's reply and answer it.
 * The AI only suggests and drafts; the user reviews every answer before it is sent.
 */
class ReplyController extends Controller
{
    /** (Re)run the AI classification of an employer's reply. */
    public function analyze(Request $request, EmailMessage $message): RedirectResponse
    {
        $this->ensureOwns($request, $message);
        abort_unless($message->direction === EmailMessage::INBOUND && $message->body_text, 409, 'This message has no text to analyze.');

        AnalyzeReply::start($message);
        $this->toast('Reading the reply…', 'info');

        return back();
    }

    /** Ask the AI to draft an answer, optionally with notes like availability or salary expectations. */
    public function draft(Request $request, EmailMessage $message, ReplyAssistant $assistant): RedirectResponse
    {
        $this->ensureOwns($request, $message);
        abort_unless($message->direction === EmailMessage::INBOUND && $message->analysis, 409, 'Analyze the reply first.');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $draft = $assistant->newDraft($message);
        DraftReply::dispatch($draft, (string) ($data['notes'] ?? ''));
        $this->toast('Drafting your reply…', 'info');

        return back();
    }

    /** Edit a draft answer before sending. */
    public function update(Request $request, EmailMessage $message): RedirectResponse
    {
        $this->ensureOwns($request, $message);
        abort_unless($message->isReplyDraft() && $message->status === EmailMessage::DRAFT, 409);

        $request->merge(['cc' => JobPosting::parseEmails($request->input('cc'))]);
        $data = $request->validate([
            'to_email' => ['required', 'email:rfc,strict', 'max:255'],
            'cc' => ['nullable', 'array', 'max:10'],
            'cc.*' => ['email:rfc,strict', 'max:255', 'distinct'],
            'subject' => ['required', 'string', 'max:255'],
            'body_text' => ['required', 'string', 'max:20000'],
            'attach_resume' => ['nullable', 'boolean'],
        ]);

        $validation = $message->validation ?? [];
        $placeholders = str_contains($data['body_text'], '[[');
        $validation['errors'] = $placeholders ? ['Fill in every [[...]] placeholder before sending'] : [];
        $validation['edited'] = true;
        $message->update([...$data, 'attach_resume' => $request->boolean('attach_resume'), 'validation' => $validation]);
        $this->toast('Draft saved.');

        return back();
    }

    /** Approve and send the reviewed answer (queued, sent in the same thread). */
    public function send(Request $request, EmailMessage $message, EmailQueue $queue): RedirectResponse
    {
        $this->ensureOwns($request, $message);
        $data = $request->validate([
            'email_account_id' => ['required', 'integer', Rule::exists('email_accounts', 'id')->where('user_id', $request->user()->id)],
        ]);
        $account = EmailAccount::query()->whereKey($data['email_account_id'])->firstOrFail();

        $sent = $queue->queueReply($message, $account, $request->user());
        $this->toast($sent->status === EmailMessage::QUEUED ? 'Reply queued; sending shortly.' : 'Reply scheduled for your next sending window.');

        return back();
    }

    /** Stop a queued answer; it goes back to being a draft. */
    public function cancel(Request $request, EmailMessage $message): RedirectResponse
    {
        $this->ensureOwns($request, $message);
        abort_unless($message->isReplyDraft() && in_array($message->status, [EmailMessage::QUEUED, EmailMessage::SCHEDULED, EmailMessage::FAILED, EmailMessage::RETRYING], true), 409);

        $message->update(['status' => EmailMessage::DRAFT, 'scheduled_at' => null]);
        $message->recordEvent('cancelled');
        $this->toast('Reply moved back to drafts.');

        return back();
    }

    /** Discard a draft answer. */
    public function destroy(Request $request, EmailMessage $message): RedirectResponse
    {
        $this->ensureOwns($request, $message);
        abort_unless($message->isReplyDraft() && $message->status === EmailMessage::DRAFT, 409);

        $message->delete();
        $this->toast('Draft discarded.');

        return back();
    }
}
