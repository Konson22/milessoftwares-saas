import { Head, Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { HeroBackdrop } from '@/sites/public/components/page-hero';
import { button, display } from '@/sites/public/components/styles';

export default function Thanks() {
    return (
        <>
            <Head title="Thank you">
                <meta head-key="robots" name="robots" content="noindex" />
            </Head>

            <section className="relative isolate overflow-hidden bg-linear-to-b from-site-wash to-white">
                <HeroBackdrop />
                <div className="mx-auto flex min-h-[60vh] max-w-2xl flex-col items-center justify-center px-4 py-24 text-center">
                    <span className="inline-flex size-16 items-center justify-center rounded-full bg-emerald-500 text-white ring-8 ring-emerald-500/15">
                        <Check className="size-8" aria-hidden />
                    </span>
                    <h1 className={`mt-8 text-4xl sm:text-5xl ${display}`}>
                        Thank you
                    </h1>
                    <p className="mt-4 text-lg text-slate-600">
                        We have your message and will be in touch within one
                        working day.
                    </p>
                    <div className="mt-10 flex flex-wrap justify-center gap-3">
                        <Link href="/start" className={button.primary}>
                            Start free trial
                        </Link>
                        <Link href="/" className={button.outline}>
                            Back to the home page
                        </Link>
                    </div>
                </div>
            </section>
        </>
    );
}
