import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/prescriptions';

type Props = {
    medicines: {
        id: string;
        label: string;
        unit: string;
        controlled: boolean;
    }[];
    today: string;
};

const selectClass = 'h-9 w-full rounded-md border bg-transparent px-3 text-sm';

export default function RecordPrescription({ medicines, today }: Props) {
    const [items, setItems] = useState([0]);
    const [nextKey, setNextKey] = useState(1);

    return (
        <>
            <Head title="Record a prescription" />
            <h1 className="text-2xl font-semibold">Record a prescription</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                A pharmacist verifies it before anything is dispensed. Valid for
                30 days from the date it was written.
            </p>

            <Form {...store.form()} className="mt-6 grid max-w-4xl gap-4">
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-4 sm:grid-cols-3">
                            {(
                                [
                                    [
                                        'patient_name',
                                        'Patient name',
                                        'text',
                                        true,
                                    ],
                                    [
                                        'patient_age',
                                        'Patient age (optional)',
                                        'number',
                                        false,
                                    ],
                                    ['issued_on', 'Written on', 'date', true],
                                    [
                                        'prescriber_name',
                                        'Prescriber',
                                        'text',
                                        true,
                                    ],
                                    [
                                        'prescriber_registration',
                                        'Prescriber registration no.',
                                        'text',
                                        false,
                                    ],
                                    ['notes', 'Notes', 'text', false],
                                ] as const
                            ).map(([name, label, type, required]) => (
                                <div key={name} className="grid gap-1.5">
                                    <Label htmlFor={name}>{label}</Label>
                                    <Input
                                        id={name}
                                        name={name}
                                        type={type}
                                        required={required}
                                        max={
                                            name === 'issued_on'
                                                ? today
                                                : undefined
                                        }
                                        defaultValue={
                                            name === 'issued_on'
                                                ? today
                                                : undefined
                                        }
                                        aria-invalid={
                                            errors[name] ? true : undefined
                                        }
                                    />
                                    {errors[name] && (
                                        <p className="text-sm text-destructive">
                                            {errors[name]}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>

                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium">
                                Medicines
                            </legend>
                            {items.map((key, i) => (
                                <div
                                    key={key}
                                    className="grid items-start gap-2 sm:grid-cols-[1fr_7rem_1fr_auto]"
                                >
                                    <select
                                        name={`items[${i}][medicine_id]`}
                                        aria-label={`Medicine ${i + 1}`}
                                        className={selectClass}
                                    >
                                        {medicines.map((medicine) => (
                                            <option
                                                key={medicine.id}
                                                value={medicine.id}
                                            >
                                                {medicine.label}
                                                {medicine.controlled
                                                    ? ' (controlled)'
                                                    : ''}
                                            </option>
                                        ))}
                                    </select>
                                    <Input
                                        name={`items[${i}][quantity]`}
                                        type="number"
                                        min={1}
                                        required
                                        placeholder="Quantity"
                                        aria-label={`Quantity ${i + 1}`}
                                    />
                                    <Input
                                        name={`items[${i}][directions]`}
                                        required
                                        placeholder="Directions, e.g. 1 tablet 3 times a day"
                                        aria-label={`Directions ${i + 1}`}
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remove medicine ${i + 1}`}
                                        disabled={items.length === 1}
                                        onClick={() =>
                                            setItems(
                                                items.filter((k) => k !== key),
                                            )
                                        }
                                    >
                                        <Trash2
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </Button>
                                    {Object.entries(errors)
                                        .filter(([field]) =>
                                            field.startsWith(`items.${i}.`),
                                        )
                                        .map(([field, message]) => (
                                            <p
                                                key={field}
                                                className="text-sm text-destructive sm:col-span-4"
                                            >
                                                {message}
                                            </p>
                                        ))}
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="justify-self-start"
                                onClick={() => {
                                    setItems([...items, nextKey]);
                                    setNextKey(nextKey + 1);
                                }}
                            >
                                <Plus className="size-4" aria-hidden />
                                Add medicine
                            </Button>
                        </fieldset>

                        <div className="flex gap-3">
                            <Button type="submit" disabled={processing}>
                                Record
                            </Button>
                            <Button variant="ghost" asChild>
                                <Link href={index()}>Cancel</Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
