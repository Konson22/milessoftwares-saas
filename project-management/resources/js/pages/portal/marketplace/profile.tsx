import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid } from '@/components/field';
import {
    CheckboxGroup,
    PublicationBadge,
    TextArea,
} from '@/components/marketplace';
import { formatDate } from '@/lib/status';
import { logo, publish, unpublish, update } from '@/routes/public-profile';
import { show as logoUrl } from '@/routes/public-profile/logo';
import type { Option } from '@/types';

type Profile = {
    status: string;
    summary: string | null;
    description: string | null;
    mission: string | null;
    vision: string | null;
    country_code: string | null;
    location: string | null;
    operating_areas: string;
    sectors: string[];
    registration_number: string | null;
    registration_authority: string | null;
    website: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    achievements: string | null;
    hasLogo: boolean;
    publishedAt: string | null;
    documents: string[];
};

const keep = { preserveScroll: true };

/**
 * What donors find about the organization in the funding marketplace. Typed
 * here for the public: nothing private is read into it.
 */
export default function PublicProfile({
    organization,
    profile,
    sectors,
    documents,
}: {
    organization: { name: string; slug: string };
    profile: Profile;
    sectors: Option[];
    documents: Option[];
}) {
    const { errors } = usePage().props;
    const published = profile.status === 'published';

    return (
        <>
            <Head title="Public profile" />
            <PageHeader
                title="Public profile"
                description="What donors see about your organization in the funding marketplace. Nothing else of your workspace is shown."
            />

            <div className="grid gap-4">
                <Panel>
                    <PanelBody className="flex flex-wrap items-center justify-between gap-4">
                        <div className="grid gap-1">
                            <div className="flex items-center gap-2">
                                <span className="font-medium">
                                    {organization.name}
                                </span>
                                <PublicationBadge status={profile.status} />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {published
                                    ? `Donors can find it${profile.publishedAt ? ` since ${formatDate(profile.publishedAt)}` : ''}. Unpublishing also hides your listings.`
                                    : 'Only your organization sees it. Publish it when it is ready.'}
                            </p>
                            {errors.publish && (
                                <p className="text-sm text-destructive">
                                    {errors.publish}
                                </p>
                            )}
                        </div>
                        <Form
                            {...(published ? unpublish.form() : publish.form())}
                            options={keep}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant={published ? 'outline' : 'default'}
                                    disabled={processing}
                                >
                                    {published ? 'Unpublish' : 'Publish'}
                                </Button>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Logo"
                        description="PNG, JPG or WebP, up to 1 MB."
                    />
                    <PanelBody className="flex flex-wrap items-center gap-4">
                        {profile.hasLogo && (
                            <img
                                src={logoUrl.url()}
                                alt={`${organization.name} logo`}
                                className="size-16 rounded-md border object-contain"
                            />
                        )}
                        <Form
                            {...logo.form()}
                            options={keep}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="logo"
                                        label="Logo file"
                                        error={errors.logo}
                                    >
                                        <Input
                                            id="logo"
                                            name="logo"
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            required
                                            {...invalid('logo', errors.logo)}
                                        />
                                    </Field>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Upload
                                    </Button>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>

                <Form {...update.form()} options={keep} className="grid gap-4">
                    {({ errors, processing }) => (
                        <>
                            <Panel>
                                <PanelHeader title="About" />
                                <PanelBody className="grid gap-4">
                                    <Field
                                        id="summary"
                                        label="Summary"
                                        error={errors.summary}
                                        hint="One or two sentences, shown in search results."
                                    >
                                        <Input
                                            id="summary"
                                            name="summary"
                                            maxLength={300}
                                            defaultValue={profile.summary ?? ''}
                                            {...invalid(
                                                'summary',
                                                errors.summary,
                                            )}
                                        />
                                    </Field>
                                    <Field
                                        id="description"
                                        label="Description"
                                        error={errors.description}
                                    >
                                        <TextArea
                                            id="description"
                                            name="description"
                                            rows={6}
                                            defaultValue={
                                                profile.description ?? ''
                                            }
                                        />
                                    </Field>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            id="mission"
                                            label="Mission"
                                            error={errors.mission}
                                        >
                                            <TextArea
                                                id="mission"
                                                name="mission"
                                                rows={3}
                                                defaultValue={
                                                    profile.mission ?? ''
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id="vision"
                                            label="Vision"
                                            error={errors.vision}
                                        >
                                            <TextArea
                                                id="vision"
                                                name="vision"
                                                rows={3}
                                                defaultValue={
                                                    profile.vision ?? ''
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <Field
                                        id="achievements"
                                        label="Previous projects and achievements"
                                        error={errors.achievements}
                                    >
                                        <TextArea
                                            id="achievements"
                                            name="achievements"
                                            rows={4}
                                            defaultValue={
                                                profile.achievements ?? ''
                                            }
                                        />
                                    </Field>
                                </PanelBody>
                            </Panel>

                            <Panel>
                                <PanelHeader title="Where and what" />
                                <PanelBody className="grid gap-4">
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <Field
                                            id="country_code"
                                            label="Country code"
                                            error={errors.country_code}
                                            hint="Two letters, e.g. SS."
                                        >
                                            <Input
                                                id="country_code"
                                                name="country_code"
                                                maxLength={2}
                                                className="uppercase"
                                                defaultValue={
                                                    profile.country_code ?? ''
                                                }
                                                {...invalid(
                                                    'country_code',
                                                    errors.country_code,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="location"
                                            label="Location"
                                            error={errors.location}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="location"
                                                name="location"
                                                defaultValue={
                                                    profile.location ?? ''
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <Field
                                        id="operating_areas"
                                        label="Operating areas"
                                        error={errors.operating_areas}
                                        hint="Separate them with commas."
                                    >
                                        <Input
                                            id="operating_areas"
                                            name="operating_areas"
                                            defaultValue={
                                                profile.operating_areas
                                            }
                                        />
                                    </Field>
                                    <fieldset className="grid gap-2">
                                        <legend className="mb-1 text-sm font-medium">
                                            Sectors
                                        </legend>
                                        <CheckboxGroup
                                            name="sectors"
                                            options={sectors}
                                            selected={profile.sectors}
                                        />
                                        {errors.sectors && (
                                            <p className="text-sm text-destructive">
                                                {errors.sectors}
                                            </p>
                                        )}
                                    </fieldset>
                                </PanelBody>
                            </Panel>

                            <Panel>
                                <PanelHeader title="Registration and contact" />
                                <PanelBody className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        id="registration_number"
                                        label="Registration number"
                                        error={errors.registration_number}
                                    >
                                        <Input
                                            id="registration_number"
                                            name="registration_number"
                                            defaultValue={
                                                profile.registration_number ??
                                                ''
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="registration_authority"
                                        label="Registered with"
                                        error={errors.registration_authority}
                                    >
                                        <Input
                                            id="registration_authority"
                                            name="registration_authority"
                                            defaultValue={
                                                profile.registration_authority ??
                                                ''
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="website"
                                        label="Website"
                                        error={errors.website}
                                    >
                                        <Input
                                            id="website"
                                            name="website"
                                            type="url"
                                            placeholder="https://"
                                            defaultValue={profile.website ?? ''}
                                            {...invalid(
                                                'website',
                                                errors.website,
                                            )}
                                        />
                                    </Field>
                                    <Field
                                        id="contact_email"
                                        label="Contact email"
                                        error={errors.contact_email}
                                    >
                                        <Input
                                            id="contact_email"
                                            name="contact_email"
                                            type="email"
                                            defaultValue={
                                                profile.contact_email ?? ''
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="contact_phone"
                                        label="Contact phone"
                                        error={errors.contact_phone}
                                    >
                                        <Input
                                            id="contact_phone"
                                            name="contact_phone"
                                            defaultValue={
                                                profile.contact_phone ?? ''
                                            }
                                        />
                                    </Field>
                                </PanelBody>
                            </Panel>

                            <Panel>
                                <PanelHeader
                                    title="Public documents"
                                    description="Shown with your profile for donors to download. Confidential and archived documents are never offered."
                                />
                                <PanelBody className="grid gap-2">
                                    <CheckboxGroup
                                        name="documents"
                                        options={documents}
                                        selected={profile.documents}
                                        empty="No documents to choose from. Add them in Documents."
                                    />
                                    {errors.documents && (
                                        <p className="text-sm text-destructive">
                                            {errors.documents}
                                        </p>
                                    )}
                                </PanelBody>
                            </Panel>

                            <div>
                                <Button type="submit" disabled={processing}>
                                    Save profile
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
