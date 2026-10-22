import { Head, Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import PageHero from '@/sites/public/components/page-hero';
import { button } from '@/sites/public/components/styles';

export default function Thanks() {
    return (
        <>
            <Head title="Thank you">
                <meta head-key="robots" name="robots" content="noindex" />
            </Head>

            <PageHero
                above={
                    <span className="mb-8 inline-flex size-16 items-center justify-center rounded-full bg-white text-site-accent-ink">
                        <Check className="size-8" aria-hidden />
                    </span>
                }
                title="Thank you"
                intro="We have your message and will be in touch within one working day."
                padding="pt-40 pb-28 sm:pt-52 sm:pb-36"
            >
                <div className="mt-10 flex flex-wrap justify-center gap-3">
                    <Link href="/start" className={button.light}>
                        Start free trial
                    </Link>
                    <Link href="/" className={button.outlineDark}>
                        Back to the home page
                    </Link>
                </div>
            </PageHero>
        </>
    );
}
