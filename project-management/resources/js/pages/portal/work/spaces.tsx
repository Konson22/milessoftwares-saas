import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Archive, Folder, ListTodo, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Select } from '@/components/field';
import { show as showList } from '@/routes/lists';
import { archive, rename } from '@/routes/structure';
import { store as storeFolder } from '@/routes/structure/folders';
import { store as storeList } from '@/routes/structure/lists';
import { store as storeSpace } from '@/routes/structure/spaces';
import { store as storeWorkspace } from '@/routes/structure/workspaces';
import type { Option } from '@/types';

type ListNode = {
    id: string;
    name: string;
    project: string | null;
    projectName: string | null;
    tasks: number;
    open: number;
};
type FolderNode = { id: string; name: string; lists: ListNode[] };
type SpaceNode = {
    id: string;
    name: string;
    folders: FolderNode[];
    lists: ListNode[];
};
type WorkspaceNode = { id: string; name: string; spaces: SpaceNode[] };
type Kind = 'workspace' | 'space' | 'folder' | 'list';

/**
 * Where work is organized: workspaces hold spaces, spaces hold folders and
 * lists, lists hold one project's tasks. Nothing is deleted: parts are
 * archived once nothing open is left in them.
 */
export default function Spaces({
    workspaces,
    projects,
    canManage,
}: {
    workspaces: WorkspaceNode[];
    projects: Option[];
    canManage: boolean;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Spaces" />
            <PageHeader
                title="Spaces"
                description="Workspaces, spaces, folders and lists: where the work is organized. Each list holds one project's tasks."
                actions={
                    canManage && (
                        <AddForm
                            label="Add workspace"
                            action={storeWorkspace.form()}
                        />
                    )
                }
            />

            {errors.structure && (
                <p
                    role="alert"
                    className="mb-4 border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                >
                    {errors.structure}
                </p>
            )}

            {workspaces.length === 0 ? (
                <Panel>
                    <EmptyState icon={Folder} title="Nothing organized yet">
                        A workspace appears with the first project, or add one.
                    </EmptyState>
                </Panel>
            ) : (
                <div className="grid gap-4">
                    {workspaces.map((workspace) => (
                        <Panel key={workspace.id}>
                            <PanelHeader
                                title={
                                    <Part
                                        kind="workspace"
                                        id={workspace.id}
                                        name={workspace.name}
                                        canManage={canManage}
                                    />
                                }
                                actions={
                                    canManage && (
                                        <AddForm
                                            label="Add space"
                                            action={storeSpace.form()}
                                            hidden={{
                                                workspace_id: workspace.id,
                                            }}
                                        />
                                    )
                                }
                            />
                            <PanelBody className="grid gap-4">
                                {workspace.spaces.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        No spaces yet.
                                    </p>
                                )}
                                {workspace.spaces.map((space) => (
                                    <SpaceSection
                                        key={space.id}
                                        space={space}
                                        projects={projects}
                                        canManage={canManage}
                                    />
                                ))}
                            </PanelBody>
                        </Panel>
                    ))}
                </div>
            )}
        </>
    );
}

function SpaceSection({
    space,
    projects,
    canManage,
}: {
    space: SpaceNode;
    projects: Option[];
    canManage: boolean;
}) {
    return (
        <section className="grid gap-2 border-l-2 pl-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-medium">
                    <Part
                        kind="space"
                        id={space.id}
                        name={space.name}
                        canManage={canManage}
                    />
                </h3>
                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        <AddForm
                            label="Add folder"
                            action={storeFolder.form()}
                            hidden={{ space_id: space.id }}
                        />
                        <AddListForm spaceId={space.id} projects={projects} />
                    </div>
                )}
            </div>

            {space.folders.map((folder) => (
                <div key={folder.id} className="grid gap-1 pl-2">
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="flex items-center gap-1.5">
                            <Folder
                                className="size-4 text-muted-foreground"
                                aria-hidden
                            />
                            <Part
                                kind="folder"
                                id={folder.id}
                                name={folder.name}
                                canManage={canManage}
                            />
                        </span>
                        {canManage && (
                            <AddListForm
                                spaceId={space.id}
                                folderId={folder.id}
                                projects={projects}
                            />
                        )}
                    </div>
                    <Lists lists={folder.lists} canManage={canManage} />
                </div>
            ))}

            <Lists lists={space.lists} canManage={canManage} />
            {space.folders.length === 0 && space.lists.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Empty: add a folder or a list.
                </p>
            )}
        </section>
    );
}

