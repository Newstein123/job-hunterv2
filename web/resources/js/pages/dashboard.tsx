import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Circle,
    Plus,
    Upload,
} from 'lucide-react';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import ApplicationRuleController from '@/actions/App/Http/Controllers/ApplicationRuleController';
import CareerProfileController from '@/actions/App/Http/Controllers/CareerProfileController';
import EmailAccountController from '@/actions/App/Http/Controllers/EmailAccountController';
import JobImportController from '@/actions/App/Http/Controllers/JobImportController';
import JobPostingController from '@/actions/App/Http/Controllers/JobPostingController';
import ResumeController from '@/actions/App/Http/Controllers/ResumeController';
import CheckReplies from '@/components/check-replies';
import Heading from '@/components/heading';
import StatusBadge from '@/components/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { dashboard } from '@/routes';
import type { ApplicationRow, ApplicationStatus, EmailAccount } from '@/types';

type Props = {
    setup: {
        profile: boolean;
        resumes: number;
        jobs: number;
        rules: boolean;
        accounts: boolean;
    };
    alerts: {
        accounts: Pick<
            EmailAccount,
            'id' | 'email' | 'status' | 'status_reason'
        >[];
        failedSends: {
            application_id: number;
            position: string;
            error: string | null;
        }[];
    };
    statusCounts: { status: ApplicationStatus; label: string; count: number }[];
    recent: ApplicationRow[];
    aiUsage: {
        calls: number;
        cost_usd: number;
        input_tokens: number;
        output_tokens: number;
        by_task: { task: string; calls: number; cost_usd: number }[];
    };
    awaitingReply: {
        id: number;
        position: string;
        company: string | null;
        since: string | null;
    }[];
};

