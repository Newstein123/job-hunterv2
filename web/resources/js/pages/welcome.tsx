import { Head, Link, usePage } from '@inertiajs/react';
import {
    FileCheck2,
    FlaskConical,
    MailCheck,
    PenLine,
    Send,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { Button } from '@/components/ui/button';
import { dashboard, login, privacy, register, terms } from '@/routes';

const steps: { icon: LucideIcon; title: string; text: string }[] = [
    {
        icon: FileCheck2,
        title: 'Pick the right resume',
        text: 'Add a job you found anywhere. JobHunter reads it, compares it with your profile, and recommends which of your existing resumes fits best, and why.',
    },
    {
        icon: PenLine,
        title: 'Get a personalised email',
        text: 'A short application email built only from your real experience, with the skills this job asks for. Your CV is never rewritten.',
    },
    {
        icon: MailCheck,
        title: 'You review and approve',
        text: 'Edit anything, then approve. Nothing is sent until you say so.',
    },
    {
        icon: Send,
        title: 'Send and track',
        text: 'It goes out from your own Gmail, now or at a time you pick. Replies are detected and summarised, so you know what to do next.',
    },
];

export default function Welcome({
    contactEmail,
}: {
    contactEmail: string | null;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="You find the job. JobHunter handles the application." />
            <div className="flex min-h-svh flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-4 py-5 sm:px-6">
                    <div className="flex items-center">
                        <AppLogo />
                    </div>
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={dashboard()}>Dashboard</Link>
                            </Button>
                        ) : (
                            <>
                                <Button variant="ghost" asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={register()}>Register</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="mx-auto w-full max-w-5xl flex-1 px-4 sm:px-6">
                    <section className="py-14 sm:py-20">
                        <h1 className="max-w-2xl text-4xl font-semibold tracking-tight sm:text-5xl">
                            You find the job.
                            <br />
                            JobHunter handles the application.
                        </h1>
                        <p className="mt-5 max-w-2xl text-lg text-muted-foreground">
                            Bring jobs from LinkedIn, company websites,
                            referrals or anywhere else. JobHunter picks your
                            best resume, writes a personalised email, sends it
                            from your Gmail after you approve it, and tracks the
                            reply.
                        </p>
                        {!auth.user && (
                            <div className="mt-8 flex flex-wrap gap-3">
                                <Button size="lg" asChild>
                                    <Link href={register()}>
                                        Create a free account
                                    </Link>
                                </Button>
                                <Button size="lg" variant="outline" asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                            </div>
                        )}
                    </section>

                    <section className="grid gap-4 pb-14 sm:grid-cols-2">
                        {steps.map((s, i) => (
                            <div
                                key={s.title}
                                className="rounded-xl border p-5"
                            >
                                <div className="flex items-center gap-3">
                                    <span className="flex size-9 items-center justify-center rounded-lg bg-muted">
                                        <s.icon className="size-4.5" />
                                    </span>
                                    <h2 className="font-semibold">
                                        <span className="mr-1.5 text-muted-foreground">
                                            {i + 1}.
                                        </span>
                                        {s.title}
                                    </h2>
                                </div>
                                <p className="mt-3 text-sm leading-6 text-muted-foreground">
                                    {s.text}
                                </p>
                            </div>
                        ))}
                    </section>

                    <section className="mb-16 rounded-xl border border-dashed p-5 sm:p-6">
                        <div className="flex items-center gap-2 font-semibold">
                            <FlaskConical className="size-4.5" />
                            Early access for testers
                        </div>
                        <ol className="mt-3 list-decimal space-y-1.5 pl-5 text-sm leading-6 text-muted-foreground">
                            <li>Create an account and confirm your email.</li>
                            <li>
                                Fill in your career profile and upload one to
                                three resumes you already use.
                            </li>
                            <li>
                                Add a job (paste the description) and draft an
                                application. Review it before anything is sent.
                            </li>
                            <li>
                                Connecting Gmail is limited to approved testers
                                while we finish Google’s review. Send us the
                                Gmail address you want to use
                                {contactEmail ? (
                                    <>
                                        {' '}
                                        at{' '}
                                        <a
                                            href={`mailto:${contactEmail}`}
                                            className="font-medium text-foreground underline underline-offset-4"
                                        >
                                            {contactEmail}
                                        </a>
                                    </>
                                ) : null}
                                . During testing, Google asks you to reconnect
                                it about once a week.
                            </li>
                        </ol>
                    </section>
                </main>

                <footer className="border-t">
                    <div className="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-6 text-sm text-muted-foreground sm:px-6">
                        <span>© {new Date().getFullYear()} JobHunter</span>
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
