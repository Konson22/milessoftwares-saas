import LeadForm, { Field } from '@/sites/public/components/lead-form';
import PageHero from '@/sites/public/components/page-hero';
import Seo from '@/sites/public/components/seo';

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
                padding="pt-16 pb-36 sm:pt-20 sm:pb-40"
                className="text-center [&_h1]:mx-auto [&_p]:mx-auto"
                title="Contact us"
                intro="We reply within one working day."
            />

            <section
                aria-label="Message"
                className="mx-auto max-w-3xl px-4 pb-24"
            >
                <div className="relative -mt-24 rounded-2xl border bg-card p-6 shadow-xl sm:p-10">
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
