import { Link } from "@inertiajs/react";
import LegalPage from "@/sites/public/components/legal";
import type { LegalSection } from "@/sites/public/components/legal";
import { Accent } from "@/sites/public/components/page-hero";
import Seo from "@/sites/public/components/seo";
import { supportEmail, whatsappNumber } from "@/sites/public/components/social";

type Props = {
    canonical: string;
};

/** Change with the text, so the page says when it last changed. */
const updated = "2026-11-07";

const email = <a href={`mailto:${supportEmail}`}>{supportEmail}</a>;

const sections: LegalSection[] = [
    {
        id: "about",
        title: "About this policy",
        body: (
            <>
                <p>
                    Mataem Plus is a restaurant app for phones and tablets, made
                    by Miles Global Link Co. Ltd ("Miles Global Link", "we", "us")
                    as part of Tatook. It runs a
                    restaurant's floor, orders, kitchen tickets, bills, shifts,
                    menu and drinks stock.
                </p>
                <p>
                    This policy explains what the app collects, where it keeps
                    it and who can see it. It adds to our main{" "}
                    <Link href="/privacy">privacy policy</Link>, which covers
                    Tatook accounts and workspaces. For anything in it, write to{" "}
                    {email} or message us on WhatsApp at {whatsappNumber}.
                </p>
            </>
        ),
    },
    {
        id: "what-the-app-handles",
        title: "What the app handles",
        body: (
            <>
                <p>
                    The app works with what the restaurant and its staff enter
                    to run service:
                </p>
                <ul>
                    <li>
                        <strong>Your account:</strong> your name and email
                        address, the restaurant and branch you work in, and what
                        your role allows. When you register a new restaurant in
                        the app, also the restaurant's name.
                    </li>
                    <li>
                        <strong>The restaurant:</strong> its name, address,
                        phone number, currency and the text printed on bills.
                    </li>
                    <li>
                        <strong>Service records:</strong> the menu and prices,
                        dining areas and tables, orders, kitchen tickets, bills
                        and payments (with any payment reference typed in),
                        shifts and their cash counts, and drinks stock.
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
        id: "workspace",
        title: "Where it is kept: your Tatook workspace",
        body: (
            <>
                <p>
                    You sign in with your Tatook account (email and password,
                    two-step sign-in, or Google). What you record is sent over
                    encrypted connections to your restaurant's Tatook workspace,
                    and shared there with the restaurant's other phones, tablets
                    and computers and the staff whose roles allow it.
                </p>
                <p>
                    The workspace belongs to your organization, and we process
                    its data on the organization's behalf. How we collect, keep
                    and share account and workspace data, and the providers that
                    host it, is set out in our main{" "}
                    <Link href="/privacy">privacy policy</Link>.
                </p>
            </>
        ),
    },
    {
        id: "on-the-device",
        title: "What stays on the device",
        body: (
            <>
                <p>So the floor can keep working when the connection drops:</p>
                <ul>
                    <li>
                        <strong>Sign-in tokens</strong> are kept in the device's
                        secure storage.
                    </li>
                    <li>
                        <strong>A working copy</strong> of the menu, tables and
                        open orders is kept in the app's private storage.
                    </li>
                    <li>
                        <strong>Waiting records:</strong> orders, dishes sent to
                        the kitchen and payments made while offline are kept in
                        the app's private storage until they can be sent to the
                        workspace.
                    </li>
                </ul>
                <p>
                    Keep the device locked with a PIN, pattern or fingerprint.
                    Signing out removes the working copy; records still waiting
                    are kept until they are sent. Uninstalling the app removes
                    everything, and any records still waiting to be sent are
                    lost with it.
                </p>
            </>
        ),
    },
    {
        id: "permissions",
        title: "Device permissions",
        body: (
            <ul>
                <li>
                    <strong>Internet:</strong> to reach your Tatook workspace.
                </li>
                <li>
                    <strong>Vibration:</strong> a short buzz to confirm an
                    action or flag a mistake.
                </li>
            </ul>
        ),
    },
    {
        id: "printing",
        title: "Printing bills and tickets",
        body: (
            <p>
                Bills and kitchen tickets are sent to the printer you choose
                through the device's printing service.
            </p>
        ),
    },
    {
        id: "sharing",
        title: "Who we share it with",
        body: (
            <p>
                We do not sell personal information, and we do not share
                anything from the app for advertising. Workspace data is kept
                with the service providers listed in our main{" "}
                <Link href="/privacy">privacy policy</Link>, which host and
                protect our servers. If you choose Google sign-in, Google
                handles that step under its own policy and tells us only your
                name and email address. We may also disclose information when
                the law requires it, or to protect our users, our service or the
                public.
            </p>
        ),
    },
    {
        id: "security",
        title: "Security",
        body: (
            <p>
                Connections to Tatook are encrypted, sign-in tokens are kept in
                the device's secure storage, and each person sees only what
                their role allows. No system is perfectly secure, so tell us at{" "}
                {email} straight away if you think an account or device has been
                compromised.
            </p>
        ),
    },
    {
        id: "deletion",
        title: "Deleting your data",
        body: (
            <>
                <ul>
                    <li>
                        <strong>Data on the device:</strong> uninstall the app,
                        or clear its storage in the device's settings.
                    </li>
                    <li>
                        <strong>Your account and workspace:</strong> write to{" "}
                        {email} from the email address on your account, as
                        explained in our main{" "}
                        <Link href="/privacy">privacy policy</Link>.
                    </li>
                    <li>
                        <strong>Customers:</strong> a restaurant controls the
                        records it keeps about its customers. Ask the restaurant
                        that holds your details.
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
        id: "children",
        title: "Children",
        body: (
            <p>
                Mataem Plus is business software for restaurants. It is not
                meant for children, and we do not knowingly collect information
                from anyone under 16.
            </p>
        ),
    },
    {
        id: "changes",
        title: "Changes to this policy",
        body: (
            <p>
                When we change this policy we update the date at the top of this
                page, and for important changes we tell users in the app or by
                email before they take effect. Using Tatook is also covered by
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
                description="What the Mataem Plus restaurant app collects, where it keeps it and who can see it, on the device and in a Tatook workspace."
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
