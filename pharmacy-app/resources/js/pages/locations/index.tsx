import { Form, Head, Link, usePage } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { show, store, update } from '@/routes/locations';

type Props = {
    locations: {
        id: string;
        name: string;
        active: boolean;
        batches: number;
        units: number;
    }[];
    notPutAway: number;
    canManage: boolean;
};

/**
 * The places stock is kept in this branch, and how much each holds.
 */
export default function Locations({ locations, notPutAway, canManage }: Props) {
    const { branch } = usePage().props;

    return (
        <>
            <Head title="Locations" />
            <PageHeader
                title="Locations"
                description={`Where stock is kept in ${branch?.name ?? 'this branch'}: shelves, the store room, the fridge.`}
            />

            <div className="grid gap-3 lg:grid-cols-[1fr_20rem]">
                <Panel>
                    {locations.length === 0 ? (
                        <EmptyState icon={MapPin} title="No locations yet">
                            Without locations, all stock is simply in the
                            branch.
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Location</TableHead>
                                    <TableHead className="text-right">
                                        Batches
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Units
                                    </TableHead>
                                    {canManage && <TableHead>Rename</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {locations.map((location) => (
                                    <TableRow key={location.id}>
                                        <TableCell>
                                            <Link
                                                href={show(location.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {location.name}
                                            </Link>{' '}
                                            {!location.active && (
                                                <StatusBadge tone="neutral">
                                                    Not in use
                                                </StatusBadge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {location.batches}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {location.units}
                                        </TableCell>
                                        {canManage && (
                                            <TableCell>
                                                <Form
                                                    {...update.form(
                                                        location.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                    className="flex flex-wrap items-start gap-2"
                                                >
                                                    {({
                                                        errors,
                                                        processing,
                                                    }) => (
                                                        <>
                                                            <Input
                                                                name="name"
                                                                defaultValue={
                                                                    location.name
                                                                }
                                                                aria-label={`Name of ${location.name}`}
                                                                className="h-8 w-40"
                                                            />
                                                            <select
                                                                name="active"
                                                                defaultValue={
                                                                    location.active
                                                                        ? '1'
                                                                        : '0'
                                                                }
                                                                aria-label={`Is ${location.name} in use`}
                                                                className="h-8 rounded-md border bg-transparent px-2 text-sm"
                                                            >
                                                                <option value="1">
                                                                    In use
                                                                </option>
                                                                <option value="0">
                                                                    Not in use
                                                                </option>
                                                            </select>
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Save
                                                            </Button>
                                                            {(errors.name ||
                                                                errors.active) && (
                                                                <p className="w-full text-xs text-destructive">
                                                                    {errors.name ??
                                                                        errors.active}
                                                                </p>
                                                            )}
                                                        </>
                                                    )}
                                                </Form>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    {notPutAway > 0 && locations.length > 0 && (
                        <p className="border-t px-4 py-3 text-sm text-muted-foreground">
                            {notPutAway} units are not put away anywhere yet:
                            place them from each medicine&apos;s stock page.
                        </p>
                    )}
                </Panel>

                {canManage && (
                    <Panel>
                        <PanelHeader title="Add a location" />
                        <PanelBody>
                            <Form
                                {...store.form()}
                                resetOnSuccess
                                className="grid gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Input
                                            name="name"
                                            required
                                            placeholder="Shelf A, Store room, Fridge"
                                            aria-label="Location name"
                                        />
                                        {errors.name && (
                                            <p className="text-sm text-destructive">
                                                {errors.name}
                                            </p>
                                        )}
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}
