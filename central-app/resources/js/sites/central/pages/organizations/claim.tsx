import { Form, Head, usePage } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { Spinner } from '@/shared/components/ui/spinner';

type Props = {
    status:
        | 'pending'
        | 'invalid'
        | 'claimed'
        | 'expired'
        | 'wrong_account';
    token: string;
    workspace: {
        name: string;
        host: string;
        sponsor: string;
        application: string;
    } | null;
    emailVerified: boolean;
};

const messages: Record<Exclude<Props['status'], 'pending'>, string> = {
    invalid:
        'This link is no longer valid. It may have been replaced by a newer one, or the workspace withdrawn by the organization that made it.',
    claimed: 'This workspace has already been claimed.',
    expired:
        'This link has expired. Ask the organization that made the workspace to send a new one.',
    wrong_account:
        'This workspace was made for a different email address. Sign out and sign in with the address the email was sent to.',
};

/**
 * A workspace a donor created for this person: claiming it makes them its
 * owner and opens it.
 */
export default function ClaimWorkspace({
    status,
    token,
    workspace,
    emailVerified,
}: Props) {
    const { errors, auth } = usePage().props as {
        errors: Record<string, string>;
        auth: { user: { email: string } };
    };

    return (
        <>
            <Head title="Claim workspace" />

            <div className="mx-auto max-w-md space-y-4">
                {status === 'pending' && workspace ? (
                    <>
                        <h1 className="text-2xl font-semibold">
                            Claim {workspace.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {workspace.sponsor} set up this workspace for you
                            in {workspace.application}. Claiming it makes you
                            its owner: you invite your team, and{' '}
                            {workspace.sponsor} sees only what you choose to
                            share with them, grant by grant.
                        </p>
                        <p className="flex items-center gap-2 truncate rounded-md bg-muted px-3 py-2 font-mono text-xs">
                            <Globe className="size-3.5 shrink-0" aria-hidden />
                            <span className="truncate">{workspace.host}</span>
                        </p>
                        <p className="text-sm text-muted-foreground">
                            You are signed in as {auth.user.email}.
                        </p>
                        {!emailVerified && (
                            <p className="text-sm text-destructive">
                                Verify your email address first, then come back
                                to this link.
                            </p>
                        )}
                        <Form action={`/workspaces/claim/${token}`} method="post">
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    disabled={processing || !emailVerified}
                                >
                                    {processing && <Spinner />}
                                    Claim workspace
                                </Button>
                            )}
                        </Form>
                        <InputError message={errors.claim} />
                    </>
                ) : (
                    <>
                        <h1 className="text-2xl font-semibold">
                            Workspace unavailable
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {
                                messages[
                                    status as Exclude<Props['status'], 'pending'>
                                ]
                            }
                        </p>
                    </>
                )}
            </div>
        </>
    );
}
