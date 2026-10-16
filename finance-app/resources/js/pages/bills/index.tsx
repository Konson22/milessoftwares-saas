import { Form, Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { DocumentsTable, StatusTabs } from '@/components/documents-table';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { Button } from '@miles/ui/components/ui/button';
import { useCan } from '@/lib/access';
import { useMoney } from '@/lib/format';
import { create, index, show } from '@/routes/bills';

type Bill = {
    id: string;
    number: string;
    supplier: string | null;
    source: string;
    billDate: string;
    dueOn: string | null;
    totalMinor: number;
    outstandingMinor: number;
    status: string;
};

type Props = {
    status: string;
    search: string;
    bills: Bill[];
    outstandingMinor: number;
};

const STATUSES = [
    { value: 'unpaid', label: 'Unpaid' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'paid', label: 'Paid' },
    { value: 'all', label: 'All' },
];

/**
 * Supplier bills: what is owed and when.
 */
export default function Bills({
    status,
    search,
    bills,
    outstandingMinor,
}: Props) {
    const can = useCan();
    const money = useMoney();

    return (
        <>
            <Head title="Bills" />
            <PageHeader
                title="Bills"
                description={`Owed to suppliers: ${money(outstandingMinor)}. Goods received in your products arrive here as bills.`}
                actions={
                    can('finance.payables.manage') && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus className="size-4" aria-hidden />
                                New bill
                            </Link>
                        </Button>
                    )
                }
            />
            <Panel>
                <PanelToolbar>
                    <StatusTabs
                        statuses={STATUSES}
                        current={status}
                        href={(value) =>
                            index({
                                query: {
                                    status: value,
                                    search: search || undefined,
                                },
                            })
                        }
                    />
                    <Form
                        {...index.form()}
                        options={{ preserveState: true }}
                        className="ml-auto"
                    >
                        <input type="hidden" name="status" value={status} />
                        <SearchField
                            name="search"
                            defaultValue={search}
                            placeholder="Number, supplier or reference"
                            aria-label="Search bills"
                            className="w-72"
                        />
                    </Form>
                </PanelToolbar>
                <DocumentsTable
                    rows={bills.map((bill) => ({
                        ...bill,
                        party: bill.supplier,
                        date: bill.billDate,
                    }))}
                    partyLabel="Supplier"
                    href={(id) => show(id)}
                    empty="No bills here."
                />
            </Panel>
        </>
    );
}
