import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { CalendarClock, Plus, Search, Sparkles, Upload, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import JobBulkController from '@/actions/App/Http/Controllers/JobBulkController';
import JobImportController from '@/actions/App/Http/Controllers/JobImportController';
import JobPostingController from '@/actions/App/Http/Controllers/JobPostingController';
import FitBadge, { fitLabel } from '@/components/fit-badge';
import CheckReplies from '@/components/check-replies';
import Heading from '@/components/heading';
import ScheduleDialog from '@/components/jobs/schedule-dialog';
import Pagination from '@/components/pagination';
import ReplyStateLine from '@/components/reply-state';
import StatusBadge from '@/components/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    AnalysisState,
    ApplicationStatus,
    CountryOption,
    EmailAccount,
    Fit,
    GenerationState,
    JobMatchSummary,
    Option,
    Paginated,
    ReplyState,
} from '@/types';

type JobRow = {
    id: number;
    position: string;
    company: string | null;
    location: string | null;
    country: string | null;
    outside_targets: boolean;
    kind: 'posting' | 'speculative';
    source: string | null;
    created_at: string;
    analysis_state: AnalysisState;
    analysis_error: string | null;
    match: JobMatchSummary | null;
    application: {
        id: number;
        status: ApplicationStatus;
        generation_state: GenerationState;
        scheduled_at: string | null;
        scheduled_tz: string | null;
        reply_state: ReplyState | null;
    } | null;
};

type Filters = {
    fit: string | null;
    sort: 'fit' | 'newest';
    stage: string | null;
    country: number | null;
};

type Props = {
    jobs: Paginated<JobRow>;
    filters: Filters;
    fitCounts: Record<Fit | 'not_analyzed', number>;
    analyzing: number;
    canAnalyze: boolean;
    stages: Option[];
    countries: CountryOption[];
    accounts: Pick<EmailAccount, 'id' | 'email' | 'status' | 'is_default'>[];
};

