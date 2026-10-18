import { Form, Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import OrganizationSettingsController from '@/actions/App/Sites/Central/Http/Controllers/Dashboard/OrganizationSettingsController';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { dashboard } from '@/routes';
import { settings as organizationSettings } from '@/routes/organization';
import { formatDate, humanize } from '@/shared/lib/billing';

type Details = {
    display_name: string;
    legal_name: string;
    business_type: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    address: string | null;
    timezone: string;
    default_currency: string;
};

type Billing = {
    billing_email: string | null;
    billing_address: string | null;
    tax_id: string | null;
};

type Props = {
    details: Details;
    billing: Billing;
    organization: {
        displayName: string;
        slug: string | null;
        workspaceUrl: string | null;
        countryCode: string;
        status: string;
        createdAt: string | null;
    };
    timezones: string[];
    rootDomain: string;
    redirectGraceDays: number;
    previousSlugs: { slug: string; redirectsUntil: string | null }[];
};

const sections = [
    { id: 'business', title: 'Business' },
    { id: 'contact', title: 'Contact' },
    { id: 'regional', title: 'Regional' },
    { id: 'billing', title: 'Billing details' },
    { id: 'address', title: 'Workspace address' },
];

const currencies = [
    'SSP',
    'USD',
    'EUR',
    'GBP',
    'KES',
    'UGX',
    'TZS',
    'ETB',
    'SDG',
    'RWF',
];

const fieldClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

export default function OrganizationSettings({
    details,
    billing,
    organization,
    timezones,
    rootDomain,
    redirectGraceDays,
    previousSlugs,
}: Props) {
    return (
        <>
            <Head title={`${organization.displayName} settings`} />

            <PageHeader
                title="General settings"
                description={`How ${organization.displayName} appears to members, on documents and on invoices.`}
                badges={
                    <StatusBadge
                        tone={
                            organization.status === 'active'
                                ? 'success'
                                : 'danger'
                        }
                    >
                        {humanize(organization.status)}
                    </StatusBadge>
                }
            />

            <div className="flex flex-col gap-3 lg:flex-row lg:gap-4">
                <aside className="shrink-0 lg:w-52">
                    <nav
                        aria-label="Settings sections"
                        className="flex gap-1 overflow-x-auto rounded-md border bg-card p-1.5 lg:sticky lg:top-4 lg:flex-col"
                    >
                        {sections.map((section) => (
                            <a
                                key={section.id}
                                href={`#${section.id}`}
                                className="rounded-md px-3 py-2 text-sm whitespace-nowrap text-muted-foreground hover:bg-muted hover:text-foreground"
                            >
                                {section.title}
                            </a>
                        ))}
                    </nav>
                    <dl className="mt-4 hidden space-y-2 px-1 text-xs text-muted-foreground lg:block">
                        <div>
                            <dt>Country</dt>
                            <dd className="text-foreground">
                                {organization.countryCode}
                            </dd>
                        </div>
                        <div>
                            <dt>Created</dt>
                            <dd className="text-foreground">
                                {formatDate(organization.createdAt)}
                            </dd>
                        </div>
                    </dl>
                </aside>

                <div className="max-w-3xl min-w-0 flex-1 space-y-3">
                    <Form
                        {...OrganizationSettingsController.updateDetails.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Section
                                    id="business"
                                    title="Business"
                                    description="Your business name is what members see; the legal name goes on documents."
                                >
                                    <Field
                                        name="display_name"
                                        label="Business name"
                                        error={errors.display_name}
                                    >
                                        <Input
                                            id="display_name"
                                            name="display_name"
                                            required
                                            maxLength={120}
                                            defaultValue={details.display_name}
                                        />
                                    </Field>
                                    <Field
                                        name="legal_name"
                                        label="Registered legal name"
                                        error={errors.legal_name}
                                    >
                                        <Input
                                            id="legal_name"
                                            name="legal_name"
                                            required
                                            maxLength={200}
                                            defaultValue={details.legal_name}
                                        />
                                    </Field>
                                    <Field
                                        name="business_type"
                                        label="Business type"
                                        error={errors.business_type}
                                    >
                                        <Input
                                            id="business_type"
                                            name="business_type"
                                            maxLength={48}
                                            placeholder="e.g. Pharmacy, Retail, School"
                                            defaultValue={
                                                details.business_type ?? ''
                                            }
                                        />
                                    </Field>
                                </Section>

                                <Section
                                    id="contact"
                                    title="Contact"
                                    description="How customers and Miles Global Softwares reach your business."
                                >
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            name="contact_email"
                                            label="Email"
                                            error={errors.contact_email}
                                        >
                                            <Input
                                                id="contact_email"
                                                name="contact_email"
                                                type="email"
                                                defaultValue={
                                                    details.contact_email ?? ''
                                                }
                                            />
                                        </Field>
                                        <Field
                                            name="contact_phone"
                                            label="Phone"
                                            error={errors.contact_phone}
                                        >
                                            <Input
                                                id="contact_phone"
                                                name="contact_phone"
                                                type="tel"
                                                maxLength={32}
                                                defaultValue={
                                                    details.contact_phone ?? ''
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <Field
                                        name="address"
                                        label="Address"
                                        error={errors.address}
                                    >
                                        <textarea
                                            id="address"
                                            name="address"
                                            rows={3}
                                            maxLength={1000}
                                            className={textareaClass}
                                            defaultValue={details.address ?? ''}
                                        />
                                    </Field>
                                </Section>

                                <Section
                                    id="regional"
                                    title="Regional"
                                    description="Defaults for dates, times and amounts in your applications."
                                    footer={
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save details
                                        </Button>
                                    }
                                >
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            name="timezone"
                                            label="Time zone"
                                            error={errors.timezone}
                                        >
                                            <select
                                                id="timezone"
                                                name="timezone"
                                                required
                                                defaultValue={details.timezone}
                                                className={fieldClass}
                                            >
                                                {timezones.map((zone) => (
                                                    <option
                                                        key={zone}
                                                        value={zone}
                                                    >
                                                        {zone.replaceAll(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        <Field
                                            name="default_currency"
                                            label="Currency"
                                            error={errors.default_currency}
                                        >
                                            <Input
                                                id="default_currency"
                                                name="default_currency"
                                                required
                                                list="currencies"
                                                maxLength={3}
                                                className="uppercase"
                                                defaultValue={
                                                    details.default_currency
                                                }
                                            />
                                            <datalist id="currencies">
                                                {currencies.map((code) => (
                                                    <option
                                                        key={code}
                                                        value={code}
                                                    />
                                                ))}
                                            </datalist>
                                        </Field>
                                    </div>
                                </Section>
                            </>
                        )}
                    </Form>

                    <Form
                        {...OrganizationSettingsController.updateBilling.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <Section
                                id="billing"
                                title="Billing details"
                                description="Who invoices from Miles Global Softwares are addressed to. Leave a field empty to use your contact details."
                                footer={
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Save billing details
                                    </Button>
                                }
                            >
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        name="billing_email"
                                        label="Billing email"
                                        error={errors.billing_email}
                                    >
                                        <Input
                                            id="billing_email"
                                            name="billing_email"
                                            type="email"
                                            placeholder={
                                                details.contact_email ??
                                                'accounts@example.com'
                                            }
                                            defaultValue={
                                                billing.billing_email ?? ''
                                            }
                                        />
                                    </Field>
                                    <Field
                                        name="tax_id"
                                        label="Tax ID (TIN)"
                                        error={errors.tax_id}
                                    >
                                        <Input
                                            id="tax_id"
                                            name="tax_id"
                                            maxLength={64}
                                            defaultValue={billing.tax_id ?? ''}
                                        />
                                    </Field>
                                </div>
                                <Field
                                    name="billing_address"
                                    label="Billing address"
                                    error={errors.billing_address}
                                >
                                    <textarea
                                        id="billing_address"
                                        name="billing_address"
                                        rows={3}
                                        maxLength={1000}
                                        placeholder={details.address ?? ''}
                                        className={textareaClass}
                                        defaultValue={
                                            billing.billing_address ?? ''
                                        }
                                    />
                                </Field>
                            </Section>
                        )}
                    </Form>

                    <Form
                        {...OrganizationSettingsController.updateSlug.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                    >
                        {({ processing, errors }) => (
                            <Section
                                id="address"
                                title="Workspace address"
                                description={`After a change, the old address keeps redirecting for ${redirectGraceDays} days and can never be taken by another organization.`}
                                footer={
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Change address
                                    </Button>
                                }
                            >
                                {organization.workspaceUrl && (
                                    <p className="text-sm">
                                        Current:{' '}
                                        <a
                                            className="font-medium underline"
                                            href={organization.workspaceUrl}
                                        >
                                            {organization.slug}.{rootDomain}
                                        </a>
                                    </p>
                                )}
                                <Field
                                    name="slug"
                                    label="New address"
                                    error={errors.slug}
                                >
                                    <div className="flex items-center gap-2">
                                        <Input
                                            id="slug"
                                            name="slug"
                                            required
                                            autoComplete="off"
                                            spellCheck={false}
                                        />
                                        <span className="shrink-0 text-sm text-muted-foreground">
                                            .{rootDomain}
                                        </span>
                                    </div>
                                </Field>
                                {previousSlugs.length > 0 && (
                                    <div className="space-y-1 text-sm text-muted-foreground">
                                        <p className="font-medium text-foreground">
                                            Previous addresses
                                        </p>
                                        <ul>
                                            {previousSlugs.map((previous) => (
                                                <li key={previous.slug}>
                                                    {previous.slug}.{rootDomain}
                                                    {previous.redirectsUntil &&
                                                        ` — redirects until ${formatDate(previous.redirectsUntil)}`}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </Section>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

function Section({
    id,
    title,
    description,
    footer,
    children,
}: {
    id: string;
    title: string;
    description: string;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Panel id={id} className="scroll-mt-4">
            <PanelHeader title={title} description={description} />
            <PanelBody className="space-y-4">{children}</PanelBody>
            {footer && (
                <div className="flex justify-end border-t bg-muted/30 px-4 py-3">
                    {footer}
                </div>
            )}
        </Panel>
    );
}

function Field({
    name,
    label,
    error,
    children,
}: {
    name: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

OrganizationSettings.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'General settings', href: organizationSettings() },
    ],
};
