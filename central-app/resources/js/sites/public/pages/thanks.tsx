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
                    <span className="inline-flex size-12 items-center justify-center rounded-full bg-white text-site-accent-ink">
                        <Check className="size-6" aria-hidden />
                    </span>
                }
                title="Thank you"
                intro="We have your message and will be in touch within one working day. A confirmation is on its way to your inbox."
            >
                <Link href="/" className={button.light}>
                    Back to the home page
                </Link>
            </PageHero>
        </>
    );
}
