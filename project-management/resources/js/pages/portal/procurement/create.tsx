import { Form, Head, Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { index, store } from '@/routes/procurement';
import type { Option } from '@/types';

type Props = {
    projects: (Option & { currency: string; grants: Option[] })[];
    categories: Option[];
};

/** Ask to buy something: what, how much, and why. Saved as a draft. */
export default function ProcurementCreate({ projects, categories }: Props) {
    const [projectId, setProjectId] = useState('');
    // Each row keeps its key, so removing one doesn't shift what was typed.
    const [rows, setRows] = useState([0]);
    const project = projects.find((option) => option.value === projectId);

    return (
        <>
            <Head title="New purchase request" />
            <PageHeader
                title="New purchase request"
                description="Prices here are your estimate. Quotes come after approval."
                actions={
                    <Link href={index()} className="text-sm underline">
                        Back to procurement
                    </Link>
                }
            />

            <Form {...store.form()} className="space-y-6">
                {({ errors, processing }) => (
                    <>
                        <Panel>
                            <PanelHeader title="What and why" />
                            <PanelBody className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="project_id"
                                    label="Project"
                                    error={errors.project_id}
                                >
                                    <Select
                                        id="project_id"
                                        name="project_id"
                                        required
                                        value={projectId}
                                        onChange={(event) =>
                                            setProjectId(event.target.value)
                                        }
                                        placeholder="Choose a project"
                                        options={projects}
                                    />
                                </Field>
                                <Field
                                    id="grant_id"
                                    label="Charged to grant"
                                    hint="Leave empty if no grant pays for this."
                                    error={errors.grant_id}
                                >
                                    <Select
                                        key={projectId}
                                        id="grant_id"
                                        name="grant_id"
                                        placeholder="No grant"
                                        options={project?.grants ?? []}
                                    />
                                </Field>
                                <Field
                                    id="title"
                                    label="Title"
                                    error={errors.title}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="title"
                                        name="title"
                                        required
                                        maxLength={200}
                                        {...invalid('title', errors.title)}
                                    />
                                </Field>
                                <Field
                                    id="category"
                                    label="Budget category"
                                    error={errors.category}
                                >
                                    <Select
                                        id="category"
                                        name="category"
                                        required
                                        placeholder="Choose a category"
                                        options={categories}
                                    />
                                </Field>
                                <Field
                                    id="needed_by"
                                    label="Needed by"
                                    error={errors.needed_by}
                                >
                                    <Input
                                        id="needed_by"
                                        name="needed_by"
                                        type="date"
                                    />
                                </Field>
                                <Field
                                    id="justification"
                                    label="Why it is needed"
                                    error={errors.justification}
                                    className="sm:col-span-2"
                                >
                                    <textarea
                                        id="justification"
                                        name="justification"
                                        rows={3}
                                        required
                                        maxLength={5000}
                                        className="w-full border border-input bg-card px-2 py-1 text-sm"
                                    />
                                </Field>
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title="Items"
                                description={
                                    project
                                        ? `Prices in ${project.currency}.`
                                        : 'Prices in the project currency.'
                                }
                            />
                            <PanelBody className="space-y-3">
                                {errors.lines && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {errors.lines}
                                    </p>
                                )}
                                {rows.map((row) => {
                                    const name = (field: string) =>
                                        `lines[${row}][${field}]`;
                                    const error = (field: string) =>
                                        (errors as Record<string, string>)[
                                            `lines.${row}.${field}`
                                        ];

                                    return (
                                        <div
                                            key={row}
                                            className="grid items-start gap-2 sm:grid-cols-[1fr_6rem_6rem_8rem_auto]"
                                        >
                                            <Field
                                                id={`line-${row}-description`}
                                                label="Item"
                                                error={error('description')}
                                            >
                                                <Input
                                                    id={`line-${row}-description`}
                                                    name={name('description')}
                                                    required
                                                    maxLength={255}
                                                />
                                            </Field>
                                            <Field
                                                id={`line-${row}-quantity`}
                                                label="Quantity"
                                                error={error('quantity')}
                                            >
                                                <Input
                                                    id={`line-${row}-quantity`}
                                                    name={name('quantity')}
                                                    inputMode="decimal"
                                                    required
                                                />
                                            </Field>
                                            <Field
                                                id={`line-${row}-unit`}
                                                label="Unit"
                                                error={error('unit')}
                                            >
                                                <Input
                                                    id={`line-${row}-unit`}
                                                    name={name('unit')}
                                                    maxLength={32}
                                                />
                                            </Field>
                                            <Field
                                                id={`line-${row}-price`}
                                                label="Unit price"
                                                error={error('unit_price')}
                                            >
                                                <Input
                                                    id={`line-${row}-price`}
                                                    name={name('unit_price')}
                                                    inputMode="decimal"
                                                    required
                                                />
                                            </Field>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="mt-6"
                                                disabled={rows.length === 1}
                                                aria-label="Remove this item"
                                                onClick={() =>
                                                    setRows(
                                                        rows.filter(
                                                            (other) =>
                                                                other !== row,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    );
                                })}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setRows([
                                            ...rows,
                                            Math.max(...rows) + 1,
                                        ])
                                    }
                                >
                                    Add an item
                                </Button>
                            </PanelBody>
                        </Panel>

                        <Button type="submit" disabled={processing}>
                            Save draft
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}
