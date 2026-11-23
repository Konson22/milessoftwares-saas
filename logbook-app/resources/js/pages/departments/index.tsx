import { Head, router, usePage } from '@inertiajs/react';
import { BookOpen, Building2, Check, Pencil, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { PageHeader } from '@/components/page-header';
import { store, update } from '@/routes/departments';

type Props = {
    departments: { id: string; name: string; logbooks: number }[];
    /** A people manager for the whole workspace adds and renames them. */
    canEdit: boolean;
    workspaceLogbooks: number;
};

/**
 * The workspace's departments, kept in Central and shared with its other
 * applications. Each logbook belongs to one, and a role given for a
 * department reaches only its logbooks.
 */
export default function Departments({
    departments,
    canEdit,
    workspaceLogbooks,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <>
            <Head title="Departments" />
            <PageHeader title="Departments" />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                <section className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]">
                    <header className="flex items-center justify-between gap-3 border-b border-[#ebebeb] px-4 py-3">
                        <h2 className="text-sm font-semibold">
                            Departments{' '}
                            <span className="font-normal text-muted-foreground tabular-nums">
                                {departments.length}
                            </span>
                        </h2>
                    </header>

                    {canEdit && (
                        <form
                            className="flex flex-col gap-2 border-b border-[#ebebeb] px-4 py-3 sm:flex-row sm:items-start"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.post(
                                    store().url,
                                    { name: name.trim() },
                                    {
                                        preserveScroll: true,
                                        onStart: () => setBusy(true),
                                        onFinish: () => setBusy(false),
                                        onSuccess: () => setName(''),
                                    },
                                );
                            }}
                        >
                            <div className="grid flex-1 gap-1">
                                <label
                                    htmlFor="new-department"
                                    className="sr-only"
                                >
                                    New department
                                </label>
                                <Input
                                    id="new-department"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder="New department, such as Maintenance"
                                    aria-invalid={
                                        errors.name && editing === null
                                            ? true
                                            : undefined
                                    }
                                />
                                {errors.name && editing === null && (
                                    <p className="text-xs text-destructive">
                                        {errors.name}
                                    </p>
                                )}
                            </div>
                            <Button
                                type="submit"
                                disabled={busy || name.trim() === ''}
                            >
                                <Plus /> Add department
                            </Button>
                        </form>
                    )}

                    {departments.length === 0 ? (
                        <EmptyState icon={Building2} title="No departments yet">
                            {canEdit
                                ? 'Add the departments your teams work in, then file each logbook under one.'
                                : 'A people manager for the whole workspace adds them.'}
                        </EmptyState>
                    ) : (
                        <ul className="divide-y divide-[#ebebeb]">
                            {departments.map((d) =>
                                editing === d.id ? (
                                    <RenameRow
                                        key={d.id}
                                        department={d}
                                        error={errors.name}
                                        onDone={() => setEditing(null)}
                                    />
                                ) : (
                                    <li
                                        key={d.id}
                                        className="flex items-center gap-3 px-4 py-3"
                                    >
                                        <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-[#eef2f6] text-[#16202c]">
                                            <Building2 className="size-4" />
                                        </span>
                                        <span className="grid min-w-0 flex-1">
                                            <span className="truncate text-sm font-medium">
                                                {d.name}
                                            </span>
                                            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                <BookOpen
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                                {d.logbooks === 1
                                                    ? '1 logbook'
                                                    : `${d.logbooks} logbooks`}
                                            </span>
                                        </span>
                                        {canEdit && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => setEditing(d.id)}
                                                aria-label={`Rename ${d.name}`}
                                            >
                                                <Pencil /> Rename
                                            </Button>
                                        )}
                                    </li>
                                ),
                            )}
                        </ul>
                    )}
                </section>

                <aside className="grid gap-3 rounded-lg border bg-card p-5 text-sm shadow-[0_1px_2px_#e3e3e3]">
                    <h2 className="font-semibold">How departments work</h2>
                    <p className="text-muted-foreground">
                        Each logbook belongs to a department. Someone given a
                        role for a department reaches only its logbooks, and
                        only those they are added to.
                    </p>
                    <p className="text-muted-foreground">
                        {workspaceLogbooks === 0
                            ? 'Every logbook belongs to a department.'
                            : `${workspaceLogbooks} ${workspaceLogbooks === 1 ? 'logbook belongs' : 'logbooks belong'} to the whole workspace: only whole-workspace roles reach ${workspaceLogbooks === 1 ? 'it' : 'them'}.`}
                    </p>
                    <p className="text-muted-foreground">
                        Departments are shared with the workspace's other
                        applications, so they are renamed here but never
                        deleted.
                    </p>
                </aside>
            </div>
        </>
    );
}

function RenameRow({
    department,
    error,
    onDone,
}: {
    department: { id: string; name: string };
    error?: string;
    onDone: () => void;
}) {
    const [name, setName] = useState(department.name);

    return (
        <li className="px-4 py-3">
            <form
                className="flex flex-col gap-2 sm:flex-row sm:items-start"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.put(
                        update(department.id).url,
                        { name: name.trim() },
                        { preserveScroll: true, onSuccess: onDone },
                    );
                }}
            >
                <div className="grid flex-1 gap-1">
                    <label
                        htmlFor={`rename-${department.id}`}
                        className="sr-only"
                    >
                        Name
                    </label>
                    <Input
                        id={`rename-${department.id}`}
                        autoFocus
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        aria-invalid={error ? true : undefined}
                    />
                    {error && (
                        <p className="text-xs text-destructive">{error}</p>
                    )}
                </div>
                <div className="flex gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        disabled={name.trim() === ''}
                    >
                        <Check /> Save
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={onDone}
                    >
                        <X /> Cancel
                    </Button>
                </div>
            </form>
        </li>
    );
}
