import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Lock } from 'lucide-react';
import { useState } from 'react';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import { ProductTile, statusLabel } from '@/sites/public/components/product';
import type { Product } from '@/sites/public/components/product';
import Seo from '@/sites/public/components/seo';
import {
    button,
    card,
    eyebrow,
    textLink,
    trialDuration,
    trialLength,
    trialUrl,
} from '@/sites/public/components/styles';

type Props = {
    /** Applications that can be tried today (released or in beta). */
    products: Product[];
    /** Preselected by ?app=, when it names one of them. */
    selected: string | null;
    trialDays: number;
    canonical: string;
};

const steps = ['Choose an application', 'Your account', 'Your workspace'];

/**
 * Choose an application, then continue to creating a workspace on the app
 * host with that application's trial preselected.
 */
export default function Start({
    products,
    selected: initial,
    trialDays,
    canonical,
}: Props) {
    const [selected, setSelected] = useState(
        initial ?? products[0]?.code ?? '',
    );
    const product = products.find((item) => item.code === selected);
    const trial = trialLength(trialDays);

    return (
        <>
            <Seo
                title="Start your free trial"
                description={`Choose a Miles Global Softwares application and try it free for ${trialDuration(trialDays)}. No payment details needed.`}
                canonical={canonical}
            />

            <PageHero
                padding="pt-14 pb-36 sm:pt-20 sm:pb-44"
                eyebrow={<Stepper current={0} />}
                title={
                    <>
                        Start your <Accent>free trial</Accent>
                    </>
                }
                intro={`Choose the application to try. It is free for ${trialDuration(trialDays)}, and no payment details are needed.`}
            />

            <section
                aria-label="Choose an application"
                className="relative mx-auto -mt-24 max-w-6xl px-4 pb-24 sm:px-6"
            >
                {products.length === 0 ? (
                    <div className={`${card} p-10 text-center`}>
                        <p>
                            Trials open as soon as our first application is
                            released.{' '}
                            <Link href="/contact" className={textLink}>
                                Contact us
                            </Link>{' '}
                            to hear first.
                        </p>
                    </div>
                ) : (
                    <div
                        className={`${card} grid gap-6 p-4 sm:p-6 lg:grid-cols-[1fr_22rem]`}
                    >
                        <fieldset>
                            <legend className="px-1 pt-1 text-lg font-semibold">
                                Which application do you want to try?
                            </legend>
                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                {products.map((item) => (
                                    <AppOption
                                        key={item.code}
                                        product={item}
                                        checked={item.code === selected}
                                        onSelect={() => setSelected(item.code)}
                                    />
                                ))}
                            </div>
                        </fieldset>

                        <aside
                            aria-label="Your trial"
                            className="relative isolate flex flex-col overflow-hidden rounded-2xl bg-linear-to-br from-site-soft to-white p-6 text-slate-600 ring-1 ring-site-line"
                        >
                            <div
                                aria-hidden
                                className="absolute -top-20 -right-20 -z-10 size-56 rounded-full bg-site-accent/15 blur-3xl"
                            />
                            <p className={eyebrow}>Your trial</p>
                            <div className="mt-3 flex items-center gap-3">
                                {product && (
                                    <ProductTile
                                        code={product.code}
                                        size="sm"
                                    />
                                )}
                                <p className="text-2xl font-bold tracking-tight text-site-ink">
                                    {product?.name ?? 'Choose an application'}
                                </p>
                            </div>
                            <ul className="mt-6 flex-1 space-y-3 text-sm">
                                {[
                                    `${trial} free trial, from today`,
                                    'A workspace with its own address',
                                    'Invite your team when you are ready',
                                    'No payment details needed',
                                ].map((line) => (
                                    <li key={line} className="flex gap-2.5">
                                        <span className="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-site-accent text-white">
                                            <Check
                                                className="size-3"
                                                aria-hidden
                                            />
                                        </span>
                                        {line}
                                    </li>
                                ))}
                            </ul>
                            <a
                                href={trialUrl(selected)}
                                className={`mt-8 ${button.primary}`}
                            >
                                Continue
                                <span className="sr-only">
                                    {' '}
                                    with a {product?.name} trial
                                </span>
                                <ArrowRight className="size-4" aria-hidden />
                            </a>
                            <p className="mt-4 flex items-center justify-center gap-1.5 text-center text-xs text-slate-500">
                                <Lock className="size-3" aria-hidden />
                                Next: sign in or create an account.
                            </p>
                        </aside>
                    </div>
                )}

                <p className="mt-8 text-center text-sm text-slate-600">
                    Not sure which one fits?{' '}
                    <Link href="/contact" className={textLink}>
                        Ask us
                    </Link>
                    .
                </p>
            </section>
        </>
    );
}

/** Where the visitor is in getting started: here, then the app host. */
function Stepper({ current }: { current: number }) {
    return (
        <ol className="mb-10 flex flex-wrap items-center justify-center gap-2 text-sm">
            {steps.map((step, i) => (
                <li
                    key={step}
                    aria-current={i === current ? 'step' : undefined}
                    className="flex items-center gap-2"
                >
                    <span
                        className={`inline-flex items-center gap-2 rounded-full px-3 py-1.5 ${i === current ? 'bg-white font-semibold text-site-ink shadow-lg shadow-site-night/30' : 'text-slate-300'}`}
                    >
                        <span
                            className={`inline-flex size-5 items-center justify-center rounded-full text-xs ${i === current ? 'bg-site-accent-ink text-white' : 'bg-white/10 text-slate-300'}`}
                        >
                            {i + 1}
                        </span>
                        {step}
                    </span>
                    {i < steps.length - 1 && (
                        <span aria-hidden className="h-px w-6 bg-white/20" />
                    )}
                </li>
            ))}
        </ol>
    );
}

function AppOption({
    product,
    checked,
    onSelect,
}: {
    product: Product;
    checked: boolean;
    onSelect: () => void;
}) {
    return (
        <label className="relative flex cursor-pointer gap-4 rounded-xl border border-site-line bg-white p-5 transition-all hover:border-site-accent/40 hover:shadow-md has-[:checked]:border-site-accent has-[:checked]:bg-site-accent/[0.04] has-[:checked]:shadow-md has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-site-accent">
            <input
                type="radio"
                name="application"
                value={product.code}
                checked={checked}
                onChange={onSelect}
                className="sr-only"
            />
            <ProductTile code={product.code} />
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2 font-semibold">
                    {product.name}
                    {statusLabel[product.status] && (
                        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                            {statusLabel[product.status]}
                        </span>
                    )}
                </span>
                {(product.tagline ?? product.description) && (
                    <span className="mt-1 block text-sm text-slate-600">
                        {product.tagline ?? product.description}
                    </span>
                )}
            </span>
            <span
                aria-hidden
                className={`mt-1 inline-flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors ${checked ? 'border-site-accent-ink bg-site-accent-ink text-white' : 'border-slate-300'}`}
            >
                {checked && <Check className="size-3" />}
            </span>
        </label>
    );
}
