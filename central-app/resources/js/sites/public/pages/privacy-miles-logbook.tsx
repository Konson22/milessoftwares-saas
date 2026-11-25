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
const updated = '2026-11-19';

const email = <a href={`mailto:${supportEmail}`}>{supportEmail}</a>;

const sections: LegalSection[] = [
    {
        id: 'about',
        title: 'About this policy',
        body: (
            <>
                <p>
                    Miles Logbook is an app for phones and tablets that staff
                    use to fill in their organization's logbooks, such as shift
                    logs, checklists, readings and incident records, and that
                    reviewers use to check them. It is part of Miles Global
                    Softwares, a product of Miles Global Link Co. Ltd ("Miles
                    Global Link", "we", "us"). The logbooks, their sheets and
                    who can use them are set up by the organization on the web.
                </p>
                <p>
                    This policy explains what the app collects, where it keeps
                    it and who can see it. It adds to our main{' '}
                    <Link href="/privacy">privacy policy</Link>, which covers
                    Miles Global Softwares accounts and workspaces. For anything
                    in it, write to {email} or message us on WhatsApp at{' '}
                    {whatsappNumber}.
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
                    The app works with what you and your organization put into
                    it:
                </p>
                <ul>
                    <li>
                        <strong>Your account:</strong> your name, your email
                        address or username, the organization you work for, and
                        whether your role lets you write entries, review them or
                        both.
                    </li>
                    <li>
                        <strong>Entries:</strong> your answers to the questions
                        on a log sheet. Depending on the sheet, these can be
                        text, numbers, dates and times, choices, yes or no
                        answers, and a colleague chosen from your organization's
                        members. Each entry records who wrote it and when.
                    </li>
                    <li>
                        <strong>Photos and documents</strong> you attach to an
                        entry.
                    </li>
                    <li>
                        <strong>Reviews:</strong> a reviewer's decision on an
                        entry, any comment they add when returning it for
                        correction, and when they did it.
                    </li>
                    <li>
                        <strong>Your organization's members:</strong> the names
                        of the people you can choose in a person question.
                    </li>
                </ul>
                <p>
                    What an entry contains depends on the questions your
                    organization asks. It can mention other people, so enter
                    only what the sheet needs.
                </p>
                <p>
                    The app does not use your location, contacts or microphone.
                    It removes the hidden details stored inside a photo,
                    including where it was taken, before attaching it. It shows
                    no adverts and contains no advertising, analytics or
                    tracking tools.
                </p>
            </>
        ),
    },
    {
        id: 'workspace',
        title: 'Where it is kept: your Miles Global Softwares workspace',
        body: (
            <>
                <p>
                    You sign in with your Miles Global Softwares account: an
                    email address or username with a password, two-step sign-in
                    if your account has it, or Google. Entries, attachments and
                    reviews are sent over encrypted connections to your
                    organization's Miles Global Softwares workspace. There, they
                    can be seen by the reviewers and other staff whose roles
                    allow it.
                </p>
                <p>
                    The workspace belongs to your organization, which decides
                    what its logbooks record and who sees them. We process that
                    data on the organization's behalf. How we collect, keep and
                    share account and workspace data, and the providers that
                    host it, is set out in our main{' '}
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
                    So you can write entries without a connection, the app keeps
                    some data on the device:
                </p>
                <ul>
                    <li>
                        <strong>Sign-in tokens</strong> are kept in the device's
                        secure storage.
                    </li>
                    <li>
                        <strong>Your entries</strong> are kept in an encrypted
                        database, whose key is held in the device's secure
                        storage. These are your drafts, the entries waiting to
                        be sent and your recent entries. The database also holds
                        a copy of the log sheets you write on and the member
                        names those sheets need. Each person's data is kept
                        apart, so someone else signing in on the same device
                        does not see yours.
                    </li>
                    <li>
                        <strong>Attachments</strong> are kept in the app's
                        private storage until the workspace has received them,
                        and are then removed from the device.
                    </li>
                    <li>
                        Entries already sent are removed from the device 90 days
                        after they last changed. Your workspace keeps them.
                    </li>
                </ul>
                <p>
                    The app's data is not included in the device's backups.
                    Signing out does not delete entries that have not been sent
                    yet. They stay encrypted on the device and are sent the next
                    time you sign in. Uninstalling the app removes all of its
                    data from the device, and any entries still waiting to be
                    sent are lost with it. Keep the device locked with a PIN,
                    pattern or fingerprint.
                </p>
            </>
        ),
    },
    {
        id: 'permissions',
        title: 'Device permissions',
        body: (
            <>
                <ul>
                    <li>
                        <strong>Camera:</strong> to take a photo for an entry,
                        only when you choose to. The photo is attached to that
                        entry and is not used for anything else.
                    </li>
                    <li>
                        <strong>Photos and files:</strong> to attach a photo or
                        a document (such as a PDF, Word or Excel file) that you
                        pick. The app receives only the files you pick, through
                        the device's own picker, not your whole gallery or
                        storage.
                    </li>
                    <li>
                        <strong>Internet:</strong> to reach your Miles Global
                        Softwares workspace.
                    </li>
                </ul>
                <p>
                    The app does not ask for location, microphone or contacts
                    permissions.
                </p>
            </>
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
                Connections to Miles Global Softwares are encrypted. On the
                device, sign-in tokens and the database key are kept in secure
                storage, and entries are kept in an encrypted database. In the
                workspace, each person sees only what their role allows, and
                changes to entries and reviews are recorded. No system is
                perfectly secure, so tell us at {email} straight away if you
                think an account or device has been compromised.
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
                        or clear its storage in the device's settings. Send any
                        waiting entries first, or they are lost.
                    </li>
                    <li>
                        <strong>Your account:</strong> write to {email} from the
                        email address on your account, as explained in our main{' '}
                        <Link href="/privacy">privacy policy</Link>. If you sign
                        in with a username, ask your organization's
                        administrator.
                    </li>
                    <li>
                        <strong>Entries and reviews:</strong> these are your
                        organization's records, and it decides how long to keep
                        them. Ask your organization. It may need to keep some
                        records by law or for its own audits.
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
                Miles Logbook is business software for an organization's staff.
                It is not meant for children, and we do not knowingly collect
                their accounts.
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
                email before they take effect. Using Miles Global Softwares is
                also covered by our <Link href="/terms">terms of service</Link>.
            </p>
        ),
    },
];

/** /privacy/miles-logbook: the Logbook mobile app's privacy policy, for its store listing. */
export default function PrivacyMilesLogbook({ canonical }: Props) {
    return (
        <>
            <Seo
                title="Miles Logbook privacy policy"
                description="What the Miles Logbook app collects, where it keeps it and who can see it, on the device and in a Miles Global Softwares workspace."
                canonical={canonical}
            />

            <LegalPage
                title={
                    <>
                        Miles Logbook <Accent>privacy policy</Accent>
                    </>
                }
                intro="What the logbook app collects, where it keeps it, and what you can ask us to do with it."
                updated={updated}
                sections={sections}
            />
        </>
    );
}
