import { Form, Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    CheckboxField,
    Field,
    SelectField,
    TextField,
    errorFor,
} from '@/mel/components/fields';
import type { Option } from '@/mel/lib/mel';
import {
    dimensions as saveDimension,
    locations as saveLocation,
    partners as savePartner,
    update,
} from '@/routes/mel/settings';

type Location = {
    id: string;
    name: string;
    code: string | null;
    parentId: string | null;
    path: string;
    archived: boolean;
};

type Partner = { id: string; name: string; kind: string; archived: boolean };

type Dimension = {
    id: string;
    name: string;
    isSensitive: boolean;
    archived: boolean;
    categories: { id: string; name: string; archived: boolean }[];
};

type Props = {
    settings: {
        provisioned: boolean;
        verifierMustDiffer: boolean;
        publisherMustDiffer: boolean;
        onTrackPercent: number;
        attentionPercent: number;
    };
    locations: Location[];
    partners: Partner[];
    dimensions: Dimension[];
    partnerKinds: Option[];
};

export default function Settings({
    settings,
    locations,
    partners,
    dimensions,
    partnerKinds,
}: Props) {
    return (
        <>
            <Head title="Settings" />
            <PageHeader title="Settings" />

            <div className="grid max-w-4xl gap-3">
                <Panel>
                    <PanelHeader
                        title="Review and performance"
                        description="How results are checked, and when an indicator counts as on track."
                    />
                    {settings.provisioned ? (
                        <Form
                            {...update.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ errors, processing }) => (
                                <PanelBody className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-3 sm:col-span-2">
                                        <CheckboxField
                                            name="verifier_must_differ"
                                            label="Someone other than the submitter verifies each result"
                                            defaultChecked={
                                                settings.verifierMustDiffer
                                            }
                                        />
                                        <CheckboxField
                                            name="publisher_must_differ"
                                            label="Someone other than the verifier publishes it"
                                            defaultChecked={
                                                settings.publisherMustDiffer
                                            }
                                        />
                                    </div>
                                    <TextField
                                        name="on_track_percent"
                                        label="On track from (% of target)"
                                        type="number"
                                        defaultValue={settings.onTrackPercent}
                                        error={errors.on_track_percent}
                                    />
                                    <TextField
                                        name="attention_percent"
                                        label="Needs attention from (% of target)"
                                        hint="Below this, off track."
                                        type="number"
                                        defaultValue={settings.attentionPercent}
                                        error={errors.attention_percent}
                                    />
                                    <div className="sm:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save
                                        </Button>
                                    </div>
                                </PanelBody>
                            )}
                        </Form>
                    ) : (
                        <PanelBody className="text-sm text-muted-foreground">
                            M&E is still being set up for this workspace.
                        </PanelBody>
                    )}
                </Panel>

                <LocationsPanel locations={locations} />
                <PartnersPanel partners={partners} kinds={partnerKinds} />
                <DimensionsPanel dimensions={dimensions} />
            </div>
        </>
    );
}

