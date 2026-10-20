<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\LlmUsage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $counts = $user->applications()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status');

        // AI usage this calendar month (UTC), for keeping an eye on cost.
        $month = LlmUsage::where('user_id', $user->id)->where('created_at', '>=', now()->startOfMonth());
        $byTask = (clone $month)->toBase()
            ->selectRaw('task, count(*) as calls, sum(input_tokens) as input, sum(output_tokens + thinking_tokens) as output, sum(cost_usd) as cost')
            ->groupBy('task')->get();

        return Inertia::render('dashboard', [
            'aiUsage' => [
                'calls' => (int) $byTask->sum('calls'),
                'cost_usd' => round((float) $byTask->sum('cost'), 4),
                'input_tokens' => (int) $byTask->sum('input'),
                'output_tokens' => (int) $byTask->sum('output'),
                'by_task' => $byTask->map(fn ($r) => [
                    'task' => $r->task, 'calls' => (int) $r->calls, 'cost_usd' => round((float) $r->cost, 4),
                ])->sortByDesc('cost_usd')->values(),
            ],
            // Unhealthy accounts and failed sends need the user's attention.
            'alerts' => [
                'accounts' => $user->emailAccounts()->where('status', '!=', EmailAccount::STATUS_ACTIVE)
                    ->get(['id', 'email', 'status', 'status_reason']),
                'failedSends' => EmailMessage::where('user_id', $user->id)->where('direction', EmailMessage::OUTBOUND)
                    ->where('status', EmailMessage::FAILED)
                    ->whereHas('application', fn ($q) => $q->where('status', ApplicationStatus::Queued))
                    ->with('application.job:id,position,company')->get()
                    ->map(fn (EmailMessage $m) => [
                        'application_id' => $m->application_id,
                        'position' => $m->application->job->displayTitle(),
                        'error' => $m->last_error,
                    ]),
            ],
            'setup' => [
                'profile' => $user->careerProfile()->exists(),
                'resumes' => $user->resumes()->where('is_active', true)->count(),
                'jobs' => $user->jobPostings()->count(),
                'rules' => $user->applicationRule()->exists(),
                'accounts' => $user->emailAccounts()->exists(),
            ],
            'statusCounts' => collect(ApplicationStatus::cases())
                ->map(fn ($s) => ['status' => $s->value, 'label' => $s->label(), 'count' => (int) ($counts[$s->value] ?? 0)])
                ->values(),
            // Employers waiting on the user: their latest reply has no answer yet.
            'awaitingReply' => $user->applications()->with(['job:id,position,company',
                'emailMessages:id,application_id,direction,status,in_reply_to_id,is_auto_reply,from_email,received_at,sent_at,created_at'])
                ->whereHas('emailMessages', fn ($q) => $q->where('direction', 'inbound'))->get()
                ->filter(fn ($a) => ($a->replyState()['state'] ?? null) === 'awaiting_you')
                ->map(fn ($a) => ['id' => $a->id, 'position' => $a->job->displayTitle(), 'company' => $a->job->company,
                    'since' => $a->replyState()['at'] ?? null])->values(),
            'recent' => $user->applications()->with('job:id,position,company')->latest('updated_at')->limit(8)->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'status' => $a->status->value,
                    'generation_state' => $a->generation_state,
                    'position' => $a->job->displayTitle(),
                    'company' => $a->job->company,
                    'updated_at' => $a->updated_at->toIso8601String(),
                ]),
        ]);
    }
}
