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
const updated = '2026-11-04';

const email = <a href={`mailto:${supportEmail}`}>{supportEmail}</a>;

const sections: LegalSection[] = [
    {
        id: 'about',
        title: 'About this policy',
        body: (
            <>
                <p>
                    Miles Pharma POS is a pharmacy counter app for phones and
                    tablets, made by Miles Global Softwares, the software
                    business of Miles Global Link Co. Ltd ("Miles", "we", "us").
                    It handles a pharmacy's sales, prescriptions, stock checks,
                    shifts and receipts.
                </p>
                <p>
                    This policy explains what the app collects, where it keeps
                    it and who can see it. It adds to our main{' '}
                    <Link href="/privacy">privacy policy</Link>, which covers
                    Miles accounts and workspaces. For anything in it, write to{' '}
                    {email} or message us on WhatsApp at {whatsappNumber}.
                </p>
            </>
        ),
    },
    {
        id: 'what-the-app-handles',
        title: 'What the app handles',
        body: (
            <>
                <p>
                    The app works with what the pharmacy and its staff enter to
                    run the counter:
                </p>
                <ul>
                    <li>
                        <strong>Your account:</strong> your name and email
                        address, the business and branch you work in, and what
                        your role allows.
                    </li>
                    <li>
                        <strong>Patients and prescriptions:</strong> the
                        patient's name and age, the prescriber's name and
                        registration number, the medicines prescribed and
                        dispensed, and any notes staff add. This can include
                        health information, so staff should enter only what the
                        pharmacy needs.
                    </li>
                    <li>
                        <strong>Customers, if entered:</strong> a customer's
                        name and phone number on a sale.
                    </li>
                    <li>
                        <strong>Pharmacy records:</strong> products and prices,
                        batches and stock, sales and payments, shifts and their
                        cash counts, and receipts.
                    </li>
                </ul>
                <p>
                    The app does not use your location, contacts, photos or
                    microphone. It shows no adverts and contains no advertising,
                    analytics or tracking tools.
                </p>
            </>
        ),
    },
    {
        id: 'workspace',
        title: 'Where it is kept: your Miles workspace',
        body: (
            <>
                <p>
                    You sign in with your Miles account (email and password,
                    two-step sign-in, or Google). What you record is sent over
                    encrypted connections to your pharmacy's Miles workspace,
                    and shared there with the pharmacy's other devices and the
                    staff whose roles allow it.
                </p>
                <p>
                    The workspace belongs to your organization, which decides
                    what is recorded about its patients and customers. We
                    process that data on the organization's behalf. How we
                    collect, keep and share account and workspace data, and the
                    providers that host it, is set out in our main{' '}
                    <Link href="/privacy">privacy policy</Link>.
                </p>
            </>
        ),
    },
    {
        id: 'on-the-device',
        title: 'What stays on the device',
        body: (
            <>
                <p>
                    So the counter can keep working when the connection drops:
                </p>
                <ul>
                    <li>
                        <strong>Sign-in tokens</strong> are kept in the device's
                        secure storage.
                    </li>
                    <li>
                        <strong>A working copy</strong> of the product catalogue
                        and of recent information such as the shift and the
                        day's sales is kept in the app's private storage.
                    </li>
                    <li>
                        <strong>Waiting records:</strong> sales and cash
                        movements made while offline are kept in the app's
                        private storage until they can be sent to the workspace.
                    </li>
                </ul>
                <p>
                    Keep the device locked with a PIN, pattern or fingerprint.
                    Uninstalling the app removes this data from the device, and
                    any records still waiting to be sent are lost with it.
                </p>
            </>
        ),
    },
    {
        id: 'permissions',
        title: 'Device permissions',
        body: (
            <ul>
                <li>
                    <strong>Camera:</strong> to scan product barcodes. It is
                    used only on the scanning screen, and we do not record or
                    keep the pictures.
                </li>
                <li>
                    <strong>Nearby devices (Bluetooth):</strong> to print
                    receipts on a printer paired with the device, if the
                    pharmacy uses one.
                </li>
                <li>
                    <strong>Internet:</strong> to reach your Miles workspace.
                </li>
            </ul>
        ),
    },
    {
        id: 'receipts',
        title: 'Printing and sharing receipts',
        body: (
            <p>
                Receipts go to the printer you choose. When you share a receipt
                as a PDF, it goes where you send it (a chat, email or drive),
                under that service's own policy.
            </p>
        ),
    },
    {
        id: 'sharing',
        title: 'Who we share it with',
        body: (
            <p>
                We do not sell personal information, and we do not share
                anything from the app for advertising. Workspace data is kept
                with the service providers listed in our main{' '}
                <Link href="/privacy">privacy policy</Link>, which host and
                protect our servers. If you choose Google sign-in, Google
                handles that step under its own policy. We may also disclose
                information when the law requires it, or to protect our users,
                our service or the public.
            </p>
        ),
    },
    {
        id: 'security',
        title: 'Security',
        body: (
            <p>
                Connections to Miles are encrypted, sign-in tokens are kept in
                the device's secure storage, and each person sees only what
                their role allows. No system is perfectly secure, so tell us at{' '}
                {email} straight away if you think an account or device has been
                compromised.
            </p>
        ),
    },
    {
        id: 'deletion',
        title: 'Deleting your data',
        body: (
            <>
                <ul>
                    <li>
                        <strong>Data on the device:</strong> uninstall the app,
                        or clear its storage in the device's settings.
                    </li>
                    <li>
                        <strong>Your account and workspace:</strong> write to{' '}
                        {email} from the email address on your account, as
                        explained in our main{' '}
                        <Link href="/privacy">privacy policy</Link>.
                    </li>
                    <li>
                        <strong>Patients and customers:</strong> a pharmacy
                        controls the records it keeps about them. Ask the
                        pharmacy that holds your details. It may need to keep
                        some records by law.
                    </li>
                </ul>
                <p>
                    We reply within one working day and complete requests within
                    30 days.
                </p>
            </>
        ),
    },
    {
        id: 'children',
        title: 'Children',
        body: (
            <p>
                Miles Pharma POS is business software for pharmacy staff. It
                is not meant for children, and we do not knowingly collect their
                accounts. A pharmacy may record a child's name and age on a
                prescription, as part of its own records.
            </p>
        ),
    },
    {
        id: 'changes',
        title: 'Changes to this policy',
        body: (
            <p>
                When we change this policy we update the date at the top of this
                page, and for important changes we tell users in the app or by
                email before they take effect. Using Miles is also covered by
                our <Link href="/terms">terms of service</Link>.
            </p>
        ),
    },
];

/** /privacy/miles-pharmacy: the pharmacy mobile app's privacy policy, for its store listing. */
export default function PrivacyMilesPharmacy({ canonical }: Props) {
    return (
        <>
            <Seo
                title="Miles Pharma POS privacy policy"
                description="What the Miles Pharma POS app collects, where it keeps it and who can see it, on the device and in a Miles workspace."
                canonical={canonical}
            />

            <LegalPage
                title={
                    <>
                        Miles Pharma POS <Accent>privacy policy</Accent>
                    </>
                }
                intro="What the pharmacy app collects, where it keeps it, and what you can ask us to do with it."
                updated={updated}
                sections={sections}
            />
        </>
    );
}
