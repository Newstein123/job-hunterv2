import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { ResumeComparisonRow } from '@/types';

/**
 * How each of the user's resumes fits this job, and what would make it stronger.
 * Suggestions only: JobHunter never edits a resume.
 */
export default function ResumeComparison({
    rows,
}: {
    rows: ResumeComparisonRow[];
}) {
    if (rows.length === 0) {
        return null;
    }
    const sorted = [...rows].sort(
        (a, b) => Number(b.recommended) - Number(a.recommended),
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>Resume comparison</CardTitle>
                <CardDescription>
                    How each of your resumes covers this job. “Could add” lists
                    skills the job wants that this resume doesn't mention, but
                    your profile backs up. Update the resume yourself and
                    re-upload it, then re-analyze. Never add a gap you can't
                    back up.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                {sorted.map((r) => (
                    <div
                        key={r.resume_id}
                        className={cn(
                            'grid gap-3 rounded-lg border p-4',
                            r.recommended && 'border-emerald-500/60',
                        )}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{r.label}</span>
                                <span className="text-xs text-muted-foreground">
                                    {r.filename}
                                </span>
                                {r.recommended && (
                                    <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">
                                        Recommended
                                    </Badge>
                                )}
                                {!r.is_active && (
                                    <Badge variant="secondary">Inactive</Badge>
                                )}
                            </div>
                            <div className="flex gap-4 text-sm tabular-nums">
                                {r.ats_match !== null && (
                                    <span title="The job's skills and keywords found in this CV's text, as an applicant tracking system would search for them. Guidance only: there is no universal pass mark.">
                                        <span className="font-semibold">
                                            {r.ats_match}%
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            keyword match
                                        </span>
                                    </span>
                                )}
                                <span>
                                    <span className="font-semibold">
                                        {r.required_covered.length}/
                                        {r.required_total}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        required
                                    </span>
                                </span>
                                <span>
                                    <span className="font-semibold">
                                        {r.preferred_covered.length}/
                                        {r.preferred_total}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        preferred
                                    </span>
                                </span>
                            </div>
                        </div>

                        <div className="grid gap-3 text-sm md:grid-cols-3">
                            <div>
                                <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                    Mentions
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    {[
                                        ...r.required_covered,
                                        ...r.preferred_covered,
                                    ].length ? (
                                        [
                                            ...r.required_covered,
                                            ...r.preferred_covered,
                                        ].map((s) => (
                                            <Badge key={s} variant="secondary">
                                                {s}
                                            </Badge>
                                        ))
                                    ) : (
                                        <span className="text-muted-foreground">
                                            None of the job's skills
                                        </span>
                                    )}
                                </div>
                            </div>
                            <div>
                                <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                    Could add (you have it)
                                </div>
                                {r.could_add.length ? (
                                    <ul className="grid gap-1">
                                        {r.could_add.map((a) => (
                                            <li key={a.skill}>
                                                <span className="font-medium">
                                                    {a.skill}
                                                </span>
                                                {a.required && (
                                                    <span className="text-xs text-amber-700 dark:text-amber-400">
                                                        {' '}
                                                        · required
                                                    </span>
                                                )}
                                                <div className="text-xs text-muted-foreground">
                                                    {a.evidence
                                                        .filter(
                                                            (e) =>
                                                                e !==
                                                                'your skills list',
                                                        )
                                                        .slice(0, 2)
                                                        .join('; ') ||
                                                        'in your skills list'}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <span className="text-muted-foreground">
                                        Nothing missing that you can back up
                                    </span>
                                )}
                            </div>
                            <div>
                                <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                    Gaps (don't claim)
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    {r.gaps.length ? (
                                        r.gaps.map((g) => (
                                            <Badge key={g} variant="outline">
                                                {g}
                                            </Badge>
                                        ))
                                    ) : (
                                        <span className="text-muted-foreground">
                                            None
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>

                        {r.ats_missing.length > 0 && (
                            <div className="text-sm">
                                <span className="text-xs font-medium text-muted-foreground uppercase">
                                    Job keywords not in this CV's text
                                </span>
                                <div className="mt-1 flex flex-wrap gap-1">
                                    {r.ats_missing.map((k) => {
                                        const provable = r.could_add.some(
                                            (a) => a.skill === k,
                                        );

                                        return (
                                            <Badge
                                                key={k}
                                                variant="outline"
                                                className={cn(
                                                    provable &&
                                                        'border-emerald-500/60',
                                                )}
                                            >
                                                {k}
                                                {provable && ' · you have it'}
                                            </Badge>
                                        );
                                    })}
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Recruiters search their system for these
                                    words. Use the job's own wording for skills
                                    you really have; don't add the rest.
                                </p>
                            </div>
                        )}

                        {(r.cv_rule_issues.length > 0 ||
                            r.keyword_hits.length > 0 ||
                            r.page_count) && (
                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                {r.page_count && (
                                    <span>{r.page_count} page(s)</span>
                                )}
                                {r.keyword_hits.length > 0 && (
                                    <span>
                                        Mentions: {r.keyword_hits.join(', ')}
                                    </span>
                                )}
                                {r.cv_rule_issues.map((i) => (
                                    <span
                                        key={i}
                                        className="text-amber-700 dark:text-amber-400"
                                    >
                                        {i}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
