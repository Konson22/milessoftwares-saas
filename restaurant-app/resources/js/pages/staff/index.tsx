import { Head } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';

type Person = {
    id: string;
    name: string;
    signInName: string;
    isOwner: boolean;
    membership: string;
    signIn: 'active' | 'disabled' | 'suspended';
    roles: { name: string; where: string; expiresAt: string | null }[];
};

type Props = {
    unreachable: boolean;
    manageUrl: string;
    people: Person[];
};

const SIGN_IN: Record<Person['signIn'], { label: string; className: string }> =
    {
        active: {
            label: 'Can sign in',
            className: 'border-green-300 bg-green-50 text-green-800',
        },
        disabled: {
            label: 'Sign-in disabled',
            className: 'border-red-300 bg-red-50 text-red-800',
        },
        suspended: {
            label: 'Suspended',
            className: 'border-amber-300 bg-amber-50 text-amber-900',
        },
    };

/**
 * Who works in the restaurant, their roles and where, and whether they may
 * sign in. Changed on the workspace's access page, which every application
 * shares.
 */
export default function Staff({ unreachable, manageUrl, people }: Props) {
    return (
        <>
            <Head title="Staff & access" />
            <PageHeader
                title="Staff & access"
                description="Roles, branches and sign-in for everyone in the restaurant."
                actions={
                    <Button asChild size="sm">
                        <a href={manageUrl}>
                            <ExternalLink className="size-4" aria-hidden />
                            Change access
                        </a>
                    </Button>
                }
            />

            {unreachable && (
                <p className="mb-4 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    The workspace could not be reached. Try again in a moment.
                </p>
            )}

            <Panel>
                <PanelBody className="overflow-x-auto">
                    {people.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nobody has a role in the restaurant yet. Give roles
                            on the workspace&apos;s access page.
                        </p>
                    ) : (
                        <table className="w-full min-w-[40rem] text-sm">
                            <thead className="text-left text-muted-foreground">
                                <tr>
                                    <th className="py-2 font-medium">Person</th>
                                    <th className="py-2 font-medium">
                                        Roles and where
                                    </th>
                                    <th className="py-2 font-medium">
                                        Sign-in
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {people.map((person) => (
                                    <tr key={person.id} className="align-top">
                                        <td className="py-2">
                                            <span className="font-medium">
                                                {person.name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {person.signInName}
                                                {person.isOwner && ' · Owner'}
                                            </span>
                                        </td>
                                        <td className="py-2">
                                            {person.isOwner &&
                                            person.roles.length === 0 ? (
                                                'Everything, everywhere'
                                            ) : person.roles.length === 0 ? (
                                                <span className="text-muted-foreground">
                                                    No role
                                                </span>
                                            ) : (
                                                <ul className="space-y-0.5">
                                                    {person.roles.map(
                                                        (role, index) => (
                                                            <li key={index}>
                                                                {role.name}
                                                                <span className="text-muted-foreground">
                                                                    {' '}
                                                                    ·{' '}
                                                                    {role.where}
                                                                    {role.expiresAt &&
                                                                        ` · until ${new Date(role.expiresAt).toLocaleDateString()}`}
                                                                </span>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </td>
                                        <td className="py-2">
                                            <span
                                                className={`inline-flex rounded-md border px-2 py-0.5 text-xs font-medium ${SIGN_IN[person.signIn].className}`}
                                            >
                                                {SIGN_IN[person.signIn].label}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