export default function Dashboard({
    setup,
    statusCounts,
    recent,
    alerts,
    awaitingReply,
    aiUsage,
}: Props) {
    const steps = [
        {
            done: setup.profile,
            label: 'Add your career profile',
            href: CareerProfileController.edit(),
        },
        {
            done: setup.resumes > 0,
            label: 'Upload your existing resumes',
            href: ResumeController.index(),
        },
        {
            done: setup.accounts,
            label: 'Connect a Gmail account to send from (optional)',
            href: EmailAccountController.index(),
        },
        {
            done: setup.rules,
            label: 'Review application rules (optional)',
            href: ApplicationRuleController.edit(),
        },
        {
            done: setup.jobs > 0,
            label: 'Add a job you found',
            href: JobPostingController.create(),
        },
    ];
    const setupDone = steps.every((s) => s.done);

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="You find the job. JobHunter handles the application."
                        description="Add jobs you found elsewhere, then review the resume choice and email before anything is sent."
                    />
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={JobImportController.create()}>
                                <Upload /> Import jobs
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={JobPostingController.create()}>
                                <Plus /> Add job
                            </Link>
                        </Button>
                    </div>
                </div>

                {alerts.accounts.length > 0 && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>Email accounts need attention</AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc pl-5">
                                {alerts.accounts.map((a) => (
                                    <li key={a.id}>
                                        {a.email}: {a.status_reason ?? a.status}
                                    </li>
                                ))}
                            </ul>
                            <Link
                                href={EmailAccountController.index()}
                                className="mt-1 underline"
                            >
                                Manage email accounts
                            </Link>
                        </AlertDescription>
                    </Alert>
                )}

                {alerts.failedSends.length > 0 && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>
                            Some applications failed to send
                        </AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc pl-5">
                                {alerts.failedSends.map((f) => (
                                    <li key={f.application_id}>
                                        <Link
                                            href={ApplicationController.show(
                                                f.application_id,
                                            )}
                                            className="underline"
                                        >
                                            {f.position}
                                        </Link>
                                        {f.error ? `: ${f.error}` : ''}
                                    </li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                <CheckReplies />

                {awaitingReply.length > 0 && (
                    <Alert>
                        <AlertTriangle />
                        <AlertTitle>
                            Employers waiting for your reply (
                            {awaitingReply.length})
                        </AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc pl-5">
                                {awaitingReply.map((r) => (
                                    <li key={r.id}>
                                        <Link
                                            href={ApplicationController.show(
                                                r.id,
                                            )}
                                            className="underline"
                                        >
                                            {r.position}
                                            {r.company ? ` · ${r.company}` : ''}
                                        </Link>
                                        {r.since
                                            ? ` · replied ${formatDate(r.since, true)}`
                                            : ''}
                                    </li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                {!setupDone && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Get set up</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2">
                            {steps.map((step) => (
                                <Link
                                    key={step.label}
                                    href={step.href}
                                    className="flex items-center gap-2 text-sm hover:underline"
                                >
                                    {step.done ? (
                                        <CheckCircle2 className="size-4 text-emerald-600" />
                                    ) : (
                                        <Circle className="size-4 text-muted-foreground" />
                                    )}
                                    <span
                                        className={
                                            step.done
                                                ? 'text-muted-foreground line-through'
                                                : ''
                                        }
                                    >
                                        {step.label}
                                    </span>
                                </Link>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    {statusCounts.map((s) => (
                        <Link
                            key={s.status}
                            href={ApplicationController.index({
                                query: { status: s.status },
                            })}
                            className="rounded-xl border p-3 transition-colors hover:bg-accent"
                        >
                            <div className="text-2xl font-semibold tabular-nums">
                                {s.count}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {s.label}
                            </div>
                        </Link>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>AI usage this month</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm">
                        <div className="flex flex-wrap gap-6">
                            <div>
                                <div className="text-2xl font-semibold tabular-nums">
                                    $
                                    {aiUsage.cost_usd.toFixed(
                                        aiUsage.cost_usd < 1 ? 4 : 2,
                                    )}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    estimated cost
                                </div>
                            </div>
                            <div>
                                <div className="text-2xl font-semibold tabular-nums">
                                    {aiUsage.calls}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    AI calls
                                </div>
                            </div>
                            <div>
                                <div className="text-2xl font-semibold tabular-nums">
                                    {(aiUsage.input_tokens / 1000).toFixed(1)}k
                                    /{' '}
                                    {(aiUsage.output_tokens / 1000).toFixed(1)}k
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    tokens in / out (incl. thinking)
                                </div>
                            </div>
                        </div>
                        {aiUsage.by_task.length > 0 && (
                            <ul className="grid gap-1 text-xs text-muted-foreground">
                                {aiUsage.by_task.map((t) => (
                                    <li key={t.task}>
                                        {{
                                            job_analysis: 'Job analysis',
                                            email: 'Application emails',
                                            reply_analysis: 'Reading replies',
                                            reply_draft: 'Reply drafts',
                                        }[t.task] ?? t.task}
                                        : {t.calls} call(s) · $
                                        {t.cost_usd.toFixed(4)}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Estimated from the tokens Gemini reports, at the
                            prices in config/services.php.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Recent applications</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {recent.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No applications yet. Open a job and choose
                                “Generate application”.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {recent.map((a) => (
                                    <li key={a.id}>
                                        <Link
                                            href={ApplicationController.show(
                                                a.id,
                                            )}
                                            className="flex items-center justify-between gap-4 py-2 hover:underline"
                                        >
                                            <span className="min-w-0 truncate text-sm">
                                                <span className="font-medium">
                                                    {a.position}
                                                </span>
                                                {a.company && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · {a.company}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="flex shrink-0 items-center gap-3">
                                                <span className="hidden text-xs text-muted-foreground sm:inline">
                                                    {formatDate(a.updated_at)}
                                                </span>
                                                <StatusBadge
                                                    status={a.status}
                                                    generationState={
                                                        a.generation_state
                                                    }
                                                />
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
