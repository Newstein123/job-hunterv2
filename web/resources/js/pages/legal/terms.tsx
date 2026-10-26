import { Link } from '@inertiajs/react';
import LegalPage, { Contact, List, Section } from '@/components/legal-page';
import type { LegalDetails } from '@/components/legal-page';
import { privacy } from '@/routes';

export default function Terms({ legal }: { legal: LegalDetails }) {
    const app = legal.app;

    return (
        <LegalPage title="Terms of Service" legal={legal}>
            <p>
                These terms apply to your use of {app}, operated by{' '}
                {legal.operator} (“we”, “us”). By creating an account or using{' '}
                {app}, you agree to them. Please also read our{' '}
                <Link
                    href={privacy()}
                    className="font-medium underline underline-offset-4"
                >
                    Privacy Policy
                </Link>
                .
            </p>

            <Section title="The service">
                <p>
                    {app} helps you apply to jobs you found yourself: it
                    analyses each job, recommends one of your existing resumes,
                    drafts an application email for you to review, sends the
                    emails you approve from your own connected Gmail account,
                    and tracks replies. {app} does not find jobs for you, and we
                    cannot promise any reply, interview or job offer.
                </p>
            </Section>

            <Section title="Your account">
                <List>
                    <li>You must be at least 16 years old.</li>
                    <li>
                        Give accurate information, keep your password safe, and
                        tell us if you think someone else has used your account.
                    </li>
                    <li>
                        You are responsible for what happens in your account.
                    </li>
                </List>
            </Section>

            <Section title="Your content">
                <p>
                    Your profile, resumes, jobs and emails remain yours. You
                    give us permission to store and process them, including with
                    the service providers named in the Privacy Policy, only to
                    run {app} for you. You confirm that you have the right to
                    use what you upload, and that your profile and resumes are
                    truthful.
                </p>
            </Section>

            <Section title="AI-generated content">
                <p>
                    Drafts, recommendations and reply suggestions are written by
                    AI. They can contain mistakes, even after {app}’s automatic
                    checks. You must review every draft, and you are responsible
                    for the content of any email you approve and send.
                </p>
            </Section>

            <Section title="Acceptable use">
                <p>
                    {app} is for genuine, personalised job applications. You
                    agree not to:
                </p>
                <List>
                    <li>
                        send spam, bulk unsolicited messages, or the same
                        generic message to many recipients;
                    </li>
                    <li>
                        send misleading or false information, or claim
                        qualifications you do not have;
                    </li>
                    <li>use addresses that were scraped, guessed or bought;</li>
                    <li>
                        get around sending limits, for example by creating or
                        rotating email accounts;
                    </li>
                    <li>
                        break Google’s terms for Gmail, anti-spam laws, or any
                        other law;
                    </li>
                    <li>
                        try to access other users’ data, or disrupt or
                        reverse-engineer the service.
                    </li>
                </List>
                <p>
                    We may limit, pause or block sending, or suspend an account,
                    to stop misuse or protect the service, other users or email
                    providers.
                </p>
            </Section>

            <Section title="Connected Gmail accounts">
                <p>
                    When you connect Gmail, you authorise {app} to send the
                    emails you approve from that account, and to read the
                    conversations started by those emails. You can disconnect at
                    any time. Google’s own terms continue to apply to your Gmail
                    account.
                </p>
            </Section>

            <Section title="Price">
                <p>
                    {app} is currently free to use. If we introduce paid
                    features, we will tell you in advance, and you will not be
                    charged without agreeing.
                </p>
            </Section>

            <Section title="Availability and changes">
                <p>
                    {app} is in active development. Features may change, and the
                    service may be unavailable at times. We may stop offering{' '}
                    {app}; if we do, we will try to give you notice so you can
                    download your resumes.
                </p>
            </Section>

            <Section title="Disclaimer">
                <p>
                    {app} is provided “as is” and “as available”, without
                    warranties of any kind, to the extent the law allows.
                </p>
            </Section>

            <Section title="Limitation of liability">
                <p>
                    To the extent the law allows, we are not liable for indirect
                    or consequential losses, such as lost job opportunities, or
                    for the content of emails you approve. Nothing in these
                    terms limits liability that cannot be limited by law.
                </p>
            </Section>

            <Section title="Ending your use">
                <p>
                    You can stop using {app} and delete your account in Settings
                    at any time. We may suspend or close accounts that break
                    these terms.
                </p>
            </Section>

            {legal.jurisdiction && (
                <Section title="Governing law">
                    <p>
                        These terms are governed by the laws of{' '}
                        {legal.jurisdiction}.
                    </p>
                </Section>
            )}

            <Section title="Changes to these terms">
                <p>
                    If we change these terms, we will update the date at the top
                    and, for significant changes, let you know in the app or by
                    email. If you keep using {app} after a change, the new terms
                    apply.
                </p>
            </Section>

            <Section title="Contact">
                <p>
                    Questions about these terms: <Contact legal={legal} />.
                </p>
            </Section>
        </LegalPage>
    );
}
