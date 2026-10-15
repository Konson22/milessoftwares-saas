import { Label } from '@/shared/components/ui/label';
import LeadForm, { Field } from '@/sites/public/components/lead-form';
import PageHero from '@/sites/public/components/page-hero';
import Seo from '@/sites/public/components/seo';

type Props = {
    products: { code: string; name: string }[];
    selected: string | null;
    formToken: string;
    canonical: string;
};

export default function Demo({
    products,
    selected,
    formToken,
    canonical,
}: Props) {
    return (
        <>
            <Seo
                title="Book a demo"
                description="See Miles Global Softwares applications with your own workflows. We reply within one working day."
                canonical={canonical}
            />

            <PageHero
                padding="pt-16 pb-36 sm:pt-20 sm:pb-40"
                className="text-center [&_h1]:mx-auto [&_p]:mx-auto"
                title="Book a demo"
                intro="Tell us about your organization and we will set up a walkthrough."
            />

            <section
                aria-label="Demo request"
                className="mx-auto max-w-3xl px-4 pb-24"
            >
                <div className="relative -mt-24 rounded-2xl border bg-card p-6 shadow-xl sm:p-10">
                    <LeadForm
                        action="/demo"
                        formToken={formToken}
                        submitLabel="Request a demo"
                    >
                        {() => (
                            <div className="grid gap-5 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="product">Application</Label>
                                    <select
                                        id="product"
                                        name="product"
                                        defaultValue={selected ?? ''}
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                    >
                                        <option value="">Not sure yet</option>
                                        {products.map((product) => (
                                            <option
                                                key={product.code}
                                                value={product.code}
                                            >
                                                {product.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <Field
                                    name="preferred_contact_time"
                                    label="Best time to reach you"
                                />
                            </div>
                        )}
                    </LeadForm>
                </div>
            </section>
        </>
    );
}
