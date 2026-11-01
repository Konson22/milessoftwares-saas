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
const updated = '2026-10-31';

const email = <a href={`mailto:${supportEmail}`}>{supportEmail}</a>;

const sections: LegalSection[] = [
    {
        id: 'about',
        title: 'About this policy',
        body: (
            <>
                <p>
                    Mataem Plus is a restaurant app for Android phones and
                    tablets, made by Miles Global Softwares, the software
                    business of Miles Global Link Co. Ltd ("Miles", "we", "us").
                    It runs a restaurant's floor, orders, kitchen tickets,
                    bills, shifts, menu and drinks stock.
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
        id: 'two-ways',
        title: 'Two ways to use the app',
        body: (
            <>
                <p>
                    On first opening, the restaurant chooses how the app works,
                    and can switch later:
                </p>
                <ul>
                    <li>
                        <strong>Offline, on this phone:</strong> the restaurant
                        and everything recorded in it are kept only on the
                        phone. Miles does not receive them.
                    </li>
                    <li>
                        <strong>Online, with a Miles workspace:</strong> the app
                        signs in to the restaurant's Miles workspace and works
                        with the data kept there, shared with the restaurant's
                        other phones, tablets and computers.
                    </li>
                </ul>
            </>
        ),
    },
    {
        id: 'what-the-app-keeps',
        title: 'What the app keeps',
        body: (
            <>
                <p>
                    The app holds what the restaurant enters to run its service:
                </p>
                <ul>
                    <li>
                        <strong>The restaurant:</strong> its name, address,
                        phone number, currency and the text printed on bills.
                    </li>
                    <li>
                        <strong>Staff:</strong> each person's name, username and
                        role. In offline mode their passwords are kept only as a
                        one-way hash, never as typed.
                    </li>
                    <li>
                        <strong>Service records:</strong> the menu and prices,
                        tables, orders, kitchen tickets, bills and payments
                        (with any payment reference typed in), shifts and their
                        cash counts, and drinks stock.
                    </li>
                    <li>
                        <strong>Customer details, if typed:</strong> a name on a
                        takeaway order and the number of guests at a table. The
                        app asks for nothing more about customers.
                    </li>
                </ul>
                <p>
                    The app does not use the camera, microphone, location,
                    contacts or photos. It shows no adverts and contains no
                    advertising, analytics or tracking tools.
                </p>
            </>
        ),
    },
    {
        id: 'offline',
        title: 'Offline mode: data stays on the phone',
        body: (
            <>
                <p>
                    In offline mode the restaurant's data is stored in the app's
                    private storage on the phone. It is not sent to Miles or
                    anyone else, with two exceptions the restaurant chooses
                    itself:
                </p>
                <ul>
                    <li>
                        <strong>Backups:</strong> the app keeps copies of the
                        restaurant's database on the phone. When a manager
                        shares a backup (to a chat, email or drive), it goes
                        where they send it, under that service's own policy.
                    </li>
                    <li>
                        <strong>Printing:</strong> bills and kitchen tickets are
                        sent to the printer the restaurant chooses through the
                        phone's printing service.
                    </li>
                </ul>
                <p>
                    Uninstalling the app deletes the restaurant's data from the
                    phone, together with any backups kept inside the app. Keep a
                    shared backup if the restaurant is to be moved or kept.
                </p>
            </>
        ),
    },
    {
        id: 'activation',
        title: 'Free trial and activation',
        body: (
            <>
                <p>
                    Offline mode starts with a 30-day free trial, kept on the
                    phone. To keep working after it, the restaurant activates
                    the app with a licence key. When the key is entered in the
                    app, it sends Miles:
                </p>
                <ul>
                    <li>the licence key;</li>
                    <li>
                        a device code: a short code derived by one-way hash from
                        the identifier Android gives this app on this phone,
                        never the identifier itself;
                    </li>
                    <li>
                        a restaurant ID made at random by the app, and the
                        restaurant's name;
                    </li>
                    <li>
                        the app's version, with the IP address the request came
                        from.
                    </li>
                </ul>
                <p>
                    We use these only to tie the licence to that phone and
                    restaurant, to prevent misuse of keys and to help when you
                    contact us about your licence. Activating from our{' '}
                    <Link href="/activate">activation page</Link> sends the same
                    codes, and the email address you give there if you ask for
                    the code by email. We keep activation records for as long as
                    the licence runs and as long as accounting law requires
                    after it.
                </p>
            </>
        ),
    },
    {
        id: 'online',
        title: 'Online mode: your Miles workspace',
        body: (
            <>
                <p>
                    In online mode you sign in with your Miles account (email
                    and password, two-step sign-in, or Google in the browser).
                    The app keeps its sign-in tokens in the phone's secure
                    storage and sends what you record to your restaurant's
                    workspace over encrypted connections.
                </p>
                <p>
                    The workspace belongs to your organization, and we process
                    its data on the organization's behalf. How we collect, keep
                    and share account and workspace data, and the providers that
                    host it, is set out in our main{' '}
                    <Link href="/privacy">privacy policy</Link>.
                </p>
            </>
        ),
    },
    {
        id: 'permissions',
        title: 'Phone permissions',
        body: (
            <ul>
                <li>
                    <strong>Internet:</strong> for online mode, licence
                    activation and opening our website.
                </li>
                <li>
                    <strong>Files:</strong> only when a manager picks a backup
                    to restore, or shares one; the app reads no other files.
                </li>
                <li>
                    <strong>Vibration:</strong> a short buzz to confirm an
                    action or flag a mistake.
                </li>
            </ul>
        ),
    },
    {
        id: 'sharing',
        title: 'Who we share it with',
        body: (
            <p>
                We do not sell personal information, and we do not share
                anything from the app for advertising. Activation records and
                online workspace data are kept with the service providers listed
                in our main <Link href="/privacy">privacy policy</Link>, which
                host and protect our servers. We may also disclose information
                when the law requires it, or to protect our users, our service
                or the public.
            </p>
        ),
    },
    {
        id: 'security',
        title: 'Security',
        body: (
            <p>
                Connections to Miles are encrypted. Staff passwords are stored
                as one-way hashes, sign-in tokens in the phone's secure storage,
                and each person sees only what their role allows. Data in
                offline mode is protected by the phone itself: keep it locked
                with a PIN, pattern or fingerprint, and keep a recent backup
                somewhere safe.
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
                        <strong>Offline data:</strong> uninstall the app, or
                        clear its storage in the phone's settings. Delete any
                        backups you shared from wherever you sent them.
                    </li>
                    <li>
                        <strong>Activation records:</strong> write to {email}{' '}
                        with your licence key or device code and we will delete
                        them, except what we must keep by law. The licence then
                        no longer works on that phone.
                    </li>
                    <li>
                        <strong>Online account and workspace:</strong> write to{' '}
                        {email} from the email address on your account, as
                        explained in our main{' '}
                        <Link href="/privacy">privacy policy</Link>.
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
                Mataem Plus is business software for restaurants. It is not
                meant for children, and we do not knowingly collect information
                from anyone under 16.
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

/** /privacy/mataem-plus: the restaurant app's privacy policy, linked from its Play Store listing. */
export default function PrivacyMataemPlus({ canonical }: Props) {
    return (
        <>
            <Seo
                title="Mataem Plus privacy policy"
                description="What the Mataem Plus restaurant app collects, where it keeps it and who can see it, offline on the phone and online with a Miles workspace."
                canonical={canonical}
            />

            <LegalPage
                title={
                    <>
                        Mataem Plus <Accent>privacy policy</Accent>
                    </>
                }
                intro="What the restaurant app collects, where it keeps it, and what you can ask us to do with it."
                updated={updated}
                sections={sections}
            />
        </>
    );
}
