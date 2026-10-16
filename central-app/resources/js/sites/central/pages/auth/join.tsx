import { Form, Head } from '@inertiajs/react';
import InputError from '@/shared/components/input-error';
import PasswordInput from '@/shared/components/password-input';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { Spinner } from '@/shared/components/ui/spinner';

type Props =
    | {
          status: 'new';
          token: string;
          email: string;
          organization: string | null;
          passwordRules: string;
      }
    | { status: 'invalid' | 'accepted' | 'revoked' | 'expired' };

const unavailable: Record<string, string> = {
    invalid: 'This invitation link is not valid.',
    accepted: 'This invitation has already been used.',
    revoked: 'This invitation was withdrawn.',
    expired:
        'This invitation has expired. Ask the person who invited you for a new one.',
};

export default function Join(props: Props) {
    if (props.status !== 'new') {
        return (
            <>
                <Head title="Invitation" />
                <p className="text-center text-sm text-muted-foreground">
                    {unavailable[props.status]}
                </p>
            </>
        );
    }

    const { token, email, organization, passwordRules } = props;

    return (
        <>
            <Head title="Join" />
            <p className="mb-6 text-center text-sm text-muted-foreground">
                {organization
                    ? `You were invited to ${organization}. Choose your name and password to join.`
                    : 'Choose your name and password to join.'}
            </p>
            <Form action={`/join/${token}`} method="post">
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" value={email} readOnly />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Your name</Label>
                            <Input
                                id="name"
                                name="name"
                                autoComplete="name"
                                autoFocus
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                        </div>
                        <InputError message={errors.invitation} />
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Join
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

Join.layout = { title: 'Join your team', description: '' };