function LocationsPanel({ locations }: { locations: Location[] }) {
    const [editing, setEditing] = useState<string | null>(null);
    const parents = (except: string | null) =>
        locations
            .filter((location) => !location.archived && location.id !== except)
            .map((location) => ({ value: location.id, label: location.path }));

    return (
        <Panel>
            <PanelHeader
                title="Locations"
                description="Countries, regions, districts and sites, nested as deep as you need."
                actions={
                    editing !== 'new' && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setEditing('new')}
                        >
                            <Plus aria-hidden />
                            Add
                        </Button>
                    )
                }
            />
            {editing === 'new' && (
                <LocationForm
                    location={null}
                    parents={parents(null)}
                    onDone={() => setEditing(null)}
                />
            )}
            {locations.length === 0 && editing !== 'new' ? (
                <p className="p-3 text-sm text-muted-foreground">None yet.</p>
            ) : (
                <ul className="divide-y text-sm">
                    {locations.map((location) => (
                        <li key={location.id}>
                            <div className="flex items-center justify-between gap-2 px-3 py-2">
                                <span>
                                    {location.path}
                                    {location.code && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {location.code}
                                        </span>
                                    )}
                                    {location.archived && (
                                        <StatusBadge className="ml-2">
                                            Archived
                                        </StatusBadge>
                                    )}
                                </span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        setEditing(
                                            editing === location.id
                                                ? null
                                                : location.id,
                                        )
                                    }
                                >
                                    Edit
                                </Button>
                            </div>
                            {editing === location.id && (
                                <LocationForm
                                    location={location}
                                    parents={parents(location.id)}
                                    onDone={() => setEditing(null)}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function LocationForm({
    location,
    parents,
    onDone,
}: {
    location: Location | null;
    parents: Option[];
    onDone: () => void;
}) {
    const prefix = `location-${location?.id ?? 'new'}-`;

    return (
        <Form
            {...saveLocation.form(location ? { location: location.id } : {})}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid gap-3 border-y bg-muted/30 p-3 sm:grid-cols-3"
        >
            {({ errors, processing }) => (
                <>
                    <TextField
                        name="name"
                        label="Name"
                        defaultValue={location?.name}
                        error={errors.name}
                        prefix={prefix}
                        required
                    />
                    <TextField
                        name="code"
                        label="Code"
                        defaultValue={location?.code}
                        error={errors.code}
                        prefix={prefix}
                    />
                    <SelectField
                        name="parent_id"
                        label="Inside"
                        options={parents}
                        empty="Nothing (top level)"
                        defaultValue={location?.parentId}
                        error={errors.parent_id}
                        prefix={prefix}
                    />
                    {location && (
                        <CheckboxField
                            name="archived"
                            label="Archived"
                            defaultChecked={location.archived}
                            prefix={prefix}
                        />
                    )}
                    <div className="flex gap-2 sm:col-span-3">
                        <Button type="submit" size="sm" disabled={processing}>
                            Save
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function PartnersPanel({
    partners,
    kinds,
}: {
    partners: Partner[];
    kinds: Option[];
}) {
    const [editing, setEditing] = useState<string | null>(null);
    const kindLabel = (kind: string) =>
        kinds.find((option) => option.value === kind)?.label ?? kind;

    return (
        <Panel>
            <PanelHeader
                title="Implementing partners"
                actions={
                    editing !== 'new' && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setEditing('new')}
                        >
                            <Plus aria-hidden />
                            Add
                        </Button>
                    )
                }
            />
            {editing === 'new' && (
                <PartnerForm
                    partner={null}
                    kinds={kinds}
                    onDone={() => setEditing(null)}
                />
            )}
            {partners.length === 0 && editing !== 'new' ? (
                <p className="p-3 text-sm text-muted-foreground">None yet.</p>
            ) : (
                <ul className="divide-y text-sm">
                    {partners.map((partner) => (
                        <li key={partner.id}>
                            <div className="flex items-center justify-between gap-2 px-3 py-2">
                                <span>
                                    {partner.name}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {kindLabel(partner.kind)}
                                    </span>
                                    {partner.archived && (
                                        <StatusBadge className="ml-2">
                                            Archived
                                        </StatusBadge>
                                    )}
                                </span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        setEditing(
                                            editing === partner.id
                                                ? null
                                                : partner.id,
                                        )
                                    }
                                >
                                    Edit
                                </Button>
                            </div>
                            {editing === partner.id && (
                                <PartnerForm
                                    partner={partner}
                                    kinds={kinds}
                                    onDone={() => setEditing(null)}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function PartnerForm({
    partner,
    kinds,
    onDone,
}: {
    partner: Partner | null;
    kinds: Option[];
    onDone: () => void;
}) {
    const prefix = `partner-${partner?.id ?? 'new'}-`;

    return (
        <Form
            {...savePartner.form(partner ? { partner: partner.id } : {})}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid gap-3 border-y bg-muted/30 p-3 sm:grid-cols-3"
        >
            {({ errors, processing }) => (
                <>
                    <TextField
                        name="name"
                        label="Name"
                        defaultValue={partner?.name}
                        error={errors.name}
                        prefix={prefix}
                        required
                    />
                    <SelectField
                        name="kind"
                        label="Kind"
                        options={kinds}
                        defaultValue={partner?.kind ?? 'ngo'}
                        error={errors.kind}
                        prefix={prefix}
                    />
                    {partner && (
                        <CheckboxField
                            name="archived"
                            label="Archived"
                            defaultChecked={partner.archived}
                            prefix={prefix}
                        />
                    )}
                    <div className="flex gap-2 sm:col-span-3">
                        <Button type="submit" size="sm" disabled={processing}>
                            Save
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function DimensionsPanel({ dimensions }: { dimensions: Dimension[] }) {
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <Panel>
            <PanelHeader
                title="Disaggregation dimensions"
                description="Groups results are broken down by: sex, age group, disability and the like. Aggregate counts only; never people's dates of birth."
                actions={
                    editing !== 'new' && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setEditing('new')}
                        >
                            <Plus aria-hidden />
                            Add
                        </Button>
                    )
                }
            />
            {editing === 'new' && (
                <DimensionForm
                    dimension={null}
                    onDone={() => setEditing(null)}
                />
            )}
            <ul className="divide-y text-sm">
                {dimensions.map((dimension) => (
                    <li key={dimension.id}>
                        <div className="flex items-center justify-between gap-2 px-3 py-2">
                            <span>
                                <span className="font-medium">
                                    {dimension.name}
                                </span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    ·{' '}
                                    {dimension.categories
                                        .filter(
                                            (category) => !category.archived,
                                        )
                                        .map((category) => category.name)
                                        .join(', ')}
                                </span>
                                {dimension.isSensitive && (
                                    <StatusBadge
                                        tone="warning"
                                        className="ml-2"
                                    >
                                        Sensitive
                                    </StatusBadge>
                                )}
                                {dimension.archived && (
                                    <StatusBadge className="ml-2">
                                        Archived
                                    </StatusBadge>
                                )}
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    setEditing(
                                        editing === dimension.id
                                            ? null
                                            : dimension.id,
                                    )
                                }
                            >
                                Edit
                            </Button>
                        </div>
                        {editing === dimension.id && (
                            <DimensionForm
                                dimension={dimension}
                                onDone={() => setEditing(null)}
                            />
                        )}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

function DimensionForm({
    dimension,
    onDone,
}: {
    dimension: Dimension | null;
    onDone: () => void;
}) {
    const [categories, setCategories] = useState<
        { key: string; id: string | null; name: string; archived: boolean }[]
    >(
        dimension?.categories.map((category) => ({
            key: category.id,
            ...category,
        })) ?? [
            { key: 'a', id: null, name: '', archived: false },
            { key: 'b', id: null, name: '', archived: false },
        ],
    );
    const prefix = `dimension-${dimension?.id ?? 'new'}-`;

    return (
        <Form
            {...saveDimension.form(
                dimension ? { dimension: dimension.id } : {},
            )}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid gap-3 border-y bg-muted/30 p-3"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <TextField
                            name="name"
                            label="Name"
                            defaultValue={dimension?.name}
                            error={errors.name}
                            prefix={prefix}
                            required
                        />
                        <div className="grid content-end gap-2">
                            <CheckboxField
                                name="is_sensitive"
                                label="Sensitive (disability, displacement and the like)"
                                defaultChecked={dimension?.isSensitive}
                                prefix={prefix}
                            />
                            {dimension && (
                                <CheckboxField
                                    name="archived"
                                    label="Archived"
                                    defaultChecked={dimension.archived}
                                    prefix={prefix}
                                />
                            )}
                        </div>
                    </div>
                    <Field
                        id={`${prefix}cat-0`}
                        label="Categories, in order"
                        hint="Categories in use are archived, not deleted."
                        error={errorFor(errors, 'categories')}
                    >
                        <ul className="grid gap-2">
                            {categories.map((category, index) => (
                                <li
                                    key={category.key}
                                    className="flex items-center gap-2"
                                >
                                    {category.id && (
                                        <input
                                            type="hidden"
                                            name={`categories[${index}][id]`}
                                            value={category.id}
                                        />
                                    )}
                                    <input
                                        type="hidden"
                                        name={`categories[${index}][archived]`}
                                        value={category.archived ? '1' : '0'}
                                    />
                                    <Input
                                        id={`${prefix}cat-${index}`}
                                        name={`categories[${index}][name]`}
                                        defaultValue={category.name}
                                        aria-label={`Category ${index + 1}`}
                                        className={
                                            category.archived
                                                ? 'text-muted-foreground line-through'
                                                : ''
                                        }
                                    />
                                    {category.id ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setCategories(
                                                    categories.map((item) =>
                                                        item.key ===
                                                        category.key
                                                            ? {
                                                                  ...item,
                                                                  archived:
                                                                      !item.archived,
                                                              }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        >
                                            {category.archived
                                                ? 'Restore'
                                                : 'Archive'}
                                        </Button>
                                    ) : (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setCategories(
                                                    categories.filter(
                                                        (item) =>
                                                            item.key !==
                                                            category.key,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Field>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setCategories([
                                    ...categories,
                                    {
                                        key: `new-${categories.length}-${Date.now()}`,
                                        id: null,
                                        name: '',
                                        archived: false,
                                    },
                                ])
                            }
                        >
                            Add a category
                        </Button>
                        <Button type="submit" size="sm" disabled={processing}>
                            Save
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
