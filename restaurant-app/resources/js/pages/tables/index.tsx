import { Form, Head } from '@inertiajs/react';
import { Armchair } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
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
import { store, update } from '@/routes/tables';

type DiningTable = {
    id: string;
    name: string;
    area: string | null;
    seats: number;
    active: boolean;
    /** Being served now: it cannot be taken out of use. */
    inUse: boolean;
};

type Props = {
    tables: DiningTable[];
    areas: string[];
};

/**
 * The branch's tables, by area: added and renamed here, taken out of use
 * when they are not being served.
 */
export default function Tables({ tables, areas }: Props) {
    return (
        <>
            <Head title="Tables" />
            <PageHeader
                title="Tables"
                description="Where guests sit in this branch. Each table has one order at a time."
            />

            <Panel className="mb-4 max-w-3xl">
                <PanelHeader title="Add a table" />
                <PanelBody>
                    <Form
                        {...store.form()}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="grid items-end gap-3 sm:grid-cols-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        placeholder="T1"
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-destructive">
                                            {errors.name}
                                        </p>
                                    )}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="area">Area</Label>
                                    <Input
                                        id="area"
                                        name="area"
                                        list="table-areas"
                                        placeholder="Terrace"
                                    />
                                    <datalist id="table-areas">
                                        {areas.map((area) => (
                                            <option key={area} value={area} />
                                        ))}
                                    </datalist>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="seats">Seats</Label>
                                    <Input
                                        id="seats"
                                        name="seats"
                                        type="number"
                                        min={1}
                                        max={100}
                                        defaultValue={4}
                                        required
                                    />
                                    {errors.seats && (
                                        <p className="text-sm text-destructive">
                                            {errors.seats}
                                        </p>
                                    )}
                                </div>
                                <Button type="submit" disabled={processing}>
                                    Add table
                                </Button>
                            </>
                        )}
                    </Form>
                </PanelBody>
            </Panel>

            <Panel>
                {tables.length === 0 ? (
                    <EmptyState icon={Armchair} title="No tables yet" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Table</TableHead>
                                <TableHead>Area</TableHead>
                                <TableHead>Seats</TableHead>
                                <TableHead>In use</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tables.map((table) => (
                                <TableRow key={table.id}>
                                    <TableCell colSpan={4}>
                                        <Form
                                            {...update.form(table.id)}
                                            options={{ preserveScroll: true }}
                                            className="flex flex-wrap items-center gap-2"
                                        >
                                            {({ errors, processing }) => (
                                                <>
                                                    <Input
                                                        name="name"
                                                        defaultValue={
                                                            table.name
                                                        }
                                                        aria-label="Name"
                                                        className="h-8 w-24"
                                                        required
                                                    />
                                                    <Input
                                                        name="area"
                                                        defaultValue={
                                                            table.area ?? ''
                                                        }
                                                        aria-label="Area"
                                                        list="table-areas"
                                                        className="h-8 w-32"
                                                    />
                                                    <Input
                                                        name="seats"
                                                        type="number"
                                                        min={1}
                                                        max={100}
                                                        defaultValue={
                                                            table.seats
                                                        }
                                                        aria-label="Seats"
                                                        className="h-8 w-20"
                                                        required
                                                    />
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
                                                                table.active
                                                            }
                                                        />
                                                        In use
                                                    </label>
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                    >
                                                        Save
                                                    </Button>
                                                    {(errors.name ||
                                                        errors.active ||
                                                        errors.seats) && (
                                                        <p className="w-full text-xs text-destructive">
                                                            {errors.name ??
                                                                errors.active ??
                                                                errors.seats}
                                                        </p>
                                                    )}
                                                </>
                                            )}
                                        </Form>
                                    </TableCell>
                                    <TableCell>
                                        {table.inUse && (
                                            <StatusBadge tone="info">
                                                Being served
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
