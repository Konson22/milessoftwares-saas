import { Form, Head } from '@inertiajs/react';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field } from '@/components/field';
import { formatDate } from '@/lib/status';
import { open } from '@/routes/collaboration';

export type ProjectInvitationSummary = {
    from: string;
    inviter: string;
    project: string;
    relationship: string;
    capabilities: string[];
    message: string | null;
    contact: string | null;
    expiresAt: string;
};

/**
 * An emailed invitation to a project, opened on the inviting organization's
 * workspace. It is accepted in the invited organization's own workspace:
 * say which one, or start one on the platform first.
 */
export default function ProjectInvitationLanding({
    token,
    invitation,
    rootDomain,
    signUpUrl,
}: {
    token: string;
    invitation: ProjectInvitationSummary | null;
    rootDomain: string;
    signUpUrl: string;
}) {
    if (invitation === null) {
        return (
            <>
                <Head title="Invitation" />
                <h1 className="mb-2 text-xl font-semibold">
                    This invitation no longer works
                </h1>
                <p className="text-sm text-muted-foreground">
                    It has been used, withdrawn or has expired. Ask whoever
                    invited you for a new one.
                </p>
            </>
        );
    }

    return (
        <>
            <Head title={`Invitation from ${invitation.from}`} />
            <h1 className="mb-1 text-xl font-semibold">
                {invitation.from} invites your organization to{' '}
                {invitation.project}
            </h1>
            <p className="mb-2 text-sm text-muted-foreground">
                {invitation.inviter} invited
                {invitation.contact ? ` ${invitation.contact}` : ' you'} to take
                part as {invitation.relationship.toLowerCase()}. It works until{' '}
                {formatDate(invitation.expiresAt)}.
            </p>
            {invitation.message && (
                <p className="mb-4 text-sm italic">“{invitation.message}”</p>
            )}
            <p className="mb-6 text-sm text-muted-foreground">
                Your organization would be able to:{' '}
                {invitation.capabilities.join(', ')}. Someone who manages your
                organization accepts it in your own workspace.
            </p>

            <Form {...open.form({ token })} className="grid gap-4">
                {({ errors, processing }) => (
                    <>
                        <Field
                            id="workspace"
                            label="Your organization's workspace"
                            error={errors.workspace}
                        >
                            <div className="flex items-center gap-2">
                                <Input
                                    id="workspace"
                                    name="workspace"
                                    required
                                    autoComplete="off"
                                    placeholder="acme"
                                    aria-invalid={
                                        errors.workspace ? true : undefined
                                    }
                                />
                                <span className="text-sm text-muted-foreground">
                                    .{rootDomain}
                                </span>
                            </div>
                        </Field>
                        <Button type="submit" disabled={processing}>
                            Continue in my workspace
                        </Button>
                    </>
                )}
            </Form>

            <p className="mt-6 text-sm text-muted-foreground">
                Your organization is not on Tatook yet?{' '}
                <a href={signUpUrl} className="underline">
                    Register it
                </a>
                , add Projects, then open this link again. Check first that
                nobody in your organization has registered it already.
            </p>
        </>
    );
}
