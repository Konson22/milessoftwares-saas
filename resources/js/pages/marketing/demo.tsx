import { Label } from '@/components/ui/label';
import LeadForm, { Field } from '@/components/marketing/lead-form';
import Seo from '@/components/marketing/seo';

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

            <section className="mx-auto max-w-2xl px-4 py-16">
                <h1 className="text-4xl font-semibold tracking-tight">
                    Book a demo
                </h1>
                <p className="mt-4 text-lg text-muted-foreground">
                    Tell us about your organization and we will set up a
                    walkthrough.
                </p>

                <div className="mt-10">
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
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
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
