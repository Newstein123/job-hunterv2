import { Lightbulb } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Recommended send times from the Career Coaching Center, shown as guidance only.
 * Nothing is held back or moved to fit them: emails go when the user schedules them.
 */
export default function SendTimeTips({ className }: { className?: string }) {
    return (
        <div
            className={cn(
                'flex gap-2 rounded-md border border-dashed p-3 text-xs text-muted-foreground',
                className,
            )}
        >
            <Lightbulb className="mt-0.5 size-3.5 shrink-0" />
            <p>
                Suggested times, in the employer's time:{' '}
                <span className="font-medium text-foreground">06:30–10:30</span>{' '}
                for jobs from job websites,{' '}
                <span className="font-medium text-foreground">11:30–15:30</span>{' '}
                for other outreach, on working days. This is a suggestion only.
                Emails go out when you schedule them.
            </p>
        </div>
    );
}
