import { Head, Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { HeroBackdrop } from '@/sites/public/components/page-hero';
import { button, darkBand, display } from '@/sites/public/components/styles';

export default function Thanks() {
    return (
        <>
            <Head title="Thank you">
                <meta head-key="robots" name="robots" content="noindex" />
            </Head>

            <section className={darkBand}>
                <HeroBackdrop />
                <div className="mx-auto flex min-h-[60vh] max-w-2xl flex-col items-center justify-center px-4 py-24 text-center">
                    <span className="inline-flex size-16 items-center justify-center rounded-full bg-linear-to-br from-[#cf440c] to-[#9a3412] text-white shadow-xl ring-8 shadow-site-accent/30 ring-white/10">
                        <Check className="size-8" aria-hidden />
                    </span>
                    <h1 className={`mt-8 text-4xl sm:text-5xl ${display}`}>
                        Thank you
                    </h1>
                    <p className="mt-4 text-lg text-slate-300">
                        We have your message and will be in touch within one
                        working day.
                    </p>
                    <div className="mt-10 flex flex-wrap justify-center gap-3">
                        <Link href="/start" className={button.primary}>
                            Start free trial
                        </Link>
                        <Link href="/" className={button.outlineDark}>
                            Back to the home page
                        </Link>
                    </div>
                </div>
            </section>
        </>
    );
}
