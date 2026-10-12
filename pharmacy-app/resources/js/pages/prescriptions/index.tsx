import { Head, Link, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useCanHere } from '@/lib/access';
import { create, index, show } from '@/routes/prescriptions';

type Prescription = {
    id: string;
    number: string;
    patientName: string;
    prescriberName: string;
    issuedOn: string;
    status: string;
    statusLabel: string;
    items: number;
};

type Props = {
    prescriptions: Prescription[];
    status: string | null;
    statuses: { value: string; label: string }[];
};

export default function Prescriptions({
    prescriptions,
    status,
    statuses,
}: Props) {
    const { branch } = usePage().props;
    const canHere = useCanHere();

    return (
        <>
            <Head title="Prescriptions" />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold">
                    Prescriptions, {branch?.name}
                </h1>
                {canHere('pharmacy.prescriptions.record') && (
                    <Button asChild>
                        <Link href={create()}>Record a prescription</Link>
                    </Button>
                )}
            </div>

            <nav
                aria-label="Filter by status"
                className="mt-6 flex flex-wrap gap-2"
            >
                <Button
                    size="sm"
                    variant={status === null ? 'default' : 'outline'}
                    asChild
                >
                    <Link href={index()}>All</Link>
                </Button>
                {statuses.map((option) => (
                    <Button
                        key={option.value}
                        size="sm"
                        variant={
                            status === option.value ? 'default' : 'outline'
                        }
                        asChild
                    >
                        <Link href={index({ query: { status: option.value } })}>
                            {option.label}
                        </Link>
                    </Button>
                ))}
            </nav>

            <ul className="mt-4 divide-y rounded-md border">
                {prescriptions.length === 0 && (
                    <li className="px-4 py-3 text-sm text-muted-foreground">
                        No prescriptions.
                    </li>
                )}
                {prescriptions.map((prescription) => (
                    <li
                        key={prescription.id}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                    >
                        <Link
                            href={show(prescription.id)}
                            className="font-medium hover:underline"
                        >
                            {prescription.number}
                        </Link>
                        <span>{prescription.patientName}</span>
                        <span className="text-muted-foreground">
                            {prescription.prescriberName} ·{' '}
                            {prescription.issuedOn} · {prescription.items}{' '}
                            item(s)
                        </span>
                        <Badge
                            variant={
                                prescription.status === 'recorded'
                                    ? 'default'
                                    : 'secondary'
                            }
                            className="ml-auto"
                        >
                            {prescription.statusLabel}
                        </Badge>
                    </li>
                ))}
            </ul>
        </>
    );
}
