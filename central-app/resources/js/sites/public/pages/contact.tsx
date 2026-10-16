import { Link } from '@inertiajs/react';
import { ArrowRight, Clock, Rocket } from 'lucide-react';
import LeadForm, { Field } from '@/sites/public/components/lead-form';
import PageHero, {
    Accent,
    HeroBadge,
} from '@/sites/public/components/page-hero';
import Seo from '@/sites/public/components/seo';
import { button, card, iconChip } from '@/sites/public/components/styles';

type Props = {
    formToken: string;
    canonical: string;
};

export default function Contact({ formToken, canonical }: Props) {
    return (
        <>
            <Seo
                title="Contact us"
                description="Questions about Miles Global Softwares, pricing or partnerships? Send us a message."
                canonical={canonical}
            />

            <PageHero
                padding="pt-16 pb-36 sm:pt-24 sm:pb-40"
                eyebrow={<HeroBadge>Contact</HeroBadge>}
                title={
                    <>
                        Get in <Accent>touch</Accent>
                    </>
                }
                intro="Questions about an application, pricing or a partnership? Send us a message and we reply within one working day."
            />

            <section
                aria-label="Message"
                className="relative mx-auto -mt-24 grid max-w-7xl gap-5 px-4 pb-24 sm:px-6 lg:grid-cols-[20rem_1fr]"
            >
                <aside className="space-y-5">
                    <div className={`${card} p-7`}>
                        <span className={`${iconChip} size-12`}>
                            <Clock className="size-5" aria-hidden />
                        </span>
                        <p className="mt-5 text-lg font-semibold">
                            One working day
                        </p>
                        <p className="mt-1 text-sm text-slate-600">
                            Every message reaches our team, and we reply within
                            one working day.
                        </p>
                    </div>
                    <div className={`${card} p-7`}>
                        <span className={`${iconChip} size-12`}>
                            <Rocket className="size-5" aria-hidden />
                        </span>
                        <p className="mt-5 text-lg font-semibold">
                            Rather try it yourself?
                        </p>
                        <p className="mt-1 text-sm text-slate-600">
                            Every application starts with a free trial.
                        </p>
                        <Link
                            href="/start"
                            className={`mt-5 ${button.primary} py-2.5`}
                        >
                            Start free trial
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                </aside>

                <div className={`${card} p-6 sm:p-10`}>
                    <LeadForm
                        action="/contact"
                        formToken={formToken}
                        submitLabel="Send message"
                    >
                        {(errors) => (
                            <Field
                                name="subject"
                                label="Subject"
                                error={errors.subject}
                            />
                        )}
                    </LeadForm>
                </div>
            </section>
        </>
    );
}
