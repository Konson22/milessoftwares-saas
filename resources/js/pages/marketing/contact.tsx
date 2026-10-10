import LeadForm, { Field } from '@/components/marketing/lead-form';
import Seo from '@/components/marketing/seo';

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

            <section className="mx-auto max-w-2xl px-4 py-16">
                <h1 className="text-4xl font-semibold tracking-tight">
                    Contact us
                </h1>
                <p className="mt-4 text-lg text-muted-foreground">
                    We reply within one working day.
                </p>

                <div className="mt-10">
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
