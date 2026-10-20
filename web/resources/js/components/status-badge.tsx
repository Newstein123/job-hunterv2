import { Loader2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { ApplicationStatus, GenerationState } from '@/types/app';

const tone: Record<ApplicationStatus, string> = {
    draft: 'bg-muted text-muted-foreground',
    generated: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    approved:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    queued: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    sent: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
    replied:
        'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    interview:
        'bg-fuchsia-100 text-fuchsia-800 dark:bg-fuchsia-950 dark:text-fuchsia-200',
    rejected: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
    offer: 'bg-green-200 text-green-900 dark:bg-green-900 dark:text-green-100',
    withdrawn: 'bg-muted text-muted-foreground line-through',
};

export default function StatusBadge({
    status,
    generationState,
}: {
    status: ApplicationStatus;
    generationState?: GenerationState;
}) {
    if (generationState === 'pending' || generationState === 'running') {
        return (
            <Badge variant="outline">
                <Loader2 className="animate-spin" /> Generating
            </Badge>
        );
    }
    if (generationState === 'failed' && status === 'draft') {
        return <Badge variant="destructive">Generation failed</Badge>;
    }

    return (
        <Badge
            variant="outline"
            className={cn('border-transparent capitalize', tone[status])}
        >
            {status}
        </Badge>
    );
}
