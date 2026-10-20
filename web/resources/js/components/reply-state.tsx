import {
    MessageCircleReply,
    MessageSquareDashed,
    Send,
    Clock,
} from 'lucide-react';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ReplyState } from '@/types';

/** Who owes the next message in the conversation with the employer. */
export default function ReplyStateLine({
    state,
    className,
}: {
    state: ReplyState | null | undefined;
    className?: string;
}) {
    if (!state) {
        return null;
    }
    const map = {
        awaiting_you: {
            icon: MessageCircleReply,
            text: `Your turn to reply${state.at ? ` · since ${formatDate(state.at, true)}` : ''}`,
            tone: 'text-amber-700 dark:text-amber-400 font-medium',
        },
        reply_draft: {
            icon: MessageSquareDashed,
            text: 'Reply drafted, not sent',
            tone: 'text-muted-foreground',
        },
        reply_scheduled: {
            icon: Clock,
            text: 'Your reply is scheduled',
            tone: 'text-muted-foreground',
        },
        you_replied: {
            icon: Send,
            text: `You replied${state.at ? ` · ${formatDate(state.at, true)}` : ''}`,
            tone: 'text-emerald-700 dark:text-emerald-400',
        },
    }[state.state];
    const Icon = map.icon;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 text-xs',
                map.tone,
                className,
            )}
        >
            <Icon className="size-3.5" /> {map.text}
        </span>
    );
}