function Lists({
    lists,
    canManage,
}: {
    lists: ListNode[];
    canManage: boolean;
}) {
    if (lists.length === 0) {
        return null;
    }

    return (
        <ul className="divide-y border">
            {lists.map((list) => (
                <li
                    key={list.id}
                    className="flex flex-wrap items-center gap-2 px-3 py-1.5 text-sm"
                >
                    <ListTodo
                        className="size-4 text-muted-foreground"
                        aria-hidden
                    />
                    <Link
                        href={showList(list.id)}
                        className="min-w-0 flex-1 truncate hover:underline"
                    >
                        {list.name}
                    </Link>
                    <span
                        className="text-xs text-muted-foreground"
                        title={list.projectName ?? undefined}
                    >
                        {list.project ?? 'No project'}
                    </span>
                    <span className="w-24 text-right text-xs text-muted-foreground">
                        {list.open} open of {list.tasks}
                    </span>
                    {canManage && (
                        <PartActions
                            kind="list"
                            id={list.id}
                            name={list.name}
                        />
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * A part's name, with rename and archive for those who organize work.
 */
function Part({
    kind,
    id,
    name,
    canManage,
}: {
    kind: Kind;
    id: string;
    name: string;
    canManage: boolean;
}) {
    return (
        <span className="inline-flex items-center gap-1">
            {name}
            {canManage && <PartActions kind={kind} id={id} name={name} />}
        </span>
    );
}

function PartActions({
    kind,
    id,
    name,
}: {
    kind: Kind;
    id: string;
    name: string;
}) {
    const [renaming, setRenaming] = useState(false);

    if (renaming) {
        return (
            <Form
                {...rename.form({ kind, id })}
                onSuccess={() => setRenaming(false)}
                options={{ preserveScroll: true }}
                className="inline-flex items-center gap-1"
            >
                {({ processing }) => (
                    <>
                        <Input
                            name="name"
                            defaultValue={name}
                            required
                            maxLength={120}
                            aria-label={`New name for ${name}`}
                            className="h-7 w-48"
                            autoFocus
                        />
                        <Button type="submit" size="sm" disabled={processing}>
                            Save
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setRenaming(false)}
                        >
                            Cancel
                        </Button>
                    </>
                )}
            </Form>
        );
    }

    return (
        <span className="inline-flex items-center">
            <Button
                type="button"
                variant="ghost"
                size="sm"
                aria-label={`Rename ${name}`}
                onClick={() => setRenaming(true)}
            >
                <Pencil className="size-3.5" />
            </Button>
            <Form
                {...archive.form({ kind, id })}
                options={{ preserveScroll: true }}
            >
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="ghost"
                        size="sm"
                        aria-label={`Archive ${name}`}
                        disabled={processing}
                    >
                        <Archive className="size-3.5" />
                    </Button>
                )}
            </Form>
        </span>
    );
}

/**
 * A button that opens a one-field form: the new part's name.
 */
function AddForm({
    label,
    action,
    hidden = {},
    children,
}: {
    label: string;
    action: ReturnType<typeof storeWorkspace.form>;
    hidden?: Record<string, string>;
    children?: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    if (!open) {
        return (
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setOpen(true)}
            >
                <Plus className="size-4" aria-hidden />
                {label}
            </Button>
        );
    }

    return (
        <Form
            {...action}
            onSuccess={() => setOpen(false)}
            options={{ preserveScroll: true }}
            className="flex flex-wrap items-center gap-1"
        >
            {({ errors, processing }) => (
                <>
                    {Object.entries(hidden).map(([name, value]) => (
                        <input
                            key={name}
                            type="hidden"
                            name={name}
                            value={value}
                        />
                    ))}
                    {children}
                    <Input
                        name="name"
                        required
                        maxLength={120}
                        placeholder="Name"
                        aria-label={`${label}: name`}
                        aria-invalid={errors.name ? true : undefined}
                        className="h-8 w-44"
                        autoFocus
                    />
                    <Button type="submit" size="sm" disabled={processing}>
                        Add
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setOpen(false)}
                    >
                        Cancel
                    </Button>
                    {(errors.name || errors.project_id) && (
                        <span className="w-full text-xs text-destructive">
                            {errors.name ?? errors.project_id}
                        </span>
                    )}
                </>
            )}
        </Form>
    );
}

function AddListForm({
    spaceId,
    folderId,
    projects,
}: {
    spaceId: string;
    folderId?: string;
    projects: Option[];
}) {
    return (
        <AddForm
            label="Add list"
            action={storeList.form()}
            hidden={{
                space_id: spaceId,
                ...(folderId ? { folder_id: folderId } : {}),
            }}
        >
            <Select
                name="project_id"
                aria-label="Project"
                placeholder="No project"
                options={projects}
                className="h-8 w-48"
            />
        </AddForm>
    );
}
