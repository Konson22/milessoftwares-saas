import { Head, Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { HeroBackdrop } from '@/sites/public/components/page-hero';
import { button } from '@/sites/public/components/styles';

export default function Thanks() {
    return (
        <>
            <Head title="Thank you">
                <meta head-key="robots" name="robots" content="noindex" />
            </Head>

            <section className="relative isolate bg-zinc-950">
                <HeroBackdrop />
                <div className="mx-auto flex min-h-[60vh] max-w-2xl flex-col items-center justify-center px-4 py-24 text-center">
                    <span className="inline-flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg ring-8 shadow-primary/40 ring-primary/15">
                        <Check className="size-7" aria-hidden />
                    </span>
                    <h1 className="mt-8 text-4xl font-semibold tracking-tight text-white">
                        Thank you
                    </h1>
                    <p className="mt-4 text-lg text-zinc-400">
                        We have your message and will be in touch within one
                        working day.
                    </p>
                    <Link href="/" className={`mt-10 ${button.outlineDark}`}>
                        Back to the home page
                    </Link>
                </div>
            </section>
        </>
    );
}
