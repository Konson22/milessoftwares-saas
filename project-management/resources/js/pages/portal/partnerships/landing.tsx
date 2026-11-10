import { Form, Head } from '@inertiajs/react';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field } from '@/components/field';
import { formatDate } from '@/lib/status';
import { open } from '@/routes/partnerships';

type Invitation = {
    donor: string;
    inviter: string;
    organization: string;
    label: string;
    email: string;
    expiresAt: string;
};

/**
 * A donor's invitation link, opened on the donor's workspace. The invited
 * organization accepts it in its own workspace: say which one, or start one.
 */
export default function PartnershipLanding({
    token,
    invitation,
    rootDomain,
    signUpUrl,
}: {
    token: string;
    invitation: Invitation | null;
    rootDomain?: string;
    signUpUrl?: string;
}) {
    if (invitation === null) {
        return (
            <>
                <Head title="Invitation" />
                <h1 className="mb-2 text-xl font-semibold">
                    This invitation no longer works
                </h1>
                <p className="text-sm text-muted-foreground">
                    It has been used, withdrawn or has expired. Ask the donor
                    for a new one.
                </p>
            </>
        );
    }

    return (
        <>
            <Head title={`Invitation from ${invitation.donor}`} />
            <h1 className="mb-1 text-xl font-semibold">
                {invitation.donor} invited {invitation.organization}
            </h1>
            <p className="mb-6 text-sm text-muted-foreground">
                {invitation.inviter} invited <strong>{invitation.email}</strong>{' '}
                to connect {invitation.organization}, as a{' '}
                {invitation.label.toLowerCase()}. It works until{' '}
                {formatDate(invitation.expiresAt)}.
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
                No workspace yet?{' '}
                <a href={signUpUrl} className="underline">
                    Start one
                </a>
                , add Projects to it, then open this link again.
            </p>
        </>
    );
}
