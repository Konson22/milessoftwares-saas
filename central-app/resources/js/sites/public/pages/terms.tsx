import { Link } from '@inertiajs/react';
import LegalPage from '@/sites/public/components/legal';
import type { LegalSection } from '@/sites/public/components/legal';
import { Accent } from '@/sites/public/components/page-hero';
import Seo from '@/sites/public/components/seo';
import { supportEmail } from '@/sites/public/components/social';
import { trialDuration } from '@/sites/public/components/styles';

type Props = {
    trialDays: number;
    canonical: string;
};

/** Change with the text, so the page says when it last changed. */
const updated = '2026-10-26';

const email = <a href={`mailto:${supportEmail}`}>{supportEmail}</a>;

function sections(trialDays: number): LegalSection[] {
    return [
        {
            id: 'agreement',
            title: 'These terms',
            body: (
                <>
                    <p>
                        These terms are an agreement between you and Miles
                        Global Link Co. Ltd, trading as Miles Global Softwares
                        ("Miles", "we", "us"). They cover milesoftwares.com, the
                        Miles applications and our desktop and mobile apps
                        (together, "the service").
                    </p>
                    <p>
                        If you use the service for an organization, you accept
                        these terms for it and confirm you may do so. Custom
                        software projects are also covered by the quotation or
                        contract we agree for that project; where the two
                        differ, the project contract wins.
                    </p>
                </>
            ),
        },
        {
            id: 'accounts',
            title: 'Accounts and workspaces',
            body: (
                <ul>
                    <li>
                        You must give accurate details when you register and
                        keep them up to date.
                    </li>
                    <li>
                        Keep your password, passkeys and two-step codes safe.
                        You are responsible for what happens under your account
                        until you tell us it has been misused.
                    </li>
                    <li>
                        The owner of a workspace controls who is invited, their
                        roles and which applications they use.
                    </li>
                    <li>
                        Workspace and subdomain names must not imitate another
                        business or mislead anyone; we may change or remove
                        names that do.
                    </li>
                </ul>
            ),
        },
        {
            id: 'trials',
            title: 'Free trials',
            body: (
                <p>
                    Each application can be tried free for{' '}
                    {trialDuration(trialDays)}, with no payment details needed.
                    At the end of the trial, the application stops until you
                    choose a plan; your data stays in your workspace so you can
                    carry on where you left off.
                </p>
            ),
        },
        {
            id: 'payment',
            title: 'Plans, invoices and payment',
            body: (
                <ul>
                    <li>
                        Prices are shown on our{' '}
                        <Link href="/pricing">pricing page</Link> or in your
                        quotation, and on every invoice.
                    </li>
                    <li>
                        Subscriptions are billed in advance for each period you
                        choose. One-time licences, setup and customization are
                        billed as agreed.
                    </li>
                    <li>
                        If an invoice stays unpaid after its due date, we may
                        limit the workspace until it is settled. We will warn
                        you first.
                    </li>
                    <li>
                        We may change prices for future periods; we tell account
                        owners at least 30 days before a change applies to them.
                    </li>
                </ul>
            ),
        },
        {
            id: 'your-data',
            title: 'Your data',
            body: (
                <>
                    <p>
                        The business data you enter belongs to your
                        organization. We use it only to provide the service, as
                        described in our{' '}
                        <Link href="/privacy">privacy policy</Link>.
                    </p>
                    <p>
                        You can ask us for a copy of your data at any time,
                        including when you leave. You are responsible for having
                        the right to enter the data you put into the service,
                        including your customers' and staff's personal
                        information.
                    </p>
                </>
            ),
        },
        {
            id: 'acceptable-use',
            title: 'Acceptable use',
            body: (
                <>
                    <p>Do not use the service to:</p>
                    <ul>
                        <li>break the law, or help anyone else do so;</li>
                        <li>
                            send spam, phishing or malware, or imitate another
                            person or business;
                        </li>
                        <li>
                            try to reach data, accounts or systems that are not
                            yours, or test our security without our written
                            permission;
                        </li>
                        <li>
                            overload, disrupt or copy the service, or resell it
                            without an agreement with us.
                        </li>
                    </ul>
                    <p>
                        We may suspend an account that does any of these,
                        telling you why unless the law or safety stops us.
                    </p>
                </>
            ),
        },
        {
            id: 'service',
            title: 'Availability and changes',
            body: (
                <p>
                    We work to keep the service available and your data backed
                    up, but we cannot promise it will never be interrupted.
                    Products marked "early access" or "coming soon" may change
                    or be withdrawn. We improve the service over time, and if we
                    remove something important that you pay for, we will tell
                    you in advance.
                </p>
            ),
        },
        {
            id: 'ownership',
            title: 'Our software',
            body: (
                <p>
                    Miles owns the service, its software and its brand. Your
                    subscription or licence lets your organization use it; it
                    does not transfer ownership. Software we build for you as a
                    custom project is owned as set out in that project's
                    contract.
                </p>
            ),
        },
        {
            id: 'liability',
            title: 'Liability',
            body: (
                <p>
                    The service is provided as it is offered at the time. As far
                    as the law allows, Miles is not liable for indirect or
                    consequential losses, such as lost profits or lost business,
                    and our total liability for any claim is limited to what you
                    paid us for the service in the 12 months before it. Nothing
                    in these terms limits liability that cannot be limited by
                    law.
                </p>
            ),
        },
        {
            id: 'ending',
            title: 'Ending the agreement',
            body: (
                <p>
                    You can stop a subscription at the end of any billing
                    period, or close your workspace, by writing to {email}. We
                    may end these terms with notice, or straight away if you
                    seriously break them. After a workspace closes, we keep its
                    data for a short period so it can be restored or exported,
                    then delete it, except what we must keep by law.
                </p>
            ),
        },
        {
            id: 'changes',
            title: 'Changes to these terms',
            body: (
                <p>
                    When we change these terms we update the date at the top of
                    this page, and for important changes we email account owners
                    before they take effect. Using the service after that means
                    you accept the new terms. Questions? Write to {email}.
                </p>
            ),
        },
    ];
}

/** /terms: the agreement for using Miles. */
export default function Terms({ trialDays, canonical }: Props) {
    return (
        <>
            <Seo
                title="Terms of service"
                description="The terms for using Miles Global Softwares: accounts, free trials, plans and payment, your data and acceptable use."
                canonical={canonical}
            />

            <LegalPage
                title={
                    <>
                        Terms of <Accent>service</Accent>
                    </>
                }
                intro="The agreement for using Miles applications, written to be read."
                updated={updated}
                sections={sections(trialDays)}
            />
        </>
    );
}
