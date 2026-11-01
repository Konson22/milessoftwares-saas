import { Form } from '@inertiajs/react';
import { CheckCircle2, Copy, Download, KeyRound } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/sites/public/components/lead-form';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import Seo from '@/sites/public/components/seo';
import { supportEmail } from '@/sites/public/components/social';
import { button, iconChip, panel } from '@/sites/public/components/styles';

type Product = 'pharmacy' | 'restaurant';

/** What the page says for each app: the device it runs on, and where its codes are. */
const apps: Record<
    Product,
    { name: string; device: string; place: string; where: string; file: string }
> = {
    pharmacy: {
        name: 'Miles Pharmacy',
        device: 'PC',
        place: 'Pharmacy',
        where: 'On the PC, open Settings, Activation. Scanning its QR code with your phone fills them in here.',
        file: 'miles-pharmacy-licence.lic',
    },
    restaurant: {
        name: 'Mataem Plus',
        device: 'phone',
        place: 'Restaurant',
        where: 'On the phone, open Account, Activation (or the app asks for it when the trial has ended). Its Share button sends a link that fills them in here.',
        file: 'miles-restaurant-licence.txt',
    },
};

type Props = {
    product: Product;
    /** Filled in from the app's QR code or link. */
    device: string | null;
    pharmacy: string | null;
    name: string | null;
    result: {
        code: string;
        device: string;
        pharmacy: string;
        expiresAt: string | null;
        emailedTo: string | null;
    } | null;
    canonical: string;
};

/**
 * Activating Miles Pharmacy on a PC, or Mataem Plus on a phone, with no
 * internet, from another device: the licence key, and the device's codes
 * (filled in by the app's QR code or link), give the activation code to
 * carry back.
 */
export default function Activate({
    product,
    device,
    pharmacy,
    name,
    result,
    canonical,
}: Props) {
    const app = apps[product] ?? apps.pharmacy;

    return (
        <>
            <Seo
                title={`Activate ${app.name}`}
                description={`Activate ${app.name} on a ${app.device} with no internet, using your licence key.`}
                canonical={canonical}
            />

            <PageHero
                title={
                    <>
                        Activate <Accent>{app.name}</Accent>
                    </>
                }
                intro={`For a ${app.device} with no internet. Your licence key and the ${app.device}'s codes give you an activation code to enter on the ${app.device}.`}
            />

            <section
                aria-label="Activation"
                className="mx-auto grid max-w-5xl gap-8 px-4 py-12 sm:px-6 lg:px-8 sm:py-16 lg:grid-cols-[18rem_1fr]"
            >
                <aside className="space-y-4 text-sm text-slate-600">
                    <span className={`${iconChip} size-12`}>
                        <KeyRound className="size-5" aria-hidden />
                    </span>
                    <p className="text-lg font-semibold text-site-ink">
                        Where to find the codes
                    </p>
                    <p>{app.where}</p>
                    <p>
                        The licence key (MILES-…) is on your card or in the
                        email you received when you bought it.
                    </p>
                    <p>
                        Stuck? Email{' '}
                        <a
                            href={`mailto:${supportEmail}`}
                            className="font-semibold text-site-accent-ink underline-offset-2 hover:underline"
                        >
                            {supportEmail}
                        </a>
                        .
                    </p>
                </aside>

                <div className={`${panel} p-6 sm:p-10`}>
                    {result ? (
                        <Done result={result} app={app} />
                    ) : (
                        <Form
                            action="/activate"
                            method="post"
                            className="space-y-5"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="product"
                                        value={product}
                                    />
                                    <Field
                                        name="key"
                                        label="Licence key"
                                        required
                                        autoComplete="off"
                                        error={errors.key}
                                    />
                                    <div className="grid gap-5 sm:grid-cols-2">
                                        <Field
                                            name="device"
                                            label="Device code"
                                            required
                                            defaultValue={device ?? ''}
                                            error={errors.device}
                                        />
                                        <Field
                                            name="pharmacy"
                                            label={`${app.place} ID`}
                                            required
                                            defaultValue={pharmacy ?? ''}
                                            error={errors.pharmacy}
                                        />
                                    </div>
                                    <Field
                                        name="name"
                                        label={`${app.place} name`}
                                        defaultValue={name ?? ''}
                                        error={errors.name}
                                    />
                                    <Field
                                        name="email"
                                        label="Email the code to (optional)"
                                        type="email"
                                        autoComplete="email"
                                        error={errors.email}
                                    />
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="h-12 rounded-full bg-site-accent-ink px-7 font-bold text-white transition-colors hover:bg-site-accent-deep disabled:opacity-60"
                                    >
                                        Get the activation code
                                    </button>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            </section>
        </>
    );
}

function Done({
    result,
    app,
}: {
    result: NonNullable<Props['result']>;
    app: (typeof apps)[Product];
}) {
    const [copied, setCopied] = useState(false);

    const copy = () =>
        void navigator.clipboard.writeText(result.code).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });

    const download = () => {
        const url = URL.createObjectURL(
            new Blob([result.code], { type: 'text/plain' }),
        );
        const link = document.createElement('a');
        link.href = url;
        link.download = app.file;
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="space-y-5">
            <p className="flex items-center gap-2 text-lg font-semibold text-site-ink">
                <CheckCircle2 className="size-5 text-emerald-600" aria-hidden />
                Activated for {app.device} {result.device}
            </p>
            <p className="text-sm text-slate-600">
                {app.device === 'PC'
                    ? 'On that PC, open Settings, Activation and paste this code under Activation code, or save the file and open it there (copied over on a USB stick).'
                    : 'Send this code to that phone (WhatsApp, SMS or email), copy it there, and tap Paste under Activation code.'}
                {result.emailedTo &&
                    ` We also emailed it to ${result.emailedTo}.`}
                {result.expiresAt &&
                    ` It runs until ${new Date(result.expiresAt).toLocaleDateString()}.`}
            </p>
            <p className="rounded-xl border border-site-line bg-slate-50 p-4 font-mono text-xs break-all select-all">
                {result.code}
            </p>
            <div className="flex flex-wrap gap-3">
                <button type="button" onClick={copy} className={button.primary}>
                    <Copy className="size-4" aria-hidden />
                    {copied ? 'Copied' : 'Copy the code'}
                </button>
                <button
                    type="button"
                    onClick={download}
                    className={button.outline}
                >
                    <Download className="size-4" aria-hidden />
                    Save as a file
                </button>
            </div>
        </div>
    );
}
