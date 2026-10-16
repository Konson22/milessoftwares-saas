import { Form, Head } from '@inertiajs/react';
import InputError from '@/shared/components/input-error';
import PasswordInput from '@/shared/components/password-input';
import { Button } from '@/shared/components/ui/button';
import { Label } from '@/shared/components/ui/label';
import { Spinner } from '@/shared/components/ui/spinner';

type Props = { email: string; passwordRules: string };

export default function SetPassword({ email, passwordRules }: Props) {
    return (
        <>
            <Head title="Choose your password" />
            <p className="mb-6 text-center text-sm text-muted-foreground">
                Signed in as {email} with a temporary password.
            </p>
            <Form action="/password/setup" method="post">
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">New password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                autoFocus
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
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Save and continue
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

SetPassword.layout = {
    title: 'Choose your password',
    description:
        'Your temporary password works only until you choose your own.',
};
