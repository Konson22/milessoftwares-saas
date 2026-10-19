import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { TextField } from '@/components/fields';
import { show } from '@/routes/employees';
import { update } from '@/routes/employees/personal';

type Props = {
    employee: { id: string; number: string; name: string; status: string };
    fields: { key: string; label: string }[];
    values: Record<string, string | null>;
    canManage: boolean;
};

// Groups of fields, by key prefix order in PersonalDetails::FIELDS.
const GROUPS: { title: string; keys: string[] }[] = [
    {
        title: 'Identity',
        keys: [
            'date_of_birth',
            'gender',
            'nationality',
            'national_id',
            'passport_number',
            'tax_id',
            'social_security_number',
        ],
    },
    {
        title: 'Home contacts',
        keys: ['personal_phone', 'personal_email', 'home_address'],
    },
    {
        title: 'Next of kin',
        keys: [
            'next_of_kin_name',
            'next_of_kin_relationship',
            'next_of_kin_phone',
        ],
    },
    {
        title: 'Bank account',
        keys: [
            'bank_name',
            'bank_branch',
            'bank_account_name',
            'bank_account_number',
        ],
    },
];

const TYPES: Record<string, string> = {
    date_of_birth: 'date',
    personal_email: 'email',
};

export default function PersonalDetails({
    employee,
    fields,
    values,
    canManage,
}: Props) {
    const [editing, setEditing] = useState(false);
    const labels = Object.fromEntries(
        fields.map((field) => [field.key, field.label]),
    );

    return (
        <>
            <Head title={`${employee.name}: personal details`} />
            <PageHeader
                title="Personal details"
                description={
                    <>
                        <Link
                            href={show(employee.id)}
                            className="hover:underline"
                        >
                            {employee.name}
                        </Link>{' '}
                        · {employee.number} · Opening this page is recorded in
                        the HR audit log.
                    </>
                }
                actions={
                    canManage && !editing ? (
                        <Button
                            variant="outline"
                            onClick={() => setEditing(true)}
                        >
                            Edit
                        </Button>
                    ) : undefined
                }
            />

            {editing ? (
                <Form
                    {...update.form(employee.id)}
                    onSuccess={() => setEditing(false)}
                    className="grid gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            {GROUPS.map((group) => (
                                <Panel key={group.title}>
                                    <PanelHeader title={group.title} />
                                    <PanelBody className="grid gap-3 sm:grid-cols-4">
                                        {group.keys.map((key) => (
                                            <TextField
                                                key={key}
                                                name={key}
                                                label={labels[key] ?? key}
                                                type={TYPES[key] ?? 'text'}
                                                defaultValue={values[key]}
                                                error={errors[key]}
                                            />
                                        ))}
                                    </PanelBody>
                                </Panel>
                            ))}
                            <div className="flex gap-2">
                                <Button type="submit" disabled={processing}>
                                    Save
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setEditing(false)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            ) : (
                <div className="grid gap-3 lg:grid-cols-2">
                    {GROUPS.map((group) => (
                        <Panel key={group.title}>
                            <PanelHeader title={group.title} />
                            <PanelBody>
                                <dl className="grid grid-cols-2 gap-3 text-sm">
                                    {group.keys.map((key) => (
                                        <div key={key}>
                                            <dt className="text-xs text-muted-foreground">
                                                {labels[key] ?? key}
                                            </dt>
                                            <dd className="mt-0.5">
                                                {values[key] ?? '—'}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </PanelBody>
                        </Panel>
                    ))}
                </div>
            )}
        </>
    );
}
