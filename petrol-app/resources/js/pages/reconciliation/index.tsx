import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { useMoney } from '@/lib/use-money';
import { show } from '@/routes/shifts';

type Row = {
    id: string;
    number: string;
    status: string;
    soldLitres: string;
    salesMinor: number;
    expectedMinor: number;
    differenceMinor: number | null;
};

export default function Reconciliation({ shifts }: { shifts: Row[] }) {
    const money = useMoney();

    return (
        <>
            <Head title="Reconciliation" />
            <PageHeader
                title="Reconciliation"
                description="Dispensed litres are closing meter minus opening meter. A cash difference is posted once, when the shift is approved."
            />
            <Panel>
                <PanelBody>
                    <ul className="grid gap-2 text-sm">
                        {shifts.map((shift) => (
                            <li key={shift.id}>
                                <Link
                                    href={show(shift.id)}
                                    className="underline"
                                >
                                    {shift.number}
                                </Link>{' '}
                                {shift.status} · sold {shift.soldLitres} L ·{' '}
                                {money(shift.salesMinor)} · difference{' '}
                                {shift.differenceMinor === null
                                    ? 'unknown'
                                    : money(shift.differenceMinor)}
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
        </>
    );
}