export default function Jobs({
    jobs,
    filters,
    fitCounts,
    analyzing,
    canAnalyze,
    stages,
    countries,
    accounts,
}: Props) {
    const bulkResult = usePage().flash.bulkResult;
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [scheduling, setScheduling] = useState(false);
    const { start, stop } = usePoll(
        4000,
        { only: ['jobs', 'fitCounts', 'analyzing'] },
        { autoStart: false },
    );

    const inFlight =
        analyzing > 0 ||
        jobs.data.some(
            (j) =>
                j.application?.generation_state === 'pending' ||
                j.application?.generation_state === 'running',
        );

    useEffect(() => {
        if (inFlight) {
            start();
        } else {
            stop();
        }
    }, [inFlight, start, stop]);

    const url = (overrides: Partial<Filters>) => {
        const next = { ...filters, ...overrides };

        return JobPostingController.index({
            query: {
                ...(next.fit ? { fit: next.fit } : {}),
                ...(next.stage ? { stage: next.stage } : {}),
                ...(next.country ? { country: next.country } : {}),
                ...(next.sort === 'fit' ? { sort: 'fit' } : {}),
            },
        });
    };
    const go = (overrides: Partial<Filters>) => {
        setSelected(new Set());
        router.visit(url(overrides), { preserveScroll: true });
    };

    const rows = jobs.data;
    const chosen = useMemo(
        () => rows.filter((j) => selected.has(j.id)),
        [rows, selected],
    );
    const ids = chosen.map((j) => j.id);
    const counts = {
        analyze: chosen.filter(
            (j) =>
                j.kind === 'posting' &&
                j.analysis_state !== 'pending' &&
                j.analysis_state !== 'running',
        ).length,
        generate: chosen.filter((j) => !j.application).length,
        schedule: chosen.filter((j) => j.application?.status === 'approved')
            .length,
        cancel: chosen.filter((j) => j.application?.status === 'queued').length,
    };
    const allOnPage = rows.length > 0 && rows.every((j) => selected.has(j.id));

    const toggle = (id: number) =>
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    const toggleAll = () =>
        setSelected(allOnPage ? new Set() : new Set(rows.map((j) => j.id)));

    const bulk = (
        action: 'analyze' | 'generate' | 'cancel',
        question: string,
    ) => {
        if (!confirm(question)) {
            return;
        }
        const route = JobBulkController[action]();
        router.post(
            route.url,
            { job_ids: ids },
            { preserveScroll: true, onSuccess: () => setSelected(new Set()) },
        );
    };

    const fitTabs: { value: string | null; label: string; count?: number }[] = [
        { value: null, label: 'Any fit' },
        ...(['strong', 'partial', 'weak', 'unknown'] as Fit[]).map((f) => ({
            value: f,
            label: fitLabel[f],
            count: fitCounts[f],
        })),
    ];

    return (
        <>
            <Head title="Jobs" />
            <div className="flex flex-1 flex-col gap-5 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Jobs"
                        description="Filter, select several jobs, then analyze, draft or schedule them together. Each email is still reviewed and approved on its own page."
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={JobImportController.create()}>
                                <Upload /> Import CSV/Excel
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={JobPostingController.create()}>
                                <Plus /> Add job
                            </Link>
                        </Button>
                    </div>
                </div>

                {analyzing > 0 && (
                    <Alert>
                        <AlertDescription>
                            Analyzing {analyzing} job(s) against your profile.
                            This list updates automatically.
                        </AlertDescription>
                    </Alert>
                )}
                {!canAnalyze && (
                    <Alert>
                        <AlertDescription>
                            Add your career profile and at least one active
                            resume to see how well each job fits.
                        </AlertDescription>
                    </Alert>
                )}
                {bulkResult && bulkResult.skipped.length > 0 && (
                    <Alert>
                        <AlertTitle>{bulkResult.message}</AlertTitle>
                        <AlertDescription>
                            <p>Skipped:</p>
                            <ul className="list-disc pl-5">
                                {bulkResult.skipped.map((s, i) => (
                                    <li key={i}>
                                        {s.job}: {s.reason}
                                    </li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                {/* Filters */}
                <div className="flex flex-wrap items-end gap-3">
                    <label className="grid gap-1 text-xs text-muted-foreground">
                        Country
                        <NativeSelect
                            value={filters.country ?? ''}
                            onChange={(e) =>
                                go({
                                    country: e.target.value
                                        ? Number(e.target.value)
                                        : null,
                                })
                            }
                            className="w-52"
                            aria-label="Filter by country"
                        >
                            <option value="">All countries</option>
                            {countries.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </label>
                    <label className="grid gap-1 text-xs text-muted-foreground">
                        Stage
                        <NativeSelect
                            value={filters.stage ?? ''}
                            onChange={(e) =>
                                go({ stage: e.target.value || null })
                            }
                            className="w-60"
                            aria-label="Filter by stage"
                        >
                            <option value="">All stages</option>
                            {stages.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </label>
                    <label className="grid gap-1 text-xs text-muted-foreground">
                        Sort
                        <NativeSelect
                            value={filters.sort}
                            onChange={(e) =>
                                go({ sort: e.target.value as Filters['sort'] })
                            }
                            className="w-36"
                            aria-label="Sort"
                        >
                            <option value="fit">Best fit</option>
                            <option value="newest">Newest</option>
                        </NativeSelect>
                    </label>
                    {(filters.country || filters.stage || filters.fit) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                go({ country: null, stage: null, fit: null })
                            }
                        >
                            <X /> Clear filters
                        </Button>
                    )}
                </div>
                <div className="flex flex-wrap gap-1">
                    {fitTabs.map((t) => (
                        <button
                            key={t.label}
                            type="button"
                            onClick={() => go({ fit: t.value })}
                            className={cn(
                                'rounded-md border px-3 py-1 text-sm hover:bg-accent',
                                (filters.fit ?? null) === t.value &&
                                    'bg-primary text-primary-foreground hover:bg-primary',
                            )}
                        >
                            {t.label}
                            {t.count !== undefined && (
                                <span className="ml-1 tabular-nums opacity-70">
                                    {t.count}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {/* Bulk bar */}
                {chosen.length > 0 && (
                    <div className="sticky top-2 z-10 flex flex-wrap items-center gap-2 rounded-xl border bg-background p-3 shadow-sm">
                        <span className="mr-2 text-sm font-medium">
                            {chosen.length} selected
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={counts.analyze === 0 || !canAnalyze}
                            onClick={() =>
                                bulk(
                                    'analyze',
                                    `Analyze ${counts.analyze} job(s)? Each one uses the AI once.`,
                                )
                            }
                        >
                            <Search /> Analyze ({counts.analyze})
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={counts.generate === 0 || !canAnalyze}
                            onClick={() =>
                                bulk(
                                    'generate',
                                    `Draft applications for ${counts.generate} job(s)? Each one uses the AI once; you review every draft before it can be sent.`,
                                )
                            }
                        >
                            <Sparkles /> Draft applications ({counts.generate})
                        </Button>
                        <Button
                            size="sm"
                            disabled={counts.schedule === 0}
                            onClick={() => setScheduling(true)}
                        >
                            <CalendarClock /> Schedule send ({counts.schedule})
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={counts.cancel === 0}
                            onClick={() =>
                                bulk(
                                    'cancel',
                                    `Cancel ${counts.cancel} scheduled email(s)? They stay approved.`,
                                )
                            }
                        >
                            <X /> Cancel scheduled ({counts.cancel})
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setSelected(new Set())}
                        >
                            Clear selection
                        </Button>
                    </div>
                )}

                {rows.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        {filters.fit || filters.stage || filters.country
                            ? 'No jobs match these filters.'
                            : 'No jobs yet. Add one you found, or import a spreadsheet.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="w-10 px-4 py-2">
                                        <input
                                            type="checkbox"
                                            checked={allOnPage}
                                            onChange={toggleAll}
                                            aria-label="Select all jobs on this page"
                                        />
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Position
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Fit
                                    </th>
                                    <th className="hidden px-4 py-2 font-medium lg:table-cell">
                                        Missing skills
                                    </th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">
                                        Best resume
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Application
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((job) => (
                                    <tr
                                        key={job.id}
                                        className={cn(
                                            'align-top hover:bg-muted/30',
                                            selected.has(job.id) &&
                                                'bg-muted/40',
                                        )}
                                    >
                                        <td className="px-4 py-3">
                                            <input
                                                type="checkbox"
                                                checked={selected.has(job.id)}
                                                onChange={() => toggle(job.id)}
                                                aria-label={`Select ${job.position}`}
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link
                                                href={JobPostingController.show(
                                                    job.id,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {job.position}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {[job.company, job.country]
                                                    .filter(Boolean)
                                                    .join(' · ') || '—'}
                                                {job.outside_targets && (
                                                    <span className="ml-1 text-amber-600 dark:text-amber-400">
                                                        · not a target country
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {job.kind === 'speculative' ? (
                                                <Badge variant="outline">
                                                    Company list
                                                </Badge>
                                            ) : (
                                                <FitBadge
                                                    match={job.match}
                                                    state={job.analysis_state}
                                                />
                                            )}
                                        </td>
                                        <td className="hidden max-w-64 px-4 py-3 text-xs lg:table-cell">
                                            {job.match ? (
                                                job.match.missing_skills
                                                    .length ? (
                                                    job.match.missing_skills.join(
                                                        ', ',
                                                    )
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        None
                                                    </span>
                                                )
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="hidden px-4 py-3 md:table-cell">
                                            {job.match?.recommended_resume ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {job.application ? (
                                                <Link
                                                    href={ApplicationController.show(
                                                        job.application.id,
                                                    )}
                                                    className="inline-flex flex-col gap-0.5"
                                                >
                                                    <StatusBadge
                                                        status={
                                                            job.application
                                                                .status
                                                        }
                                                        generationState={
                                                            job.application
                                                                .generation_state
                                                        }
                                                    />
                                                    <ReplyStateLine
                                                        state={
                                                            job.application
                                                                .reply_state
                                                        }
                                                    />
                                                    {job.application
                                                        .scheduled_at && (
                                                        <span className="text-xs text-muted-foreground">
                                                            {formatDate(
                                                                job.application
                                                                    .scheduled_at,
                                                                true,
                                                                job.application
                                                                    .scheduled_tz ??
                                                                    undefined,
                                                            )}
                                                            {job.application
                                                                .scheduled_tz &&
                                                                ` (${job.application.scheduled_tz})`}
                                                        </span>
                                                    )}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Not started
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <CheckReplies />
                <Pagination paginator={jobs} />
                <p className="text-xs text-muted-foreground">
                    Fit counts the job's required skills you can back up with
                    your profile or resumes: strong ≥ 80%, partial ≥ 50%, weak
                    below. “Unclear” means no required skills were found in the
                    posting.
                </p>
            </div>

            <ScheduleDialog
                open={scheduling}
                onOpenChange={setScheduling}
                jobIds={ids}
                approvedCount={counts.schedule}
                accounts={accounts}
                onDone={() => setSelected(new Set())}
            />
        </>
    );
}

Jobs.layout = {
    breadcrumbs: [{ title: 'Jobs', href: JobPostingController.index() }],
};
