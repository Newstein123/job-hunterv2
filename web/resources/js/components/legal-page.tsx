import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, home, login, privacy, register, terms } from '@/routes';

export type LegalDetails = {
    app: string;
    operator: string;
    contact_email: string | null;
    jurisdiction: string | null;
    updated: string;
};

export function formatLegalDate(iso: string): string {
    return new Date(`${iso}T00:00:00Z`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

/** How to reach the operator: the contact email when configured. */
export function Contact({ legal }: { legal: LegalDetails }) {
    return legal.contact_email ? (
        <a
            href={`mailto:${legal.contact_email}`}
            className="font-medium underline underline-offset-4"
        >
            {legal.contact_email}
        </a>
    ) : (
        <>the contact address shown in the app</>
    );
}

export function Section({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3">
            <h2 className="text-lg font-semibold text-foreground">{title}</h2>
            {children}
        </section>
    );
}

export function List({ children }: { children: ReactNode }) {
    return <ul className="list-disc space-y-1.5 pl-5">{children}</ul>;
}

export default function LegalPage({
    title,
    legal,
    children,
}: {
    title: string;
    legal: LegalDetails;
    children: ReactNode;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title={title} />
            <div className="min-h-svh bg-background text-foreground">
                <header className="border-b">
                    <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                        <Link
                            href={home()}
                            className="flex items-center gap-2 font-medium"
                        >
                            <AppLogoIcon className="size-7 fill-current" />
                            <span>{legal.app}</span>
                        </Link>
                        <nav className="flex items-center gap-4 text-sm">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="hover:underline"
                                >
                                    Dashboard
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="hover:underline"
                                    >
                                        Log in
                                    </Link>
                                    <Link
                                        href={register()}
                                        className="hover:underline"
                                    >
                                        Register
                                    </Link>
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <main className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
                    <h1 className="text-3xl font-semibold tracking-tight">
                        {title}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Last updated: {formatLegalDate(legal.updated)}
                    </p>
                    <div className="mt-8 space-y-8 text-[15px] leading-7 text-muted-foreground">
                        {children}
                    </div>
                </main>

                <footer className="border-t">
                    <div className="mx-auto flex max-w-3xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-6 text-sm text-muted-foreground sm:px-6">
                        <span>
                            © {new Date().getFullYear()} {legal.operator}
                        </span>
                        <Link href={privacy()} className="hover:underline">
                            Privacy Policy
                        </Link>
                        <Link href={terms()} className="hover:underline">
                            Terms of Service
                        </Link>
                    </div>
                </footer>
            </div>
        </>
    );
}
