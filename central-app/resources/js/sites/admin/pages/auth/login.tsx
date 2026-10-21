import { Form, Head } from '@inertiajs/react';
import InputError from '@/shared/components/input-error';
import PasswordInput from '@/shared/components/password-input';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { Spinner } from '@/shared/components/ui/spinner';

/**
 * Staff sign-in on the admin host: email and password here, then the
 * two-factor code on the next page.
 */
export default function AdminLogin({ status }: { status?: string }) {
    return (
        <>
            <Head title="Admin sign-in" />

            {status && (
                <p className="mb-4 rounded-md border bg-muted px-3 py-2 text-center text-sm">
                    {status}
                </p>
            )}

            <Form
                action="/login"
                method="post"
                resetOnSuccess={['password']}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                autoComplete="username"
                            />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="current-password"
                            />
                            <InputError message={errors.password} />
                        </div>
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Continue
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

AdminLogin.layout = {
    title: 'Sign in to admin',
    description: 'Use your staff account.',
};
