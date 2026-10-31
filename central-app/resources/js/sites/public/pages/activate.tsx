import { Form } from '@inertiajs/react';
import { CheckCircle2, Copy, Download, KeyRound } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/sites/public/components/lead-form';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import Seo from '@/sites/public/components/seo';
import { supportEmail } from '@/sites/public/components/social';
import { button, iconChip, panel } from '@/sites/public/components/styles';

type Props = {
    /** Filled in from the app's QR code. */
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
 * Activating Miles Pharmacy on a PC with no internet, from a phone: the
 * licence key, and the PC's codes (filled in by the app's QR code), give the
 * activation code to carry to the PC.
 */
export default function Activate({
    device,
    pharmacy,
    name,
    result,
    canonical,
}: Props) {
    return (
        <>
            <Seo
                title="Activate Miles Pharmacy"
                description="Activate Miles Pharmacy on a PC with no internet, using your licence key."
                canonical={canonical}
            />

            <PageHero
                title={
                    <>
                        Activate <Accent>Miles Pharmacy</Accent>
                    </>
                }
                intro="For a PC with no internet. Your licence key and the PC's codes give you an activation code to enter on the PC."
            />

            <section
                aria-label="Activation"
                className="mx-auto grid max-w-5xl gap-8 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-[18rem_1fr]"
            >
                <aside className="space-y-4 text-sm text-slate-600">
                    <span className={`${iconChip} size-12`}>
                        <KeyRound className="size-5" aria-hidden />
                    </span>
                    <p className="text-lg font-semibold text-site-ink">
                        Where to find the codes
                    </p>
                    <p>
                        On the PC, open <b>Settings, Activation</b>. Scanning
                        its QR code with your phone fills them in here.
                    </p>
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
                        <Done result={result} />
                    ) : (
                        <Form
                            action="/activate"
                            method="post"
                            className="space-y-5"
                        >
                            {({ errors, processing }) => (
                                <>
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
                                            label="Pharmacy ID"
                                            required
                                            defaultValue={pharmacy ?? ''}
                                            error={errors.pharmacy}
                                        />
                                    </div>
                                    <Field
                                        name="name"
                                        label="Pharmacy name"
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
                                        className="h-12 rounded-full bg-site-accent-ink px-7 font-bold text-white transition-colors hover:bg-site-navy disabled:opacity-60"
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

function Done({ result }: { result: NonNullable<Props['result']> }) {
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
        link.download = 'miles-pharmacy-licence.lic';
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="space-y-5">
            <p className="flex items-center gap-2 text-lg font-semibold text-site-ink">
                <CheckCircle2 className="size-5 text-emerald-600" aria-hidden />
                Activated for PC {result.device}
            </p>
            <p className="text-sm text-slate-600">
                On that PC, open <b>Settings, Activation</b> and paste this code
                under Activation code, or save the file and open it there
                (copied over on a USB stick).
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
