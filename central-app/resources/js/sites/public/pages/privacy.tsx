import { Link } from '@inertiajs/react';
import LegalPage from '@/sites/public/components/legal';
import type { LegalSection } from '@/sites/public/components/legal';
import { Accent } from '@/sites/public/components/page-hero';
import Seo from '@/sites/public/components/seo';
import { supportEmail, whatsappNumber } from '@/sites/public/components/social';

type Props = {
    canonical: string;
};

/** Change with the text, so the page says when it last changed. */
const updated = '2026-10-26';

const email = <a href={`mailto:${supportEmail}`}>{supportEmail}</a>;

const sections: LegalSection[] = [
    {
        id: 'who-we-are',
        title: 'Who we are',
        body: (
            <>
                <p>
                    Miles Global Softwares is the software business of Miles
                    Global Link Co. Ltd ("Miles", "we", "us"). This policy
                    explains what personal information we collect through
                    milesoftwares.com, our applications and our desktop and
                    mobile apps, why we collect it and what you can ask us to do
                    with it.
                </p>
                <p>
                    For anything in this policy, write to {email} or message us
                    on WhatsApp at {whatsappNumber}.
                </p>
            </>
        ),
    },
    {
        id: 'what-we-collect',
        title: 'What we collect',
        body: (
            <>
                <p>
                    We collect only what we need to answer you and run the
                    service:
                </p>
                <ul>
                    <li>
                        <strong>When you contact us:</strong> your name, email
                        address, company, phone number, the subject and your
                        message, with the IP address and browser details the
                        request came from (to block spam and abuse).
                    </li>
                    <li>
                        <strong>When you create an account:</strong> your name,
                        email address and password (stored only as a one-way
                        hash), or the profile details Google shares if you sign
                        in with Google. If you add a passkey or two-step
                        sign-in, we keep what is needed to check it, never your
                        fingerprint or face.
                    </li>
                    <li>
                        <strong>About your organization:</strong> its name,
                        branches, the people you invite and their roles, the
                        applications you use, your subscriptions and invoices.
                    </li>
                    <li>
                        <strong>Security records:</strong> sign-ins, the device
                        and IP address used, and an audit log of important
                        changes in your workspace.
                    </li>
                    <li>
                        <strong>Business data you enter:</strong> sales, stock,
                        customers, staff, accounts and anything else you record
                        in a Miles application. This belongs to your
                        organization; we process it on your behalf.
                    </li>
                </ul>
            </>
        ),
    },
    {
        id: 'how-we-use-it',
        title: 'How we use it',
        body: (
            <ul>
                <li>
                    To provide the applications you sign up for, and to keep
                    your account and workspace secure.
                </li>
                <li>
                    To reply to your messages, quotation and demo requests, and
                    to send service emails such as trial confirmations, invoices
                    and security alerts.
                </li>
                <li>
                    To bill for subscriptions and projects, and to meet our
                    legal and accounting obligations.
                </li>
                <li>
                    To understand how visitors find and use our public website,
                    so we can improve it (see cookies below).
                </li>
            </ul>
        ),
    },
    {
        id: 'cookies',
        title: 'Cookies and similar technology',
        body: (
            <>
                <p>
                    We use a small number of cookies that are needed for the
                    site and applications to work: a session cookie that keeps
                    you signed in and a security token that protects our forms.
                    They expire when you sign out or soon after you leave.
                </p>
                <p>
                    Our public website (not the applications you sign in to)
                    also uses the Meta Pixel, which tells Meta that a page was
                    visited so we can measure and target our adverts on Facebook
                    and Instagram. Meta may combine this with what it already
                    knows about you under{' '}
                    <a
                        href="https://www.facebook.com/privacy/policy"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        its own privacy policy
                    </a>
                    . You can block it with your browser's privacy settings or
                    in your Facebook ad preferences.
                </p>
            </>
        ),
    },
    {
        id: 'sharing',
        title: 'Who we share it with',
        body: (
            <>
                <p>
                    We do not sell personal information. We share it only with
                    the service providers that help us run Miles, and only what
                    each one needs:
                </p>
                <ul>
                    <li>
                        DigitalOcean, which hosts our servers and databases.
                    </li>
                    <li>
                        Cloudflare, which delivers and protects our websites.
                    </li>
                    <li>Resend, which sends our emails.</li>
                    <li>Google, if you choose to sign in with Google.</li>
                    <li>Meta, through the Meta Pixel on our public website.</li>
                </ul>
                <p>
                    We may also disclose information when the law requires it,
                    or to protect our users, our service or the public.
                </p>
            </>
        ),
    },
    {
        id: 'storage',
        title: 'Where it is kept and for how long',
        body: (
            <>
                <p>
                    Our providers may store and process data outside your
                    country. We choose providers that protect it with encryption
                    in transit and access controls.
                </p>
                <p>
                    We keep account and workspace data while your account is
                    open. Messages sent through our contact form are kept for as
                    long as we need them to follow up with you. Invoices and
                    billing records are kept for as long as accounting law
                    requires. When you close your workspace you can ask us to
                    delete its data, except what we must keep by law.
                </p>
            </>
        ),
    },
    {
        id: 'your-choices',
        title: 'Your rights and choices',
        body: (
            <>
                <p>You can ask us to:</p>
                <ul>
                    <li>
                        give you a copy of the personal information we hold
                        about you;
                    </li>
                    <li>correct anything that is wrong or out of date;</li>
                    <li>
                        delete your information, or your organization's
                        workspace;
                    </li>
                    <li>stop using your information for marketing.</li>
                </ul>
                <p>
                    Write to {email} from the email address on your account. We
                    reply within one working day and complete requests within 30
                    days. For data inside an organization's workspace, we may
                    ask that the request comes from the organization's owner.
                </p>
            </>
        ),
    },
    {
        id: 'security',
        title: 'Security',
        body: (
            <p>
                We protect your information with encrypted connections, hashed
                passwords, optional passkeys and two-step sign-in, roles and
                permissions per branch, and an audit log. No system is perfectly
                secure; if a breach affects your information, we will tell you
                without undue delay.
            </p>
        ),
    },
    {
        id: 'children',
        title: 'Children',
        body: (
            <p>
                Miles is business software. It is not meant for children, and we
                do not knowingly collect information from anyone under 16.
            </p>
        ),
    },
    {
        id: 'changes',
        title: 'Changes to this policy',
        body: (
            <p>
                When we change this policy we update the date at the top of this
                page, and for important changes we email account owners before
                they take effect. Using Miles is also covered by our{' '}
                <Link href="/terms">terms of service</Link>.
            </p>
        ),
    },
];

/** /privacy: what we collect, why, who sees it and what you can ask for. */
export default function Privacy({ canonical }: Props) {
    return (
        <>
            <Seo
                title="Privacy policy"
                description="How Miles Global Softwares collects, uses and protects personal information on milesoftwares.com and in Miles applications."
                canonical={canonical}
            />

            <LegalPage
                title={
                    <>
                        Privacy <Accent>policy</Accent>
                    </>
                }
                intro="What we collect, why we collect it, and what you can ask us to do with it."
                updated={updated}
                sections={sections}
            />
        </>
    );
}
