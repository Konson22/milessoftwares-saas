import { Form, Head, usePage } from '@inertiajs/react';
import { Gift } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { update } from '@/routes/retail/loyalty';

type Props = {
    program: {
        active: boolean;
        spendPerPoint: string;
        pointValue: string;
    } | null;
    members: { id: string; number: string; name: string; points: number }[];
    outstandingPoints: number;
};

/**
 * The loyalty program: customers named at the till earn points on what
 * they pay, and spend them there as a discount.
 */
export default function Loyalty({
    program,
    members,
    outstandingPoints,
}: Props) {
    const { currency } = usePage().props;

    return (
        <>
            <Head title="Loyalty" />
            <PageHeader
                title="Loyalty"
                description="Customers named at the till earn points on what they pay, and spend them there as a discount."
            />

            <div className="grid gap-3 lg:grid-cols-[22rem_1fr]">
                <Panel>
                    <PanelHeader title="Program" />
                    <PanelBody>
                        <Form
                            {...update.form()}
                            options={{ preserveScroll: true }}
                            className="grid gap-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="spend_per_point">
                                            Spending this earns one point (
                                            {currency})
                                        </Label>
                                        <Input
                                            id="spend_per_point"
                                            name="spend_per_point"
                                            inputMode="decimal"
                                            required
                                            defaultValue={
                                                program?.spendPerPoint ?? ''
                                            }
                                            placeholder="100.00"
                                        />
                                        {errors.spend_per_point && (
                                            <p className="text-sm text-destructive">
                                                {errors.spend_per_point}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="point_value">
                                            One point takes off ({currency})
                                        </Label>
                                        <Input
                                            id="point_value"
                                            name="point_value"
                                            inputMode="decimal"
                                            required
                                            defaultValue={
                                                program?.pointValue ?? ''
                                            }
                                            placeholder="1.00"
                                        />
                                        {errors.point_value && (
                                            <p className="text-sm text-destructive">
                                                {errors.point_value}
                                            </p>
                                        )}
                                    </div>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="hidden"
                                            name="active"
                                            value="0"
                                        />
                                        <input
                                            type="checkbox"
                                            name="active"
                                            value="1"
                                            defaultChecked={
                                                program?.active ?? true
                                            }
                                        />
                                        Running: customers earn and spend points
                                    </label>
                                    <Button type="submit" disabled={processing}>
                                        Save
                                    </Button>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Most points"
                        description={`${outstandingPoints} points held by customers`}
                    />
                    {members.length === 0 ? (
                        <EmptyState icon={Gift} title="No points yet">
                            Customers earn points once they are named on a sale.
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Customer</TableHead>
                                    <TableHead className="text-right">
                                        Points
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {members.map((member) => (
                                    <TableRow key={member.id}>
                                        <TableCell>
                                            {member.name}{' '}
                                            <span className="text-xs text-muted-foreground">
                                                {member.number}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {member.points}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>
            </div>
        </>
    );
}
