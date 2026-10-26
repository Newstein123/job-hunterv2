import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Search, X } from 'lucide-react';
import { useState } from 'react';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import CheckReplies from '@/components/check-replies';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import ReplyStateLine from '@/components/reply-state';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ApplicationRow, CountryOption, Option, Paginated } from '@/types';

type Filters = {
    status: string | null;
    country: number | null;
    kind: 'posting' | 'speculative' | null;
    q: string | null;
};

type Props = {
    applications: Paginated<ApplicationRow>;
    statuses: Option[];
    countries: CountryOption[];
    filters: Filters;
};

export default function Applications({
    applications,
    statuses,
    countries,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.q ?? '');
    const statusTabs = [{ value: '', label: 'All' }, ...statuses];

    const go = (overrides: Partial<Filters>) => {
        const next = { ...filters, ...overrides };
        router.visit(
            ApplicationController.index({
                query: {
                    ...(next.status ? { status: next.status } : {}),
                    ...(next.country ? { country: next.country } : {}),
                    ...(next.kind ? { kind: next.kind } : {}),
                    ...(next.q ? { q: next.q } : {}),
                },
            }),
            { preserveScroll: true },
        );
    };
    const filtered = Boolean(
        filters.status || filters.country || filters.kind || filters.q,
    );

    return (
        <>
            <Head title="Applications" />
            <div className="flex flex-1 flex-col gap-5 p-4">
                <Heading
                    title="Applications"
                    description="Every application, its status and its history."
                />

                {/* Filters */}
                <div className="flex flex-wrap items-end gap-3">
                    <form
                        className="grid gap-1 text-xs text-muted-foreground"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ q: search.trim() || null });
                        }}
                    >
                        <label htmlFor="app-search">Position or company</label>
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                            <Input
                                id="app-search"
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search, then press Enter"
                                className="w-60 pl-8"
                            />
                        </div>
                    </form>
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
                        Type
                        <NativeSelect
                            value={filters.kind ?? ''}
                            onChange={(e) =>
                                go({
                                    kind:
                                        (e.target.value as Filters['kind']) ||
                                        null,
                                })
                            }
                            className="w-44"
                            aria-label="Filter by type"
                        >
                            <option value="">All types</option>
                            <option value="posting">Job posting</option>
                            <option value="speculative">Speculative</option>
                        </NativeSelect>
                    </label>
                    {filtered && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setSearch('');
                                go({
                                    status: null,
                                    country: null,
                                    kind: null,
                                    q: null,
                                });
                            }}
                        >
                            <X /> Clear filters
                        </Button>
                    )}
                </div>
                <div className="flex flex-wrap gap-1">
                    {statusTabs.map((s) => (
                        <button
                            key={s.value}
                            type="button"
                            onClick={() => go({ status: s.value || null })}
                            className={cn(
                                'rounded-md border px-3 py-1 text-sm hover:bg-accent',
                                (filters.status ?? '') === s.value &&
                                    'bg-primary text-primary-foreground hover:bg-primary',
                            )}
                        >
                            {s.label}
                        </button>
                    ))}
                </div>

                {applications.data.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        {filtered
                            ? 'No applications match these filters.'
                            : 'No applications yet.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Position
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Company
                                    </th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">
                                        Country
                                    </th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">
                                        Resume
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">
                                        Updated
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {applications.data.map((a) => (
                                    <tr
                                        key={a.id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-2">
                                            <Link
                                                href={ApplicationController.show(
                                                    a.id,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {a.position}
                                            </Link>
                                            {a.has_errors && (
                                                <AlertTriangle
                                                    className="ml-1 inline size-3.5 text-amber-600"
                                                    aria-label="Has validation issues"
                                                />
                                            )}
                                            {a.kind === 'speculative' && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Speculative
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            {a.company ?? '—'}
                                        </td>
                                        <td className="hidden px-4 py-2 md:table-cell">
                                            {a.country ?? '—'}
                                        </td>
                                        <td className="hidden px-4 py-2 md:table-cell">
                                            {a.resume ?? '—'}
                                        </td>
                                        <td className="px-4 py-2">
                                            <StatusBadge
                                                status={a.status}
                                                generationState={
                                                    a.generation_state
                                                }
                                            />
                                            {a.scheduled_at && (
                                                <div className="text-xs text-muted-foreground">
                                                    Sends{' '}
                                                    {formatDate(
                                                        a.scheduled_at,
                                                        true,
                                                    )}
                                                </div>
                                            )}
                                            <div>
                                                <ReplyStateLine
                                                    state={a.reply_state}
                                                />
                                            </div>
                                        </td>
                                        <td className="hidden px-4 py-2 text-muted-foreground md:table-cell">
                                            {formatDate(a.updated_at)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <CheckReplies />
                <Pagination paginator={applications} />
            </div>
        </>
    );
}

Applications.layout = {
    breadcrumbs: [
        { title: 'Applications', href: ApplicationController.index() },
    ],
};
