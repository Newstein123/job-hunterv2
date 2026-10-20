import { Link, usePage } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import EmailAccountController from '@/actions/App/Http/Controllers/EmailAccountController';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';

/** Replies are checked every 10 minutes; this shows when, and checks now on demand. */
export default function CheckReplies() {
    const checkedAt = usePage().props.mailboxCheckedAt as
        | string
        | null
        | undefined;

    return (
        <span className="inline-flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            {checkedAt
                ? `Replies last checked ${formatDate(checkedAt, true)} · checked every 10 min`
                : 'Replies are checked every 10 minutes'}
            <Button size="sm" variant="outline" asChild>
                <Link
                    href={EmailAccountController.sync()}
                    as="button"
                    preserveScroll
                >
                    <RefreshCw /> Check for replies now
                </Link>
            </Button>
        </span>
    );
}
