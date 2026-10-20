import { Loader2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { AnalysisState, Fit, JobMatchSummary } from '@/types';

export const fitLabel: Record<Fit, string> = {
    strong: 'Strong fit',
    partial: 'Partial fit',
    weak: 'Weak fit',
    unknown: 'Unclear',
};

const tone: Record<Fit, string> = {
    strong: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    partial:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    weak: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
    unknown: 'bg-muted text-muted-foreground',
};

/** Fit from required-skill coverage: strong ≥ 80%, partial ≥ 50%, weak below. */
export default function FitBadge({
    match,
    state,
}: {
    match: JobMatchSummary | null;
    state: AnalysisState;
}) {
    if (state === 'pending' || state === 'running') {
        return (
            <Badge variant="outline">
                <Loader2 className="animate-spin" /> Analyzing
            </Badge>
        );
    }
    if (!match) {
        return state === 'failed' ? (
            <Badge variant="destructive">Analysis failed</Badge>
        ) : (
            <span className="text-xs text-muted-foreground">Not analyzed</span>
        );
    }

    return (
        <span className="inline-flex flex-col gap-0.5">
            <Badge
                variant="outline"
                className={cn('border-transparent', tone[match.fit])}
            >
                {fitLabel[match.fit]}
            </Badge>
            <span className="text-xs text-muted-foreground tabular-nums">
                {match.required_covered}/{match.required_total} required
                {match.stale ? ' · outdated' : ''}
            </span>
        </span>
    );
}
