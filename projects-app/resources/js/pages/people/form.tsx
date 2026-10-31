import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid } from '@/components/field';
import { index, store, update } from '@/routes/people';

type Person = {
    id: string;
    name: string;
    email: string | null;
    title: string | null;
    member: boolean;
    hourlyCost: string;
    active: boolean;
};

/**
 * Adding a contractor, or changing someone's role and cost.
 */
export default function PersonForm({ person }: { person: Person | null }) {
    const action = person ? update.form(person.id) : store.form();
    const title = person ? person.name : 'Add a contractor';
    // Members' names and emails come from their platform account.
    const fixed = person?.member ?? false;

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← People</Link>
                    </Button>
                }
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                    hint={
                                        fixed
                                            ? 'From their workspace account.'
                                            : undefined
                                    }
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        readOnly={fixed}
                                        defaultValue={person?.name}
                                        {...invalid('name', errors.name)}
                                    />
                                </Field>
                                <Field
                                    id="email"
                                    label="Email"
                                    error={errors.email}
                                >
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        readOnly={fixed}
                                        defaultValue={person?.email ?? ''}
                                        {...invalid('email', errors.email)}
                                    />
                                </Field>
                                <Field
                                    id="title"
                                    label="Role"
                                    error={errors.title}
                                >
                                    <Input
                                        id="title"
                                        name="title"
                                        placeholder="Engineer, designer..."
                                        defaultValue={person?.title ?? ''}
                                    />
                                </Field>
                                <Field
                                    id="hourly_cost"
                                    label="Cost per hour"
                                    error={errors.hourly_cost}
                                    hint="Prices their logged time into project costs."
                                >
                                    <Input
                                        id="hourly_cost"
                                        name="hourly_cost"
                                        inputMode="decimal"
                                        defaultValue={person?.hourlyCost ?? ''}
                                        {...invalid(
                                            'hourly_cost',
                                            errors.hourly_cost,
                                        )}
                                    />
                                </Field>
                                {person && (
                                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                        <input
                                            type="hidden"
                                            name="active"
                                            value="0"
                                        />
                                        <input
                                            type="checkbox"
                                            name="active"
                                            value="1"
                                            defaultChecked={person.active}
                                        />
                                        Active: can be put on projects and
                                        assigned tasks
                                    </label>
                                )}
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {person ? 'Save' : 'Add contractor'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            </Panel>
        </>
    );
}
