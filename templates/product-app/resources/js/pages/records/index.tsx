import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/records';

type Record = {
    id: string;
    title: string;
    createdBy: string | null;
    createdAt: string | null;
};

type Props = {
    records: Record[];
    canCreate: boolean;
};

export default function Records({ records, canCreate }: Props) {
    return (
        <>
            <Head title="Records" />
            <h1 className="text-2xl font-semibold">Records</h1>

            {canCreate && (
                <Form
                    {...store.form()}
                    resetOnSuccess
                    className="mt-6 flex flex-wrap items-end gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="title">Title</Label>
                                <Input
                                    id="title"
                                    name="title"
                                    required
                                    aria-invalid={
                                        errors.title ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.title ? 'title-error' : undefined
                                    }
                                />
                                {errors.title && (
                                    <p
                                        id="title-error"
                                        className="text-sm text-destructive"
                                    >
                                        {errors.title}
                                    </p>
                                )}
                            </div>
                            <Button type="submit" disabled={processing}>
                                Add record
                            </Button>
                        </>
                    )}
                </Form>
            )}

            <ul className="mt-6 divide-y rounded-md border">
                {records.length === 0 && (
                    <li className="px-4 py-3 text-sm text-muted-foreground">
                        No records yet.
                    </li>
                )}
                {records.map((record) => (
                    <li key={record.id} className="px-4 py-3">
                        <p className="font-medium">{record.title}</p>
                        <p className="text-sm text-muted-foreground">
                            {record.createdBy}
                        </p>
                    </li>
                ))}
            </ul>
        </>
    );
}
