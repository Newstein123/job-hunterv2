import LegalPage, { Contact, List, Section } from '@/components/legal-page';
import type { LegalDetails } from '@/components/legal-page';

export default function Privacy({ legal }: { legal: LegalDetails }) {
    const app = legal.app;

    return (
        <LegalPage title="Privacy Policy" legal={legal}>
            <p>
                This policy explains what information {app} collects, how it is
                used, and the choices you have. {app} is operated by{' '}
                {legal.operator} (“we”, “us”). If you have a question, contact
                us at <Contact legal={legal} />.
            </p>

            <Section title="What JobHunter does">
                <p>
                    You find jobs elsewhere and add them to {app}. {app} helps
                    you pick the right existing resume, write a personalised
                    application email, send it from your own connected Gmail
                    account after you review and approve it, and track replies.
                    Nothing is sent without your approval.
                </p>
            </Section>

            <Section title="Information we collect">
                <List>
                    <li>
                        <strong>Account details:</strong> your name, email
                        address and password (stored only as a secure hash), and
                        two-factor or passkey settings if you turn them on.
                    </li>
                    <li>
                        <strong>Career information you enter:</strong> your
                        profile, contact details, experience, skills, projects
                        and education.
                    </li>
                    <li>
                        <strong>Resumes you upload</strong>, and the text we
                        read from them.
                    </li>
                    <li>
                        <strong>
                            Jobs and company lists you add or import
                        </strong>
                        , including the contact email addresses they contain.
                    </li>
                    <li>
                        <strong>Applications:</strong> generated drafts, your
                        edits, the resume and strategy chosen, scheduled times,
                        and status history.
                    </li>
                    <li>
                        <strong>Your rules and preferences</strong> for how
                        applications are written.
                    </li>
                    <li>
                        <strong>Gmail data, if you connect an account:</strong>{' '}
                        see “Google user data” below.
                    </li>
                    <li>
                        <strong>Technical data:</strong> server logs (such as IP
                        address, time and errors), the cookies described below,
                        and counts of AI usage per feature so we can monitor
                        cost.
                    </li>
                </List>
            </Section>

            <Section title="How we use your information">
                <p>We use your information only to provide {app} to you:</p>
                <List>
                    <li>
                        analysing jobs against your profile and recommending a
                        resume;
                    </li>
                    <li>
                        drafting application emails, optional cover letters and
                        replies for you to review;
                    </li>
                    <li>
                        sending the emails you approve and detecting replies to
                        them;
                    </li>
                    <li>
                        sending account emails (such as email verification and
                        password reset);
                    </li>
                    <li>
                        keeping the service secure, preventing abuse, and fixing
                        problems.
                    </li>
                </List>
                <p>
                    We do not sell your information, use it for advertising, or
                    share it with others for their own purposes.
                </p>
            </Section>

            <Section title="AI processing">
                <p>
                    To analyse jobs and write drafts, {app} sends the relevant
                    information to our AI service, which uses Google’s Gemini
                    API. Depending on the feature, this includes the job
                    description, your career profile, your resumes, the
                    application email you sent, and the text of replies in that
                    conversation. We use Google’s paid API service, which under
                    Google’s terms does not use this content to improve Google’s
                    products.
                </p>
                <p>
                    AI output can be wrong. Every draft is shown to you for
                    review, and nothing is sent until you approve it.
                </p>
            </Section>

            <Section title="Google user data">
                <p>
                    If you connect a Gmail account, you grant {app} two
                    permissions:
                </p>
                <List>
                    <li>
                        <strong>Send email (gmail.send)</strong>: to send the
                        applications and replies you approve, from your own
                        address.
                    </li>
                    <li>
                        <strong>Read email (gmail.readonly)</strong>: to read
                        only the conversations started by applications {app}{' '}
                        sent, so we can detect replies, show them to you,
                        classify them and help you answer. We do not read the
                        rest of your mailbox.
                    </li>
                </List>
                <p>
                    We store your Gmail address, your access tokens (encrypted),
                    and, for those application conversations only, message
                    identifiers, headers and the text of replies. Replies are
                    sent to the AI service described above to be classified and,
                    when you ask, to draft an answer.
                </p>
                <p>
                    {app}’s use and transfer to any other app of information
                    received from Google APIs will adhere to the{' '}
                    <a
                        href="https://developers.google.com/terms/api-services-user-data-policy"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-medium underline underline-offset-4"
                    >
                        Google API Services User Data Policy
                    </a>
                    , including the Limited Use requirements. In particular:
                </p>
                <List>
                    <li>
                        we use Google user data only to provide the features
                        described here;
                    </li>
                    <li>
                        we do not use it for advertising, and we do not sell it;
                    </li>
                    <li>
                        we do not use it to develop, improve or train
                        generalised AI or machine-learning models;
                    </li>
                    <li>
                        people do not read it, unless you ask us to (for
                        example, for support), it is needed for security or to
                        investigate abuse, or the law requires it.
                    </li>
                </List>
                <p>
                    You can disconnect an account at any time on the Email
                    accounts page, which also revokes {app}’s access at Google,
                    or remove access from your{' '}
                    <a
                        href="https://myaccount.google.com/permissions"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-medium underline underline-offset-4"
                    >
                        Google Account permissions
                    </a>
                    .
                </p>
            </Section>

            <Section title="Who we share information with">
                <List>
                    <li>
                        <strong>The recipients you choose:</strong> employers
                        receive the emails you approve, including any resume
                        attached.
                    </li>
                    <li>
                        <strong>Service providers</strong> that run {app} for
                        us: Laravel Cloud (hosting, database and file storage),
                        Amazon Web Services (our AI service), Google (the Gemini
                        API and the Gmail API), and the email provider that
                        sends account emails.
                    </li>
                    <li>
                        <strong>Legal reasons:</strong> when the law requires
                        it, or to protect the rights and safety of users and the
                        service.
                    </li>
                </List>
                <p>
                    These providers may process data in countries other than
                    yours.
                </p>
            </Section>

            <Section title="Security">
                <p>
                    Connections use HTTPS, Gmail tokens are encrypted, resumes
                    are kept in private storage and only served to you through
                    the app, and each user can only see their own data. No
                    system is perfectly secure, so please use a strong password
                    and consider turning on two-factor authentication.
                </p>
            </Section>

            <Section title="How long we keep information">
                <p>
                    We keep your information until you delete it or delete your
                    account. When you delete your account, we delete your
                    profile, resumes, jobs, applications and the email records
                    stored in {app}, and revoke {app}’s Gmail access. Emails you
                    already sent stay in your Gmail and in the recipients’
                    inboxes. Copies in backups and logs are removed as those are
                    replaced over time.
                </p>
            </Section>

            <Section title="Your choices and rights">
                <List>
                    <li>
                        View and edit your information in the app at any time,
                        and download your resumes.
                    </li>
                    <li>
                        Delete individual items, disconnect Gmail accounts, or
                        delete your whole account in Settings.
                    </li>
                    <li>
                        Ask us for a copy of your information, or to correct or
                        delete it, by contacting <Contact legal={legal} />.
                        Depending on where you live, you may have further rights
                        under local data-protection law.
                    </li>
                </List>
            </Section>

            <Section title="Cookies">
                <p>
                    {app} uses only cookies needed to run it: a session cookie
                    to keep you signed in, a security token that protects forms,
                    and small preference cookies for the colour theme and the
                    sidebar. We do not use advertising or analytics cookies.
                </p>
            </Section>

            <Section title="Children">
                <p>
                    {app} is not intended for anyone under 16, and we do not
                    knowingly collect information from children.
                </p>
            </Section>

            <Section title="Changes to this policy">
                <p>
                    If we change this policy, we will update the date at the top
                    and, for significant changes, let you know in the app or by
                    email.
                </p>
            </Section>

            <Section title="Contact">
                <p>
                    Questions or requests about your privacy:{' '}
                    <Contact legal={legal} />.
                </p>
            </Section>
        </LegalPage>
    );
}
