import { Form, Head, usePage } from '@inertiajs/react';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';

type Props = {
    status:
        | 'pending'
        | 'invalid'
        | 'accepted'
        | 'revoked'
        | 'expired'
        | 'wrong_account';
    token: string;
    organization: { displayName: string } | null;
    invitedEmail: string | null;
    emailVerified: boolean;
};

const messages: Record<Exclude<Props['status'], 'pending'>, string> = {
    invalid: 'This invitation link is not valid.',
    accepted: 'This invitation has already been used.',
    revoked: 'This invitation was withdrawn.',
    expired:
        'This invitation has expired. Ask the person who invited you for a new one.',
    wrong_account: 'This invitation was sent to a different email address.',
};

export default function InvitationShow({
    status,
    token,
    organization,
    invitedEmail,
    emailVerified,
}: Props) {
    const { errors, auth } = usePage().props as {
        errors: Record<string, string>;
        auth: { user: { email: string } };
    };

    return (
        <>
            <Head title="Invitation" />

            <div className="mx-auto max-w-md space-y-6 p-4">
                {status === 'pending' && organization ? (
                    <>
                        <h1 className="text-2xl font-semibold">
                            Join {organization.displayName}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            You are signed in as {auth.user.email}. Accepting
                            adds you to {organization.displayName} as a member.
                        </p>
                        {!emailVerified && (
                            <p className="text-sm text-destructive">
                                Verify your email address first, then come back
                                to this link.
                            </p>
                        )}
                        <Form
                            action={`/invitations/${token}/accept`}
                            method="post"
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    disabled={processing || !emailVerified}
                                >
                                    Accept invitation
                                </Button>
                            )}
                        </Form>
                        <InputError message={errors.invitation} />
                    </>
                ) : (
                    <>
                        <h1 className="text-2xl font-semibold">
                            Invitation unavailable
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {
                                messages[
                                    status as Exclude<
                                        Props['status'],
                                        'pending'
                                    >
                                ]
                            }
                        </p>
                        {status === 'wrong_account' && invitedEmail && (
                            <p className="text-sm text-muted-foreground">
                                Sign out and sign in as {invitedEmail} to accept
                                it. You are signed in as {auth.user.email}.
                            </p>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
