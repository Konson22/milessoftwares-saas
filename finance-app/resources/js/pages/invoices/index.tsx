import { Form, Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { DocumentsTable, StatusTabs } from '@/components/documents-table';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelToolbar } from '@/components/panel';
import { SearchField } from '@/components/search-field';
import { Button } from '@/components/ui/button';
import { useCan } from '@/lib/access';
import { useMoney } from '@/lib/format';
import { create, index, show } from '@/routes/invoices';

type Invoice = {
    id: string;
    number: string;
    customer: string | null;
    source: string;
    issuedOn: string;
    dueOn: string | null;
    totalMinor: number;
    outstandingMinor: number;
    status: string;
};

type Props = {
    status: string;
    search: string;
    invoices: Invoice[];
    outstandingMinor: number;
};

const STATUSES = [
    { value: 'unpaid', label: 'Unpaid' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'paid', label: 'Paid' },
    { value: 'void', label: 'Void' },
    { value: 'all', label: 'All' },
];

/**
 * Customer invoices and sales: what customers owe.
 */
export default function Invoices({
    status,
    search,
    invoices,
    outstandingMinor,
}: Props) {
    const can = useCan();
    const money = useMoney();

    return (
        <>
            <Head title="Invoices" />
            <PageHeader
                title="Invoices"
                description={`Customers owe: ${money(outstandingMinor)}. Sales in your products arrive here already paid.`}
                actions={
                    can('finance.receivables.manage') && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus className="size-4" aria-hidden />
                                New invoice
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
                            placeholder="Number or customer"
                            aria-label="Search invoices"
                            className="w-72"
                        />
                    </Form>
                </PanelToolbar>
                <DocumentsTable
                    rows={invoices.map((invoice) => ({
                        ...invoice,
                        party: invoice.customer,
                        date: invoice.issuedOn,
                    }))}
                    partyLabel="Customer"
                    href={(id) => show(id)}
                    empty="No invoices here."
                />
            </Panel>
        </>
    );
}
