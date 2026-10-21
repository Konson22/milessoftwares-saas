import { Form, Head, Link } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/shared/components/ui/input-otp';
import { OTP_MAX_LENGTH } from '@/shared/hooks/use-two-factor-auth';

/**
 * The second step of the admin sign-in: a code from the authenticator app,
 * or one of the recovery codes.
 */
export default function AdminTwoFactor() {
    const [recovery, setRecovery] = useState(false);
    const [code, setCode] = useState('');

    return (
        <>
            <Head title="Admin sign-in" />

            <Form
                action="/two-factor"
                method="post"
                resetOnError
                className="grid gap-5"
            >
                {({ errors, processing, clearErrors }) => (
                    <>
                        {recovery ? (
                            <div className="grid gap-2">
                                <Input
                                    name="recovery_code"
                                    placeholder="Recovery code"
                                    aria-label="Recovery code"
                                    autoFocus
                                    required
                                />
                                <InputError message={errors.recovery_code} />
                            </div>
                        ) : (
                            <div className="grid justify-items-center gap-2">
                                <InputOTP
                                    name="code"
                                    maxLength={OTP_MAX_LENGTH}
                                    value={code}
                                    onChange={setCode}
                                    disabled={processing}
                                    pattern={REGEXP_ONLY_DIGITS}
                                    autoFocus
                                >
                                    <InputOTPGroup>
                                        {Array.from(
                                            { length: OTP_MAX_LENGTH },
                                            (_, index) => (
                                                <InputOTPSlot
                                                    key={index}
                                                    index={index}
                                                />
                                            ),
                                        )}
                                    </InputOTPGroup>
                                </InputOTP>
                                <InputError message={errors.code} />
                            </div>
                        )}

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            Sign in
                        </Button>

                        <div className="flex justify-between text-sm text-muted-foreground">
                            <button
                                type="button"
                                className="underline underline-offset-4 hover:text-foreground"
                                onClick={() => {
                                    setRecovery((value) => !value);
                                    setCode('');
                                    clearErrors();
                                }}
                            >
                                {recovery
                                    ? 'Use the authenticator app'
                                    : 'Use a recovery code'}
                            </button>
                            <Link
                                href="/login"
                                className="underline underline-offset-4 hover:text-foreground"
                            >
                                Start again
                            </Link>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

AdminTwoFactor.layout = {
    title: 'Two-factor code',
    description: 'Enter the code from your authenticator app.',
};
